<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';
require_once __DIR__ . '/lib/print_layout.php';

lc_session_start();
lc_require_staff();

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
                    FROM lc_order_item_lots oll2
                    JOIN lc_inventory inv2 ON oll2.inventory_id = inv2.id
                    WHERE oll2.order_item_id = oi.id
                      AND inv2.expiry_date IS NOT NULL) AS earliest_expiry,
                   (SELECT GROUP_CONCAT(
                        CONCAT(DATE_FORMAT(inv3.expiry_date, '%d %b %Y'), ' x', oll3.quantity)
                        ORDER BY inv3.expiry_date ASC SEPARATOR ' / ')
                    FROM lc_order_item_lots oll3
                    JOIN lc_inventory inv3 ON oll3.inventory_id = inv3.id
                    WHERE oll3.order_item_id = oi.id
                      AND inv3.expiry_date IS NOT NULL) AS expiry_info
            FROM lc_order_items oi
            JOIN lc_orders o ON oi.order_id = o.id
            JOIN lc_products p ON oi.product_id = p.id
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

lc_print_head(t('logistics.print_outbound.title') . ' - ' . t('logistics.print_outbound.print'), $extraCss);

$metaItems = [
    t('logistics.print_outbound.printed') . ': ' . date('Y-m-d H:i'),
    t('logistics.print_outbound.total') . ': ' . number_format(count($list)) . ' ' . t('logistics.print_outbound.records'),
];
if ($search) { $metaItems[] = t('logistics.print_outbound.search') . ': "' . htmlspecialchars($search) . '"'; }
lc_print_doc_header(t('logistics.print_outbound.title'), $metaItems);

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
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.order_number')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.outbound_datetime')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.store')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.product_code')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.product_name')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.spec')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.expiry_by_lot')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.quantity')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.unit_price')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_outbound.subtotal')); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($list)): ?>
    <tr><td colspan="10" class="center"><?php echo htmlspecialchars(t('logistics.print_outbound.empty')); ?></td></tr>
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

<?php lc_print_tail(); ?>
