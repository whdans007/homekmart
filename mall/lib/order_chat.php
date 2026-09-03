<?php
/**
 * 주문별 채팅 유스케이스 (Application Layer)
 * Design Ref: mall-order-chat.design.md §3.3, §2.2 — 발송/조회/읽음처리/안읽음카운트를 이 파일로 단일화
 *
 * is_read_by_member/is_read_by_admin은 "발신자 본인 기준으로는 항상 읽음(1)"으로 저장한다(§3.1) —
 * 안읽음 카운트 쿼리가 sender_type 조건만으로 단순해지고, 자기 메시지가 배지에 잡히는 걸 막는다.
 */
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../../config/db_config.php';

/**
 * 메시지를 발송합니다. 배송기사가 배정된 주문은 같은 대화방에 3자(고객/관리자/기사)로 참여한다.
 * @param int $order_id
 * @param string $sender_type 'member'|'admin'|'driver'
 * @param int $sender_id sender_type에 따라 mall_members.id / users.id / mall_drivers.id
 * @param string $message
 * @return array 삽입된 메시지 행(id, created_at)
 */
function mall_order_chat_send($order_id, $sender_type, $sender_id, $message) {
    $conn = mall_get_db_connection();

    $sender_member_id = $sender_type === 'member' ? $sender_id : null;
    $sender_admin_id = $sender_type === 'admin' ? $sender_id : null;
    $sender_driver_id = $sender_type === 'driver' ? $sender_id : null;
    // 발신자 본인 메시지는 스키마 단에서부터 항상 "읽음"으로 저장한다(다른 두 역할 기준으로는 안읽음).
    $is_read_by_member = $sender_type === 'member' ? 1 : 0;
    $is_read_by_admin = $sender_type === 'admin' ? 1 : 0;
    $is_read_by_driver = $sender_type === 'driver' ? 1 : 0;

    $stmt = $conn->prepare(
        'INSERT INTO mall_order_messages
            (order_id, sender_type, sender_member_id, sender_admin_id, sender_driver_id, message, is_read_by_member, is_read_by_admin, is_read_by_driver)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'isiiisiii',
        $order_id, $sender_type, $sender_member_id, $sender_admin_id, $sender_driver_id, $message,
        $is_read_by_member, $is_read_by_admin, $is_read_by_driver
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    // 누가 보내든(고객/관리자/기사) 새 메시지가 오면 종료된 채팅방도 다시 "진행중"으로 돌아간다.
    mall_order_chat_reopen($order_id);

    $row_stmt = $conn->prepare('SELECT id, created_at FROM mall_order_messages WHERE id = ?');
    $row_stmt->bind_param('i', $id);
    $row_stmt->execute();
    $row = $row_stmt->get_result()->fetch_assoc();
    $row_stmt->close();

    return $row;
}

/**
 * 채팅방을 "완료"로 닫습니다(관리자 전용 액션). 이후 누구든 새 메시지를 보내면 자동으로 재개된다.
 * @param int $order_id
 * @param int $admin_id
 * @return void
 */
function mall_order_chat_close($order_id, $admin_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        'INSERT INTO mall_order_chat_closed (order_id, closed_by_admin_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE closed_at = CURRENT_TIMESTAMP, closed_by_admin_id = VALUES(closed_by_admin_id)'
    );
    $stmt->bind_param('ii', $order_id, $admin_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * 채팅방을 "진행중"으로 되돌립니다(종료 표시 제거).
 * @param int $order_id
 * @return void
 */
function mall_order_chat_reopen($order_id) {
    try {
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare('DELETE FROM mall_order_chat_closed WHERE order_id = ?');
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // mall_order_chat_closed 마이그레이션 미실행 상태에서도 메시지 발송 자체는 막지 않는다.
        error_log('mall_order_chat_reopen error: ' . $e->getMessage());
    }
}

/**
 * 채팅방이 관리자에 의해 종료된 상태인지 확인합니다.
 * @param int $order_id
 * @return bool
 */
function mall_order_chat_is_closed($order_id) {
    try {
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare('SELECT order_id FROM mall_order_chat_closed WHERE order_id = ?');
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (bool)$row;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 주문의 메시지를 id 오름차순으로 조회합니다(폴링 증분 조회 지원).
 * is_read_by_member/is_read_by_admin을 함께 반환해 "읽음" 표시(수신확인)에 쓴다
 * (member↔admin 기준만 사용 — 기사는 배정 시에만 잠깐 참여하므로 수신확인 기준에서는 제외).
 * @param int $order_id
 * @param int $after_id 이 id보다 큰 메시지만 반환(0이면 전체)
 * @return array<int, array>
 */
function mall_order_chat_list($order_id, $after_id = 0) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        'SELECT id, sender_type, message, created_at, is_read_by_member, is_read_by_admin
         FROM mall_order_messages
         WHERE order_id = ? AND id > ?
         ORDER BY id ASC'
    );
    $stmt->bind_param('ii', $order_id, $after_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * reader가 아직 읽지 않은 상대방(본인 이외) 메시지를 읽음 처리합니다.
 * @param int $order_id
 * @param string $reader_type 'member'|'admin'|'driver' — 이 값을 읽는 쪽
 * @return void
 */
function mall_order_chat_mark_read($order_id, $reader_type) {
    $conn = mall_get_db_connection();

    $column_by_reader = [
        'member' => 'is_read_by_member',
        'admin' => 'is_read_by_admin',
        'driver' => 'is_read_by_driver',
    ];
    $column = $column_by_reader[$reader_type] ?? null;
    if ($column === null) {
        return;
    }

    $stmt = $conn->prepare(
        "UPDATE mall_order_messages SET $column = 1 WHERE order_id = ? AND sender_type != ? AND $column = 0"
    );
    $stmt->bind_param('is', $order_id, $reader_type);
    $stmt->execute();
    $stmt->close();
}

/**
 * "내가(sender_type) 보낸 메시지 중 reader_role이 읽은 가장 최근 id"를 반환합니다(수신확인 표시용).
 * 화면에서는 이 값 이하의 id를 가진 내 메시지에 "읽음" 표시를 한다(보통 가장 마지막 메시지에만 노출).
 * @param int $order_id
 * @param string $sender_type 'member'|'admin'|'driver' — 수신확인을 확인하려는 내 발신 타입
 * @param string $reader_role 'member'|'admin'|'driver' — 읽었는지 확인할 상대방
 * @return int 없으면 0
 */
function mall_order_chat_sent_read_upto($order_id, $sender_type, $reader_role) {
    $column_by_reader = [
        'member' => 'is_read_by_member',
        'admin' => 'is_read_by_admin',
        'driver' => 'is_read_by_driver',
    ];
    $column = $column_by_reader[$reader_role] ?? null;
    if ($column === null) {
        return 0;
    }

    try {
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare(
            "SELECT MAX(id) AS max_id FROM mall_order_messages WHERE order_id = ? AND sender_type = ? AND $column = 1"
        );
        $stmt->bind_param('is', $order_id, $sender_type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['max_id'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 회원의 전체 주문을 통틀어 안 읽은 관리자 메시지 수를 반환합니다(하단 탭바 배지용).
 * @param int $member_id
 * @return int
 */
function mall_order_chat_unread_count_for_member($member_id) {
    // bottom_nav.php에서 모든 고객 페이지 로드 시마다 호출되므로, 마이그레이션 미실행 상태에서도
    // 몰 화면 전체가 죽지 않도록 방어한다.
    try {
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt
             FROM mall_order_messages m
             INNER JOIN mall_orders o ON o.id = m.order_id
             WHERE o.member_id = ? AND m.sender_type IN ('admin', 'driver') AND m.is_read_by_member = 0"
        );
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['cnt'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 특정 주문의 안 읽은 고객 메시지 수를 반환합니다(관리자 주문 상세용).
 * @param int $order_id
 * @return int
 */
function mall_order_chat_unread_count_for_admin($order_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM mall_order_messages WHERE order_id = ? AND sender_type IN ('member', 'driver') AND is_read_by_admin = 0"
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['cnt'] ?? 0);
}

/**
 * 여러 주문의 안읽음(고객→관리자) 개수를 한 번에 조회합니다(주문 목록 N+1 방지).
 * @param array<int> $order_ids
 * @return array<int,int> order_id => 안읽음 개수(0건인 주문은 키 자체가 없음)
 */
function mall_order_chat_unread_map_for_admin($order_ids) {
    $order_ids = array_values(array_filter(array_map('intval', $order_ids)));
    if (empty($order_ids)) {
        return [];
    }

    // 마이그레이션(sql/run_add_mall_order_messages_migration.php) 실행 전 배포 창구에서도
    // orders.php 목록 자체가 죽지 않도록 방어한다(이 함수는 페이지 렌더 경로에서 항상 호출됨).
    try {
        $conn = mall_get_db_connection();
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
        $types = str_repeat('i', count($order_ids));

        $stmt = $conn->prepare(
            "SELECT order_id, COUNT(*) AS cnt
             FROM mall_order_messages
             WHERE order_id IN ($placeholders) AND sender_type IN ('member', 'driver') AND is_read_by_admin = 0
             GROUP BY order_id"
        );
        $stmt->bind_param($types, ...$order_ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['order_id']] = (int)$row['cnt'];
    }
    return $map;
}

/**
 * 기사 앱 "채팅" 메뉴용 대화 목록 — 현재 "활성 배정"인 주문만 반환한다(메시지 유무 무관, 먼저 말
 * 걸기 가능). 배송이 완료/실패로 끝나면 그 즉시 채팅 접근이 막혀야 하므로, 과거 배정 이력은
 * 포함하지 않는다(mall_delivery_get_active_assignment()와 동일한 "활성 배정" 기준).
 * @param int $driver_id
 * @return array<int, array> order_id, order_number, member_name, is_active_assignment,
 *                            last_message, last_sender_type, last_message_at, unread_count
 */
function mall_order_chat_driver_conversations($driver_id) {
    try {
        $conn = mall_get_db_connection();
        // 같은 주문에 같은 기사가 재배정 등으로 여러 번 배정 이력이 남을 수 있어, 주문당 가장 최근
        // 배정 1건만 골라낸 뒤(latest_pair) 조인한다 — GROUP BY o.id로 비집계 컬럼을 섞는 걸 피한다.
        $stmt = $conn->prepare(
            "SELECT o.id AS order_id, o.order_number, mem.name AS member_name,
                    o.ship_detail_address, o.ship_barangay, o.ship_city,
                    (la.status IN ('assigned', 'delivering', 'arrived')) AS is_active_assignment,
                    lm.message AS last_message, lm.sender_type AS last_sender_type, lm.created_at AS last_message_at,
                    COALESCE(uc.unread_count, 0) AS unread_count
             FROM (
                 SELECT order_id, MAX(id) AS max_id FROM mall_order_driver_assignments WHERE driver_id = ? GROUP BY order_id
             ) latest_pair
             INNER JOIN mall_order_driver_assignments la ON la.id = latest_pair.max_id
             INNER JOIN mall_orders o ON o.id = la.order_id
             INNER JOIN mall_members mem ON mem.id = o.member_id
             LEFT JOIN (
                 SELECT m.order_id, m.message, m.sender_type, m.created_at
                 FROM mall_order_messages m
                 INNER JOIN (SELECT order_id, MAX(id) AS max_id FROM mall_order_messages GROUP BY order_id) latest
                     ON latest.order_id = m.order_id AND latest.max_id = m.id
             ) lm ON lm.order_id = o.id
             LEFT JOIN (
                 SELECT order_id, COUNT(*) AS unread_count FROM mall_order_messages
                 WHERE sender_type IN ('member', 'admin') AND is_read_by_driver = 0 GROUP BY order_id
             ) uc ON uc.order_id = o.id
             WHERE la.status IN ('assigned', 'delivering', 'arrived')
             ORDER BY (unread_count > 0) DESC, COALESCE(lm.created_at, la.assigned_at) DESC"
        );
        $stmt->bind_param('i', $driver_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 기사에게 현재 활성 배정된 주문만 통틀어 안읽음(고객/관리자→기사) 총합을 반환합니다(기사 앱 메뉴 배지용).
 * 배송이 끝난 주문은 채팅 접근 자체가 막히므로 배지 대상에서도 제외한다.
 * @param int $driver_id
 * @return int
 */
function mall_order_chat_unread_total_for_driver($driver_id) {
    try {
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt
             FROM mall_order_messages m
             WHERE m.sender_type IN ('member', 'admin') AND m.is_read_by_driver = 0
               AND m.order_id IN (
                   SELECT order_id FROM mall_order_driver_assignments
                   WHERE driver_id = ? AND status IN ('assigned', 'delivering', 'arrived')
               )"
        );
        $stmt->bind_param('i', $driver_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['cnt'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 전체 주문을 통틀어 관리자가 안 읽은 고객 메시지 총합을 반환합니다(관리자 상단 메뉴 배지용).
 * @return int
 */
function mall_order_chat_unread_total_for_admin() {
    // sidebar.php에서 모든 관리자 페이지 로드 시마다 호출되므로, 마이그레이션 미실행 상태에서도
    // 관리자 화면 전체가 죽지 않도록 방어한다.
    try {
        $conn = mall_get_db_connection();
        $result = $conn->query(
            "SELECT COUNT(*) AS cnt FROM mall_order_messages WHERE sender_type IN ('member', 'driver') AND is_read_by_admin = 0"
        );
        $row = $result->fetch_assoc();
        return (int)($row['cnt'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 주문이 "진행중"(배송 파이프라인이 아직 끝나지 않음)인지 판단하는 기준.
 * 완료/취소/배송실패는 채팅방이 "완료" 목록으로 넘어가는 종료 상태로 취급한다.
 * @return array<string>
 */
function mall_order_chat_done_statuses() {
    return ['completed', 'cancelled', 'delivery_failed'];
}

/**
 * 관리자 "주문톡 관리" 인박스용 원본 데이터 — 주문자(고객) 단위로 채팅을 관리할 수 있도록,
 * 화면에 보여줘야 할 모든 주문 행을 한 번에 조회한다:
 *   (a) 현재 진행중인 모든 주문(메시지 유무 무관 — 관리자가 먼저 말을 걸 수 있어야 함)
 *   (b) 채팅 이력이 있는 주문(진행중/완료 여부는 mall_order_chat_closed 존재 여부로 결정)
 * 진행중/완료는 더 이상 배송상태가 아니라 관리자의 "채팅종료" 액션(mall_order_chat_close)으로만
 * 결정된다 — 종료된 뒤에도 누구든 새 메시지를 보내면 mall_order_chat_send()가 자동으로 재개시킨다.
 * 페이지(mall/admin/order_chat.php)에서 이 원본을 주문자별로 묶어(진행중/완료 탭) 렌더링한다.
 * @param int $limit
 * @return array<int, array> order_id, order_number, status, order_created_at, member_id, member_name,
 *                            last_message, last_sender_type, last_message_at, unread_count, is_closed
 */
function mall_order_chat_admin_working_orders($limit = 500) {
    try {
        $conn = mall_get_db_connection();
        $limit = (int)$limit;
        $done_statuses = mall_order_chat_done_statuses();
        $done_placeholders = implode(',', array_fill(0, count($done_statuses), '?'));

        $stmt = $conn->prepare(
            "SELECT o.id AS order_id, o.order_number, o.status, o.created_at AS order_created_at,
                    o.ship_detail_address, o.ship_barangay, o.ship_city,
                    o.member_id, mem.name AS member_name,
                    lm.message AS last_message, lm.sender_type AS last_sender_type, lm.created_at AS last_message_at,
                    COALESCE(uc.unread_count, 0) AS unread_count,
                    (cc.order_id IS NOT NULL) AS is_closed
             FROM mall_orders o
             INNER JOIN mall_members mem ON mem.id = o.member_id
             LEFT JOIN (
                 SELECT m.order_id, m.message, m.sender_type, m.created_at
                 FROM mall_order_messages m
                 INNER JOIN (SELECT order_id, MAX(id) AS max_id FROM mall_order_messages GROUP BY order_id) latest
                     ON latest.order_id = m.order_id AND latest.max_id = m.id
             ) lm ON lm.order_id = o.id
             LEFT JOIN (
                 SELECT order_id, COUNT(*) AS unread_count FROM mall_order_messages
                 WHERE sender_type IN ('member', 'driver') AND is_read_by_admin = 0 GROUP BY order_id
             ) uc ON uc.order_id = o.id
             LEFT JOIN mall_order_chat_closed cc ON cc.order_id = o.id
             WHERE o.status NOT IN ($done_placeholders)
                OR lm.message IS NOT NULL
             ORDER BY o.created_at DESC
             LIMIT $limit"
        );
        $stmt->bind_param(str_repeat('s', count($done_statuses)), ...$done_statuses);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 여러 주문의 안읽음(고객/관리자→기사) 개수를 한 번에 조회합니다(기사 배정 목록 N+1 방지).
 * @param array<int> $order_ids
 * @return array<int,int> order_id => 안읽음 개수(0건인 주문은 키 자체가 없음)
 */
function mall_order_chat_unread_map_for_driver($order_ids) {
    $order_ids = array_values(array_filter(array_map('intval', $order_ids)));
    if (empty($order_ids)) {
        return [];
    }

    try {
        $conn = mall_get_db_connection();
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
        $types = str_repeat('i', count($order_ids));

        $stmt = $conn->prepare(
            "SELECT order_id, COUNT(*) AS cnt
             FROM mall_order_messages
             WHERE order_id IN ($placeholders) AND sender_type IN ('member', 'admin') AND is_read_by_driver = 0
             GROUP BY order_id"
        );
        $stmt->bind_param($types, ...$order_ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['order_id']] = (int)$row['cnt'];
    }
    return $map;
}
