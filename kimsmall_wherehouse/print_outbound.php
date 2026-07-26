<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';
require_once __DIR__ . '/lib/print_layout.php';

kw_session_start();
kw_require_staff();

$search = trim($_GET['search'] ?? '');

try {
    $conn = get_lc_db();

    $where  = "WHERE o.status IN ('shipped','delivered')";
    $params = [];
    $types  = '';
    if ($search) {
        $where .= " AND (p.name_en LIKE ? OR s.name LIKE ?)";
        $params = ["%$search%", "%$search%"];
        $types  = 'ss';
    }

    $sql = "SELECT o.id AS order_id, o.shipped_at, o.status,
                   s.name AS store_name,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS product_code,
                   CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name, p.capacity, p.unit,
                   oi.id AS item_id,
                   oi.quantity, oi.unit_price,
                   oi.quantity * oi.unit_price AS subtotal,
                   (SELECT MIN(inv2.expiry_date)
                    FROM kw_order_item_lots oll2
                    JOIN kw_inventory inv2 ON oll2.inventory_id = inv2.id
                    WHERE oll2.order_item_id = oi.id
                      AND inv2.expiry_date IS NOT NULL) AS earliest_expiry,
                   (SELECT GROUP_CONCAT(
                        CONCAT(DATE_FORMAT(inv3.expiry_date, '%d %b %Y'), ' x', oll3.quantity)
                        ORDER BY inv3.expiry_date ASC SEPARATOR ' / ')
                    FROM kw_order_item_lots oll3
                    JOIN kw_inventory inv3 ON oll3.inventory_id = inv3.id
                    WHERE oll3.order_item_id = oi.id
                      AND inv3.expiry_date IS NOT NULL) AS expiry_info
            FROM kw_order_items oi
            JOIN kw_orders o ON oi.order_id = o.id
            JOIN kw_products p ON oi.product_id = p.id
            JOIN stores s ON o.store_id = s.id
            $where
            ORDER BY o.shipped_at DESC, o.id DESC";
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
  colgroup .c-no    { width: 7%; }
  colgroup .c-date  { width: 11%; }
  colgroup .c-store { width: 10%; }
  colgroup .c-code  { width: 9%; }
  colgroup .c-prod  { width: 17%; }
  colgroup .c-spec  { width: 8%; }
  colgroup .c-exp   { width: 14%; }
  colgroup .c-qty   { width: 9%; }
  colgroup .c-price { width: 8%; }
  colgroup .c-sub   { width: 7%; }
CSS;

kw_print_head('Outbound History - Print', $extraCss);

$metaItems = [
    'Printed: ' . date('Y-m-d H:i'),
    'Total: ' . number_format(count($list)) . ' record(s)',
];
if ($search) { $metaItems[] = 'Search: "' . htmlspecialchars($search) . '"'; }
kw_print_doc_header('Outbound History', $metaItems);

if (isset($db_error)): ?>
<p style="color:#c00;"><?php echo htmlspecialchars($db_error); ?></p>
<?php endif; ?>

<table id="srcTable">
  <colgroup>
    <col class="c-no"><col class="c-date"><col class="c-store"><col class="c-code"><col class="c-prod"><col class="c-spec">
    <col class="c-exp"><col class="c-qty"><col class="c-price"><col class="c-sub">
  </colgroup>
  <thead>
    <tr>
      <th>Order Number</th>
      <th>Outbound Date/Time</th>
      <th>Store</th>
      <th>Product Code</th>
      <th>Product Name</th>
      <th>Spec</th>
      <th>Expiry Date (by lot)</th>
      <th>Quantity</th>
      <th>Unit Price</th>
      <th>Subtotal</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($list)): ?>
    <tr><td colspan="10" class="center">No outbound history.</td></tr>
    <?php endif; ?>
    <?php foreach ($list as $row): ?>
    <tr>
      <td class="mono center">#<?php echo str_pad($row['order_id'], 4, '0', STR_PAD_LEFT); ?></td>
      <td><?php echo $row['shipped_at'] ? htmlspecialchars($row['shipped_at']) : '-'; ?></td>
      <td><?php echo htmlspecialchars($row['store_name']); ?></td>
      <td class="mono"><?php echo $row['product_code'] ? htmlspecialchars($row['product_code']) : '-'; ?></td>
      <td><?php echo htmlspecialchars($row['product_name']); ?></td>
      <td><?php echo $row['capacity'] ? htmlspecialchars($row['capacity']) : '-'; ?></td>
      <td class="mono"><?php echo $row['expiry_info'] ? htmlspecialchars($row['expiry_info']) : '-'; ?></td>
      <td class="right"><?php echo number_format($row['quantity']) . ' ' . htmlspecialchars($row['unit']); ?></td>
      <td class="right"><?php echo number_format($row['unit_price'], 2); ?></td>
      <td class="right"><?php echo number_format($row['subtotal'], 2); ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php kw_print_tail(); ?>
