<?php
// File: logistics/test_pack_unit.php
// Purpose: pack-unit L1 테스트 (Design §8.2) — PACK 묶음 단위 검증
// 실행: php logistics/test_pack_unit.php (CLI) 또는 브라우저
// DB 쓰기 테스트는 트랜잭션 시작 후 마지막에 ROLLBACK — 실DB 무손상.
// 주의: 마이그레이션 v22 적용 후 실행할 것.

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

echo "=== pack-unit L1 Test ===\n\n";

// ── Test 1: lc_is_bundle_unit (Design §4.2) ─────────────────────
echo "[Test 1] lc_is_bundle_unit / lc_valid_unit / lc_normalize_unit\n";
check("lc_is_bundle_unit('BOX')  → true",  lc_is_bundle_unit('BOX') === true);
check("lc_is_bundle_unit('PACK') → true",  lc_is_bundle_unit('PACK') === true);
check("lc_is_bundle_unit('PCS')  → false", lc_is_bundle_unit('PCS') === false);
check("lc_is_bundle_unit(' pack ') → true (공백/소문자)", lc_is_bundle_unit(' pack ') === true);
check("lc_valid_unit('pack') → PACK", lc_valid_unit('pack') === 'PACK');
check("lc_valid_unit('XXX') → PCS(기본)", lc_valid_unit('XXX') === 'PCS');
check("lc_normalize_unit('팩') → PACK", lc_normalize_unit('팩') === 'PACK');
check("lc_normalize_unit('PACK') → PACK", lc_normalize_unit('PACK') === 'PACK');
check("lc_normalize_unit('EA') → PCS", lc_normalize_unit('EA') === 'PCS');
echo "\n";

// ── Test 2: lc_pcs_cost (PACK도 동일 환산) ──────────────────────
echo "[Test 2] lc_pcs_cost (PACK 원가 환산)\n";
check("120/6 = 20.0",   abs(lc_pcs_cost(120, 6) - 20.0) < 0.0001);
check("100/0 = 100 (ppb 보정)", abs(lc_pcs_cost(100, 0) - 100.0) < 0.0001);
echo "\n";

// ── DB 테스트 준비 ──────────────────────────────────────────────
$conn = get_lc_db();

// v22 적용 확인 (lc_inventory.unit ENUM에 PACK 포함)
$col = $conn->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lc_inventory' AND COLUMN_NAME = 'unit'"
)->fetch_assoc();
if (!$col || stripos($col['COLUMN_TYPE'], "'PACK'") === false) {
    echo "⚠️  마이그레이션 v22 미적용 — DB 테스트(3~9)를 건너뜁니다. run_migration_v22.php 먼저 실행하세요.\n";
    echo "\n=== 결과: PASS $pass / FAIL $fail ===\n";
    exit($fail > 0 ? 1 : 0);
}

$conn->autocommit(false); // 전체 롤백용 트랜잭션

