<?php
/**
 * Phase 3 거래 연동 검증 — 폐기(disposal) 경로 (lib/expiry_helper.php)
 * Plan Ref: docs/01-plan/features/unified-inventory-reference-store.plan.md §6 Phase 3
 *
 * CLI 전용: php tests/verify_phase3_disposal.php
 *
 * register_disposal()/update_disposal()/delete_disposal()가 inventory_apply_delta()를 통해
 * inventory_ledger에 기록하면서도, 선택한 특정 로트(inventory_expirations)를 이중으로 차감하지
 * 않는지 검증한다 — inventory_apply_delta() 호출 시 'lot' 옵션을 넘기지 않아야 FIFO 자동 차감이
 * 함께 실행되지 않는다(코디네이터 지적 사항: 선택 lot 수동 차감 + FIFO 자동 차감 중복 방지).
 *
 * 전체를 하나의 트랜잭션으로 실행하고 끝에 항상 ROLLBACK한다 — 실제 DB에는 아무 것도 남지 않는다.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI 전용 스크립트입니다.\n");
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/inventory_service.php';
require_once __DIR__ . '/../lib/expiry_helper.php';

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
    $sku = 'TEST-DISPOSAL-' . bin2hex(random_bytes(4));
    $ins = $conn->prepare("INSERT INTO products (sku, name_ko, pieces_per_box, is_active) VALUES (?, '폐기 테스트 상품', 1, 1)");
    $ins->bind_param('s', $sku);
    $ins->execute();
    $product_id = $conn->insert_id;
    $ins->close();

    $store_id = 1;

    // 로트 2개 준비 (선택 대상 로트는 lot_a, 나머지 lot_b는 손대지 않아야 함)
    $exp_a = date('Y-m-d', strtotime('-5 days'));
    $exp_b = date('Y-m-d', strtotime('+30 days'));
    inventory_ledger_increment_lot($conn, $store_id, $product_id, $exp_a, 10);
    inventory_ledger_increment_lot($conn, $store_id, $product_id, $exp_b, 20);

    // inventory.quantity를 로트 합계로 맞춘다(실사 조정을 거쳐 실제 화면과 동일한 절차로 반영)
    $adj = inventory_apply_manual_adjustment($conn, [
        'store_id' => $store_id, 'product_id' => $product_id, 'physical_quantity' => 30,
        'source_id' => 1, 'reason' => '테스트 준비', 'manage_transaction' => false,
    ]);
    v_assert($adj['success'] && $adj['quantity_after'] === 30.0, '준비: 로트 합계(10+20)와 inventory.quantity(30) 일치', $failures, $pass_count);

    $lot_a_id_row = $conn->query("SELECT id FROM inventory_expirations WHERE store_id={$store_id} AND product_id={$product_id} AND expiration_date='{$exp_a}'")->fetch_assoc();
    $lot_a_id = (int)$lot_a_id_row['id'];

    echo "[폐기 등록 — 이중 차감 방지]\n";
    $r1 = register_disposal($conn, [
        'store_id' => $store_id, 'product_id' => $product_id, 'inventory_expiration_id' => $lot_a_id,
        'quantity' => 4, 'reason' => 'expired', 'reason_note' => null, 'user_id' => 1,
    ]);
    v_assert($r1['success'], '폐기 등록 성공: ' . ($r1['error'] ?? ''), $failures, $pass_count);
    v_assert(inventory_get_quantity($conn, $store_id, $product_id) === 26.0, 'inventory.quantity 30 -> 26 (정확히 4만 차감, 이중 차감 없음)', $failures, $pass_count);

    $lot_a_after = $conn->query("SELECT quantity FROM inventory_expirations WHERE id={$lot_a_id}")->fetch_assoc();
    v_assert((float)$lot_a_after['quantity'] === 6.0, '선택한 lot_a 10 -> 6 (정확히 4만 차감)', $failures, $pass_count);

    $lot_b_after = $conn->query("SELECT quantity FROM inventory_expirations WHERE store_id={$store_id} AND product_id={$product_id} AND expiration_date='{$exp_b}'")->fetch_assoc();
    v_assert((float)$lot_b_after['quantity'] === 20.0, '건드리지 않은 lot_b는 그대로 20 (FIFO 자동 차감이 함께 실행되지 않음)', $failures, $pass_count);

    $ledger_row = $conn->query("SELECT event_type, quantity_change, source_type FROM inventory_ledger WHERE source_type='disposal' AND product_id={$product_id} AND store_id={$store_id}")->fetch_assoc();
    v_assert($ledger_row && $ledger_row['event_type'] === 'DISPOSAL_OUT' && (float)$ledger_row['quantity_change'] === -4.0, 'DISPOSAL_OUT 원장 1건 기록(-4)', $failures, $pass_count);

    $disposal_row = $conn->query("SELECT id FROM product_disposals WHERE store_id={$store_id} AND product_id={$product_id}")->fetch_assoc();
    $disposal_id = (int)$disposal_row['id'];

    echo "\n[폐기 이력 수정 — 증가]\n";
    $r2 = update_disposal($conn, [
        'store_id' => $store_id, 'disposal_id' => $disposal_id, 'quantity' => 7, 'reason' => 'expired', 'reason_note' => null,
    ]);
    v_assert($r2['success'], '폐기 수량 4 -> 7 수정 성공: ' . ($r2['error'] ?? ''), $failures, $pass_count);
    v_assert(inventory_get_quantity($conn, $store_id, $product_id) === 23.0, 'inventory.quantity 26 -> 23 (추가로 3만 더 차감)', $failures, $pass_count);
    $lot_a_after2 = $conn->query("SELECT quantity FROM inventory_expirations WHERE id={$lot_a_id}")->fetch_assoc();
    v_assert((float)$lot_a_after2['quantity'] === 3.0, 'lot_a 6 -> 3', $failures, $pass_count);

    echo "\n[폐기 이력 수정 — 감소(반환)]\n";
    $r3 = update_disposal($conn, [
        'store_id' => $store_id, 'disposal_id' => $disposal_id, 'quantity' => 2, 'reason' => 'expired', 'reason_note' => null,
    ]);
    v_assert($r3['success'], '폐기 수량 7 -> 2 수정 성공: ' . ($r3['error'] ?? ''), $failures, $pass_count);
    v_assert(inventory_get_quantity($conn, $store_id, $product_id) === 28.0, 'inventory.quantity 23 -> 28 (5만큼 반환)', $failures, $pass_count);

    echo "\n[폐기 이력 삭제(전체 취소)]\n";
    $r4 = delete_disposal($conn, ['store_id' => $store_id, 'disposal_id' => $disposal_id]);
    v_assert($r4['success'], '폐기 이력 삭제 성공: ' . ($r4['error'] ?? ''), $failures, $pass_count);
    v_assert(inventory_get_quantity($conn, $store_id, $product_id) === 30.0, 'inventory.quantity 28 -> 30 (등록 이전 상태로 완전 복구)', $failures, $pass_count);

    $remaining_disposal = $conn->query("SELECT COUNT(*) c FROM product_disposals WHERE id={$disposal_id}")->fetch_assoc();
    v_assert((int)$remaining_disposal['c'] === 0, '폐기 이력 행 삭제됨', $failures, $pass_count);

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
