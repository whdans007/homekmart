<?php
/**
 * 중복 상품 병합: SPRITE SWAKTO 190ml (COCA-COLA)
 * 정상 바코드 4801981127191 (배치 #1270 LUCKY OFFICE, #552 KIM'S MALL)
 *  vs 오타 바코드 14801981127191 (배치 #991 G-MART, 2026-07-03 입고 시 바코드 앞자리 1 오입력)
 *
 * 증상: inbound_items.php 검색 시 같은 상품이 바코드만 다르게 3건으로 나뉘어 보이고,
 *       store/order.php 는 product_id 기준으로 재고를 GROUP BY 하므로
 *       #991 배치 재고가 별도 product_id 로 분리 집계되어 매장 주문화면 재고가 실제보다 적게/나뉘어 보임.
 *
 * 처리 방식: 물리삭제(DELETE) 대신 소프트 병합.
 *   1) kw_inbound / kw_inventory / kw_order_items / kw_box_breaks 의 product_id 를
 *      오타 상품(#merge) → 정상 상품(#keep) 으로 재배정
 *   2) 오타 상품은 is_active=0 처리 + 오타 바코드 컬럼만 해제 (이력 보존을 위해 행은 삭제하지 않음)
 *   3) kw_product_history 는 그대로 두어 오타 상품 자체의 등록 이력을 보존, keep 상품 쪽에 병합 이력 1건 기록
 *   4) 병합 전/후 kw_inventory 수량 합계(quantity_in/out)가 동일한지 검증 후에만 COMMIT
 *
 * 접속: http://main.homekmart.net/kimsmall_wherehouse/sql/fix_merge_duplicate_product_4801981127191.php
 * 주의: 실행 후 이 파일은 반드시 삭제하세요! (병합은 1회성 작업이며 재실행 시 이미 병합된 상태이므로 안전하게 스킵됩니다)
 */
require_once __DIR__ . '/../../config/db_config.php';

$KEEP_BARCODE  = '4801981127191';   // 정상 바코드 (유지할 상품)
$MERGE_BARCODE = '14801981127191';  // 오타 바코드 (병합 후 비활성화할 상품)

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];
function step(array &$steps, string $status, string $msg) { $steps[] = [$status, $msg]; }

function find_product_by_barcode(mysqli $conn, string $bc): ?array {
    $st = $conn->prepare(
        "SELECT id, name_en, name_ko, capacity, unit, pieces_per_box, is_active,
                barcode_unit, barcode_box, barcode_logistics
         FROM kw_products
         WHERE barcode_unit = ? OR barcode_box = ? OR barcode_logistics = ?
         ORDER BY is_active DESC, id ASC"
    );
    $st->bind_param('sss', $bc, $bc, $bc);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows[0] ?? null;
}

$keep  = find_product_by_barcode($conn, $KEEP_BARCODE);
$merge = find_product_by_barcode($conn, $MERGE_BARCODE);

if (!$keep)  step($steps, 'ERROR', "유지할 상품(바코드 {$KEEP_BARCODE})을 찾을 수 없습니다.");
if (!$merge) step($steps, 'ERROR', "병합 대상 상품(바코드 {$MERGE_BARCODE})을 찾을 수 없습니다. 이미 병합되었거나 오타가 수정되었을 수 있습니다.");

if ($keep && $merge && (int)$keep['id'] === (int)$merge['id']) {
    step($steps, 'ERROR', "두 바코드가 이미 동일 product_id(#{$keep['id']})입니다. 병합이 필요하지 않습니다.");
    $keep = null;
}

// 안전장치: 이름/용량/단위가 다르면 자동 병합 중단 (실제로 다른 상품일 가능성)
if ($keep && $merge) {
    $sameName = mb_strtolower(trim($keep['name_en'])) === mb_strtolower(trim($merge['name_en']));
    $sameCap  = trim((string)$keep['capacity']) === trim((string)$merge['capacity']);
    $sameUnit = trim((string)$keep['unit']) === trim((string)$merge['unit']);
    if (!$sameName || !$sameCap || !$sameUnit) {
        step($steps, 'ERROR', "상품명/용량/단위가 일치하지 않아 자동 병합을 중단합니다. 수동 확인이 필요합니다.");
        step($steps, 'INFO', "유지 후보 #{$keep['id']}: {$keep['name_en']} / {$keep['capacity']} / {$keep['unit']}");
        step($steps, 'INFO', "병합 후보 #{$merge['id']}: {$merge['name_en']} / {$merge['capacity']} / {$merge['unit']}");
        $keep = null;
    }
}