try {
    // 테스트 상품 생성 (unit=PACK, ppb=6)
    $conn->query("INSERT INTO lc_products (name_en, unit, pieces_per_box, is_active) VALUES ('__TEST_PACK__', 'PACK', 6, 1)");
    $pid = $conn->insert_id;

    // PACK 5개 입고 (PACK원가 120 → PCS원가 20)
    $today = date('Y-m-d');
    $pcs_cost = lc_pcs_cost(120.0, 6);
    $st = $conn->prepare(
        "INSERT INTO lc_inbound (inbound_date, product_id, lot_number, expiry_date, quantity, inbound_unit, pieces_per_box, cost_price, cost_price_pcs)
         VALUES (?, ?, 'PLOT1', '2027-01-01', 5, 'PACK', 6, 120.00, ?)"
    );
    $st->bind_param('sid', $today, $pid, $pcs_cost);
    $st->execute();
    $inbound_id = $conn->insert_id;
    $st->close();

    $st = $conn->prepare(
        "INSERT INTO lc_inventory (inbound_id, product_id, unit, lot_number, expiry_date, quantity_in, quantity_out)
         VALUES (?, ?, 'PACK', 'PLOT1', '2027-01-01', 5, 0)"
    );
    $st->bind_param('ii', $inbound_id, $pid);
    $st->execute();
    $pack_lot_id = $conn->insert_id;
    $st->close();

    // ── Test 3: 단위별 재고 집계 (BOX/PACK/PCS 3키) ───────────────
    echo "[Test 3] PACK 5 입고 → lc_get_stock_by_unit (3키)\n";
    $stock = lc_get_stock_by_unit($conn, $pid);
    check("BOX=0, PACK=5, PCS=0", $stock['BOX'] === 0 && $stock['PACK'] === 5 && $stock['PCS'] === 0, json_encode($stock));
    check("lc_format_stock = '5 PACK'", lc_format_stock($stock) === '5 PACK', lc_format_stock($stock));
    echo "\n";

    // ── Test 4: PACK 개봉 — 1팩 개봉, 파손 1 (Design §4.4) ──────────
    echo "[Test 4] 1팩(ppb6) 개봉, 파손 1 → PACK 4 + PCS 5\n";
    $res = lc_execute_box_break($conn, $pack_lot_id, 1, 1, 'L1 PACK test');
    check("개봉 성공 + pcs_created=5", ($res['success'] ?? false) && $res['pcs_created'] === 5, json_encode($res));
    $pcs_lot_id = $res['new_inventory_id'];

    $stock = lc_get_stock_by_unit($conn, $pid);
    check("PACK=4, PCS=5", $stock['PACK'] === 4 && $stock['PCS'] === 5, json_encode($stock));
    check("damage_cost = 20.0 (PCS단가×1)", abs((float)$res['damage_cost'] - 20.0) < 0.0001, (string)$res['damage_cost']);
    echo "\n";

    // ── Test 5: 유효단가 — 개봉 파생 PCS lot은 cost_price_pcs (Design §2.3) ──
    echo "[Test 5] 유효단가 검증 (PACK 파생 PCS)\n";
    $row = $conn->query(
        "SELECT " . LC_EFFECTIVE_COST_SQL . " AS ec
         FROM lc_inventory i JOIN lc_inbound b ON i.inbound_id = b.id
         WHERE i.id = $pcs_lot_id"
    )->fetch_assoc();
    check("PCS lot(PACK 입고 파생) 유효단가 = 20.0", abs((float)$row['ec'] - 20.0) < 0.0001, $row['ec']);
    $row = $conn->query(
        "SELECT " . LC_EFFECTIVE_COST_SQL . " AS ec
         FROM lc_inventory i JOIN lc_inbound b ON i.inbound_id = b.id
         WHERE i.id = $pack_lot_id"
    )->fetch_assoc();
    check("PACK lot 유효단가 = 120.0", abs((float)$row['ec'] - 120.0) < 0.0001, $row['ec']);
    echo "\n";

    // ── Test 6: 단위별 FEFO 차감 — PACK 2 출고 (Plan SC-07) ──────
    echo "[Test 6] PACK 2 출고 → PACK 2, PCS 무변경\n";
    $ded = lc_unit_fifo_ship_allow_negative($conn, $pid, 2, 'PACK');
    $stock = lc_get_stock_by_unit($conn, $pid);
    check("PACK=2, PCS=5", $stock['PACK'] === 2 && $stock['PCS'] === 5, json_encode($stock));
    check("차감 lot 단가 = cost_price(120.0)", abs($ded[0]['cost_price'] - 120.0) < 0.0001, json_encode($ded));
    echo "\n";

    // ── Test 7: PCS 부족 + PACK 보유 → suggest_break=true (Plan SC-08) ──
    echo "[Test 7] PCS 20 미리보기 (PCS 5 보유, PACK 2 보유)\n";
    $prev = lc_unit_fefo_preview_allow_negative($conn, $pid, 20, 'PCS');
    check("shortfall = 15", $prev['shortfall'] === 15, (string)$prev['shortfall']);
    check("suggest_break = true (PACK 보유)", $prev['suggest_break'] === true);
    echo "\n";

    // ── Test 8: 개봉 검증 오류 — 거부 + 재고 무변경 (Plan SC-10) ──
    echo "[Test 8] 개봉 검증 오류 → 거부, 재고 무변경 (현재 PACK 2 / PCS 5)\n";
    $before = lc_get_stock_by_unit($conn, $pid);
    $res = lc_execute_box_break($conn, $pack_lot_id, 99, 0);
    check("잔여 초과 개봉 거부", $res['success'] === false, json_encode($res));
    $res = lc_execute_box_break($conn, $pack_lot_id, 1, 7); // 파손 > 1팩×6
    check("파손 수량 초과(>packs×ppb) 거부", $res['success'] === false, json_encode($res));
    $res = lc_execute_box_break($conn, $pcs_lot_id, 1, 0);
    check("PCS lot 개봉 거부 (최소 단위)", $res['success'] === false, json_encode($res));
    $after = lc_get_stock_by_unit($conn, $pid);
    check("재고 무변경", $before === $after, json_encode($after));
    echo "\n";

    // ── Test 9: 전량 파손 개봉 — PCS lot 미생성, 이력만 기록 (Plan SC-04) ──
    echo "[Test 9] 전량 파손 (1팩, damaged=6) → PCS lot 미생성\n";
    $res = lc_execute_box_break($conn, $pack_lot_id, 1, 6, '전량 파손 테스트');
    check("성공 + pcs_created=0", ($res['success'] ?? false) && $res['pcs_created'] === 0, json_encode($res));
    check("new_inventory_id = NULL", $res['new_inventory_id'] === null);
    check("damage_cost = 120.0 (6×20.0)", abs((float)$res['damage_cost'] - 120.0) < 0.0001, (string)$res['damage_cost']);
    $stock = lc_get_stock_by_unit($conn, $pid);
    check("PACK=1, PCS=5 (PCS 무변경)", $stock['PACK'] === 1 && $stock['PCS'] === 5, json_encode($stock));
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
