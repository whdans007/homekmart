<?php
/**
 * 재고 불일치 진단 도구 (읽기 전용)
 * 바코드 하나에 대해 중복 상품 / 재고 lot / 주문·차감 현황을 한 화면에 표시.
 * 증상: inventory.php 재고가 남아있지만 주문+출고가 끝나 0 이어야 하는 경우 원인 분석.
 *
 * 사용법: /kimsmall_wherehouse/diag_stock.php?barcode=4800014147090
 * ⚠ SELECT 전용 — 데이터를 변경하지 않습니다.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';

kw_require_staff();

$barcode = trim($_GET['barcode'] ?? '4800014147090');

/** 결과셋을 HTML 표로 렌더 */
function render_table(array $rows): string {
    if (!$rows) return '<p style="color:#999;padding:8px 0">결과 없음</p>';
    $cols = array_keys($rows[0]);
    $h = '<table><thead><tr>';
    foreach ($cols as $c) $h .= '<th>' . htmlspecialchars($c) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($cols as $c) {
            $v = $r[$c];
            $h .= '<td>' . htmlspecialchars((string)($v ?? '')) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table>';
}

/** prepared SELECT 실행 → 연관배열 배열 반환 */
function q(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $st = $conn->prepare($sql);
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

$err = null;
$sections = [];
try {
    $conn = get_lc_db();

    // 1) 동일 바코드로 등록된 상품 목록 (중복 여부)
    $sections['① 동일 바코드 상품 목록 (행이 2개 이상이면 중복 등록 = 유력 원인)'] = q($conn,
        "SELECT p.id, p.name_en, p.name_ko, p.is_active,
                p.barcode_unit, p.barcode_box, p.barcode_logistics, p.created_at
         FROM kw_products p
         WHERE p.barcode_unit = ? OR p.barcode_box = ? OR p.barcode_logistics = ?
         ORDER BY p.is_active DESC, p.id",
        'sss', [$barcode, $barcode, $barcode]);

    // 2) 상품별 재고 합계 + 주문 항목 수 (어느 product_id에 재고가 남고 주문이 걸렸는지)
    $sections['② 상품별 재고/주문 현황 (재고 남은 product_id ↔ 주문 걸린 product_id 비교)'] = q($conn,
        "SELECT p.id, p.name_en, p.is_active,
                (SELECT COALESCE(SUM(i.quantity_in),0)     FROM kw_inventory i WHERE i.product_id = p.id) AS total_in,
                (SELECT COALESCE(SUM(i.quantity_out),0)    FROM kw_inventory i WHERE i.product_id = p.id) AS total_out,
                (SELECT COALESCE(SUM(i.quantity_remain),0) FROM kw_inventory i WHERE i.product_id = p.id) AS stock_remain,
                (SELECT COUNT(*) FROM kw_order_items oi WHERE oi.product_id = p.id) AS order_items_cnt
         FROM kw_products p
         WHERE p.barcode_unit = ? OR p.barcode_box = ? OR p.barcode_logistics = ?
         ORDER BY p.is_active DESC, p.id",
        'sss', [$barcode, $barcode, $barcode]);

    // 3) lot 단위 상세 (재고가 어느 lot에 남아있는지: quantity_in/out)
    $sections['③ lot 상세 (재고가 남은 lot의 quantity_in / quantity_out 확인)'] = q($conn,
        "SELECT i.id AS inventory_id, i.product_id, i.unit, i.lot_number, i.expiry_date,
                i.quantity_in, i.quantity_out, i.quantity_remain, i.storage_location
         FROM kw_inventory i
         JOIN kw_products p ON i.product_id = p.id
         WHERE p.barcode_unit = ? OR p.barcode_box = ? OR p.barcode_logistics = ?
         ORDER BY i.product_id, i.expiry_date, i.id",
        'sss', [$barcode, $barcode, $barcode]);

    // 4) 걸린 주문 + 상태 + 실제 차감량 (가설 2 확인)
    $sections['④ 주문·상태·차감량 (status=pending 이면 미차감 / approved+shipped인데 deducted_qty=0 이면 차감누락)'] = q($conn,
        "SELECT o.id AS order_id, o.status, o.approved_at, o.shipped_at,
                oi.id AS order_item_id, oi.product_id, oi.quantity, oi.order_unit,
                (SELECT COALESCE(SUM(l.quantity),0) FROM kw_order_item_lots l WHERE l.order_item_id = oi.id) AS deducted_qty
         FROM kw_order_items oi
         JOIN kw_orders o    ON oi.order_id = o.id
         JOIN kw_products p  ON oi.product_id = p.id
         WHERE (p.barcode_unit = ? OR p.barcode_box = ? OR p.barcode_logistics = ?)
           AND o.deleted_at IS NULL
         ORDER BY o.id DESC, oi.id",
        'sss', [$barcode, $barcode, $barcode]);

    // 5) 입고 원본 내역 (중복/과다 입고 확인: 같은 수량·원가가 날짜만 다르게 2건이면 중복 의심)
    $sections['⑤ 입고 원본 (kw_inbound) — 58 BOX가 2건이면 중복/오등록 의심, 날짜·공급처·원가 비교'] = q($conn,
        "SELECT ib.id AS inbound_id, ib.inbound_date, ib.lot_number, ib.expiry_date,
                ib.quantity AS inbound_qty, ib.cost_price, ib.supplier_id, ib.created_by,
                ib.created_at, ib.notes,
                inv.id AS inventory_id, inv.quantity_in, inv.quantity_out, inv.quantity_remain
         FROM kw_inbound ib
         JOIN kw_products p    ON ib.product_id = p.id
         LEFT JOIN kw_inventory inv ON inv.inbound_id = ib.id
         WHERE p.barcode_unit = ? OR p.barcode_box = ? OR p.barcode_logistics = ?
         ORDER BY ib.inbound_date, ib.id",
        'sss', [$barcode, $barcode, $barcode]);

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
<title>재고 진단 · <?php echo htmlspecialchars($barcode); ?></title>
<style>
    body { font-family: -apple-system, "Malgun Gothic", sans-serif; margin: 24px; color: #1f2937; background: #f9fafb; }
    h1 { font-size: 18px; }
    h2 { font-size: 14px; margin: 24px 0 8px; color: #0f766e; }
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
<h1>🔍 재고 불일치 진단 <span style="color:#6b7280;font-weight:normal">(읽기 전용)</span></h1>

<form method="get">
    바코드: <input type="text" name="barcode" value="<?php echo htmlspecialchars($barcode); ?>">
    <button type="submit">진단</button>
</form>

<?php if ($err): ?>
<div class="err">DB 오류: <?php echo htmlspecialchars($err); ?></div>
<?php else: ?>

<div class="verdict">
    <strong>판정 가이드</strong><br>
    • ①에서 상품 행이 <b>2개 이상</b> → 중복 바코드. ②에서 재고 남은 <code>product_id</code>와 주문 걸린 <code>product_id</code>가 다르면 원인 확정.<br>
    • ④에서 <code>status=pending</code> → 아직 미승인이라 재고 미차감 (승인 시 차감).<br>
    • ④에서 <code>status</code>가 approved/shipped/delivered인데 <code>deducted_qty=0</code> → 승인 없이 상태만 넘어간 <b>차감 누락</b>.
</div>

<?php foreach ($sections as $title => $rows): ?>
    <h2><?php echo htmlspecialchars($title); ?></h2>
    <div class="scroll"><?php echo render_table($rows); ?></div>
<?php endforeach; ?>

<?php endif; ?>
</body>
</html>
