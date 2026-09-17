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

lc_print_head(t('logistics.print_inbound_items.title') . ' - ' . t('logistics.print_inbound_items.print'), $extraCss);

$metaItems = [
    t('logistics.print_inbound_items.printed') . ': ' . date('Y-m-d H:i'),
    t('logistics.print_inbound_items.total') . ': ' . number_format(count($items)) . ' ' . t('logistics.print_inbound_items.items'),
];
if ($filters['search']) { $metaItems[] = t('logistics.print_inbound_items.search') . ': "' . htmlspecialchars($filters['search']) . '"'; }
if ($filters['date_from'])          { $metaItems[] = t('logistics.print_inbound_items.from') . ': ' . htmlspecialchars($filters['date_from']); }
if ($filters['date_to'])            { $metaItems[] = t('logistics.print_inbound_items.to') . ': ' . htmlspecialchars($filters['date_to']); }
lc_print_doc_header(t('logistics.print_inbound_items.title'), $metaItems);

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
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.number')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.category')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.brand')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.product_name')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.capacity')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.unit')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.pkg')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.unit_barcode')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.supplier')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.cost_price')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.quantity')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound_items.inbound_date')); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($items)): ?>
    <tr><td colspan="12" class="center"><?php echo htmlspecialchars(t('logistics.print_inbound_items.empty')); ?></td></tr>
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
