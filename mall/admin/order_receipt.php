<?php
/**
 * 영수증 인쇄 전용 페이지 (고객용)
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
        'SELECT oi.product_name_snapshot, oi.unit_price_snapshot, oi.quantity, oi.line_total, oi.is_sold_out,
                p.sku AS barcode, COALESCE(mp.display_name_en, p.name_en) AS display_name_en
         FROM mall_order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         LEFT JOIN mall_products mp ON mp.product_id = oi.product_id
         WHERE oi.order_id = ?
         ORDER BY oi.id'
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
    error_log('order_receipt.php error: ' . $e->getMessage());
    http_response_code(500);
    echo 'An error occurred while loading the receipt: ' . htmlspecialchars($e->getMessage());
    exit;
}

$has_discount = (float)$order['discount_amount'] > 0;
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.receipt.title'); ?> - <?php echo htmlspecialchars($order['order_number']); ?></title>
    <link rel="icon" href="data:,">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/JsBarcode/3.11.5/JsBarcode.all.min.js"></script>
    <style>
        body { font-family: -apple-system, "Malgun Gothic", sans-serif; margin: 0; padding: 1.5rem; color: #111; }
        h1 { font-size: 1.1rem; margin: 0 0 0.25rem; }
        .meta { font-size: 0.8rem; color: #555; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; vertical-align: middle; }
        th { background: #f3f4f6; text-align: center; }
        td.num, th.num { text-align: right; }
        td.center { text-align: center; }
        .totals { margin-top: 1rem; width: 260px; margin-left: auto; font-size: 0.85rem; }
        .totals div { display: flex; justify-content: space-between; padding: 3px 0; }
        .totals .grand { font-weight: 700; font-size: 1rem; border-top: 1px solid #333; margin-top: 4px; padding-top: 6px; }
        tr.sold-out td { color: #9ca3af; text-decoration: line-through; }
        tr.sold-out .sold-out-badge { display: inline-block; margin-left: 6px; padding: 2px 6px; font-size: 0.7rem; font-weight: 700; color: #b91c1c; background: #fee2e2; border-radius: 4px; text-decoration: none; }
        .name-en { display: block; font-size: 0.72rem; color: #666; }
        .barcode-cell { text-align: center; }
        .barcode { max-width: 130px; height: 32px; }
    </style>
</head>
<body>
    <h1><?php echo t('mall_admin.receipt.title'); ?> — <?php echo htmlspecialchars($order['order_number']); ?></h1>
    <div class="meta">
        <?php echo t('mall_admin.receipt.order_datetime'); ?>: <?php echo htmlspecialchars(substr($order['created_at'], 0, 16)); ?> ·
        <?php echo t('mall_admin.receipt.customer'); ?>: <?php echo htmlspecialchars($order['member_name']); ?> (<?php echo htmlspecialchars($order['phone'] ?? ''); ?>)
    </div>

    <table>
        <thead>
            <tr>
                <th><?php echo t('mall_admin.receipt.no'); ?></th>
                <th><?php echo t('mall_admin.receipt.item'); ?></th>
                <th><?php echo t('mall_admin.picking_slip.barcode'); ?></th>
                <th class="num"><?php echo t('mall_admin.receipt.unit_price'); ?></th>
                <th><?php echo t('common.quantity'); ?></th>
                <th class="num"><?php echo t('mall_admin.receipt.amount'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $it): ?>
            <tr<?php echo $it['is_sold_out'] ? ' class="sold-out"' : ''; ?>>
                <td class="center"><?php echo $i + 1; ?></td>
                <td>
                    <?php echo htmlspecialchars($it['product_name_snapshot']); ?>
                    <?php if ($it['is_sold_out']): ?><span class="sold-out-badge"><?php echo t('mall_admin.receipt.sold_out'); ?></span><?php endif; ?>
                    <?php if (!empty($it['display_name_en'])): ?>
                        <span class="name-en"><?php echo htmlspecialchars($it['display_name_en']); ?></span>
                    <?php endif; ?>
                </td>
                <td class="barcode-cell">
                    <?php if (!empty($it['barcode'])): ?><svg class="barcode" data-code="<?php echo htmlspecialchars($it['barcode']); ?>"></svg>
                    <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                </td>
                <td class="num"><?php echo number_format((float)$it['unit_price_snapshot'], 2); ?></td>
                <td class="center"><?php echo (int)$it['quantity']; ?></td>
                <td class="num"><?php echo $it['is_sold_out'] ? number_format(0, 2) : number_format((float)$it['line_total'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($fresh_items as $j => $fi):
                $__fi_confirmed = $fi['actual_weight_g'] !== null;
                $__fi_amount = $__fi_confirmed ? $fi['confirmed_price'] : $fi['estimated_price'];
                $__fi_qty = $fi['sale_type_snapshot'] === 'weight'
                    ? ($__fi_confirmed ? $fi['actual_weight_g'] . 'g (확정)' : $fi['weight_g'] . 'g (예상)')
                    : (int)$fi['quantity'];
            ?>
            <tr<?php echo $fi['is_sold_out'] ? ' class="sold-out"' : ''; ?>>
                <td class="center"><?php echo count($items) + $j + 1; ?></td>
                <td>
                    <?php echo htmlspecialchars($fi['product_name_snapshot']); ?>
                    <?php if (!empty($fi['product_name_en'])): ?><span class="name-en"><?php echo htmlspecialchars($fi['product_name_en']); ?></span><?php endif; ?>
                    <?php if ($fi['is_sold_out']): ?><span class="sold-out-badge">품절</span><?php endif; ?>
                </td>
                <td class="barcode-cell">
                    <?php if (!empty($fi['barcode'])): ?><svg class="barcode" data-code="<?php echo htmlspecialchars($fi['barcode']); ?>"></svg>
                    <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                </td>
                <td class="num"><?php echo number_format((float)$fi['unit_price_snapshot'], 2); ?></td>
                <td class="center"><?php echo htmlspecialchars((string)$__fi_qty); ?></td>
                <td class="num"><?php echo $fi['is_sold_out'] ? number_format(0, 2) : number_format((float)$__fi_amount, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span><?php echo t('mall_admin.receipt.subtotal'); ?></span><span><?php echo number_format((float)$order['subtotal'], 2); ?></span></div>
        <?php if ($has_discount): ?>
        <div><span><?php echo t('mall_admin.receipt.discount'); ?></span><span>-<?php echo number_format((float)$order['discount_amount'], 2); ?></span></div>
        <?php endif; ?>
        <div><span><?php echo t('mall_admin.receipt.shipping_fee'); ?></span><span><?php echo number_format((float)$order['shipping_fee'], 2); ?></span></div>
        <div class="grand"><span><?php echo t('mall_admin.orders.total'); ?></span><span><?php echo number_format((float)$order['total_amount'], 2); ?></span></div>
    </div>
<script>
document.querySelectorAll('.barcode').forEach(function (el) {
    try { JsBarcode(el, el.dataset.code, { format: 'CODE128', width: 1.2, height: 28, fontSize: 9, margin: 1 }); }
    catch (e) { el.outerHTML = '<span style="color:#999;">' + el.dataset.code + '</span>'; }
});
</script>
</body>
</html>
