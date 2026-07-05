<?php
// File: logistics/test_box_pcs_unit.php
// Purpose: box-pcs-unit L1 테스트 (Design §8.2)
// 실행: php logistics/test_box_pcs_unit.php (CLI) 또는 브라우저
// DB 쓰기 테스트는 트랜잭션 시작 후 마지막에 ROLLBACK — 실DB 무손상.
// 주의: 마이그레이션 v18 적용 후 실행할 것.

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/unit_helper.php';

// 웹 접근 시 CENTER(물류센터) 소속/슈퍼관리자만 허용 (CLI 실행은 예외)
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/lib/auth.php';
    lc_require_staff();
}

header('Content-Type: text/plain; charset=utf-8');

$pass = 0; $fail = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "✅ PASS: $name\n"; }
    else       { $fail++; echo "❌ FAIL: $name" . ($detail ? " — $detail" : '') . "\n"; }
}

echo "=== box-pcs-unit L1 Test ===\n\n";

// ── Test 1: lc_normalize_unit (Design §8.2 #1) ──────────────────
echo "[Test 1] lc_normalize_unit\n";
check("'박스' → BOX",  lc_normalize_unit('박스') === 'BOX');
check("'BOX' → BOX",   lc_normalize_unit('BOX') === 'BOX');
check("'box ' → BOX",  lc_normalize_unit('box ') === 'BOX');
check("'EA' → PCS",    lc_normalize_unit('EA') === 'PCS');
check("null → PCS",    lc_normalize_unit(null) === 'PCS');
check("lc_valid_unit('pcs') → PCS", lc_valid_unit('pcs') === 'PCS');
check("lc_valid_unit('XXX') → PCS(기본)", lc_valid_unit('XXX') === 'PCS');
echo "\n";

// ── Test 2: lc_pcs_cost (Design §8.2 #2) ────────────────────────
echo "[Test 2] lc_pcs_cost\n";
check("100/20 = 5.0",   abs(lc_pcs_cost(100, 20) - 5.0) < 0.0001);
check("100/0 = 100 (ppb 보정)", abs(lc_pcs_cost(100, 0) - 100.0) < 0.0001);
check("100/3 = 33.3333", abs(lc_pcs_cost(100, 3) - 33.3333) < 0.0001);
echo "\n";

// ── DB 테스트 준비 ──────────────────────────────────────────────
$conn = get_lc_db();

// v18 적용 확인
$has_unit = $conn->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lc_inventory' AND COLUMN_NAME = 'unit'"
)->fetch_row()[0];
if (!$has_unit) {
    echo "⚠️  마이그레이션 v18 미적용 — DB 테스트(3~8)를 건너뜁니다. run_migration_v18.php 먼저 실행하세요.\n";
    echo "\n=== 결과: PASS $pass / FAIL $fail ===\n";
    exit($fail > 0 ? 1 : 0);
}

$conn->autocommit(false); // 전체 롤백용 트랜잭션

