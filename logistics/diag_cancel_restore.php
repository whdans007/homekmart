<?php
/**
 * [테스트·비파괴] 주문 취소 시 재고 복원 검증
 * lc_restore_order_stock()을 트랜잭션 안에서 실제로 실행해 재고가 되돌아오는지 측정한 뒤
 * 반드시 ROLLBACK 한다 → DB 데이터는 전혀 변경되지 않는다.
 *
 * 사용법: /logistics/diag_cancel_restore.php?order_id=170
 * (주의: .htaccess가 test/debug/check 로 시작하는 php를 차단하므로 파일명은 diag_ 접두어 유지)
 * ⚠ 실제 취소가 아님. 복원 로직의 효과만 시뮬레이션한다.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';

lc_require_staff();

$order_id = (int)($_GET['order_id'] ?? 0);

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES); }

/** 상품 재고 스냅샷 (product_id => remain) */
function stock_snapshot(mysqli $conn, array $product_ids): array {
    if (!$product_ids) return [];
    $csv = implode(',', array_map('intval', $product_ids));
    $rows = $conn->query(
        "SELECT product_id, COALESCE(SUM(quantity_remain),0) AS remain
         FROM lc_inventory WHERE product_id IN ($csv) GROUP BY product_id"
    )->fetch_all(MYSQLI_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['product_id']] = (int)$r['remain'];
    return $out;
}

