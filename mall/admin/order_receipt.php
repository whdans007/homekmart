<?php
/**
 * 영수증 인쇄 전용 페이지 (고객용)
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';

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
                mp.display_name_en
         FROM mall_order_items oi
         LEFT JOIN mall_products mp ON mp.product_id = oi.product_id
         WHERE oi.order_id = ?
         ORDER BY oi.id'
    );
    $items_stmt->bind_param('i', $order_id);
    $items_stmt->execute();
    $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $items_stmt->close();
    $conn->close();
} catch (Throwable $e) {
    error_log('order_receipt.php error: ' . $e->getMessage());
    http_response_code(500);
    echo 'An error occurred while loading the receipt: ' . htmlspecialchars($e->getMessage());
    exit;
}

$has_discount = (float)$order['discount_amount'] > 0;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>영수증 - <?php echo htmlspecialchars($order['order_number']); ?></title>
    <link rel="icon" href="data:,">
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
    </style>
</head>
<body>
    <h1>영수증 — <?php echo htmlspecialchars($order['order_number']); ?></h1>
    <div class="meta">
        주문일시: <?php echo htmlspecialchars(substr($order['created_at'], 0, 16)); ?> ·
        고객: <?php echo htmlspecialchars($order['member_name']); ?> (<?php echo htmlspecialchars($order['phone'] ?? ''); ?>)
    </div>

    <table>
        <thead>
            <tr>
                <th>순번</th>
                <th>상품</th>
                <th class="num">단가</th>
                <th>수량</th>
                <th class="num">금액</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $it): ?>
            <tr<?php echo $it['is_sold_out'] ? ' class="sold-out"' : ''; ?>>
                <td class="center"><?php echo $i + 1; ?></td>
                <td>
                    <?php echo htmlspecialchars($it['product_name_snapshot']); ?>
                    <?php if ($it['is_sold_out']): ?><span class="sold-out-badge">품절</span><?php endif; ?>
                    <?php if (!empty($it['display_name_en'])): ?>
                        <span class="name-en"><?php echo htmlspecialchars($it['display_name_en']); ?></span>
                    <?php endif; ?>
                </td>
                <td class="num"><?php echo number_format((float)$it['unit_price_snapshot'], 2); ?></td>
                <td class="center"><?php echo (int)$it['quantity']; ?></td>
                <td class="num"><?php echo $it['is_sold_out'] ? number_format(0, 2) : number_format((float)$it['line_total'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span>소계</span><span><?php echo number_format((float)$order['subtotal'], 2); ?></span></div>
        <?php if ($has_discount): ?>
        <div><span>할인</span><span>-<?php echo number_format((float)$order['discount_amount'], 2); ?></span></div>
        <?php endif; ?>
        <div><span>배송비</span><span><?php echo number_format((float)$order['shipping_fee'], 2); ?></span></div>
        <div class="grand"><span>합계</span><span><?php echo number_format((float)$order['total_amount'], 2); ?></span></div>
    </div>
</body>
</html>
