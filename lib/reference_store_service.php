<?php
/**
 * Reference Store 서비스
 * Design Ref: docs/02-design/features/unified-inventory-reference-store.design.md §2.1, §2.2, §5
 *
 * "Reference Store"는 쇼핑몰 가격/재고/품절 자동화가 기준으로 삼는 점포다.
 * - 현재값은 system_settings.mall_reference_store_id 에 캐시된다.
 * - 과거 시점의 기준 점포는 mall_reference_store_history 에서 조회한다(비소급 원칙).
 * - 실제 매입/판매/이동 등 거래는 절대 이 값을 쓰지 않고 항상 거래 자체의 store_id를 쓴다.
 *
 * 이 파일은 mysqli 커넥션을 받는 순수 함수만 제공한다(get_db_connection() 또는
 * mall_get_db_connection() 커넥션 모두 사용 가능).
 */

if (!function_exists('get_db_connection')) {
    require_once __DIR__ . '/../config/db_config.php';
}

if (!defined('REFERENCE_STORE_SETTING_KEY')) {
    define('REFERENCE_STORE_SETTING_KEY', 'mall_reference_store_id');
}

/**
 * mysqli->prepare() 실패를 즉시 예외로 바꾼다(트랜잭션 내부에서 false 무시 방지).
 */
function reference_store_prepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('DB 오류: ' . $conn->error);
    }
    return $stmt;
}

/**
 * history 테이블이 비어 있거나(마이그레이션 전) system_settings에도 값이 없을 때 쓰는 최종 fallback.
 * @return int
 */
function reference_store_default_id(): int
{
    if (!defined('MALL_STORE_ID')) {
        require_once __DIR__ . '/../mall/config/mall_config.php';
    }
    return (int)MALL_STORE_ID;
}

/**
 * 현재 저장된 Reference Store id (system_settings 캐시값). 값이 없으면 기본값으로 대체한다.
 */
function reference_store_get_current(mysqli $conn): int
{
    $key = REFERENCE_STORE_SETTING_KEY;
    $stmt = reference_store_prepare($conn, 'SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && $row['setting_value'] !== null && $row['setting_value'] !== '') {
        return (int)$row['setting_value'];
    }
    return reference_store_default_id();
}

/**
 * 현재 열려 있는(effective_to IS NULL) history 행을 반환한다. 없으면 null.
 */
