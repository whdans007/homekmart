<?php
/**
 * 재고 원장(inventory_ledger) 저장/조회 헬퍼 (저수준)
 * Design Ref: docs/02-design/features/unified-inventory-reference-store.design.md §2.3, §3.3
 *
 * lib/inventory_service.php의 inventory_apply_delta()가 이 파일의 함수들을 사용해
 * 원장 기록과 유통기한 로트(inventory_expirations) 반영을 담당한다.
 * 이 파일의 함수들은 자체적으로 트랜잭션을 열지 않는다 — 호출측(inventory_service.php)이
 * row lock과 트랜잭션 경계를 책임진다.
 */

if (!function_exists('get_db_connection')) {
    require_once __DIR__ . '/../config/db_config.php';
}

if (!defined('INVENTORY_LEDGER_EVENT_TYPES')) {
    define('INVENTORY_LEDGER_EVENT_TYPES', [
        'PURCHASE_IN',
        'POS_OUT',
        'MALL_OUT',
        'WHOLESALE_OUT',
        'CREDIT_OUT',
        'TRANSFER_OUT',
        'TRANSFER_IN',
        'DISPOSAL_OUT',
        'ADJUSTMENT_IN',
        'ADJUSTMENT_OUT',
        'RETURN_IN',
        'REVERSAL_OUT',
    ]);
}

/**
 * 원본 거래 재시도 시 중복 반영을 알리는 예외. inventory_ledger의 UNIQUE 키
 * (source_type, source_id, store_id, product_id, event_type) 위반 시 발생한다.
 */
class InventoryLedgerDuplicateException extends RuntimeException
{
}

function inventory_ledger_is_valid_event_type(string $event_type): bool
{
    return in_array($event_type, INVENTORY_LEDGER_EVENT_TYPES, true);
}

function inventory_ledger_prepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('DB 오류: ' . $conn->error);
    }
    return $stmt;
}

/**
 * 같은 원본 거래가 이미 원장에 반영되어 있는지 확인한다(Plan §4.4 중복 처리).
 */
