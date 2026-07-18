<?php
/**
 * 음수 LOT 재조정: SEOUL MILK MYEOLGYUN CHOCOLATE (서울우유 멸균 초코) 200ml · 바코드 88004464
 *
 * 증상: 2026-06-22 입고 LOT(7 in)에서 15개가 출고되어 quantity_remain -8 로 표시됨.
 *       이후 2026-07-13에 같은 유통기한(2026-11-09)으로 63개가 추가 입고되어 상품 전체
 *       재고 합계(7-15+63=55)는 정상이지만, LOT 단위 화면에서는 첫 LOT만 마이너스로 남아있음.
 *
 * 원인: lc_fifo_ship_allow_negative()가 재고 부족분을 "그 시점에 존재하던 최근 LOT"에
 *       음수로 기록하는데(당시엔 LOT이 하나뿐이라 자기 자신에 기록됨), 이후 새 LOT이 입고돼도
 *       과거 음수가 자동으로 재조정되지 않던 문제. (재발 방지: lib/inventory_helper.php
 *       lc_rebalance_negative_lots() 추가 + inbound_add.php 신규 입고 시 자동 호출)
 *
 * 처리 방식: lc_rebalance_negative_lots()와 동일한 로직으로, 음수 LOT의 quantity_out을
 *   FEFO 순서(유통기한 ASC, id ASC)로 다른 양수 LOT에 옮긴다. quantity_in은 건드리지 않고
 *   LOT 간 quantity_out만 이전하므로 상품 전체 재고 합계(in/out 총합)는 변하지 않는다.
 *   병합 전/후 합계가 동일한지 검증한 뒤에만 COMMIT.
 *
 * 접속: http://main.homekmart.net/logistics/sql/fix_negative_lot_88004464.php
 * 주의: 실행 후 이 파일은 반드시 삭제하세요! (1회성 작업이며, 재실행 시 음수 LOT이
 *       이미 없으므로 안전하게 스킵됩니다)
 */
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../lib/inventory_helper.php';

$BARCODE = '88004464';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];
function step(array &$steps, string $status, string $msg) { $steps[] = [$status, $msg]; }

$st = $conn->prepare(
    "SELECT id, name_en, name_ko FROM lc_products
     WHERE barcode_unit = ? OR barcode_box = ? OR barcode_logistics = ?
     ORDER BY is_active DESC, id ASC"
);
$st->bind_param('sss', $BARCODE, $BARCODE, $BARCODE);
$st->execute();
$product = $st->get_result()->fetch_assoc();
$st->close();

if (!$product) {
    step($steps, 'ERROR', "바코드 {$BARCODE} 상품을 찾을 수 없습니다.");
} else {
    $product_id = (int)$product['id'];
    step($steps, 'INFO', "대상 상품: #$product_id {$product['name_en']} ({$product['name_ko']})");

    $before_lots = $conn->query(
        "SELECT id, expiry_date, quantity_in, quantity_out, quantity_remain
         FROM lc_inventory WHERE product_id = $product_id ORDER BY expiry_date ASC, id ASC"
    )->fetch_all(MYSQLI_ASSOC);

    $before_totals = $conn->query(
        "SELECT COALESCE(SUM(quantity_in),0) tin, COALESCE(SUM(quantity_out),0) tout,
                COALESCE(SUM(quantity_remain),0) trem,
                SUM(CASE WHEN quantity_remain < 0 THEN 1 ELSE 0 END) neg_cnt
         FROM lc_inventory WHERE product_id = $product_id"
    )->fetch_assoc();

    step($steps, 'INFO', "조정 전: LOT " . count($before_lots) . "건, in {$before_totals['tin']} / out {$before_totals['tout']} / remain {$before_totals['trem']}, 음수 LOT {$before_totals['neg_cnt']}건");
    foreach ($before_lots as $l) {
        step($steps, 'INFO', "  LOT #{$l['id']} exp={$l['expiry_date']} in={$l['quantity_in']} out={$l['quantity_out']} remain={$l['quantity_remain']}");
    }

    if ((int)$before_totals['neg_cnt'] === 0) {
        step($steps, 'OK', '음수 LOT이 없습니다. 조정할 내용이 없어 종료합니다.');
    } else {
        $conn->autocommit(false);
        $ok = true;
        try {
            lc_rebalance_negative_lots($conn, $product_id);
        } catch (Throwable $e) {
            $ok = false;
            step($steps, 'ERROR', '재조정 중 오류: ' . $e->getMessage());
        }

        $after_totals = $conn->query(
            "SELECT COALESCE(SUM(quantity_in),0) tin, COALESCE(SUM(quantity_out),0) tout,
                    COALESCE(SUM(quantity_remain),0) trem,
                    SUM(CASE WHEN quantity_remain < 0 THEN 1 ELSE 0 END) neg_cnt
             FROM lc_inventory WHERE product_id = $product_id"
        )->fetch_assoc();

        $same_in  = (string)$before_totals['tin']  === (string)$after_totals['tin'];
        $same_out = (string)$before_totals['tout'] === (string)$after_totals['tout'];
        $same_rem = (string)$before_totals['trem'] === (string)$after_totals['trem'];

        if ($ok && $same_in && $same_out && $same_rem) {
            $conn->commit();
            step($steps, 'OK', "검증 통과 (in/out/remain 총합 불변): COMMIT 완료.");
            step($steps, 'OK', "조정 후: in {$after_totals['tin']} / out {$after_totals['tout']} / remain {$after_totals['trem']}, 음수 LOT {$after_totals['neg_cnt']}건");

            $after_lots = $conn->query(
                "SELECT id, expiry_date, quantity_in, quantity_out, quantity_remain
                 FROM lc_inventory WHERE product_id = $product_id ORDER BY expiry_date ASC, id ASC"
            )->fetch_all(MYSQLI_ASSOC);
            foreach ($after_lots as $l) {
                step($steps, 'OK', "  LOT #{$l['id']} exp={$l['expiry_date']} in={$l['quantity_in']} out={$l['quantity_out']} remain={$l['quantity_remain']}");
            }
        } else {
            $conn->rollback();
            step($steps, 'ERROR', "검증 실패 — 총합이 변경되었거나 오류 발생. ROLLBACK 수행됨. 변경사항 없음.");
            step($steps, 'ERROR', "전: in {$before_totals['tin']}/out {$before_totals['tout']}/remain {$before_totals['trem']} → 후: in {$after_totals['tin']}/out {$after_totals['tout']}/remain {$after_totals['trem']}");
        }
        $conn->autocommit(true);
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>음수 LOT 재조정 - 88004464</title>
<style>body{font-family:monospace;padding:2em;white-space:pre-wrap;} .ok{color:green;} .error{color:red;font-weight:bold;} .info{color:#0066cc;}</style>
</head>
<body>
<h2>음수 LOT 재조정 결과 — SEOUL MILK MYEOLGYUN CHOCOLATE (88004464)</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일은 삭제하세요!</strong></p>
</body>
</html>
