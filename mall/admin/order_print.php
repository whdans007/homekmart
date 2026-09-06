<?php
/**
 * 피킹슬립 인쇄 전용 페이지
 * Design Ref: mall-delivery-dispatch.design.md §5.4 — 사진/바코드/한·영 상품명/수량/단가/금액 + 합계
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../lib/fresh_order.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$order_id = (int)($_GET['id'] ?? 0);

// 이 페이지는 화면 전체가 PHP 블록 안에서 데이터에 의존하므로, 여기서 처리되지 않은 예외가 나면
// 본문을 한 글자도 출력하지 못한 채(에러 표시가 꺼진 운영 서버에서는) 완전히 빈 화면이 된다.
// 그래서 다른 mall/admin/ajax/*.php와 동일하게 try/catch로 감싸 원인을 화면에 보여준다.
try {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        'SELECT o.id, o.order_number, o.channel, o.subtotal, o.discount_amount, o.shipping_fee, o.total_amount, o.created_at,
                m.name AS member_name, m.phone
         FROM mall_orders o
         INNER JOIN mall_members m ON m.id = o.member_id
         WHERE o.id = ?'
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        $conn->close();
        http_response_code(404);
        echo 'Order not found.';
        exit;
    }

    $items_stmt = $conn->prepare(
        "SELECT oi.product_id, oi.product_name_snapshot, oi.unit_price_snapshot, oi.discount_rate_snapshot, oi.quantity, oi.line_total, oi.is_sold_out,
                p.sku AS barcode, mp.display_name_en,
                (SELECT image_path FROM mall_product_images WHERE product_id = oi.product_id ORDER BY sort_order LIMIT 1) AS image_path
         FROM mall_order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         LEFT JOIN mall_products mp ON mp.product_id = oi.product_id
         WHERE oi.order_id = ?"
    );
    $items_stmt->bind_param('i', $order_id);
    $items_stmt->execute();
    $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $items_stmt->close();
    $conn->close();

    // 신선상품 라인(mall_fresh_order_items) — 정가상품과 완전히 분리된 테이블이라 별도 조회한다.
    // Design Ref: mall-fresh-products.design.md §5.4.
    $fresh_items = mall_fresh_order_items_get_by_order($order_id);
} catch (Throwable $e) {
    error_log('order_print.php error: ' . $e->getMessage());
    http_response_code(500);
    echo 'An error occurred while loading the picking slip: ' . htmlspecialchars($e->getMessage());
    exit;
}

$total_floor = (int)floor((float)$order['total_amount']);
$cash_received = (int)ceil($total_floor / 1000) * 1000;
$change_due = $cash_received - $total_floor;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Picking Slip - <?php echo htmlspecialchars($order['order_number']); ?></title>
    <link rel="icon" href="data:,">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/JsBarcode/3.11.5/JsBarcode.all.min.js"></script>
    <style>
        body { font-family: -apple-system, "Malgun Gothic", sans-serif; margin: 0; padding: 1.5rem; color: #111; }
        h1 { font-size: 1.1rem; margin: 0 0 0.25rem; }
        .meta { font-size: 0.8rem; color: #555; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; vertical-align: middle; }
        th { background: #f3f4f6; text-align: center; }
        td.num { text-align: right; }
        td.barcode-cell { text-align: center; }
        th.qty, td.qty { background: #fde2e7; font-weight: 700; font-size: 15.5px; text-align: center; }
        .thumb { width: 48px; height: 48px; object-fit: cover; border: 1px solid #ddd; }
        .names { font-weight: 700; }
        .totals { margin-top: 1rem; width: 260px; margin-left: auto; font-size: 0.85rem; }
        .totals div { display: flex; justify-content: space-between; padding: 3px 0; }
        .totals .grand { font-weight: 700; font-size: 1rem; border-top: 1px solid #333; margin-top: 4px; padding-top: 6px; }
        .totals .cash-row { font-weight: 700; color: #b91c1c; }
        tr.sold-out td { color: #9ca3af; text-decoration: line-through; }
        tr.sold-out .sold-out-badge { display: inline-block; margin-left: 6px; padding: 2px 6px; font-size: 0.7rem; font-weight: 700; color: #b91c1c; background: #fee2e2; border-radius: 4px; text-decoration: none; }
        .signoff { width: 100%; border-collapse: collapse; margin-top: 2rem; font-size: 0.8rem; table-layout: fixed; }
        .signoff th { border: 1px solid #999; background: #f3f4f6; padding: 6px; text-align: center; font-weight: 700; }
        .signoff td { border: 1px solid #999; padding: 10px 12px; height: 90px; vertical-align: top; }
        .signoff .sig-line { text-align: right; margin-top: 40px; }
    </style>
</head>
<body>
    <h1>Picking Slip — <?php echo htmlspecialchars($order['order_number']); ?></h1>
    <div class="meta">
        Order Date: <?php echo htmlspecialchars(substr($order['created_at'], 0, 16)); ?> ·
        Recipient: <?php echo htmlspecialchars($order['member_name']); ?> (<?php echo htmlspecialchars($order['phone'] ?? ''); ?>) ·
        Channel: <?php echo $order['channel'] === 'wholesale' ? 'Wholesale' : 'Retail'; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Photo</th>
                <th>Barcode</th>
                <th>Product</th>
                <th class="qty">Qty</th>
                <th class="num">Unit Price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $it): ?>
            <tr<?php echo $it['is_sold_out'] ? ' class="sold-out"' : ''; ?>>
                <td>
                    <?php if (!empty($it['image_path'])): ?>
                        <img class="thumb" src="/mall/<?php echo htmlspecialchars($it['image_path']); ?>" alt="">
                    <?php endif; ?>
                </td>
                <td class="barcode-cell">
                    <?php if (!empty($it['barcode'])): ?>
                        <svg class="barcode" data-code="<?php echo htmlspecialchars($it['barcode']); ?>"></svg>
                    <?php else: ?>
                        <span style="color:#999;">-</span>
                    <?php endif; ?>
                </td>
                <td class="names">
                    <?php echo htmlspecialchars($it['display_name_en'] ?: $it['product_name_snapshot']); ?>
                    <?php if ($it['is_sold_out']): ?><span class="sold-out-badge">품절</span><?php endif; ?>
                </td>
                <td class="qty"><?php echo (int)$it['quantity']; ?></td>
                <td class="num"><?php echo number_format((float)$it['unit_price_snapshot'], 2); ?></td>
                <td class="num"><?php echo $it['is_sold_out'] ? number_format(0, 2) : number_format((float)$it['line_total'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($fresh_items as $fi):
                $__fi_confirmed = $fi['actual_weight_g'] !== null;
                $__fi_amount = $__fi_confirmed ? $fi['confirmed_price'] : $fi['estimated_price'];
                // 새 lang 키 추가 대신, 이 페이지가 이미 t()에 의존하는 다른 진행중인 다국어 작업과
                // 충돌하지 않도록 이 두 문구만 고정 한글 문자열로 둔다.
                $__fi_qty = $fi['sale_type_snapshot'] === 'weight'
                    ? ($__fi_confirmed ? $fi['actual_weight_g'] . 'g (확정)' : $fi['weight_g'] . 'g (예상)')
                    : (int)$fi['quantity'];
            ?>
            <tr<?php echo $fi['is_sold_out'] ? ' class="sold-out"' : ''; ?>>
                <td></td>
                <td class="barcode-cell"><span style="color:#999;">-</span></td>
                <td class="names">
                    <?php echo htmlspecialchars($fi['product_name_snapshot']); ?>
                    <?php if ($fi['is_sold_out']): ?><span class="sold-out-badge"><?php echo t('mall_admin.receipt.sold_out'); ?></span><?php endif; ?>
                </td>
                <td class="qty"><?php echo htmlspecialchars((string)$__fi_qty); ?></td>
                <td class="num"><?php echo number_format((float)$fi['unit_price_snapshot'], 2); ?></td>
                <td class="num"><?php echo $fi['is_sold_out'] ? number_format(0, 2) : number_format((float)$__fi_amount, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span><?php echo number_format((float)$order['subtotal'], 2); ?></span></div>
        <div><span>Discount</span><span>-<?php echo number_format((float)$order['discount_amount'], 2); ?></span></div>
        <div><span>Shipping Fee</span><span><?php echo number_format((float)$order['shipping_fee'], 2); ?></span></div>
        <div class="grand"><span>Grand Total</span><span><?php echo number_format((float)$order['total_amount'], 2); ?></span></div>
        <div class="cash-row"><span>잔돈준비</span><span><?php echo number_format($change_due); ?></span></div>
        <div class="cash-row"><span>합계 입금금액</span><span><?php echo number_format($cash_received); ?></span></div>
    </div>

    <table class="signoff">
        <thead>
            <tr>
                <th>Prepared By</th>
                <th>Delivery Driver</th>
                <th>Cashier</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div>Name: ________________________</div>
                    <div class="sig-line">Signature: ________________________</div>
                </td>
                <td>
                    <div>Name: ________________________</div>
                    <div class="sig-line">Signature: ________________________</div>
                </td>
                <td>
                    <div>Name: ________________________</div>
                    <div class="sig-line">Signature: ________________________</div>
                </td>
            </tr>
        </tbody>
    </table>

    <script>
        document.querySelectorAll('.barcode').forEach(function (el) {
            try {
                JsBarcode(el, el.dataset.code, { format: 'CODE128', width: 1.4, height: 32, fontSize: 10, margin: 2 });
            } catch (e) {
                el.outerHTML = '<span style="color:#999;">' + el.dataset.code + '</span>';
            }
        });
    </script>
</body>
</html>