function reference_store_history_open(mysqli $conn): ?array
{
    $stmt = reference_store_prepare(
        $conn,
        'SELECT id, store_id, effective_from, changed_by FROM mall_reference_store_history WHERE effective_to IS NULL ORDER BY effective_from DESC LIMIT 1'
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * 주어진 시점에 유효했던 Reference Store의 store_id.
 * 과거 원장 조회 등에서 "그 시점 기준으로 어느 점포가 기준이었는지"를 알아낼 때 쓴다.
 * 거래 자체의 store_id를 대체하는 용도가 아니다 — 몰 가격/품절 자동화 조회 전용(Design §5).
 */
function reference_store_at(mysqli $conn, DateTimeInterface $at): int
{
    $at_str = $at->format('Y-m-d H:i:s');
    $stmt = reference_store_prepare(
        $conn,
        'SELECT store_id FROM mall_reference_store_history WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to > ?) ORDER BY effective_from DESC LIMIT 1'
    );
    $stmt->bind_param('ss', $at_str, $at_str);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['store_id'] : reference_store_default_id();
}

/**
 * 주어진 시점에 유효했던 history 행의 id. inventory_ledger.reference_history_id에 참조용으로 남긴다.
 * 행이 없으면(마이그레이션 전/시드 전) null.
 */
function reference_store_history_id_at(mysqli $conn, DateTimeInterface $at): ?int
{
    $at_str = $at->format('Y-m-d H:i:s');
    $stmt = reference_store_prepare(
        $conn,
        'SELECT id FROM mall_reference_store_history WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to > ?) ORDER BY effective_from DESC LIMIT 1'
    );
    $stmt->bind_param('ss', $at_str, $at_str);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['id'] : null;
}

/**
 * Reference Store를 변경한다. 하나의 트랜잭션으로 처리한다(Design §2.1 불변 규칙):
 *  - 열려 있는 history 행을 FOR UPDATE로 잠가 동시 변경을 막는다.
 *  - 이미 같은 점포면 아무 것도 하지 않는다(멱등).
 *  - 기존 열린 행의 effective_to를 지금 시각으로 닫는다.
 *  - 새 행을 effective_from = 지금 시각으로 연다.
 *  - system_settings 캐시값을 갱신한다.
 * 과거 거래의 store_id는 절대 바꾸지 않는다 — 이 함수는 history/캐시만 갱신한다.
 *
 * @param mysqli $conn
 * @param int $new_store_id
 * @param int|null $user_id 변경한 관리자 (없으면 null)
 * @param bool $manage_transaction 기본 true. 호출측이 이미 트랜잭션 중이면 false로 넘긴다.
 * @return array ['success'=>bool, 'error'=>?string, 'changed'=>bool, 'effective_from'=>?string]
 */
function reference_store_set(mysqli $conn, int $new_store_id, ?int $user_id, bool $manage_transaction = true): array
{
    if ($new_store_id <= 0) {
        return ['success' => false, 'error' => '유효하지 않은 점포입니다.', 'changed' => false, 'effective_from' => null];
    }

    $store_check = reference_store_prepare($conn, 'SELECT id FROM stores WHERE id = ?');
    $store_check->bind_param('i', $new_store_id);
    $store_check->execute();
    $store_exists = (bool)$store_check->get_result()->fetch_assoc();
    $store_check->close();
    if (!$store_exists) {
        return ['success' => false, 'error' => '존재하지 않는 점포입니다.', 'changed' => false, 'effective_from' => null];
    }

    if ($manage_transaction) {
        $conn->begin_transaction();
    }

    try {
        $lock_stmt = reference_store_prepare(
            $conn,
            'SELECT id, store_id FROM mall_reference_store_history WHERE effective_to IS NULL ORDER BY effective_from DESC LIMIT 1 FOR UPDATE'
        );
        $lock_stmt->execute();
        $open = $lock_stmt->get_result()->fetch_assoc();
        $lock_stmt->close();

        if ($open && (int)$open['store_id'] === $new_store_id) {
            if ($manage_transaction) {
                $conn->commit();
            }
            return ['success' => true, 'error' => null, 'changed' => false, 'effective_from' => null];
        }

        $now = date('Y-m-d H:i:s');

        if ($open) {
            $close_stmt = reference_store_prepare($conn, 'UPDATE mall_reference_store_history SET effective_to = ? WHERE id = ?');
            $open_id = (int)$open['id'];
            $close_stmt->bind_param('si', $now, $open_id);
            $close_stmt->execute();
            $close_stmt->close();
        }

        $insert_stmt = reference_store_prepare(
            $conn,
            'INSERT INTO mall_reference_store_history (store_id, effective_from, changed_by) VALUES (?, ?, ?)'
        );
        $insert_stmt->bind_param('isi', $new_store_id, $now, $user_id);
        $insert_stmt->execute();
        $insert_stmt->close();

        $key = REFERENCE_STORE_SETTING_KEY;
        $value = (string)$new_store_id;
        $setting_stmt = reference_store_prepare(
            $conn,
            "INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
             VALUES (?, ?, 'number', '쇼핑몰 가격/재고/품절 자동화 기준 점포(Reference Store)')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $setting_stmt->bind_param('ss', $key, $value);
        $setting_stmt->execute();
        $setting_stmt->close();

        if ($manage_transaction) {
            $conn->commit();
        }

        return ['success' => true, 'error' => null, 'changed' => true, 'effective_from' => $now];
    } catch (Throwable $e) {
        if ($manage_transaction) {
            $conn->rollback();
        }
        error_log('reference_store_set error: ' . $e->getMessage());
        if (!$manage_transaction) {
            throw $e;
        }
        return ['success' => false, 'error' => '저장 중 오류가 발생했습니다.', 'changed' => false, 'effective_from' => null];
    }
}
