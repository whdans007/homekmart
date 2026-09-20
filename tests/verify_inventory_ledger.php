<?php
/**
 * 통합 재고 원장 / Reference Store 서비스 검증 스크립트
 * Plan Ref: docs/01-plan/features/unified-inventory-reference-store.plan.md §7 (검증 시나리오)
 *
 * CLI 전용: php tests/verify_inventory_ledger.php
 *
 * 안전장치: 전체 시나리오를 하나의 트랜잭션으로 실행하고 끝에 항상 ROLLBACK한다.
 * 어떤 단계가 실패해도(assert 실패/예외) 실제 DB에는 아무 것도 남지 않는다 — 로컬/개발
 * DB에서 실제 상품·재고 데이터를 건드리지 않고 반복 실행할 수 있다.
 *
 * lib/inventory_service.php, lib/inventory_ledger.php, lib/reference_store_service.php를
 * 대상으로 Plan §7 시나리오 1,2,3,4(멱등),5(RETURN_IN),9(FIFO),10(조정),11(Reference Store
 * 비소급)을 커버한다. 실제 화면 연동(매입확정/POS/몰/이동/도매/크레딧/폐기)은 Phase 3
 * 후속 작업이라 이 스크립트 범위 밖이다.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI 전용 스크립트입니다.\n");
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/inventory_service.php';
require_once __DIR__ . '/../lib/inventory_ledger.php';
require_once __DIR__ . '/../lib/reference_store_service.php';

$failures = [];
$pass_count = 0;

function v_assert(bool $cond, string $label, array &$failures, int &$pass_count): void
{
    if ($cond) {
        $pass_count++;
        echo "  [PASS] {$label}\n";
    } else {
        $failures[] = $label;
        echo "  [FAIL] {$label}\n";
    }
}

$conn = get_db_connection();
$conn->begin_transaction();

try {
    // --- 준비: 테스트 전용 상품 2개 생성 (실제 상품 데이터에 영향 없음, 트랜잭션 끝에 롤백) ---
    $sku = 'TEST-LEDGER-' . bin2hex(random_bytes(4));
    $ins = $conn->prepare("INSERT INTO products (sku, name_ko, pieces_per_box, is_active) VALUES (?, '재고원장 테스트 상품', 12, 1)");
    $ins->bind_param('s', $sku);
    $ins->execute();
    $product_id = $conn->insert_id;
    $ins->close();

    $sku2 = 'TEST-LEDGER-B-' . bin2hex(random_bytes(4));
    $ins2 = $conn->prepare("INSERT INTO products (sku, name_ko, pieces_per_box, is_active) VALUES (?, '재고원장 테스트 상품(박스미설정)', 0, 1)");
    $ins2->bind_param('s', $sku2);
    $ins2->execute();
    $product_id_no_box = $conn->insert_id;
    $ins2->close();

    $store_a = 1; // 기존 시드 기준 점포
    $store_b_candidate = null;
    $store_res = $conn->query('SELECT id FROM stores WHERE id != ' . (int)$store_a . ' ORDER BY id LIMIT 1');
    if ($store_res && $row = $store_res->fetch_assoc()) {
        $store_b_candidate = (int)$row['id'];
    }

    echo "테스트 상품 id={$product_id} (pieces_per_box=12), id={$product_id_no_box} (pieces_per_box=0), store_a={$store_a}, store_b=" . ($store_b_candidate ?? 'N/A') . "\n\n";

    // --- 시나리오 1: 박스 매입 2박스 x 12개 = +24 ---
    echo "[박스 환산 + PURCHASE_IN]\n";
    $pieces = inventory_convert_box_to_pieces($conn, $product_id, 2);
    v_assert($pieces === 24.0, 'pieces_per_box=12 상품 2박스 -> 24개 환산', $failures, $pass_count);

    $pieces_default = inventory_convert_box_to_pieces($conn, $product_id_no_box, 5);
    v_assert($pieces_default === 5.0, 'pieces_per_box=0(미설정) 상품은 1개들이로 취급(5 -> 5)', $failures, $pass_count);

    $r1 = inventory_apply_delta($conn, [
        'store_id' => $store_a, 'product_id' => $product_id, 'quantity_change' => $pieces,
        'event_type' => 'PURCHASE_IN', 'source_type' => 'test_purchase', 'source_id' => 9001,
        'remarks' => '테스트 매입확정', 'manage_transaction' => false,
    ]);
    v_assert($r1['success'] && !$r1['skipped'] && $r1['quantity_after'] === 24.0, 'PURCHASE_IN 적용 후 재고 24', $failures, $pass_count);

    // --- 시나리오 2/3: POS 판매가 재고보다 많아 음수 발생, 화면에 그대로 표시(=DB에 그대로 저장) ---
    echo "\n[음수 재고 허용 (POS_OUT)]\n";
    $r2 = inventory_apply_delta($conn, [
        'store_id' => $store_a, 'product_id' => $product_id, 'quantity_change' => -27,
        'event_type' => 'POS_OUT', 'source_type' => 'test_pos_upload', 'source_id' => 5555,
        'manage_transaction' => false,
    ]);
    v_assert($r2['success'] && !$r2['skipped'] && $r2['quantity_after'] === -3.0, 'POS 초과 판매 시 재고 -3으로 저장(0 보정 없음)', $failures, $pass_count);
    v_assert(inventory_get_quantity($conn, $store_a, $product_id) === -3.0, 'inventory.quantity가 실제로 -3', $failures, $pass_count);

    // --- 시나리오 4: 같은 POS 업로드 재시도 시 중복 차감 없음 ---
    echo "\n[중복 방지 (동일 source 재시도)]\n";
    $r3 = inventory_apply_delta($conn, [
        'store_id' => $store_a, 'product_id' => $product_id, 'quantity_change' => -27,
        'event_type' => 'POS_OUT', 'source_type' => 'test_pos_upload', 'source_id' => 5555,
        'manage_transaction' => false,
    ]);
    v_assert($r3['success'] && $r3['skipped'] === true, '동일 source_type+source_id 재시도는 skipped=true', $failures, $pass_count);
    v_assert(inventory_get_quantity($conn, $store_a, $product_id) === -3.0, '재시도 후에도 재고는 여전히 -3(중복 차감 없음)', $failures, $pass_count);

    // DB 레벨 안전장치: 사전 존재 체크를 우회해서 직접 INSERT해도 UNIQUE 키가 막는지 확인.
    $conn->query('SAVEPOINT sp_dup_check');
    $duplicate_caught = false;
    try {
        inventory_ledger_insert($conn, [
            'store_id' => $store_a, 'product_id' => $product_id, 'quantity_change' => -27, 'quantity_after' => -3,
            'event_type' => 'POS_OUT', 'source_type' => 'test_pos_upload', 'source_id' => 5555,
            'reference_history_id' => null, 'occurred_at' => date('Y-m-d H:i:s'), 'user_id' => null, 'remarks' => null,
        ]);
    } catch (InventoryLedgerDuplicateException $e) {
        $duplicate_caught = true;
    }
    $conn->query('ROLLBACK TO SAVEPOINT sp_dup_check');
    v_assert($duplicate_caught, 'UNIQUE 키가 DB 레벨에서도 중복 원장 행 삽입을 차단', $failures, $pass_count);

    // --- 시나리오 5: 몰 주문 취소 시 재고 복구(RETURN_IN) ---
    echo "\n[취소/반품 복구 (RETURN_IN)]\n";
    $r4 = inventory_apply_delta($conn, [
        'store_id' => $store_a, 'product_id' => $product_id, 'quantity_change' => 5,
        'event_type' => 'RETURN_IN', 'source_type' => 'test_mall_cancel', 'source_id' => 7001,
        'manage_transaction' => false,
    ]);
    v_assert($r4['success'] && $r4['quantity_after'] === 2.0, 'RETURN_IN 반영 후 재고 2(-3 + 5)', $failures, $pass_count);

    // --- 시나리오 10: 실사 조정 ---
    echo "\n[실사 조정]\n";
    $adj1 = inventory_apply_manual_adjustment($conn, [
        'store_id' => $store_a, 'product_id' => $product_id, 'physical_quantity' => 10,
        'source_id' => 8001, 'reason' => '실사 결과 반영', 'manage_transaction' => false,
    ]);
    v_assert($adj1['success'] && !$adj1['skipped'] && $adj1['delta'] === 8.0 && $adj1['quantity_after'] === 10.0, '장부 2 -> 실사 10, ADJUSTMENT_IN +8 기록', $failures, $pass_count);

    $adj2 = inventory_apply_manual_adjustment($conn, [
        'store_id' => $store_a, 'product_id' => $product_id, 'physical_quantity' => 10,
        'source_id' => 8002, 'reason' => '차이 없음', 'manage_transaction' => false,
    ]);
    v_assert($adj2['success'] && $adj2['skipped'] === true && $adj2['delta'] === 0.0, '장부==실사면 조정 없이 skipped', $failures, $pass_count);

    // --- 잘못된 이벤트 유형은 재고를 건드리지 않고 거부 ---
    echo "\n[검증 실패 케이스]\n";
    $before_invalid = inventory_get_quantity($conn, $store_a, $product_id);
    $r_invalid = inventory_apply_delta($conn, [
        'store_id' => $store_a, 'product_id' => $product_id, 'quantity_change' => 1,
        'event_type' => 'NOT_A_REAL_EVENT', 'source_type' => 'test_invalid', 'source_id' => 9999,
        'manage_transaction' => false,
    ]);
    v_assert(!$r_invalid['success'], '정의되지 않은 event_type은 거부됨', $failures, $pass_count);
    v_assert(inventory_get_quantity($conn, $store_a, $product_id) === $before_invalid, '거부된 요청은 재고에 영향 없음', $failures, $pass_count);

    // --- 시나리오 9: 폐기 FIFO 로트 차감 (부족분은 오래된 로트에 음수로 반영) ---
    echo "\n[FIFO 로트 차감]\n";
    $lot_old = date('Y-m-d', strtotime('+10 days'));
    $lot_new = date('Y-m-d', strtotime('+40 days'));
    inventory_ledger_increment_lot($conn, $store_a, $product_id, $lot_old, 4);
    inventory_ledger_increment_lot($conn, $store_a, $product_id, $lot_new, 3);

    $r5 = inventory_apply_delta($conn, [
        'store_id' => $store_a, 'product_id' => $product_id, 'quantity_change' => -10,
        'event_type' => 'DISPOSAL_OUT', 'source_type' => 'test_disposal', 'source_id' => 6001,
        'manage_transaction' => false, 'lot' => ['mode' => 'fifo_deduct'],
    ]);
    v_assert($r5['success'] && $r5['quantity_after'] === 0.0, 'DISPOSAL_OUT -10 반영, 전체 재고 10 -> 0', $failures, $pass_count);

    $lot_check = $conn->prepare('SELECT expiration_date, quantity FROM inventory_expirations WHERE store_id=? AND product_id=? ORDER BY expiration_date ASC');
    $lot_check->bind_param('ii', $store_a, $product_id);
    $lot_check->execute();
    $lots = $lot_check->get_result()->fetch_all(MYSQLI_ASSOC);
    $lot_check->close();
    v_assert(count($lots) === 2, '로트 2개 존재', $failures, $pass_count);
    v_assert((float)$lots[0]['quantity'] === -3.0, '임박 로트(먼저 소진)가 부족분(-3)까지 음수로 반영', $failures, $pass_count);
    v_assert((float)$lots[1]['quantity'] === 0.0, '나중 로트는 정확히 소진되어 0', $failures, $pass_count);

    // --- Reference Store 서비스 ---
    echo "\n[Reference Store]\n";
    $current = reference_store_get_current($conn);
    v_assert($current === 1, '마이그레이션 시드값 기준 현재 Reference Store = 1', $failures, $pass_count);

    $past = reference_store_at($conn, new DateTime('2000-01-01 00:00:00'));
    v_assert($past === 1, '2000년 시점 조회도 시드 점포(1)로 귀속', $failures, $pass_count);

    if ($store_b_candidate !== null) {
        $set1 = reference_store_set($conn, $store_b_candidate, null, false);
        v_assert($set1['success'] && $set1['changed'] === true, "Reference Store를 {$store_b_candidate}로 변경 성공", $failures, $pass_count);

        $current_after = reference_store_get_current($conn);
        v_assert($current_after === $store_b_candidate, '변경 후 현재값이 새 점포로 갱신', $failures, $pass_count);

        $past_after_change = reference_store_at($conn, new DateTime('2000-01-01 00:00:00'));
        v_assert($past_after_change === 1, '변경 이후에도 과거 시점 조회는 여전히 옛 점포(비소급)', $failures, $pass_count);

        $now_after_change = reference_store_at($conn, new DateTime());
        v_assert($now_after_change === $store_b_candidate, '지금 시점 조회는 새 점포', $failures, $pass_count);

        $set2 = reference_store_set($conn, $store_b_candidate, null, false);
        v_assert($set2['success'] && $set2['changed'] === false, '같은 점포로 재저장하면 변경 없음(멱등)', $failures, $pass_count);
    } else {
        echo "  [SKIP] 두 번째 점포가 없어 Reference Store 변경 시나리오는 건너뜀\n";
    }

    // 항상 롤백 — 실제 DB에는 아무 것도 남기지 않는다.
    $conn->rollback();
    echo "\n(모든 변경사항 ROLLBACK 완료 — 실제 데이터에는 영향 없음)\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "\n[예외 발생] " . $e->getMessage() . "\n";
    $failures[] = 'unexpected exception: ' . $e->getMessage();
}

echo "\n=== 결과: {$pass_count}건 통과, " . count($failures) . "건 실패 ===\n";
if (!empty($failures)) {
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
exit(0);
