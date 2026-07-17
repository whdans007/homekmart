<?php
/**
 * 검색어 기준 상품 노출 차이 진단 도구 (읽기 전용)
 * 증상: logistics/inventory.php 검색에는 나오는데 store/order.php 검색에는 안 나오는 상품 원인 분석.
 *
 * store/order.php 상품 목록 조건 (전부 만족해야 노출):
 *   1) p.is_active = 1
 *   2) i.quantity_remain > 0 (0 이하 lot은 집계 제외)
 *   3) lc_inbound.batch_id 가 lc_inbound_batches 에 실제로 존재 (INNER JOIN)
 *
 * logistics/inventory.php 기본 목록 조건:
 *   1) is_active 조건 없음 (비활성 상품도 노출)
 *   2) i.quantity_remain <> 0 (음수 lot도 노출)
 *   3) lc_inbound_batches 조인 없음
 *
 * 사용법: /logistics/diag_product_search.php?q=화장지
 * ⚠ SELECT 전용 — 데이터를 변경하지 않습니다.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';

lc_require_staff();

$q = trim($_GET['q'] ?? '');

function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function render_table(array $rows): string {
    if (!$rows) return '<p style="color:#999;padding:8px 0">결과 없음</p>';
    $cols = array_keys($rows[0]);
    $out = '<table><thead><tr>';
    foreach ($cols as $c) $out .= '<th>' . h($c) . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $out .= '<tr>';
        foreach ($cols as $c) $out .= '<td>' . h($r[$c]) . '</td>';
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

$err = null;
$rows = [];
try {
    if ($q !== '') {
        $conn = get_lc_db();
        $like = "%$q%";

        $rows = [];
        $st = $conn->prepare(
            "SELECT p.id, p.name_en, p.name_ko, p.is_active,
                    COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                    -- logistics/inventory.php 집계 기준 (quantity_remain <> 0, is_active 무관, batch 조인 없음)
                    (SELECT COALESCE(SUM(i.quantity_remain), 0)
                       FROM lc_inventory i JOIN lc_inbound ib ON i.inbound_id = ib.id
                       WHERE i.product_id = p.id AND i.quantity_remain <> 0) AS inventory_php_stock,
                    -- store/order.php 집계 기준 (quantity_remain > 0 + batch INNER JOIN)
                    (SELECT COALESCE(SUM(i.quantity_remain), 0)
                       FROM lc_inventory i
                       JOIN lc_inbound ib ON i.inbound_id = ib.id
                       JOIN lc_inbound_batches bat ON ib.batch_id = bat.id
                       WHERE i.product_id = p.id AND i.quantity_remain > 0) AS order_php_stock,
                    -- batch_id 가 lc_inbound_batches 에 없는(고아) inbound 건수
                    (SELECT COUNT(*) FROM lc_inbound ib2
                       LEFT JOIN lc_inbound_batches bat2 ON ib2.batch_id = bat2.id
                       WHERE ib2.product_id = p.id AND bat2.id IS NULL) AS orphan_batch_inbound_cnt,
                    -- store/order.php 는 unit='BOX' 또는 'PCS' 재고만 인식 (PACK 단위는 집계 안 됨)
                    (SELECT COALESCE(SUM(i.quantity_remain), 0) FROM lc_inventory i
                       WHERE i.product_id = p.id AND i.unit = 'BOX' AND i.quantity_remain > 0) AS box_stock,
                    (SELECT COALESCE(SUM(i.quantity_remain), 0) FROM lc_inventory i
                       WHERE i.product_id = p.id AND i.unit = 'PACK' AND i.quantity_remain > 0) AS pack_stock,
                    (SELECT COALESCE(SUM(i.quantity_remain), 0) FROM lc_inventory i
                       WHERE i.product_id = p.id AND i.unit = 'PCS' AND i.quantity_remain > 0) AS pcs_stock
             FROM lc_products p
             WHERE p.name_en LIKE ? OR p.name_ko LIKE ?
             ORDER BY p.id"
        );
        $st->bind_param('ss', $like, $like);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        foreach ($rows as &$r) {
            $reasons = [];
            if (!$r['is_active']) $reasons[] = 'is_active=0 (비활성 상품 — order.php는 비활성 상품 제외)';
            if ((float)$r['order_php_stock'] <= 0 && (float)$r['inventory_php_stock'] > 0) {
                if ((int)$r['orphan_batch_inbound_cnt'] > 0) {
                    $reasons[] = 'batch_id 고아 레코드 ' . $r['orphan_batch_inbound_cnt'] . '건 (lc_inbound_batches 에 없음 — order.php INNER JOIN에서 누락)';
                } else {
                    $reasons[] = '유효 재고(quantity_remain>0)가 없음 (음수 조정만 있을 수 있음)';
                }
            }
            if ((float)$r['box_stock'] <= 0 && (float)$r['pcs_stock'] <= 0 && (float)$r['pack_stock'] > 0) {
                $reasons[] = 'PACK 단위 재고만 있음 (' . $r['pack_stock'] . ') — order.php는 BOX/PCS만 인식해서 화면에서 통째로 숨겨짐';
                $r['order_php_노출여부_예정'] = 'X (PACK 미지원)';
            }
            $r['order_php_노출여부'] = ((float)$r['order_php_stock'] > 0 && $r['is_active']) ? 'O' : 'X';
            $r['제외_사유'] = $reasons ? implode(' / ', $reasons) : '-';
        }
        unset($r);

        $conn->close();
    }
} catch (Exception $e) {
    $err = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>상품 노출 차이 진단</title>
<style>
    body { font-family: -apple-system, "Malgun Gothic", sans-serif; margin: 24px; color: #1f2937; background: #f9fafb; }
    h1 { font-size: 18px; }
    form { margin-bottom: 16px; }
    input[type=text] { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; width: 240px; }
    button { padding: 6px 14px; background: #0d9488; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
    table { border-collapse: collapse; width: 100%; background: #fff; font-size: 13px; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
    th, td { border: 1px solid #e5e7eb; padding: 6px 10px; text-align: left; white-space: nowrap; }
    th { background: #f3f4f6; font-weight: 600; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px; border-radius: 8px; }
    .verdict { background: #ecfeff; border: 1px solid #a5f3fc; padding: 12px; border-radius: 8px; margin: 16px 0; font-size: 13px; line-height: 1.7; }
    .scroll { overflow-x: auto; }
</style>
</head>
<body>
<h1>🔍 상품 노출 차이 진단 (store/order.php vs logistics/inventory.php) <span style="color:#6b7280;font-weight:normal">(읽기 전용)</span></h1>

<form method="get">
    검색어(상품명): <input type="text" name="q" value="<?php echo h($q); ?>">
    <button type="submit">진단</button>
</form>

<?php if ($err): ?>
<div class="err">DB 오류: <?php echo h($err); ?></div>
<?php elseif ($q === ''): ?>
<p style="color:#6b7280">검색어를 입력하세요. 예: <a href="?q=화장지">?q=화장지</a></p>
<?php else: ?>

<div class="verdict">
    <strong>판정 가이드</strong><br>
    • <code>order_php_노출여부</code>가 X 인데 <code>inventory_php_stock</code>이 0보다 크면, store/order.php 에서 안 보이는 상품입니다.<br>
    • <code>제외_사유</code> 컬럼에 원인이 표시됩니다 (비활성 상품 / batch 고아 레코드 / 유효재고 없음).
</div>

<div class="scroll"><?php echo render_table($rows); ?></div>

<?php endif; ?>
</body>
</html>
