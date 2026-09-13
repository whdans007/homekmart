<?php
/**
 * ?쇳궧?щ┰ ?몄뇙 ?꾩슜 ?섏씠吏
 * Design Ref: mall-delivery-dispatch.design.md 짠5.4 ???ъ쭊/諛붿퐫???쑣룹쁺 ?곹뭹紐??섎웾/?④?/湲덉븸 + ?⑷퀎
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

// Printing is always in English, regardless of the admin session language.
function print_en($key) {
    $value = load_translations('en');
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $key;
        $value = $value[$part];
    }
    return is_string($value) ? $value : $key;
}

// ???섏씠吏???붾㈃ ?꾩껜媛 PHP 釉붾줉 ?덉뿉???곗씠?곗뿉 ?섏〈?섎?濡? ?ш린??泥섎━?섏? ?딆? ?덉쇅媛 ?섎㈃
// 蹂몃Ц????湲?먮룄 異쒕젰?섏? 紐삵븳 梨??먮윭 ?쒖떆媛 爰쇱쭊 ?댁쁺 ?쒕쾭?먯꽌?? ?꾩쟾??鍮??붾㈃???쒕떎.
// 洹몃옒???ㅻⅨ mall/admin/ajax/*.php? ?숈씪?섍쾶 try/catch濡?媛먯떥 ?먯씤???붾㈃??蹂댁뿬以??
try {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        'SELECT o.id, o.order_number, o.channel, o.subtotal, o.discount_amount, o.shipping_fee, o.total_amount, o.created_at,
                o.ship_recipient_name, o.ship_phone, o.ship_region, o.ship_city, o.ship_barangay, o.ship_detail_address, o.ship_landmark,
                m.name AS member_name, m.english_name AS member_english_name, m.phone
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
                p.sku AS barcode, COALESCE(mp.display_name_en, p.name_en) AS product_name_en,
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

    // ?좎꽑?곹뭹 ?쇱씤(mall_fresh_order_items) ???뺢??곹뭹怨??꾩쟾??遺꾨━???뚯씠釉붿씠??蹂꾨룄 議고쉶?쒕떎.
    // Design Ref: mall-fresh-products.design.md 짠5.4.
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
$shipping_address = trim(implode(' ', array_filter([$order['ship_detail_address'] ?? '', $order['ship_barangay'] ?? '', $order['ship_city'] ?? '', $order['ship_region'] ?? ''])));
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo print_en('mall_admin.picking_slip.title'); ?> - <?php echo htmlspecialchars($order['order_number']); ?></title>
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
    <h1><?php echo print_en('mall_admin.picking_slip.title'); ?> — <?php echo htmlspecialchars($shipping_address !== '' ? $shipping_address : $order['order_number']); ?></h1>
    <div class="meta">
        <div><?php echo print_en('mall_admin.picking_slip.order_date'); ?>: <?php echo htmlspecialchars(substr($order['created_at'], 0, 16)); ?></div>
        <div><?php echo print_en('mall_admin.picking_slip.recipient'); ?>: <?php echo htmlspecialchars($order['member_name']); ?><?php if (!empty($order['member_english_name'])): ?> (<?php echo htmlspecialchars($order['member_english_name']); ?>)<?php endif; ?> (<?php echo htmlspecialchars($order['phone'] ?? ''); ?>)</div>
        <div><?php echo print_en('mall_admin.orders.channel'); ?>: <?php echo $order['channel'] === 'wholesale' ? print_en('mall_admin.orders.channel_wholesale') : print_en('mall_admin.orders.channel_retail'); ?></div>
        <?php if ($shipping_address !== ''): ?><div><?php echo htmlspecialchars(print_en('mall_admin.orders.shipping_address')); ?>: <?php echo htmlspecialchars($shipping_address); ?><?php if (!empty($order['ship_landmark'])): ?> (<?php echo htmlspecialchars($order['ship_landmark']); ?>)<?php endif; ?></div><?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th><?php echo print_en('mall_admin.picking_slip.photo'); ?></th>
                <th><?php echo print_en('mall_admin.picking_slip.barcode'); ?></th>
                <th><?php echo print_en('mall_admin.picking_slip.product'); ?></th>
                <th class="qty"><?php echo print_en('mall_admin.picking_slip.qty'); ?></th>
                <th class="num"><?php echo print_en('mall_admin.receipt.unit_price'); ?></th>
                <th class="num"><?php echo print_en('mall_admin.receipt.amount'); ?></th>
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
                    <div><?php echo htmlspecialchars($it['product_name_snapshot']); ?></div>
                    <?php if (!empty($it['product_name_en'])): ?><div class="name-en"><?php echo htmlspecialchars($it['product_name_en']); ?></div><?php endif; ?>
                    <?php if ($it['is_sold_out']): ?><span class="sold-out-badge"><?php echo print_en('mall_admin.receipt.sold_out'); ?></span><?php endif; ?>
                </td>
                <td class="qty"><?php echo (int)$it['quantity']; ?></td>
                <td class="num"><?php echo number_format((float)$it['unit_price_snapshot'], 2); ?></td>
                <td class="num"><?php echo $it['is_sold_out'] ? number_format(0, 2) : number_format((float)$it['line_total'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($fresh_items as $fi):
                $__fi_confirmed = $fi['actual_weight_g'] !== null;
                $__fi_amount = $__fi_confirmed ? $fi['confirmed_price'] : $fi['estimated_price'];
                $__fi_qty = $fi['sale_type_snapshot'] === 'weight'
                    ? ($__fi_confirmed
                        ? $fi['actual_weight_g'] . 'g ' . print_en('mall_admin.picking_slip.fresh_confirmed_suffix')
                        : $fi['weight_g'] . 'g ' . print_en('mall_admin.picking_slip.fresh_estimated_suffix'))
                    : (int)$fi['quantity'];
            ?>
            <tr<?php echo $fi['is_sold_out'] ? ' class="sold-out"' : ''; ?>>
                <td>
                    <?php if (!empty($fi['image_url'])): ?>
                        <img class="thumb" src="<?php echo htmlspecialchars($fi['image_url']); ?>" alt="">
                    <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                </td>
                <td class="barcode-cell">
                    <?php if (!empty($fi['barcode'])): ?><svg class="barcode" data-code="<?php echo htmlspecialchars($fi['barcode']); ?>"></svg>
                    <?php else: ?><span style="color:#999;">-</span><?php endif; ?>
                </td>
                <td class="names">
                    <div><?php echo htmlspecialchars($fi['product_name_snapshot']); ?></div>
                    <?php if (!empty($fi['product_name_en'])): ?><div class="name-en"><?php echo htmlspecialchars($fi['product_name_en']); ?></div><?php endif; ?>
                    <?php if ($fi['is_sold_out']): ?><span class="sold-out-badge"><?php echo print_en('mall_admin.receipt.sold_out'); ?></span><?php endif; ?>
                </td>
                <td class="qty"><?php echo htmlspecialchars((string)$__fi_qty); ?></td>
                <td class="num"><?php echo number_format((float)$fi['unit_price_snapshot'], 2); ?></td>
                <td class="num"><?php echo $fi['is_sold_out'] ? number_format(0, 2) : number_format((float)$__fi_amount, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span><?php echo print_en('mall_admin.receipt.subtotal'); ?></span><span><?php echo number_format((float)$order['subtotal'], 2); ?></span></div>
        <div><span><?php echo print_en('mall_admin.receipt.discount'); ?></span><span>-<?php echo number_format((float)$order['discount_amount'], 2); ?></span></div>
        <div><span><?php echo print_en('mall_admin.receipt.shipping_fee'); ?></span><span><?php echo number_format((float)$order['shipping_fee'], 2); ?></span></div>
        <div class="grand"><span><?php echo print_en('mall_admin.picking_slip.grand_total'); ?></span><span><?php echo number_format((float)$order['total_amount'], 2); ?></span></div>
        <div class="cash-row"><span><?php echo print_en('mall_admin.picking_slip.change_due'); ?></span><span><?php echo number_format($change_due); ?></span></div>
        <div class="cash-row"><span><?php echo print_en('mall_admin.picking_slip.cash_received'); ?></span><span><?php echo number_format($cash_received); ?></span></div>
    </div>

    <table class="signoff">
        <thead>
            <tr>
                <th><?php echo print_en('mall_admin.picking_slip.prepared_by'); ?></th>
                <th><?php echo print_en('mall_admin.picking_slip.delivery_driver'); ?></th>
                <th><?php echo print_en('mall_admin.picking_slip.cashier'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div><?php echo print_en('mall_admin.picking_slip.name'); ?>: ________________________</div>
                    <div class="sig-line"><?php echo print_en('mall_admin.picking_slip.signature'); ?>: ________________________</div>
                </td>
                <td>
                    <div><?php echo print_en('mall_admin.picking_slip.name'); ?>: ________________________</div>
                    <div class="sig-line"><?php echo print_en('mall_admin.picking_slip.signature'); ?>: ________________________</div>
                </td>
                <td>
                    <div><?php echo print_en('mall_admin.picking_slip.name'); ?>: ________________________</div>
                    <div class="sig-line"><?php echo print_en('mall_admin.picking_slip.signature'); ?>: ________________________</div>
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