function inventory_ledger_exists(mysqli $conn, string $source_type, int $source_id, int $store_id, int $product_id, string $event_type): bool
{
    $stmt = inventory_ledger_prepare(
        $conn,
        'SELECT id FROM inventory_ledger WHERE source_type = ? AND source_id = ? AND store_id = ? AND product_id = ? AND event_type = ? LIMIT 1'
    );
    $stmt->bind_param('siiis', $source_type, $source_id, $store_id, $product_id, $event_type);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

/**
 * 원장 행을 삽입한다. UNIQUE 제약 위반(errno 1062) 시 InventoryLedgerDuplicateException을 던진다.
 *
 * @param mysqli $conn
 * @param array $entry [store_id, product_id, quantity_change, quantity_after, event_type,
 *                       source_type, source_id, reference_history_id, occurred_at, user_id, remarks]
 * @return int 삽입된 행의 id
 */
function inventory_ledger_insert(mysqli $conn, array $entry): int
{
    $stmt = inventory_ledger_prepare(
        $conn,
        'INSERT INTO inventory_ledger
            (store_id, product_id, quantity_change, quantity_after, event_type, source_type, source_id, reference_history_id, occurred_at, user_id, remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $store_id = (int)$entry['store_id'];
    $product_id = (int)$entry['product_id'];
    $quantity_change = (float)$entry['quantity_change'];
    $quantity_after = (float)$entry['quantity_after'];
    $event_type = (string)$entry['event_type'];
    $source_type = (string)$entry['source_type'];
    $source_id = (int)$entry['source_id'];
    $reference_history_id = $entry['reference_history_id'] !== null ? (int)$entry['reference_history_id'] : null;
    $occurred_at = (string)$entry['occurred_at'];
    $user_id = $entry['user_id'] !== null ? (int)$entry['user_id'] : null;
    $remarks = $entry['remarks'] !== null ? (string)$entry['remarks'] : null;

    $stmt->bind_param(
        'iiddssiisis',
        $store_id,
        $product_id,
        $quantity_change,
        $quantity_after,
        $event_type,
        $source_type,
        $source_id,
        $reference_history_id,
        $occurred_at,
        $user_id,
        $remarks
    );

    // PHP 8.1+ 기본 mysqli 리포트 모드(MYSQLI_REPORT_ERROR|STRICT)에서는 execute() 실패 시
    // false를 반환하지 않고 mysqli_sql_exception을 곧바로 던진다 — 두 경우 모두 처리한다.
    try {
        $executed = $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $errno = $e->getCode();
        $error = $e->getMessage();
        $stmt->close();
        if ($errno === 1062) {
            throw new InventoryLedgerDuplicateException('이미 처리된 거래입니다: ' . $error);
        }
        throw new RuntimeException('DB 오류: ' . $error);
    }

    if (!$executed) {
        $errno = $stmt->errno;
        $error = $stmt->error;
        $stmt->close();
        if ($errno === 1062) {
            throw new InventoryLedgerDuplicateException('이미 처리된 거래입니다: ' . $error);
        }
        throw new RuntimeException('DB 오류: ' . $error);
    }

    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

/**
 * 출고(FIFO) — 유통기한이 가장 임박한 로트부터 순서대로 차감한다(Design §3.3).
 * 로트가 부족해도 전체 출고를 막지 않는다 — 모자란 만큼은 가장 오래된(혹은 유일한) 로트를
 * 음수로 반영해 원인 추적이 가능하게 하고, 로트/전체 수량 차이는 대사 화면(후속 작업)에서 다룬다.
 * 해당 상품/점포에 로트가 하나도 없으면 아무 것도 하지 않는다(로트 관리 대상이 아닌 상품).
 *
 * 호출측이 이미 트랜잭션 안에서 inventory row를 잠근 상태여야 한다.
 */
function inventory_ledger_deduct_fifo_lots(mysqli $conn, int $store_id, int $product_id, float $qty_to_deduct): void
{
    if ($qty_to_deduct <= 0) {
        return;
    }

    $lot_stmt = inventory_ledger_prepare(
        $conn,
        'SELECT id, quantity FROM inventory_expirations WHERE store_id = ? AND product_id = ? AND quantity > 0 ORDER BY expiration_date ASC, id ASC FOR UPDATE'
    );
    $lot_stmt->bind_param('ii', $store_id, $product_id);
    $lot_stmt->execute();
    $lots = $lot_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lot_stmt->close();

    if (empty($lots)) {
        return;
    }

    $remaining = $qty_to_deduct;
    foreach ($lots as $lot) {
        if ($remaining <= 0) {
            break;
        }
        $take = min($remaining, (float)$lot['quantity']);
        if ($take <= 0) {
            continue;
        }
        $lot_id = (int)$lot['id'];
        $upd = inventory_ledger_prepare($conn, 'UPDATE inventory_expirations SET quantity = quantity - ? WHERE id = ?');
        $upd->bind_param('di', $take, $lot_id);
        $upd->execute();
        $upd->close();
        $remaining -= $take;
    }

    if ($remaining > 0) {
        // 모든 로트를 소진했는데도 남은 수량 — 가장 임박했던(첫) 로트에 음수로 반영한다.
        $oldest_lot_id = (int)$lots[0]['id'];
        $upd = inventory_ledger_prepare($conn, 'UPDATE inventory_expirations SET quantity = quantity - ? WHERE id = ?');
        $upd->bind_param('di', $remaining, $oldest_lot_id);
        $upd->execute();
        $upd->close();
    }
}

/**
 * PDO 커넥션용 원장 기록 (admin/store_transfers.php 등 기존에 PDO를 쓰는 화면 전용).
 * inventory_apply_delta()와 달리 inventory row lock/수량 갱신은 하지 않는다 — 호출측이
 * 이미 자신의 PDO 트랜잭션 안에서 inventory를 직접 갱신했다는 전제 하에 "원장 기록"만 담당한다
 * (Design §3.1은 mysqli 기준 표준 경로이고, 이 함수는 PDO 화면을 리팩터링하지 않고도 같은
 * inventory_ledger 테이블에 기록을 남기기 위한 보조 경로다).
 *
 * 중복 방지는 DB의 UNIQUE 키(uniq_ledger_source)에 최종적으로 의존한다 — 사전 조회 후 삽입
 * 사이의 경합은 이 키가 막아준다. 호출측은 InventoryLedgerDuplicateException을 잡아 skip 처리한다.
 *
 * @param PDO $conn
 * @param array $entry [store_id, product_id, quantity_change, quantity_after, event_type,
 *                       source_type, source_id, reference_history_id, occurred_at, user_id, remarks]
 * @return int 삽입된 행의 id
 */
function inventory_ledger_insert_pdo(PDO $conn, array $entry): int
{
    $stmt = $conn->prepare(
        'INSERT INTO inventory_ledger
            (store_id, product_id, quantity_change, quantity_after, event_type, source_type, source_id, reference_history_id, occurred_at, user_id, remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    try {
        $stmt->execute([
            (int)$entry['store_id'],
            (int)$entry['product_id'],
            (float)$entry['quantity_change'],
            (float)$entry['quantity_after'],
            (string)$entry['event_type'],
            (string)$entry['source_type'],
            (int)$entry['source_id'],
            $entry['reference_history_id'] !== null ? (int)$entry['reference_history_id'] : null,
            (string)$entry['occurred_at'],
            $entry['user_id'] !== null ? (int)$entry['user_id'] : null,
            $entry['remarks'] !== null ? (string)$entry['remarks'] : null,
        ]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            throw new InventoryLedgerDuplicateException('이미 처리된 거래입니다: ' . $e->getMessage());
        }
        throw new RuntimeException('DB 오류: ' . $e->getMessage());
    }

    return (int)$conn->lastInsertId();
}

/**
 * 같은 원본 거래가 이미 원장에 반영되어 있는지 확인한다(PDO 버전). inventory_ledger_exists()의
 * PDO 대응 함수 — store_transfers.php 등에서 원장 INSERT 전에 미리 걸러 불필요한 예외를 줄인다.
 */
function inventory_ledger_exists_pdo(PDO $conn, string $source_type, int $source_id, int $store_id, int $product_id, string $event_type): bool
{
    $stmt = $conn->prepare(
        'SELECT id FROM inventory_ledger WHERE source_type = ? AND source_id = ? AND store_id = ? AND product_id = ? AND event_type = ? LIMIT 1'
    );
    $stmt->execute([$source_type, $source_id, $store_id, $product_id, $event_type]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * PDO 화면 공용: 호출측이 이미 자신의 PDO 트랜잭션 안에서 inventory를 직접 갱신한 뒤,
 * 그 결과를 inventory_ledger에 기록한다(quantity_after는 여기서 현재값을 다시 조회해 계산).
 * 중복(재시도)이면 조용히 skip 처리한다 — 재고는 이미 정상 처리되어 있으므로 원장 기록만 건너뛴다.
 * store_transfers.php, wholesale_sales.php 등 PDO 기반 화면에서 공통으로 사용한다.
 *
 * @return array ['success'=>bool, 'skipped'=>bool]
 */
function inventory_ledger_record_pdo(PDO $conn, string $source_type, int $source_id, int $store_id, int $product_id, float $quantity_change, string $event_type, ?int $user_id, string $remarks): array
{
    $qty_stmt = $conn->prepare('SELECT quantity FROM inventory WHERE product_id = ? AND store_id = ?');
    $qty_stmt->execute([$product_id, $store_id]);
    $quantity_after = (float)($qty_stmt->fetchColumn() ?: 0);

    try {
        inventory_ledger_insert_pdo($conn, [
            'store_id' => $store_id,
            'product_id' => $product_id,
            'quantity_change' => $quantity_change,
            'quantity_after' => $quantity_after,
            'event_type' => $event_type,
            'source_type' => $source_type,
            'source_id' => $source_id,
            'reference_history_id' => null,
            'occurred_at' => date('Y-m-d H:i:s'),
            'user_id' => $user_id,
            'remarks' => $remarks,
        ]);
        return ['success' => true, 'skipped' => false];
    } catch (InventoryLedgerDuplicateException $e) {
        return ['success' => true, 'skipped' => true];
    }
}

/**
 * PDO 트랜잭션 안에서 inventory 수량 갱신과 원장 기록을 함께 처리한다.
 * 호출자가 이미 inventory를 직접 갱신한 경우에는 inventory_ledger_record_pdo()를 사용한다.
 */
function inventory_apply_delta_pdo(PDO $conn, string $source_type, int $source_id, int $store_id, int $product_id, float $quantity_change, string $event_type, ?int $user_id, string $remarks): array
{
    if (inventory_ledger_exists_pdo($conn, $source_type, $source_id, $store_id, $product_id, $event_type)) {
        return ['success' => true, 'skipped' => true];
    }
    $upsert = $conn->prepare('INSERT INTO inventory (product_id, store_id, quantity) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE quantity = quantity');
    $upsert->execute([$product_id, $store_id]);
    $update = $conn->prepare('UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND store_id = ?');
    $update->execute([$quantity_change, $product_id, $store_id]);
    return inventory_ledger_record_pdo($conn, $source_type, $source_id, $store_id, $product_id, $quantity_change, $event_type, $user_id, $remarks);
}

/**
 * 입고 — 특정 유통기한 로트 수량을 늘린다(없으면 새로 만든다).
 * inventory_expirations.idx_store_product_exp UNIQUE(store_id, product_id, expiration_date)를 이용한
 * upsert이므로 동시 입고에도 안전하다.
 */
function inventory_ledger_increment_lot(mysqli $conn, int $store_id, int $product_id, string $expiration_date, float $qty, ?int $user_id = null): void
{
    if ($qty <= 0) {
        return;
    }

    $stmt = inventory_ledger_prepare(
        $conn,
        'INSERT INTO inventory_expirations (store_id, product_id, expiration_date, quantity, registered_by, registered_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
    );
    $stmt->bind_param('iisdi', $store_id, $product_id, $expiration_date, $qty, $user_id);
    $stmt->execute();
    $stmt->close();
}