try {
    // 테스트 상품 생성 (ppb=20)
    $conn->query("INSERT INTO lc_products (name_en, unit, pieces_per_box, is_active) VALUES ('__TEST_BOXPCS__', 'BOX', 20, 1)");
    $pid = $conn->insert_id;

    // BOX 5개 입고 (BOX원가 100 → PCS원가 5)
    $today = date('Y-m-d');
    $pcs_cost = lc_pcs_cost(100.0, 20);
    $st = $conn->prepare(
        "INSERT INTO lc_inbound (inbound_date, product_id, lot_number, expiry_date, quantity, inbound_unit, pieces_per_box, cost_price, cost_price_pcs)
         VALUES (?, ?, 'TLOT1', '2027-01-01', 5, 'BOX', 20, 100.00, ?)"
    );
    $st->bind_param('sid', $today, $pid, $pcs_cost);
    $st->execute();
    $inbound_id = $conn->insert_id;
    $st->close();

    $st = $conn->prepare(
        "INSERT INTO lc_inventory (inbound_id, product_id, unit, lot_number, expiry_date, quantity_in, quantity_out)
         VALUES (?, ?, 'BOX', 'TLOT1', '2027-01-01', 5, 0)"
    );
    $st->bind_param('ii', $inbound_id, $pid);
    $st->execute();
    $box_lot_id = $conn->insert_id;
    $st->close();

    // ── Test 3: 단위별 재고 집계 (Design §8.2 #3) ───────────────
    echo "[Test 3] BOX 5 입고 → lc_get_stock_by_unit\n";
    $stock = lc_get_stock_by_unit($conn, $pid);
    check("BOX=5, PCS=0", $stock['BOX'] === 5 && $stock['PCS'] === 0, json_encode($stock));
    check("lc_format_stock = '5 BOX'", lc_format_stock($stock) === '5 BOX', lc_format_stock($stock));
    echo "\n";

    // ── Test 4: 개봉 — 1박스 개봉, 파손 1 (Design §8.2 #4) ──────────
    // lc_execute_box_break: ajax/box_break.php와 동일한 실제 경로 검증
    echo "[Test 4] 1박스(ppb20) 개봉, 파손 1 → BOX 4 + PCS 19\n";
    $res = lc_execute_box_break($conn, $box_lot_id, 1, 1, 'L1 test');
    check("개봉 성공 + pcs_created=19", ($res['success'] ?? false) && $res['pcs_created'] === 19, json_encode($res));
    $pcs_lot_id = $res['new_inventory_id'];

    $stock = lc_get_stock_by_unit($conn, $pid);
    check("BOX=4, PCS=19", $stock['BOX'] === 4 && $stock['PCS'] === 19, json_encode($stock));
    check("damage_cost = 5.0000 (PCS단가×1)", abs((float)$res['damage_cost'] - 5.0) < 0.0001, (string)$res['damage_cost']);
    echo "\n";

    // ── Test 5: 유효단가 — 개봉 파생 PCS lot은 cost_price_pcs (Design §3.2, §8.2 #6) ──
    echo "[Test 5] 유효단가 검증\n";
    $row = $conn->query(
        "SELECT " . LC_EFFECTIVE_COST_SQL . " AS ec
         FROM lc_inventory i JOIN lc_inbound b ON i.inbound_id = b.id
         WHERE i.id = $pcs_lot_id"
    )->fetch_assoc();
    check("PCS lot(BOX 입고 파생) 유효단가 = 5.0", abs((float)$row['ec'] - 5.0) < 0.0001, $row['ec']);
    $row = $conn->query(
        "SELECT " . LC_EFFECTIVE_COST_SQL . " AS ec
         FROM lc_inventory i JOIN lc_inbound b ON i.inbound_id = b.id
         WHERE i.id = $box_lot_id"
    )->fetch_assoc();
    check("BOX lot 유효단가 = 100.0", abs((float)$row['ec'] - 100.0) < 0.0001, $row['ec']);
    echo "\n";

    // ── Test 6: 단위별 FEFO 차감 — PCS 10 출고 (Design §8.2 #6) ──
    echo "[Test 6] PCS 10 출고 → PCS 9, BOX 무변경\n";
    $ded = lc_unit_fifo_ship_allow_negative($conn, $pid, 10, 'PCS');
    $stock = lc_get_stock_by_unit($conn, $pid);
    check("BOX=4, PCS=9", $stock['BOX'] === 4 && $stock['PCS'] === 9, json_encode($stock));
    check("차감 lot 단가 = cost_price_pcs(5.0)", abs($ded[0]['cost_price'] - 5.0) < 0.0001, json_encode($ded));
    echo "\n";

    // ── Test 7: BOX 2 출고 — BOX lot에서만 차감 (Design §8.2 #7) ──
    echo "[Test 7] BOX 2 출고 → BOX 2, PCS 무변경\n";
    $ded = lc_unit_fifo_ship_allow_negative($conn, $pid, 2, 'BOX');
    $stock = lc_get_stock_by_unit($conn, $pid);
    check("BOX=2, PCS=9", $stock['BOX'] === 2 && $stock['PCS'] === 9, json_encode($stock));
    check("차감 lot 단가 = cost_price(100.0)", abs($ded[0]['cost_price'] - 100.0) < 0.0001, json_encode($ded));
    echo "\n";

    // ── Test 8: 미리보기 shortfall + suggest_break (Design §8.2 #8, FR-06) ──
    echo "[Test 8] PCS 30 미리보기 (PCS 9 보유, BOX 2 보유)\n";
    $prev = lc_unit_fefo_preview_allow_negative($conn, $pid, 30, 'PCS');
    check("shortfall = 21", $prev['shortfall'] === 21, (string)$prev['shortfall']);
    check("suggest_break = true (BOX 보유)", $prev['suggest_break'] === true);
    $prev2 = lc_unit_fefo_preview_allow_negative($conn, $pid, 1, 'PCS');
    check("재고 충분 시 shortfall=0, suggest_break=false", $prev2['shortfall'] === 0 && $prev2['suggest_break'] === false);
    echo "\n";

    // ── Test 9: 개봉 검증 오류 — 거부 + 재고 무변경 (Design §8.2 #5) ──
    echo "[Test 9] 개봉 검증 오류 → 거부, 재고 무변경 (현재 BOX 2 / PCS 9)\n";
    $before = lc_get_stock_by_unit($conn, $pid);
    $res = lc_execute_box_break($conn, $box_lot_id, 99, 0);
    check("잔여 초과 개봉 거부", $res['success'] === false, json_encode($res));
    $res = lc_execute_box_break($conn, $box_lot_id, 1, 21);
    check("파손 수량 초과(>boxes×ppb) 거부", $res['success'] === false, json_encode($res));
    $res = lc_execute_box_break($conn, $pcs_lot_id, 1, 0);
    check("PCS lot 개봉 거부", $res['success'] === false, json_encode($res));
    $after = lc_get_stock_by_unit($conn, $pid);
    check("재고 무변경", $before === $after, json_encode($after));
    echo "\n";

    // ── Test 10: 전량 파손 개봉 — PCS lot 미생성, 이력만 기록 (Design §8.2 #10) ──
    echo "[Test 10] 전량 파손 (1박스, damaged=20) → PCS lot 미생성\n";
    $res = lc_execute_box_break($conn, $box_lot_id, 1, 20, '전량 파손 테스트');
    check("성공 + pcs_created=0", ($res['success'] ?? false) && $res['pcs_created'] === 0, json_encode($res));
    check("new_inventory_id = NULL", $res['new_inventory_id'] === null);
    check("damage_cost = 100.0 (20×5.0)", abs((float)$res['damage_cost'] - 100.0) < 0.0001, (string)$res['damage_cost']);
    $stock = lc_get_stock_by_unit($conn, $pid);
    check("BOX=1, PCS=9 (PCS 무변경)", $stock['BOX'] === 1 && $stock['PCS'] === 9, json_encode($stock));
    $cnt = (int)$conn->query("SELECT COUNT(*) FROM lc_box_breaks WHERE product_id = $pid")->fetch_row()[0];
    check("개봉 이력 2건 (Test 4 + Test 10)", $cnt === 2, (string)$cnt);
    echo "\n";

} catch (Exception $e) {
    $fail++;
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
} finally {
    $conn->rollback(); // 모든 테스트 데이터 롤백 — 실DB 무손상
    $conn->close();
}

echo "=== 결과: PASS $pass / FAIL $fail (테스트 데이터는 전부 롤백됨) ===\n";
exit($fail > 0 ? 1 : 0);
