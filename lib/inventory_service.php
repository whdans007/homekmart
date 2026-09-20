<?php
/**
 * 통합 재고 서비스 (고수준)
 * Design Ref: docs/02-design/features/unified-inventory-reference-store.design.md §3
 *
 * 실제 점포 재고 기준은 inventory(product_id, store_id)다(Design §1 원칙 1).
 * 모든 증감은 이 파일의 inventory_apply_delta()를 거쳐 inventory 갱신 + inventory_ledger 기록을
 * 하나의 트랜잭션으로 처리한다. 음수 재고를 정상적으로 허용하고 0으로 보정하지 않는다(Plan §4.2).
 *
 * 이 파일은 거래 화면(매입확정/POS/몰/이동/도매/크레딧/폐기)에 아직 연동되지 않았다 —
 * Plan §6 Phase 3(거래 연동)은 후속 작업이며, 이번 변경은 Phase 1~2(서비스 기반)까지다.
 */

require_once __DIR__ . '/inventory_ledger.php';
require_once __DIR__ . '/reference_store_service.php';

/**
 * 같은 엔티티(매입 품목, 도매/크레딧 판매 등)가 여러 번 수정될 수 있는 화면에서, 수정마다
 * 서로 다른 source_id를 부여해 inventory_ledger의 중복 방지 키가 "최초 1회"가 아니라
 * "이 수정 1회"를 기준으로 동작하게 한다(Plan §4.4는 "재시도" 중복만 막는 것이지, 같은
 * 항목에 대한 서로 다른 정상 수정을 막는 것이 아니다).
 *
 * entity_id를 상위 비트로 두고 현재 시각(초)을 하위 비트로 결합한다 — 같은 초 안에 반복된
 * 완전히 동일한 재시도(더블클릭 등)는 여전히 같은 키로 걸러지고, 사람이 실제로 다시 수정하는
 * 정상적인 경우(보통 초 단위 이상 간격)는 새 키를 받는다. entity_id는 나눗셈으로 복원 가능해
 * 원장 조회 화면에서 원본 레코드를 역추적할 수 있다.
 */
function inventory_ledger_scoped_source_id(int $entity_id, int $bucket = 1000000): int
{
    return $entity_id * $bucket + (time() % $bucket);
}

function inventory_service_prepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('DB 오류: ' . $conn->error);
    }
    return $stmt;
}

/**
 * 현재 inventory.quantity를 반환한다(행이 없으면 0).
 */
