<?php
/**
 * 재고 원장 대조 진단 도구 (읽기 전용)
 * 배경: store/order.php가 "주문 접수 즉시 재고 차감(예약)"으로 바뀌면서(대책 C)
 *       물류센터 재고(lc_inventory)가 실물과 안 맞는다는 보고 발생.
 *
 * 확인하는 것:
 *   lc_inventory.quantity_out (차감 요약값) 이
 *   lc_order_item_lots에 실제 기록된 차감 합계와 정확히 일치하는지 대조.
 *   → 여기서 어긋나면 "버그로 인한 이중차감/누락"이 실재한다는 뜻.
 *   → 어긋나지 않는다면, 체감되는 재고 차이는 버그가 아니라
 *     "아직 출고 안 된 pending/approved 주문의 예약분"이 새로 재고에서
 *     빠지기 시작한 정책 변경 때문일 가능성이 높음(§2 요약에서 구분해서 보여줌).
 *
 * 사용법: /logistics/diag_stock_reconcile.php
 * ⚠ SELECT 전용 — 데이터를 변경하지 않습니다.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';

lc_require_staff();

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
$lot_mismatches = [];
$product_summary = [];
$reserved_summary = [];

try {
    $conn = get_lc_db();

    // 1) lot 단위 대조: quantity_out(요약값) vs lc_order_item_lots 합계(원장)
    //    어긋나는 lot만 표시. 어긋나면 진짜 버그(이중차감/복원누락 등).
    $lot_mismatches = $conn->query(
        "SELECT i.id AS inventory_id, i.product_id,
                CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                i.lot_number, i.quantity_in,
                i.quantity_out AS stored_quantity_out,
                COALESCE(SUM(ol.quantity), 0) AS ledger_quantity_out,
                i.quantity_out - COALESCE(SUM(ol.quantity), 0) AS delta
         FROM lc_inventory i
         JOIN lc_products p ON i.product_id = p.id
         LEFT JOIN lc_order_item_lots ol ON ol.inventory_id = i.id
         GROUP BY i.id
         HAVING i.quantity_out - COALESCE(SUM(ol.quantity), 0) <> 0
         ORDER BY ABS(i.quantity_out - COALESCE(SUM(ol.quantity), 0)) DESC
         LIMIT 500"
    )->fetch_all(MYSQLI_ASSOC);

    // 2) 상품별 요약: 현재 재고 vs "아직 출고 안 된(pending/approved) 주문 예약분"
    //    이 예약분만큼 시스템 재고가 실물보다 낮게 보이는 게 정상(버그 아님)일 수 있음.
    $reserved_summary = $conn->query(
        "SELECT p.id AS product_id,
                CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                COALESCE((SELECT SUM(i.quantity_remain) FROM lc_inventory i
                          WHERE i.product_id = p.id AND i.quantity_remain <> 0), 0) AS system_stock,
                COALESCE((SELECT SUM(ol.quantity)
                          FROM lc_order_item_lots ol
                          JOIN lc_order_items oi ON ol.order_item_id = oi.id
                          JOIN lc_orders o ON oi.order_id = o.id
                          WHERE oi.product_id = p.id AND o.status IN ('pending','approved')), 0) AS reserved_not_shipped
         FROM lc_products p
         HAVING reserved_not_shipped <> 0
         ORDER BY reserved_not_shipped DESC
         LIMIT 200"
    )->fetch_all(MYSQLI_ASSOC);

    $conn->close();
} catch (Exception $e) {
    $err = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>재고 원장 대조 진단</title>
<style>
    body { font-family: -apple-system, "Malgun Gothic", sans-serif; margin: 24px; color: #1f2937; background: #f9fafb; }
    h1 { font-size: 18px; }
    h2 { font-size: 15px; margin-top: 28px; }
    table { border-collapse: collapse; width: 100%; background: #fff; font-size: 13px; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
    th, td { border: 1px solid #e5e7eb; padding: 6px 10px; text-align: left; white-space: nowrap; }
    th { background: #f3f4f6; font-weight: 600; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px; border-radius: 8px; }
    .verdict { background: #ecfeff; border: 1px solid #a5f3fc; padding: 12px; border-radius: 8px; margin: 16px 0; font-size: 13px; line-height: 1.7; }
    .scroll { overflow-x: auto; }
</style>
</head>
<body>
<h1>🔍 재고 원장 대조 진단 (읽기 전용)</h1>

<?php if ($err): ?>
<div class="err">DB 오류: <?php echo h($err); ?></div>
<?php else: ?>

<div class="verdict">
    <strong>판정 가이드</strong><br>
    • <strong>§1 lot 불일치</strong>에 행이 있으면 → <code>quantity_out</code>(요약값)과 실제 차감 원장이 어긋난 것 = <strong>버그로 인한 재고 오차</strong>. delta가 양수면 실제보다 더 차감된 것(과다차감), 음수면 덜 차감된 것.<br>
    • <strong>§1이 비어있고 §2에만 값이 있으면</strong> → 원장 자체는 정확함. 체감되는 재고 차이는 <strong>"주문 접수 즉시 차감" 정책으로 아직 출고 안 된 pending/approved 주문 예약분이 재고에서 미리 빠진 것</strong> — 버그가 아니라 정책 변경의 정상 결과.
</div>

<h2>§1. Lot 단위 불일치 (quantity_out ≠ lc_order_item_lots 합계) — <?php echo count($lot_mismatches); ?>건</h2>
<div class="scroll"><?php echo render_table($lot_mismatches); ?></div>

<h2>§2. 상품별 "아직 출고 안 된 예약분" (pending/approved 상태 주문에 이미 차감된 수량) — <?php echo count($reserved_summary); ?>건</h2>
<div class="scroll"><?php echo render_table($reserved_summary); ?></div>

<?php endif; ?>
</body>
</html>
