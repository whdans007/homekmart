<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/print_layout.php';

lc_session_start();
lc_require_staff();

$search_supplier = trim($_GET['supplier'] ?? '');
$search_date     = trim($_GET['date']     ?? '');

try {
    $conn = get_lc_db();

    $col_check = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lc_inbound_batches' AND COLUMN_NAME = 'is_confirmed'");
    $has_confirmed = (bool)$col_check->fetch_row()[0];

    $conds = []; $params = []; $types = '';
    if ($search_supplier) {
        $conds[] = "s.name LIKE ?";
        $params[] = "%$search_supplier%"; $types .= 's';
    }
    if ($search_date) {
        $conds[] = "b.inbound_date = ?";
        $params[] = $search_date; $types .= 's';
    }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $confirmed_col = $has_confirmed ? 'b.is_confirmed,' : '0 AS is_confirmed,';
    $sql = "SELECT b.id, b.inbound_date, b.created_at, $confirmed_col
                   s.name AS supplier_name,
                   u.full_name AS created_by_name,
                   COUNT(i.id) AS item_count,
                   COALESCE(SUM(i.quantity * i.cost_price), 0) AS total_amount
            FROM lc_inbound_batches b
            LEFT JOIN lc_suppliers s ON b.supplier_id = s.id
            LEFT JOIN users u ON b.created_by = u.id
            LEFT JOIN lc_inbound i ON i.batch_id = b.id
            $where
            GROUP BY b.id
            ORDER BY b.inbound_date DESC, b.id DESC";
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
  colgroup .c-no    { width: 6%; }
  colgroup .c-date  { width: 14%; }
  colgroup .c-sup   { width: 22%; }
  colgroup .c-cnt   { width: 12%; }
  colgroup .c-total { width: 15%; }
  colgroup .c-by    { width: 17%; }
  colgroup .c-stat  { width: 14%; }
CSS;

lc_print_head(t('logistics.print_inbound.title') . ' - ' . t('logistics.print_inbound.print'), $extraCss);

$metaItems = [
    t('logistics.print_inbound.printed') . ': ' . date('Y-m-d H:i'),
    t('logistics.print_inbound.total') . ': ' . number_format(count($list)) . ' ' . t('logistics.print_inbound.records'),
];
if ($search_supplier) { $metaItems[] = t('logistics.print_inbound.supplier') . ': "' . htmlspecialchars($search_supplier) . '"'; }
if ($search_date)     { $metaItems[] = t('logistics.print_inbound.inbound_date') . ': ' . htmlspecialchars($search_date); }
lc_print_doc_header(t('logistics.print_inbound.title'), $metaItems);

if (isset($db_error)): ?>
<p style="color:#c00;"><?php echo htmlspecialchars($db_error); ?></p>
<?php endif; ?>

<table id="srcTable">
  <colgroup>
    <col class="c-no"><col class="c-date"><col class="c-sup"><col class="c-cnt">
    <col class="c-total"><col class="c-by"><col class="c-stat">
  </colgroup>
  <thead>
    <tr>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound.number')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound.inbound_date')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound.supplier')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound.item_count')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound.total_amount')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound.registered_by')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inbound.status')); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($list)): ?>
    <tr><td colspan="7" class="center"><?php echo htmlspecialchars(t('logistics.print_inbound.empty')); ?></td></tr>
    <?php endif; ?>
    <?php $no = 1; foreach ($list as $row): ?>
    <tr>
      <td class="center"><?php echo $no++; ?></td>
      <td>
        <?php echo htmlspecialchars(date('d M Y', strtotime($row['inbound_date']))); ?>
        <div class="sub"><?php echo htmlspecialchars(date('H:i', strtotime($row['created_at']))); ?></div>
      </td>
      <td><?php echo htmlspecialchars($row['supplier_name'] ?? '-'); ?></td>
      <td class="right"><?php echo number_format($row['item_count']); ?></td>
      <td class="right"><?php echo number_format($row['total_amount'], 2); ?></td>
      <td><?php echo htmlspecialchars($row['created_by_name'] ?? '-'); ?></td>
      <td class="center"><?php echo htmlspecialchars(t($row['is_confirmed'] ? 'logistics.print_inbound.locked' : 'logistics.print_inbound.editable')); ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php lc_print_tail(); ?>