if ($keep && $merge) {
    $keep_id  = (int)$keep['id'];
    $merge_id = (int)$merge['id'];

    step($steps, 'INFO', "유지 상품: #$keep_id {$keep['name_en']} ({$keep['name_ko']}) capacity={$keep['capacity']}");
    step($steps, 'INFO', "병합(비활성화) 상품: #$merge_id {$merge['name_en']} ({$merge['name_ko']}) capacity={$merge['capacity']}");

    $before = $conn->query(
        "SELECT COALESCE(SUM(quantity_in),0) tin, COALESCE(SUM(quantity_out),0) tout, COUNT(*) cnt
         FROM kw_inventory WHERE product_id IN ($keep_id, $merge_id)"
    )->fetch_assoc();
    step($steps, 'INFO', "병합 전 재고 스냅샷(양쪽 합): lot {$before['cnt']}건, in {$before['tin']}, out {$before['tout']}");

    $conn->autocommit(false);
    $ok = true;

    foreach (['kw_inbound', 'kw_inventory', 'kw_order_items', 'kw_box_breaks'] as $t) {
        $st = $conn->prepare("UPDATE {$t} SET product_id = ? WHERE product_id = ?");
        $st->bind_param('ii', $keep_id, $merge_id);
        if ($st->execute()) {
            step($steps, 'OK', "{$t}: product_id {$merge_id} -> {$keep_id} 이전 ({$st->affected_rows}건)");
        } else {
            step($steps, 'ERROR', "{$t} 갱신 실패: " . $conn->error);
            $ok = false;
        }
        $st->close();
        if (!$ok) break;
    }

    if ($ok) {
        // 오타가 실제로 들어있던 바코드 컬럼만 정확히 찾아서 해제 (다른 정상 바코드 컬럼은 보존)
        $clearCol = null;
        foreach (['barcode_unit', 'barcode_box', 'barcode_logistics'] as $c) {
            if ((string)$merge[$c] === $MERGE_BARCODE) { $clearCol = $c; break; }
        }
        $newName = trim($merge['name_en']) . ' [MERGED->#' . $keep_id . ']';
        $sql = "UPDATE kw_products SET is_active = 0, name_en = ?" .
               ($clearCol ? ", {$clearCol} = NULL" : "") .
               " WHERE id = ?";
        $st = $conn->prepare($sql);
        $st->bind_param('si', $newName, $merge_id);
        if ($st->execute()) {
            step($steps, 'OK', "중복 상품 #$merge_id 비활성화" . ($clearCol ? " + {$clearCol} 해제" : "") . " 완료");
        } else {
            step($steps, 'ERROR', "중복 상품 비활성화 실패: " . $conn->error);
            $ok = false;
        }
        $st->close();
    }

    if ($ok) {
        $oldVal = "product_id=$merge_id (barcode $MERGE_BARCODE, typo)";
        $newVal = "merged into product_id=$keep_id (barcode $KEEP_BARCODE)";
        $st = $conn->prepare(
            "INSERT INTO kw_product_history (product_id, action, field_name, field_label, old_value, new_value)
             VALUES (?, 'update', 'merge', '중복상품 병합', ?, ?)"
        );
        $st->bind_param('iss', $keep_id, $oldVal, $newVal);
        $st->execute();
        $st->close();
        step($steps, 'OK', "병합 이력 기록 완료 (kw_product_history)");
    }

    if ($ok) {
        $after = $conn->query(
            "SELECT COALESCE(SUM(quantity_in),0) tin, COALESCE(SUM(quantity_out),0) tout, COUNT(*) cnt
             FROM kw_inventory WHERE product_id = $keep_id"
        )->fetch_assoc();

        if ($before['tin'] === $after['tin'] && $before['tout'] === $after['tout'] && $before['cnt'] === $after['cnt']) {
            step($steps, 'OK', "수량 무변경 검증 통과: lot {$after['cnt']}건, in {$after['tin']}, out {$after['tout']}");
            $conn->commit();
            step($steps, 'OK', 'COMMIT 완료 — 병합이 반영되었습니다.');
        } else {
            step($steps, 'ERROR', "수량 검증 실패! 병합 전(양쪽 합): in {$before['tin']}/out {$before['tout']}/{$before['cnt']}건 -> 병합 후(유지상품): in {$after['tin']}/out {$after['tout']}/{$after['cnt']}건");
            $conn->rollback();
            step($steps, 'ERROR', 'ROLLBACK 수행됨 — 변경사항 없음.');
        }
    } elseif ($keep && $merge) {
        $conn->rollback();
        step($steps, 'ERROR', 'ROLLBACK 수행됨 — 변경사항 없음.');
    }

    $conn->autocommit(true);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>중복 상품 병합 - 4801981127191</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .error{color:red;font-weight:bold;} .info{color:#0066cc;}</style>
</head>
<body>
<h2>중복 상품 병합 결과 — SPRITE SWAKTO (4801981127191 / 14801981127191)</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일은 삭제하세요!</strong></p>
</body>
</html>
