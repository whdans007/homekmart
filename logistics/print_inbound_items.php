<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/print_layout.php';
require_once __DIR__ . '/lib/inbound_helper.php';

lc_session_start();
lc_require_staff();

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? ''),
];

$result = getAllFilteredInboundItems($filters);
$items  = $result['items'] ?? [];
$db_error = $result['error'] ?? null;

$extraCss = <<<CSS
  colgroup .c-no   { width: 4%; }
  colgroup .c-cat  { width: 8%; }
  colgroup .c-brand{ width: 8%; }
  colgroup .c-name { width: 16%; }
  colgroup .c-cap  { width: 8%; }
  colgroup .c-unit { width: 6%; }
  colgroup .c-pkg  { width: 5%; }
  colgroup .c-bc   { width: 11%; }
  colgroup .c-sup  { width: 10%; }
  colgroup .c-cost { width: 8%; }
  colgroup .c-qty  { width: 8%; }
  colgroup .c-date { width: 8%; }
CSS;

lc_print_head('Inbound Items List - Print', $extraCss);

$metaItems = [
    'Printed: ' . date('Y-m-d H:i'),
    'Total: ' . number_format(count($items)) . ' item(s)',
];
if ($filters['search']) { $metaItems[] = 'Search: "' . htmlspecialchars($filters['search']) . '"'; }
if ($filters['date_from'])          { $metaItems[] = 'From: ' . htmlspecialchars($filters['date_from']); }
if ($filters['date_to'])            { $metaItems[] = 'To: ' . htmlspecialchars($filters['date_to']); }
lc_print_doc_header('Inbound Items List', $metaItems);

if ($db_error): ?>
<p style="color:#c00;"><?php echo htmlspecialchars($db_error); ?></p>
<?php endif; ?>

<table id="srcTable">
  <colgroup>
    <col class="c-no"><col class="c-cat"><col class="c-brand"><col class="c-name"><col class="c-cap">
    <col class="c-unit"><col class="c-pkg"><col class="c-bc"><col class="c-sup"><col class="c-cost">
    <col class="c-qty"><col class="c-date">
  </colgroup>
  <thead>
    <tr>
      <th>#</th>
      <th>Category</th>
      <th>Brand</th>
      <th>Product Name</th>
      <th>Capacity</th>
      <th>Unit</th>
      <th>PKG</th>
      <th>Unit Barcode</th>
      <th>Supplier</th>
      <th>Cost Price</th>
      <th>Qty</th>
      <th>Inbound Date</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($items)): ?>
    <tr><td colspan="12" class="center">No inbound items found.</td></tr>
    <?php endif; ?>
    <?php $no = 1; foreach ($items as $row): ?>
    <?php $iu = $row['inbound_unit'] ?? 'PCS'; ?>
    <tr>
      <td class="center"><?php echo $no++; ?></td>
      <td><?php echo htmlspecialchars($row['category_name'] ?: '-'); ?></td>
      <td><?php echo htmlspecialchars($row['brand_name'] ?: '-'); ?></td>
      <td><?php echo htmlspecialchars($row['product_name'] ?: '-'); ?></td>
      <td><?php echo htmlspecialchars($row['capacity'] ?: '-'); ?></td>
      <td><?php echo htmlspecialchars($row['product_unit'] ?: '-'); ?></td>
      <td class="center"><?php echo (int)($row['pieces_per_box'] ?? 1); ?></td>
      <td class="mono"><?php echo htmlspecialchars($row['barcode'] ?: '-'); ?></td>
      <td><?php echo htmlspecialchars($row['supplier_name'] ?? '-'); ?></td>
      <td class="right"><?php echo number_format((float)$row['cost_price'], 2); ?></td>
      <td class="right"><?php echo number_format((int)$row['quantity']) . ' ' . htmlspecialchars($iu); ?></td>
      <td class="center"><?php echo htmlspecialchars(date('d M Y', strtotime($row['inbound_date']))); ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php lc_print_tail(); ?>
