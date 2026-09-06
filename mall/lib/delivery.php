<?php
/**
 * 배송 배정/상태전환/위치기록 유스케이스 (Application Layer)
 * Design Ref: mall-delivery-dispatch.design.md §2, §3, §6 — 모든 배정/상태전환은 이 파일의 함수만 거친다
 *
 * 상태 전이 순서: ready →(배정)→ assigned →(배송시작)→ delivering →(도착)→ arrived →(완료)→ completed
 *                                    └─(배송실패, assigned/delivering에서 가능)→ delivery_failed →(재배정)→ assigned
 * 서버가 매 요청마다 "현재 상태 → 요청 상태"가 허용된 전이인지 재확인한다(클라이언트 신뢰 안 함).
 */
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/fresh_order.php';

/**
 * 주문의 현재(종료되지 않은) 배정 행을 조회합니다.
 * @param int $order_id
 * @return array|null
 */
function mall_delivery_get_active_assignment($order_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        "SELECT id, order_id, driver_id, status, assigned_at, delivering_at, arrived_at, completed_at, failed_reason
         FROM mall_order_driver_assignments
         WHERE order_id = ? AND status NOT IN ('completed', 'failed', 'reassigned')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * 활성(is_active=1) 배송기사 목록을 진행중인 배송 건수와 함께 조회합니다(관리자 배정 드롭다운용).
 * @return array<int, array>
 */
function mall_driver_list_active() {
    $conn = mall_get_db_connection();
    $result = $conn->query(
        "SELECT d.id, d.name, d.phone,
                (SELECT COUNT(*) FROM mall_order_driver_assignments a
                 WHERE a.driver_id = d.id AND a.status IN ('assigned', 'delivering', 'arrived')) AS active_count
         FROM mall_drivers d
         WHERE d.is_active = 1
         ORDER BY d.name"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * 상품준비중(preparing) 주문을 준비완료(ready)로 처리합니다.
 * @param int $order_id
 * @return array{success:bool, error?:string}
 */
function mall_order_mark_ready($order_id) {
    $conn = mall_get_db_connection();

    $order_stmt = $conn->prepare('SELECT status FROM mall_orders WHERE id = ?');
    $order_stmt->bind_param('i', $order_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();

    if (!$order) {
        return ['success' => false, 'error' => 'NOT_FOUND'];
    }
    if ($order['status'] !== 'preparing') {
        return ['success' => false, 'error' => 'INVALID_STATE_TRANSITION'];
    }
    // Design Ref: mall-fresh-products.design.md §4.3, §6.2 — weight 타입 신선 라인이 아직 실측
    // 입력 전이면 준비완료로 넘어갈 수 없다(서버가 최종 방어; 화면에서도 버튼을 비활성화해둔다).
    if (mall_fresh_order_has_unconfirmed_weight($order_id)) {
        $conn->close();
        return ['success' => false, 'error' => 'PREPARING_BLOCKED'];
    }

    $stmt = $conn->prepare("UPDATE mall_orders SET status = 'ready', ready_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->close();

    return ['success' => true];
}

/**
 * 준비완료(ready) 주문에 배송기사를 배정합니다.
 * @param int $order_id
 * @param int $driver_id
 * @return array{success:bool, error?:string}
 */
function mall_order_assign_driver($order_id, $driver_id) {
    $conn = mall_get_db_connection();

    $order_stmt = $conn->prepare('SELECT status FROM mall_orders WHERE id = ?');
    $order_stmt->bind_param('i', $order_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();

    if (!$order) {
        return ['success' => false, 'error' => 'NOT_FOUND'];
    }
    if ($order['status'] !== 'ready') {
        return ['success' => false, 'error' => 'INVALID_STATE_TRANSITION'];
    }
    if (mall_delivery_get_active_assignment($order_id)) {
        return ['success' => false, 'error' => 'ALREADY_ASSIGNED'];
    }

    $conn->begin_transaction();
    try {
        $insert = $conn->prepare(
            "INSERT INTO mall_order_driver_assignments (order_id, driver_id, status) VALUES (?, ?, 'assigned')"
        );
        $insert->bind_param('ii', $order_id, $driver_id);
        $insert->execute();
        $insert->close();

        $update = $conn->prepare("UPDATE mall_orders SET status = 'assigned', current_driver_id = ? WHERE id = ?");
        $update->bind_param('ii', $driver_id, $order_id);
        $update->execute();
        $update->close();

        $conn->commit();
        return ['success' => true];
    } catch (Exception $e) {
        $conn->rollback();
        error_log('mall_order_assign_driver error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 배송실패(delivery_failed) 주문을 다른 기사에게 재배정합니다.
 * 기존 실패 배정 행은 'reassigned'로 종료 처리하고 새 배정 행을 만든다(이력 보존).
 * @param int $order_id
 * @param int $new_driver_id
 * @return array{success:bool, error?:string}
 */
function mall_order_reassign_driver($order_id, $new_driver_id) {
    $conn = mall_get_db_connection();

    $order_stmt = $conn->prepare('SELECT status FROM mall_orders WHERE id = ?');
    $order_stmt->bind_param('i', $order_id);
    $order_stmt->execute();
    $order = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();

    if (!$order) {
        return ['success' => false, 'error' => 'NOT_FOUND'];
    }
    if ($order['status'] !== 'delivery_failed') {
        return ['success' => false, 'error' => 'INVALID_STATE_TRANSITION'];
    }

    $conn->begin_transaction();
    try {
        // UPDATE에는 ORDER BY/LIMIT을 직접 걸 수 없어 대상 id를 먼저 조회한다.
        $find = $conn->prepare(
            "SELECT id FROM mall_order_driver_assignments WHERE order_id = ? AND status = 'failed' ORDER BY id DESC LIMIT 1"
        );
        $find->bind_param('i', $order_id);
        $find->execute();
        $failed_row = $find->get_result()->fetch_assoc();
        $find->close();

        if ($failed_row) {
            $close_failed = $conn->prepare("UPDATE mall_order_driver_assignments SET status = 'reassigned' WHERE id = ?");
            $close_failed->bind_param('i', $failed_row['id']);
            $close_failed->execute();
            $close_failed->close();
        }

        $insert = $conn->prepare(
            "INSERT INTO mall_order_driver_assignments (order_id, driver_id, status) VALUES (?, ?, 'assigned')"
        );
        $insert->bind_param('ii', $order_id, $new_driver_id);
        $insert->execute();
        $insert->close();

        $update = $conn->prepare("UPDATE mall_orders SET status = 'assigned', current_driver_id = ? WHERE id = ?");
        $update->bind_param('ii', $new_driver_id, $order_id);
        $update->execute();
        $update->close();

        $conn->commit();
        return ['success' => true];
    } catch (Exception $e) {
        $conn->rollback();
        error_log('mall_order_reassign_driver error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 배정 행이 특정 기사 소유이고 기대한 상태인지 검증합니다(IDOR + 상태전이 방어 공용 헬퍼).
 * @param int $order_id
 * @param int $driver_id
 * @param string $expected_status
 * @return array{ok:bool, error?:string, assignment?:array}
 */
function mall_delivery_verify_assignment($order_id, $driver_id, $expected_status) {
    $assignment = mall_delivery_get_active_assignment($order_id);
    if (!$assignment || (int)$assignment['driver_id'] !== (int)$driver_id) {
        return ['ok' => false, 'error' => 'NOT_ASSIGNED_TO_YOU'];
    }
    if ($assignment['status'] !== $expected_status) {
        return ['ok' => false, 'error' => 'INVALID_STATE_TRANSITION'];
    }
    return ['ok' => true, 'assignment' => $assignment];
}

/**
 * 기사가 배송을 시작합니다(assigned → delivering).
 * @param int $driver_id
 * @param int $order_id
 * @return array{success:bool, error?:string}
 */
function mall_delivery_start($driver_id, $order_id) {
    $check = mall_delivery_verify_assignment($order_id, $driver_id, 'assigned');
    if (!$check['ok']) {
        return ['success' => false, 'error' => $check['error']];
    }

    $conn = mall_get_db_connection();
    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE mall_order_driver_assignments SET status = 'delivering', delivering_at = NOW() WHERE id = ?");
        $update->bind_param('i', $check['assignment']['id']);
        $update->execute();
        $update->close();

        $order_update = $conn->prepare("UPDATE mall_orders SET status = 'delivering' WHERE id = ?");
        $order_update->bind_param('i', $order_id);
        $order_update->execute();
        $order_update->close();

        $conn->commit();
        return ['success' => true];
    } catch (Exception $e) {
        $conn->rollback();
        error_log('mall_delivery_start error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 배송 중 기사 위치를 기록합니다(delivering 상태에서만 허용).
 * @param int $driver_id
 * @param int $order_id
 * @param float $lat
 * @param float $lng
 * @return array{success:bool, error?:string}
 */
function mall_delivery_record_location($driver_id, $order_id, $lat, $lng) {
    $check = mall_delivery_verify_assignment($order_id, $driver_id, 'delivering');
    if (!$check['ok']) {
        return ['success' => false, 'error' => $check['error']];
    }

    $conn = mall_get_db_connection();
    try {
        $insert = $conn->prepare(
            'INSERT INTO mall_driver_locations (driver_id, order_id, lat, lng) VALUES (?, ?, ?, ?)'
        );
        $insert->bind_param('iidd', $driver_id, $order_id, $lat, $lng);
        $insert->execute();
        $insert->close();

        $update = $conn->prepare('UPDATE mall_drivers SET last_lat = ?, last_lng = ?, last_seen_at = NOW() WHERE id = ?');
        $update->bind_param('ddi', $lat, $lng, $driver_id);
        $update->execute();
        $update->close();

        return ['success' => true];
    } catch (Exception $e) {
        error_log('mall_delivery_record_location error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 기사가 도착을 알립니다(delivering → arrived).
 * @param int $driver_id
 * @param int $order_id
 * @return array{success:bool, error?:string}
 */
function mall_delivery_mark_arrived($driver_id, $order_id) {
    $check = mall_delivery_verify_assignment($order_id, $driver_id, 'delivering');
    if (!$check['ok']) {
        return ['success' => false, 'error' => $check['error']];
    }

    $conn = mall_get_db_connection();
    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE mall_order_driver_assignments SET status = 'arrived', arrived_at = NOW() WHERE id = ?");
        $update->bind_param('i', $check['assignment']['id']);
        $update->execute();
        $update->close();

        $order_update = $conn->prepare("UPDATE mall_orders SET status = 'arrived' WHERE id = ?");
        $order_update->bind_param('i', $order_id);
        $order_update->execute();
        $order_update->close();

        $conn->commit();
        return ['success' => true];
    } catch (Exception $e) {
        $conn->rollback();
        error_log('mall_delivery_mark_arrived error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 기사가 배송완료를 처리합니다(arrived → completed).
 * @param int $driver_id
 * @param int $order_id
 * @return array{success:bool, error?:string}
 */
function mall_delivery_complete($driver_id, $order_id) {
    $check = mall_delivery_verify_assignment($order_id, $driver_id, 'arrived');
    if (!$check['ok']) {
        return ['success' => false, 'error' => $check['error']];
    }

    $conn = mall_get_db_connection();
    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE mall_order_driver_assignments SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $update->bind_param('i', $check['assignment']['id']);
        $update->execute();
        $update->close();

        $order_update = $conn->prepare("UPDATE mall_orders SET status = 'completed' WHERE id = ?");
        $order_update->bind_param('i', $order_id);
        $order_update->execute();
        $order_update->close();

        $conn->commit();
        return ['success' => true];
    } catch (Exception $e) {
        $conn->rollback();
        error_log('mall_delivery_complete error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 기사가 배송실패를 보고합니다(assigned 또는 delivering → delivery_failed).
 * @param int $driver_id
 * @param int $order_id
 * @param string $reason
 * @return array{success:bool, error?:string}
 */
function mall_delivery_fail($driver_id, $order_id, $reason) {
    $assignment = mall_delivery_get_active_assignment($order_id);
    if (!$assignment || (int)$assignment['driver_id'] !== (int)$driver_id) {
        return ['success' => false, 'error' => 'NOT_ASSIGNED_TO_YOU'];
    }
    if (!in_array($assignment['status'], ['assigned', 'delivering'], true)) {
        return ['success' => false, 'error' => 'INVALID_STATE_TRANSITION'];
    }

    $conn = mall_get_db_connection();
    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE mall_order_driver_assignments SET status = 'failed', failed_reason = ? WHERE id = ?");
        $update->bind_param('si', $reason, $assignment['id']);
        $update->execute();
        $update->close();

        $order_update = $conn->prepare("UPDATE mall_orders SET status = 'delivery_failed' WHERE id = ?");
        $order_update->bind_param('i', $order_id);
        $order_update->execute();
        $order_update->close();

        $conn->commit();
        return ['success' => true];
    } catch (Exception $e) {
        $conn->rollback();
        error_log('mall_delivery_fail error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 특정 기사에게 현재 배정된(종료되지 않은) 주문 목록을 조회합니다(기사 앱 배정 목록 화면용).
 * @param int $driver_id
 * @return array<int, array>
 */
function mall_driver_get_assigned_orders($driver_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        "SELECT o.id AS order_id, o.order_number, o.status, o.total_amount, o.shipping_fee, o.member_id,
                o.ship_detail_address, o.ship_barangay, o.ship_city, o.ship_region, o.ship_landmark,
                a.id AS assignment_id, a.assigned_at,
                m.name AS member_name
         FROM mall_order_driver_assignments a
         INNER JOIN mall_orders o ON o.id = a.order_id
         INNER JOIN mall_members m ON m.id = o.member_id
         WHERE a.driver_id = ? AND a.status IN ('assigned', 'delivering', 'arrived')
         ORDER BY a.assigned_at ASC"
    );
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * 특정 기사가 과거에 완료/실패 처리한 주문 목록을 조회합니다(기사 앱 "완료" 배달 목록 탭용).
 * 재배정으로 다른 기사에게 넘어간 배정 이력(status='reassigned')은 본인 몫이 아니므로 제외한다.
 * @param int $driver_id
 * @param int $limit
 * @return array<int, array>
 */
function mall_driver_get_completed_orders($driver_id, $limit = 50) {
    $conn = mall_get_db_connection();
    $limit = (int)$limit;
    $stmt = $conn->prepare(
        "SELECT o.id AS order_id, o.order_number, o.status, o.total_amount, o.shipping_fee, o.member_id,
                o.ship_detail_address, o.ship_barangay, o.ship_city, o.ship_region, o.ship_landmark,
                a.id AS assignment_id, a.status AS assignment_status, a.assigned_at, a.completed_at, a.failed_reason,
                m.name AS member_name
         FROM mall_order_driver_assignments a
         INNER JOIN mall_orders o ON o.id = a.order_id
         INNER JOIN mall_members m ON m.id = o.member_id
         WHERE a.driver_id = ? AND a.status IN ('completed', 'failed')
         ORDER BY COALESCE(a.completed_at, a.assigned_at) DESC
         LIMIT $limit"
    );
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * 배송지 스냅샷(ship_detail_address/ship_barangay/ship_city)에서 기사 앱 표시용 한 줄 주소를 만듭니다.
 * 기사는 주문코드가 아니라 "어디로 가야 하는지"로 배달을 식별하므로, 배달 목록/채팅 목록 모두 이 함수를 쓴다.
 * @param array $o ship_detail_address, ship_barangay, ship_city 키를 포함하는 행(연관 배열)
 * @return string
 */
function mall_driver_address_line($o) {
    $parts = array_filter([$o['ship_detail_address'] ?? null, $o['ship_barangay'] ?? null, $o['ship_city'] ?? null]);
    return $parts ? implode(' ', $parts) : '배송지 정보 없음';
}

/**
 * 기사별 배송 통계(완료 건수, 평균 소요시간)를 조회합니다(관리자 대시보드용).
 * @return array<int, array>
 */
function mall_driver_stats() {
    $conn = mall_get_db_connection();
    $result = $conn->query(
        "SELECT d.id, d.name,
                COUNT(CASE WHEN a.status = 'completed' THEN 1 END) AS completed_count,
                COUNT(CASE WHEN a.status = 'failed' THEN 1 END) AS failed_count,
                AVG(CASE WHEN a.status = 'completed' THEN TIMESTAMPDIFF(MINUTE, a.assigned_at, a.completed_at) END) AS avg_minutes
         FROM mall_drivers d
         LEFT JOIN mall_order_driver_assignments a ON a.driver_id = d.id
         GROUP BY d.id, d.name
         ORDER BY d.name"
    );
    return $result->fetch_all(MYSQLI_ASSOC);
}
