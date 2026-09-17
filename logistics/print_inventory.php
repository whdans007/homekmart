<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/print_layout.php';

lc_session_start();
// Design Ref: role-permission-management - 재고 현황은 물류센터 직원/관리자만 접근
lc_require_staff();

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all | expiring | low | out

$filter_labels = ['all' => t('logistics.print_inventory.all'), 'expiring' => t('logistics.print_inventory.expiring'), 'low' => t('logistics.print_inventory.low_stock'), 'out' => t('logistics.print_inventory.out_of_stock')];

try {
    $conn = get_lc_db();

    if ($filter === 'out') {
        // 재고 0 상품 — lc_inventory에 lot이 아예 없는 상품도 노출되도록 LEFT JOIN
        $conds  = ["p.is_active = 1", "p.min_stock > 0"];
        $params = [];
        $types  = '';

        if ($search) {
            $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?)";
            $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
            $types   .= 'sssss';
        }
        $where = 'WHERE ' . implode(' AND ', $conds);

        $sql = "SELECT p.id AS product_id,
                       CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                       p.unit, p.min_stock,
                       COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                       0 AS total_stock, 0 AS lot_count, NULL AS earliest_expiry, NULL AS days_left
                FROM lc_products p
                LEFT JOIN lc_inventory i ON i.product_id = p.id AND i.quantity_remain > 0
                $where
                GROUP BY p.id
                HAVING COALESCE(SUM(i.quantity_remain), 0) <= 0
                ORDER BY p.name_en ASC";
        $st = $conn->prepare($sql);
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $list = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    } else {
        $conds  = ["i.quantity_remain > 0"];
        $params = [];
        $types  = '';

        if ($search) {
            $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?)";
            $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
            $types   .= 'sssss';
        }
        if ($filter === 'expiring') {
            $conds[] = "MIN(i.expiry_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
        } elseif ($filter === 'low') {
            $conds[] = "SUM(i.quantity_remain) <= MIN(p.min_stock) AND MIN(p.min_stock) > 0";
        }

        $where = 'WHERE ' . implode(' AND ', array_filter($conds, fn($c) => !str_starts_with($c, 'MIN(') && !str_starts_with($c, 'SUM(')));
        $having = '';
        $havingConds = array_filter($conds, fn($c) => str_starts_with($c, 'MIN(') || str_starts_with($c, 'SUM('));
        if ($havingConds) $having = 'HAVING ' . implode(' AND ', $havingConds);

        $sql = "SELECT p.id AS product_id,
                       CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                       p.unit, p.min_stock,
                       COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                       SUM(i.quantity_remain) AS total_stock,
                       COUNT(i.id)            AS lot_count,
                       MIN(i.expiry_date)     AS earliest_expiry,
                       DATEDIFF(MIN(i.expiry_date), CURDATE()) AS days_left
                FROM lc_inventory i
                JOIN lc_products p ON i.product_id = p.id
                JOIN lc_inbound ib ON i.inbound_id = ib.id
                $where
                GROUP BY p.id $having
                ORDER BY earliest_expiry ASC, p.name_en ASC";
        $st = $conn->prepare($sql);
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $list = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    }
    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
    $list = [];
}

$extraCss = <<<CSS
  colgroup .c-name  { width: 38%; }
  colgroup .c-exp   { width: 18%; }
  colgroup .c-lot   { width: 10%; }
  colgroup .c-stock { width: 18%; }
  colgroup .c-stat  { width: 16%; }
CSS;

lc_print_head(t('logistics.print_inventory.title') . ' - ' . t('logistics.print_inventory.print'), $extraCss);

$metaItems = [
    t('logistics.print_inventory.printed') . ': ' . date('Y-m-d H:i'),
    t('logistics.print_inventory.total') . ': ' . number_format(count($list)) . ' ' . t('logistics.print_inventory.records'),
    t('logistics.print_inventory.filter') . ': ' . htmlspecialchars($filter_labels[$filter] ?? $filter),
];
if ($search) { $metaItems[] = t('logistics.print_inventory.search') . ': "' . htmlspecialchars($search) . '"'; }
lc_print_doc_header(t('logistics.print_inventory.title'), $metaItems);

if (isset($db_error)): ?>
<p style="color:#c00;"><?php echo htmlspecialchars($db_error); ?></p>
<?php endif; ?>

<table id="srcTable">
  <colgroup>
    <col class="c-name"><col class="c-exp"><col class="c-lot"><col class="c-stock"><col class="c-stat">
  </colgroup>
  <thead>
    <tr>
      <th><?php echo htmlspecialchars(t('logistics.print_inventory.product_name')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inventory.earliest_expiry')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inventory.lot_count')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inventory.current_stock')); ?></th>
      <th><?php echo htmlspecialchars(t('logistics.print_inventory.status')); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($list)): ?>
    <tr><td colspan="5" class="center"><?php echo htmlspecialchars(t('logistics.print_inventory.empty')); ?></td></tr>
    <?php endif; ?>
    <?php foreach ($list as $row):
        $isOut = $row['total_stock'] <= 0;
        $isLow = !$isOut && $row['min_stock'] > 0 && $row['total_stock'] <= $row['min_stock'];
        $days  = $row['days_left'];
        $expBadge = '';
        if ($row['earliest_expiry']) {
            if ($days < 0)       { $expBadge = ' (' . t('logistics.print_inventory.expired') . ')'; }
            elseif ($days <= 30) { $expBadge = ' (D-' . $days . ')'; }
            elseif ($days <= 90) { $expBadge = ' (D-' . $days . ')'; }
        }
    ?>
    <tr>
      <td>
        <?php echo htmlspecialchars($row['product_name']); ?>
        <?php if ($row['barcode']): ?><div class="sub mono"><?php echo htmlspecialchars($row['barcode']); ?></div><?php endif; ?>
      </td>
      <td><?php echo $row['earliest_expiry'] ? htmlspecialchars($row['earliest_expiry'] . $expBadge) : '-'; ?></td>
      <td class="center"><?php echo number_format($row['lot_count']); ?></td>
      <td class="right"><?php echo number_format($row['total_stock']) . ' ' . htmlspecialchars($row['unit']); ?></td>
      <td class="center"><?php echo htmlspecialchars($isOut ? t('logistics.print_inventory.out_of_stock') : ($isLow ? t('logistics.print_inventory.low_stock') : t('logistics.print_inventory.normal'))); ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php lc_print_tail(); ?>