function inventory_get_quantity(mysqli $conn, int $store_id, int $product_id): float
{
    $stmt = inventory_service_prepare($conn, 'SELECT quantity FROM inventory WHERE store_id = ? AND product_id = ?');
    $stmt->bind_param('ii', $store_id, $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['quantity'] : 0.0;
}

/**
 * 박스 수량 → 낱개 수량 환산 (products.pieces_per_box 기준). Plan §4.2.
 * pieces_per_box가 없거나 0 이하이면 1로 취급한다(박스=낱개 상품).
 */
function inventory_convert_box_to_pieces(mysqli $conn, int $product_id, float $box_quantity): float
{
    $stmt = inventory_service_prepare($conn, 'SELECT pieces_per_box FROM products WHERE id = ?');
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $pieces_per_box = $row ? (int)($row['pieces_per_box'] ?? 1) : 1;
    if ($pieces_per_box <= 0) {
        $pieces_per_box = 1;
    }

    return round($box_quantity * $pieces_per_box, 2);
}

/**
 * 실사 조정값 계산 (Design §3.2): adjustment = physical_quantity - book_quantity.
 * 차이가 없으면 event_type은 null(적용할 필요 없음을 의미).
 *
 * @return array ['delta'=>float, 'event_type'=>?string]
 */
function inventory_calculate_adjustment(float $physical_quantity, float $book_quantity): array
{
    $delta = round($physical_quantity - $book_quantity, 2);
    if (abs($delta) < 0.01) {
        return ['delta' => 0.0, 'event_type' => null];
    }
    return ['delta' => $delta, 'event_type' => $delta > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT'];
}

/**
 * 재고 증감을 원장에 기록하고 inventory를 갱신한다(Design §3.1).
 *
 * 동작 순서:
 *  1. event_type/필수값 검증
 *  2. 같은 원본 거래가 이미 반영되어 있으면 아무 것도 하지 않고 skipped=true 반환(멱등)
 *  3. inventory 행을 FOR UPDATE로 잠근다(없으면 0으로 생성 후 다시 잠금)
 *  4. quantity_after = quantity_before + quantity_change (음수 허용, 0으로 보정하지 않음)
 *  5. inventory.quantity 갱신
 *  6. inventory_ledger에 기록 (UNIQUE 키 위반 시 경합으로 판단해 이번 변경만 되돌리고 skipped 처리)
 *  7. lot 옵션이 있으면 유통기한 로트도 함께 반영
 *
 * @param mysqli $conn
 * @param array $args [
 *   'store_id' => int, 'product_id' => int, 'quantity_change' => float (필수, 0 불가, 음수 허용),
 *   'event_type' => string (INVENTORY_LEDGER_EVENT_TYPES 중 하나),
 *   'source_type' => string, 'source_id' => int (둘 다 필수 — 중복 방지 키),
 *   'user_id' => ?int, 'remarks' => ?string,
 *   'occurred_at' => ?string ('Y-m-d H:i:s', 기본 지금),
 *   'reference_history_id' => ?int (생략 시 occurred_at 기준으로 자동 조회),
 *   'lot' => ?array ['mode' => 'fifo_deduct'] 또는 ['mode' => 'increment', 'expiration_date' => 'Y-m-d'],
 *   'manage_transaction' => bool (기본 true. 호출측이 이미 트랜잭션 중이면 false로 넘기고,
 *                                  실패 시 이 함수가 던지는 예외를 잡아 호출측 트랜잭션을 롤백해야 한다),
 * ]
 * @return array ['success'=>bool, 'skipped'=>bool, 'quantity_before'=>?float, 'quantity_after'=>?float,
 *                'ledger_id'=>?int, 'error'=>?string]
 */
function inventory_apply_delta(mysqli $conn, array $args): array
{
    $store_id = (int)($args['store_id'] ?? 0);
    $product_id = (int)($args['product_id'] ?? 0);
    $quantity_change = isset($args['quantity_change']) ? (float)$args['quantity_change'] : 0.0;
    $event_type = (string)($args['event_type'] ?? '');
    $source_type = (string)($args['source_type'] ?? '');
    $source_id = (int)($args['source_id'] ?? 0);
    $user_id = isset($args['user_id']) && $args['user_id'] !== null ? (int)$args['user_id'] : null;
    $remarks = isset($args['remarks']) ? (string)$args['remarks'] : null;
    $occurred_at = $args['occurred_at'] ?? date('Y-m-d H:i:s');
    $lot = $args['lot'] ?? null;
    $manage_transaction = $args['manage_transaction'] ?? true;

    $fail = static function (string $message): array {
        return ['success' => false, 'skipped' => false, 'quantity_before' => null, 'quantity_after' => null, 'ledger_id' => null, 'error' => $message];
    };

    if ($store_id <= 0 || $product_id <= 0) {
        return $fail('점포/상품 정보가 올바르지 않습니다.');
    }
    if (!inventory_ledger_is_valid_event_type($event_type)) {
        return $fail('유효하지 않은 이벤트 유형입니다: ' . $event_type);
    }
    if ($source_type === '' || $source_id <= 0) {
        return $fail('원본 거래 정보(source_type/source_id)가 필요합니다.');
    }
    if (abs($quantity_change) < 0.01) {
        return $fail('수량 변화가 0입니다.');
    }

    // 중복 방지: 같은 원본 거래가 이미 반영되어 있으면 재적용하지 않는다(Plan §4.4).
    if (inventory_ledger_exists($conn, $source_type, $source_id, $store_id, $product_id, $event_type)) {
        return [
            'success' => true,
            'skipped' => true,
            'quantity_before' => null,
            'quantity_after' => inventory_get_quantity($conn, $store_id, $product_id),
            'ledger_id' => null,
            'error' => null,
        ];
    }

    $savepoint = null;
    if ($manage_transaction) {
        $conn->begin_transaction();
    } else {
        // 호출측이 이미 더 큰 트랜잭션 안에 있을 수 있으므로, 이 함수만의 실패를 국소적으로
        // 되돌릴 수 있도록 SAVEPOINT를 쓴다(다른 이미 반영된 작업까지 날리지 않기 위함).
        $savepoint = 'sp_inv_' . bin2hex(random_bytes(4));
        $conn->query("SAVEPOINT `{$savepoint}`");
    }

    try {
        $lock_stmt = inventory_service_prepare($conn, 'SELECT quantity FROM inventory WHERE store_id = ? AND product_id = ? FOR UPDATE');
        $lock_stmt->bind_param('ii', $store_id, $product_id);
        $lock_stmt->execute();
        $row = $lock_stmt->get_result()->fetch_assoc();
        $lock_stmt->close();

        if ($row) {
            $quantity_before = (float)$row['quantity'];
        } else {
            $insert_stmt = inventory_service_prepare($conn, 'INSERT INTO inventory (product_id, store_id, quantity) VALUES (?, ?, 0)');
            $insert_stmt->bind_param('ii', $product_id, $store_id);
            $insert_stmt->execute();
            $insert_stmt->close();
            $quantity_before = 0.0;

            // 방금 만든 행을 다시 잠근다(동시 요청이 같은 행을 새로 만들려던 경우를 대비).
            $relock_stmt = inventory_service_prepare($conn, 'SELECT quantity FROM inventory WHERE store_id = ? AND product_id = ? FOR UPDATE');
            $relock_stmt->bind_param('ii', $store_id, $product_id);
            $relock_stmt->execute();
            $relock_row = $relock_stmt->get_result()->fetch_assoc();
            $relock_stmt->close();
            if ($relock_row) {
                $quantity_before = (float)$relock_row['quantity'];
            }
        }

        $quantity_after = round($quantity_before + $quantity_change, 2);

        $update_stmt = inventory_service_prepare($conn, 'UPDATE inventory SET quantity = ? WHERE store_id = ? AND product_id = ?');
        $update_stmt->bind_param('dii', $quantity_after, $store_id, $product_id);
        $update_stmt->execute();
        $update_stmt->close();

        $reference_history_id = array_key_exists('reference_history_id', $args) && $args['reference_history_id'] !== null
            ? (int)$args['reference_history_id']
            : reference_store_history_id_at($conn, new DateTime($occurred_at));

        $ledger_id = inventory_ledger_insert($conn, [
            'store_id' => $store_id,
            'product_id' => $product_id,
            'quantity_change' => $quantity_change,
            'quantity_after' => $quantity_after,
            'event_type' => $event_type,
            'source_type' => $source_type,
            'source_id' => $source_id,
            'reference_history_id' => $reference_history_id,
            'occurred_at' => $occurred_at,
            'user_id' => $user_id,
            'remarks' => $remarks,
        ]);

        if (is_array($lot) && isset($lot['mode'])) {
            if ($lot['mode'] === 'fifo_deduct') {
                inventory_ledger_deduct_fifo_lots($conn, $store_id, $product_id, abs($quantity_change));
            } elseif ($lot['mode'] === 'increment' && !empty($lot['expiration_date'])) {
                inventory_ledger_increment_lot($conn, $store_id, $product_id, (string)$lot['expiration_date'], abs($quantity_change), $user_id);
            }
        }

        if ($manage_transaction) {
            $conn->commit();
        } elseif ($savepoint) {
            $conn->query("RELEASE SAVEPOINT `{$savepoint}`");
        }

        return [
            'success' => true,
            'skipped' => false,
            'quantity_before' => $quantity_before,
            'quantity_after' => $quantity_after,
            'ledger_id' => $ledger_id,
            'error' => null,
        ];
    } catch (InventoryLedgerDuplicateException $e) {
        if ($manage_transaction) {
            $conn->rollback();
        } elseif ($savepoint) {
            $conn->query("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        }
        return [
            'success' => true,
            'skipped' => true,
            'quantity_before' => null,
            'quantity_after' => inventory_get_quantity($conn, $store_id, $product_id),
            'ledger_id' => null,
            'error' => null,
        ];
    } catch (Throwable $e) {
        if ($manage_transaction) {
            $conn->rollback();
        } elseif ($savepoint) {
            $conn->query("ROLLBACK TO SAVEPOINT `{$savepoint}`");
        }
        error_log('inventory_apply_delta error: ' . $e->getMessage());
        if (!$manage_transaction) {
            throw $e;
        }
        return $fail('재고 반영 중 오류가 발생했습니다: ' . $e->getMessage());
    }
}

/**
 * 실사 조정 적용 — 장부 수량과 실사 수량의 차이를 계산해 inventory_apply_delta()로 반영한다
 * (Design §3.2). 차이가 0이면 아무 것도 하지 않고 skipped=true를 반환한다.
 *
 * @param array $args ['store_id','product_id','physical_quantity','source_id','user_id','reason','manage_transaction']
 */
function inventory_apply_manual_adjustment(mysqli $conn, array $args): array
{
    $store_id = (int)($args['store_id'] ?? 0);
    $product_id = (int)($args['product_id'] ?? 0);
    $physical_quantity = (float)($args['physical_quantity'] ?? 0);
    $source_id = (int)($args['source_id'] ?? 0);
    $user_id = isset($args['user_id']) && $args['user_id'] !== null ? (int)$args['user_id'] : null;
    $reason = trim((string)($args['reason'] ?? ''));
    $manage_transaction = $args['manage_transaction'] ?? true;

    if ($reason === '') {
        return ['success' => false, 'skipped' => false, 'delta' => 0.0, 'quantity_after' => null, 'error' => '조정 사유는 필수입니다.'];
    }
    if ($source_id <= 0) {
        return ['success' => false, 'skipped' => false, 'delta' => 0.0, 'quantity_after' => null, 'error' => '조정 이력 번호(source_id)가 필요합니다.'];
    }

    $book_quantity = inventory_get_quantity($conn, $store_id, $product_id);
    $calc = inventory_calculate_adjustment($physical_quantity, $book_quantity);

    if ($calc['event_type'] === null) {
        return ['success' => true, 'skipped' => true, 'delta' => 0.0, 'quantity_after' => $book_quantity, 'error' => null];
    }

    $result = inventory_apply_delta($conn, [
        'store_id' => $store_id,
        'product_id' => $product_id,
        'quantity_change' => $calc['delta'],
        'event_type' => $calc['event_type'],
        'source_type' => 'adjustment',
        'source_id' => $source_id,
        'user_id' => $user_id,
        'remarks' => $reason,
        'manage_transaction' => $manage_transaction,
    ]);
    $result['delta'] = $calc['delta'];
    return $result;
}
