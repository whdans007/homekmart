<?php
/**
 * Expiry Helper - 유통기한 점검/폐기 관리 공유 로직
 * Design Ref: docs/02-design/features/expiry-management.design.md §4.3, §9
 *
 * mysqli 커넥션(get_db_connection())을 사용하는 admin/expiry_*.php,
 * admin/partials/header.php에서 공통으로 호출한다.
 */

if (!function_exists('get_db_connection')) {
    require_once __DIR__ . '/../config/db_config.php';
}

/**
 * mysqli prepare() 실패(테이블/컬럼 누락, SQL 오류 등)를 즉시 Exception으로 변환한다.
 * prepare()가 false를 반환한 상태에서 bind_param()을 호출하면 처리되지 않는
 * 치명적 오류(Error)로 죽어버리므로, 트랜잭션 내부에서는 항상 이 헬퍼를 사용한다.
 *
 * @param mysqli $conn
 * @param string $sql
 * @return mysqli_stmt
 */
function expiry_prepare($conn, $sql) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('DB 오류: ' . $conn->error);
    }
    return $stmt;
}

/**
 * 유통기한 임계값 설정 조회 (expiry_settings, 단일 행 id=1)
 * 행이 없거나 조회 실패 시 기본값(관찰 60일/알림 30일)으로 대체
 *
 * @param mysqli $conn
 * @return array ['warning_days' => int, 'alert_days' => int]
 */
function get_expiry_settings($conn) {
    $defaults = ['warning_days' => 60, 'alert_days' => 30];

    $result = $conn->query("SELECT warning_days, alert_days FROM expiry_settings WHERE id = 1 LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        return [
            'warning_days' => (int)$row['warning_days'],
            'alert_days' => (int)$row['alert_days'],
        ];
    }

    return $defaults;
}

/**
 * 유통기한까지 남은 일수를 임계값 기준으로 분류
 *
 * @param string $expiration_date 'Y-m-d'
 * @param array $settings get_expiry_settings() 반환값
 * @return string 'expired' | 'alert' | 'warning' | 'normal'
 */
function get_expiry_status($expiration_date, $settings) {
    $today = new DateTime(date('Y-m-d'));
    $target = new DateTime($expiration_date);
    $remaining_days = (int)$today->diff($target)->format('%r%a');

    if ($remaining_days < 0) {
        return 'expired';
    }
    if ($remaining_days <= $settings['alert_days']) {
        return 'alert';
    }
    if ($remaining_days <= $settings['warning_days']) {
        return 'warning';
    }
    return 'normal';
}

/**
 * 점포 기준, 알림 임계값(alert_days) 이내이며 수량이 남아있는 로트 건수
 * (점검기록 메뉴 배지에 사용)
 *
 * @param mysqli $conn
 * @param int $store_id
 * @return int
 */
function get_expiry_alert_count($conn, $store_id) {
    $settings = get_expiry_settings($conn);

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM inventory_expirations
        WHERE store_id = ?
          AND quantity > 0
          AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
    ");
    $stmt->bind_param("ii", $store_id, $settings['alert_days']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['cnt'] ?? 0);
}

/**
 * 폐기 등록: 선택한 유통기한 로트에서 수량을 차감하고, 전체 재고(inventory)를
 * 동기화한 뒤 폐기 이력(product_disposals)을 남긴다. 전체를 하나의 트랜잭션으로 처리한다.
 * Plan SC: 폐기 등록 시 해당 로트와 전체 재고 수량이 자동으로 줄어든다
 *
 * @param mysqli $conn
 * @param array $params [
 *   'store_id' => int, 'product_id' => int, 'inventory_expiration_id' => int,
 *   'quantity' => int, 'reason' => string, 'reason_note' => ?string, 'user_id' => int
 * ]
 * @return array ['success' => bool, 'error' => ?string]
 */
