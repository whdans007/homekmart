<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';
require_once __DIR__ . '/lib/print_layout.php';

lc_session_start();
lc_require_login();

$status_filter = $_GET['status'] ?? 'all';
$store_filter  = (int)($_GET['store'] ?? 0);
$statuses = ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'cancel_requested' => 'Cancel Requested', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
$store_name_filter = null;

try {
    $conn = get_lc_db();

    $conds  = [];
    $params = [];
    $types  = '';

    if (lc_is_store_user()) {
        $sid = lc_current_store_id();
        $conds[] = "o.store_id = ?";
        $params[] = $sid;
        $types   .= 'i';
    } elseif ($store_filter > 0) {
        $conds[] = "o.store_id = ?";
        $params[] = $store_filter;
        $types   .= 'i';
        // 메타 표기용 점포명 조회
        $sn = $conn->prepare("SELECT name FROM stores WHERE id = ?");
        $sn->bind_param('i', $store_filter); $sn->execute();
        $store_name_filter = $sn->get_result()->fetch_row()[0] ?? null;
        $sn->close();
    }

    if ($status_filter !== 'all') {
        $conds[] = "o.status = ?";
        $params[] = $status_filter;
        $types   .= 's';
    }

    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $sql = "SELECT o.id, o.order_date, o.status, o.total_amount, o.created_at,
                   s.name AS store_name,
                   u.full_name AS created_by_name,
                   COUNT(oi.id) AS item_count
            FROM lc_orders o
            LEFT JOIN stores s ON o.store_id = s.id
            LEFT JOIN users u ON o.created_by = u.id
            LEFT JOIN lc_order_items oi ON o.id = oi.order_id
            $where
            GROUP BY o.id
            ORDER BY o.created_at DESC";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $list = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
    $list = [];
}

$extraCss = <<<CSS
  colgroup .c-no    { width: 10%; }
  colgroup .c-date  { width: 12%; }
  colgroup .c-store { width: 16%; }
  colgroup .c-stat  { width: 14%; }
  colgroup .c-cnt   { width: 12%; }
  colgroup .c-total { width: 16%; }
  colgroup .c-by    { width: 20%; }
CSS;

lc_print_head('Order List - Print', $extraCss);

$metaItems = [
    'Printed: ' . date('Y-m-d H:i'),
    'Total: ' . number_format(count($list)) . ' record(s)',
    'Status: ' . htmlspecialchars($statuses[$status_filter] ?? $status_filter),
];
if ($store_name_filter !== null) {
    $metaItems[] = 'Store: ' . htmlspecialchars($store_name_filter);
}
lc_print_doc_header('Order List', $metaItems);

if (isset($db_error)): ?>
<p style="color:#c00;"><?php echo htmlspecialchars($db_error); ?></p>
<?php endif; ?>

<table id="srcTable">
  <colgroup>
    <col class="c-no"><col class="c-date"><col class="c-store"><col class="c-stat">
    <col class="c-cnt"><col class="c-total"><col class="c-by">
  </colgroup>
  <thead>
    <tr>
      <th>Order Number</th>
      <th>Order Date</th>
      <th>Store</th>
      <th>Status</th>
      <th>Item Count</th>
      <th>Total Amount</th>
      <th>Ordered By</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($list)): ?>
    <tr><td colspan="7" class="center">No orders found.</td></tr>
    <?php endif; ?>
    <?php foreach ($list as $o): ?>
    <tr>
      <td class="mono center">#<?php echo str_pad($o['id'], 4, '0', STR_PAD_LEFT); ?></td>
      <td><?php echo htmlspecialchars($o['order_date']); ?></td>
      <td><?php echo htmlspecialchars($o['store_name'] ?? '-'); ?></td>
      <td class="center"><?php echo htmlspecialchars(lc_status_label($o['status'])); ?></td>
      <td class="center"><?php echo number_format($o['item_count']); ?></td>
      <td class="right"><?php echo number_format($o['total_amount'], 2); ?></td>
      <td><?php echo htmlspecialchars($o['created_by_name'] ?? '-'); ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php lc_print_tail(); ?>