$err = null; $order = null; $items = []; $before = []; $after = []; $lot_total = [];
try {
    $conn = get_lc_db();

    // 주문 헤더
    $st = $conn->prepare("SELECT id, status, store_id, shipped_at, delivered_at FROM lc_orders WHERE id = ?");
    $st->bind_param('i', $order_id); $st->execute();
    $order = $st->get_result()->fetch_assoc(); $st->close();

    if (!$order) {
        $err = "주문 #$order_id 를 찾을 수 없습니다.";
    } else {
        // 주문 품목 + product_id
        $items = $conn->query(
            "SELECT oi.id AS order_item_id, oi.product_id, oi.quantity, oi.order_unit,
                    p.name_en,
                    (SELECT COALESCE(SUM(l.quantity),0) FROM lc_order_item_lots l WHERE l.order_item_id = oi.id) AS deducted_qty
             FROM lc_order_items oi
             JOIN lc_products p ON oi.product_id = p.id
             WHERE oi.order_id = " . (int)$order_id
        )->fetch_all(MYSQLI_ASSOC);

        $product_ids = array_values(array_unique(array_map(fn($i) => (int)$i['product_id'], $items)));

        // 복원 전 재고
        $before = stock_snapshot($conn, $product_ids);

        // ── 트랜잭션: 실제 복원 실행 후 롤백 ──────────────────────────
        $conn->begin_transaction();
        try {
            lc_restore_order_stock($conn, (int)$order_id);   // quantity_out 되돌림 + lot 삭제
            $after = stock_snapshot($conn, $product_ids);    // 복원 후(커밋 전) 재고
        } finally {
            $conn->rollback();                               // ← 원상복구, DB 변경 없음
        }
    }
    if (isset($conn)) $conn->close();
} catch (Exception $e) {
    $err = $e->getMessage();
    if (isset($conn)) { try { $conn->rollback(); } catch (Throwable $t) {} }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>취소→재고복원 테스트 · 주문 <?php echo h($order_id); ?></title>
<style>
    body { font-family: -apple-system, "Malgun Gothic", sans-serif; margin: 24px; color: #1f2937; background: #f9fafb; }
    h1 { font-size: 18px; } h2 { font-size: 14px; margin: 20px 0 8px; color: #0f766e; }
    form { margin-bottom: 16px; }
    input[type=text] { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; width: 120px; }
    button { padding: 6px 14px; background: #0d9488; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
    table { border-collapse: collapse; width: 100%; background: #fff; font-size: 13px; }
    th, td { border: 1px solid #e5e7eb; padding: 6px 10px; text-align: left; white-space: nowrap; }
    th { background: #f3f4f6; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .up { color: #047857; font-weight: 700; }
    .same { color: #9ca3af; }
    .err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px; border-radius: 8px; }
    .box { background: #ecfeff; border: 1px solid #a5f3fc; padding: 12px; border-radius: 8px; margin: 12px 0; font-size: 13px; line-height: 1.7; }
    .warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 12px; border-radius: 8px; margin: 12px 0; font-size: 13px; }
</style>
</head>
<body>
<h1>🧪 주문 취소 → 재고 복원 테스트 <span style="color:#6b7280;font-weight:normal">(비파괴 · 자동 롤백)</span></h1>

<form method="get">
    주문번호: <input type="text" name="order_id" value="<?php echo h($order_id); ?>">
    <button type="submit">테스트 실행</button>
</form>

<div class="warn">
    이 페이지는 <code>lc_restore_order_stock()</code>을 트랜잭션 안에서 실행한 뒤 <strong>즉시 ROLLBACK</strong> 합니다.
    실제 주문 상태·재고는 <strong>변경되지 않습니다.</strong> "취소하면 재고가 돌아오는가"의 효과만 미리 봅니다.
</div>

<?php if ($err): ?>
<div class="err"><?php echo h($err); ?></div>
<?php elseif ($order): ?>

<div class="box">
    <strong>주문 #<?php echo h($order['id']); ?></strong> ·
    상태: <code><?php echo h($order['status']); ?></code> ·
    출고일: <?php echo h($order['shipped_at'] ?: '-'); ?> ·
    배송일: <?php echo h($order['delivered_at'] ?: '-'); ?><br>
    <?php if (in_array($order['status'], ['shipped','delivered'], true)): ?>
    ⚠ 이 주문은 <b><?php echo h($order['status']); ?></b> 상태라 UI의 취소 버튼으로는 <b>직접 취소가 불가</b>합니다.
    (delivered는 <code>revert_delivery</code>→approved 후 <code>cancel</code> 2단계 필요)<br>
    아래는 그 2단계를 거쳐 <code>cancel</code>에 도달했을 때 복원되는 재고량과 동일합니다.
    <?php endif; ?>
</div>

<h2>품목별 차감 내역 (lc_order_item_lots)</h2>
<table>
    <thead><tr><th>order_item_id</th><th>product_id</th><th>상품</th><th class="num">주문수량</th><th>단위</th><th class="num">차감기록(lot합)</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
        <tr>
            <td><?php echo h($it['order_item_id']); ?></td>
            <td><?php echo h($it['product_id']); ?></td>
            <td><?php echo h($it['name_en']); ?></td>
            <td class="num"><?php echo h($it['quantity']); ?></td>
            <td><?php echo h($it['order_unit']); ?></td>
            <td class="num"><?php echo h($it['deducted_qty']); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>재고 복원 결과 (product 단위 quantity_remain)</h2>
<table>
    <thead><tr><th>product_id</th><th class="num">복원 전</th><th class="num">복원 후</th><th class="num">증가분</th><th>판정</th></tr></thead>
    <tbody>
    <?php foreach ($before as $pid => $b):
        $a = $after[$pid] ?? $b; $delta = $a - $b; ?>
        <tr>
            <td><?php echo h($pid); ?></td>
            <td class="num"><?php echo h($b); ?></td>
            <td class="num"><?php echo h($a); ?></td>
            <td class="num <?php echo $delta > 0 ? 'up' : 'same'; ?>"><?php echo ($delta >= 0 ? '+' : '') . h($delta); ?></td>
            <td><?php echo $delta > 0 ? '✅ 복원됨' : ($delta === 0 ? '변화 없음 (차감기록 없음/이미 복원)' : '⚠ 감소'); ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$before): ?>
        <tr><td colspan="5" style="color:#9ca3af">해당 주문에 연결된 상품 재고가 없습니다.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<div class="box" style="margin-top:16px">
    <strong>해석</strong><br>
    • 증가분이 주문수량만큼 <b>+되면 복원 로직은 정상</b> — 취소 경로에만 도달하면 재고가 정확히 돌아옵니다.<br>
    • 증가분이 0이면 <code>lc_order_item_lots</code> 차감기록이 없는 것(pending 등 미차감 주문).<br>
    • DB는 롤백되었으므로 실제 재고는 그대로입니다.
</div>

<?php endif; ?>
</body>
</html>