function register_disposal($conn, array $params) {
    $store_id = (int)$params['store_id'];
    $product_id = (int)$params['product_id'];
    $lot_id = (int)$params['inventory_expiration_id'];
    $quantity = (int)$params['quantity'];
    $reason = $params['reason'];
    $reason_note = $params['reason_note'] ?? null;
    $user_id = (int)($params['user_id'] ?? 0);

    if ($quantity <= 0) {
        return ['success' => false, 'error' => '폐기 수량은 1 이상이어야 합니다.'];
    }
    if (!in_array($reason, ['expired', 'damaged', 'other'], true)) {
        return ['success' => false, 'error' => '유효하지 않은 폐기 사유입니다.'];
    }

    $conn->begin_transaction();
    try {
        // 로트가 실제로 해당 store_id/product_id 소속인지 재검증 (클라이언트 값 신뢰 금지)
        $lot_stmt = expiry_prepare($conn, "
            SELECT quantity, expiration_date FROM inventory_expirations
            WHERE id = ? AND store_id = ? AND product_id = ?
            FOR UPDATE
        ");
        $lot_stmt->bind_param("iii", $lot_id, $store_id, $product_id);
        $lot_stmt->execute();
        $lot = $lot_stmt->get_result()->fetch_assoc();
        $lot_stmt->close();

        if (!$lot) {
            throw new Exception('선택한 유통기한 정보를 다시 확인해주세요.');
        }
        if ($quantity > (int)$lot['quantity']) {
            throw new Exception('재고 수량을 초과했습니다. (남은 수량: ' . $lot['quantity'] . ')');
        }

        // 폐기 시점 원가 스냅샷
        $cost_stmt = expiry_prepare($conn, "SELECT cost_price FROM inventory WHERE product_id = ? AND store_id = ? LIMIT 1");
        $cost_stmt->bind_param("ii", $product_id, $store_id);
        $cost_stmt->execute();
        $cost_row = $cost_stmt->get_result()->fetch_assoc();
        $cost_stmt->close();
        $unit_cost = $cost_row ? (float)$cost_row['cost_price'] : 0;

        // 로트 수량 차감
        $update_lot = expiry_prepare($conn, "UPDATE inventory_expirations SET quantity = quantity - ? WHERE id = ?");
        $update_lot->bind_param("ii", $quantity, $lot_id);
        $update_lot->execute();
        $update_lot->close();

        // 전체 재고 동기화
        $update_inv = expiry_prepare($conn, "UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND store_id = ?");
        $update_inv->bind_param("iii", $quantity, $product_id, $store_id);
        $update_inv->execute();
        $update_inv->close();

        // 이력 저장
        $insert_stmt = expiry_prepare($conn, "
            INSERT INTO product_disposals
                (store_id, product_id, inventory_expiration_id, expiration_date, quantity, unit_cost, reason, reason_note, disposed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert_stmt->bind_param(
            "iiisidssi",
            $store_id, $product_id, $lot_id, $lot['expiration_date'], $quantity, $unit_cost, $reason, $reason_note, $user_id
        );
        $insert_stmt->execute();
        $insert_stmt->close();

        $conn->commit();
        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 폐기 이력 수정: 수량/사유/사유상세를 변경하고, 수량 변동분(delta)만큼
 * 원본 로트(inventory_expirations)와 전체 재고(inventory)를 함께 보정한다.
 * 상품/로트/유통기한 자체는 변경할 수 없다(등록 시점 데이터로 고정).
 *
 * @param mysqli $conn
 * @param array $params ['disposal_id' => int, 'store_id' => int, 'quantity' => int, 'reason' => string, 'reason_note' => ?string]
 * @return array ['success' => bool, 'error' => ?string]
 */
function update_disposal($conn, array $params) {
    $store_id = (int)$params['store_id'];
    $disposal_id = (int)$params['disposal_id'];
    $new_quantity = (int)$params['quantity'];
    $reason = $params['reason'];
    $reason_note = $params['reason_note'] ?? null;

    if ($new_quantity <= 0) {
        return ['success' => false, 'error' => '폐기 수량은 1 이상이어야 합니다.'];
    }
    if (!in_array($reason, ['expired', 'damaged', 'other'], true)) {
        return ['success' => false, 'error' => '유효하지 않은 폐기 사유입니다.'];
    }

    $conn->begin_transaction();
    try {
        $stmt = expiry_prepare($conn, "
            SELECT product_id, inventory_expiration_id, quantity FROM product_disposals
            WHERE id = ? AND store_id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $disposal_id, $store_id);
        $stmt->execute();
        $disposal = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$disposal) {
            throw new Exception('폐기 이력을 찾을 수 없습니다.');
        }

        $product_id = (int)$disposal['product_id'];
        $lot_id = (int)$disposal['inventory_expiration_id'];
        $delta = $new_quantity - (int)$disposal['quantity']; // 양수: 추가 차감 필요, 음수: 재고 반환

        if ($delta !== 0 && $lot_id) {
            $lot_stmt = expiry_prepare($conn, "SELECT quantity FROM inventory_expirations WHERE id = ? AND store_id = ? AND product_id = ? FOR UPDATE");
            $lot_stmt->bind_param("iii", $lot_id, $store_id, $product_id);
            $lot_stmt->execute();
            $lot = $lot_stmt->get_result()->fetch_assoc();
            $lot_stmt->close();

            if ($lot) {
                if ($delta > (int)$lot['quantity']) {
                    throw new Exception('로트 잔여 수량을 초과하여 수정할 수 없습니다. (남은 수량: ' . $lot['quantity'] . ')');
                }
                $upd_lot = expiry_prepare($conn, "UPDATE inventory_expirations SET quantity = quantity - ? WHERE id = ?");
                $upd_lot->bind_param("ii", $delta, $lot_id);
                $upd_lot->execute();
                $upd_lot->close();
            }
        }

        if ($delta !== 0) {
            $upd_inv = expiry_prepare($conn, "UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND store_id = ?");
            $upd_inv->bind_param("iii", $delta, $product_id, $store_id);
            $upd_inv->execute();
            $upd_inv->close();
        }

        $upd_stmt = expiry_prepare($conn, "
            UPDATE product_disposals SET quantity = ?, reason = ?, reason_note = ?
            WHERE id = ? AND store_id = ?
        ");
        $upd_stmt->bind_param("issii", $new_quantity, $reason, $reason_note, $disposal_id, $store_id);
        $upd_stmt->execute();
        $upd_stmt->close();

        $conn->commit();
        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 폐기 이력 삭제: 등록을 취소하고, 차감했던 수량을 원본 로트(있으면)와
 * 전체 재고(inventory)에 되돌린 뒤 이력 행을 삭제한다.
 *
 * @param mysqli $conn
 * @param array $params ['disposal_id' => int, 'store_id' => int]
 * @return array ['success' => bool, 'error' => ?string]
 */
function delete_disposal($conn, array $params) {
    $store_id = (int)$params['store_id'];
    $disposal_id = (int)$params['disposal_id'];

    $conn->begin_transaction();
    try {
        $stmt = expiry_prepare($conn, "
            SELECT product_id, inventory_expiration_id, quantity FROM product_disposals
            WHERE id = ? AND store_id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $disposal_id, $store_id);
        $stmt->execute();
        $disposal = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$disposal) {
            throw new Exception('폐기 이력을 찾을 수 없습니다.');
        }

        $product_id = (int)$disposal['product_id'];
        $lot_id = (int)$disposal['inventory_expiration_id'];
        $quantity = (int)$disposal['quantity'];

        if ($lot_id) {
            $lot_stmt = expiry_prepare($conn, "SELECT id FROM inventory_expirations WHERE id = ? AND store_id = ? AND product_id = ?");
            $lot_stmt->bind_param("iii", $lot_id, $store_id, $product_id);
            $lot_stmt->execute();
            $lot_exists = $lot_stmt->get_result()->num_rows > 0;
            $lot_stmt->close();

            if ($lot_exists) {
                $upd_lot = expiry_prepare($conn, "UPDATE inventory_expirations SET quantity = quantity + ? WHERE id = ?");
                $upd_lot->bind_param("ii", $quantity, $lot_id);
                $upd_lot->execute();
                $upd_lot->close();
            }
        }

        $upd_inv = expiry_prepare($conn, "UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND store_id = ?");
        $upd_inv->bind_param("iii", $quantity, $product_id, $store_id);
        $upd_inv->execute();
        $upd_inv->close();

        $del_stmt = expiry_prepare($conn, "DELETE FROM product_disposals WHERE id = ? AND store_id = ?");
        $del_stmt->bind_param("ii", $disposal_id, $store_id);
        $del_stmt->execute();
        $del_stmt->close();

        $conn->commit();
        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 유통기한 임계값 설정 저장 (expiry_settings, 단일 행 id=1)
 *
 * @param mysqli $conn
 * @param int $warning_days
 * @param int $alert_days
 * @param int $user_id
 * @return array ['success' => bool, 'error' => ?string]
 */
function save_expiry_settings($conn, $warning_days, $alert_days, $user_id) {
    $warning_days = (int)$warning_days;
    $alert_days = (int)$alert_days;

    if ($warning_days <= 0 || $alert_days <= 0) {
        return ['success' => false, 'error' => '일수는 1 이상이어야 합니다.'];
    }
    if ($alert_days > $warning_days) {
        return ['success' => false, 'error' => '알림 일수는 관찰 일수보다 클 수 없습니다.'];
    }

    $stmt = $conn->prepare("
        INSERT INTO expiry_settings (id, warning_days, alert_days, updated_by)
        VALUES (1, ?, ?, ?)
        ON DUPLICATE KEY UPDATE warning_days = VALUES(warning_days), alert_days = VALUES(alert_days), updated_by = VALUES(updated_by)
    ");
    $stmt->bind_param("iii", $warning_days, $alert_days, $user_id);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? ['success' => true, 'error' => null] : ['success' => false, 'error' => $conn->error];
}
