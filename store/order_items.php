<?php
$page_title = 'Ordered Items List';
require_once __DIR__ . '/partials/header.php';

$store_id = store_current_store_id();
$status   = $_GET['status'] ?? 'all';
$q        = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 30;
$offset   = ($page - 1) * $limit;

try {
    $conn = get_store_db();

    $conds  = ['o.store_id = ?'];
    $params = [$store_id];
    $types  = 'i';

    if ($status !== 'all') {
        $conds[] = 'o.status = ?';
        $params[] = $status;
        $types   .= 's';
    }
    if ($q !== '') {
        $conds[] = '(p.name_en LIKE ? OR p.name_ko LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like;
        $types   .= 'ss';
    }

    $where = 'WHERE ' . implode(' AND ', $conds);

    $st = $conn->prepare(
        "SELECT COUNT(*)
         FROM lc_order_items oi
         JOIN lc_orders o ON o.id = oi.order_id
         JOIN lc_products p ON p.id = oi.product_id
         $where"
    );
    $st->bind_param($types, ...$params); $st->execute();
    $total = (int)$st->get_result()->fetch_row()[0]; $st->close();
    $total_pages = max(1, (int)ceil($total / $limit));

    $st = $conn->prepare(
        "SELECT oi.id, oi.order_id, oi.quantity, oi.unit_price, oi.order_unit,
                o.order_date, o.status, o.created_at,
                p.name_en, p.name_ko, p.unit
         FROM lc_order_items oi
         JOIN lc_orders o ON o.id = oi.order_id
         JOIN lc_products p ON p.id = oi.product_id
         $where
         ORDER BY o.created_at DESC, oi.id DESC
         LIMIT $limit OFFSET $offset"
    );
    $st->bind_param($types, ...$params); $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

    $conn->close();
} catch (Exception $e) {
    $items = []; $total = 0; $total_pages = 1;
}

$statuses = ['all'=>'All','pending'=>'Pending','approved'=>'Approved','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'];
?>

<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-bold text-gray-900">Ordered Items List</h2>
    <a href="<?php echo STORE_BASE; ?>/order.php"
       class="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700">
        <i class="fas fa-plus mr-1"></i>New Order
    </a>
</div>

<!-- Filters -->
<form method="get" class="flex flex-wrap items-center gap-2 mb-4">
    <div class="flex gap-2 overflow-x-auto pb-1 scrollbar-none">
        <?php foreach ($statuses as $key => $label): ?>
        <a href="?status=<?php echo $key; ?>&q=<?php echo urlencode($q); ?>"
           class="flex-shrink-0 px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                  <?php echo $status === $key ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-teal-50'; ?>">
            <?php echo $label; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
    <div class="flex-1 min-w-[180px] flex gap-2 ml-auto">
        <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search product name..."
               class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-teal-400">
        <button type="submit" class="px-4 py-1.5 bg-gray-600 text-white text-sm font-medium rounded-lg hover:bg-gray-700">
            <i class="fas fa-search"></i>
        </button>
    </div>
</form>

<?php if (empty($items)): ?>
<div class="bg-white rounded-xl border border-gray-200 p-10 text-center text-gray-400">
    <i class="fas fa-box-open text-4xl mb-3 block"></i>
    <p>No ordered items found.</p>
</div>
<?php else: ?>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 text-sm text-gray-500">
        <?php echo number_format($total); ?> item<?php echo $total === 1 ? '' : 's'; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="px-3 py-3 text-left text-xs text-gray-500 font-medium">Order Date</th>
                <th class="px-3 py-3 text-left text-xs text-gray-500 font-medium">Order #</th>
                <th class="px-3 py-3 text-left text-xs text-gray-500 font-medium">Product Name</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:44px;">Qty</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:38px;">Unit</th>
                <th class="px-2 py-3 text-right text-xs text-gray-500 font-medium" style="width:76px;">Unit Price</th>
                <th class="px-3 py-3 text-right text-xs text-gray-500 font-medium" style="width:80px;">Subtotal</th>
                <th class="px-3 py-3 text-center text-xs text-gray-500 font-medium" style="width:110px;">Status</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($items as $item):
                $row_unit = !empty($item['order_unit']) ? $item['order_unit'] : ($item['unit'] ?? '-');
            ?>
            <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap"><?php echo date('d M Y', strtotime($item['order_date'])); ?></td>
                <td class="px-3 py-2 whitespace-nowrap">
                    <a href="<?php echo STORE_BASE; ?>/order_detail.php?id=<?php echo $item['order_id']; ?>"
                       class="font-mono text-xs font-bold text-teal-700 hover:underline">
                        #<?php echo str_pad($item['order_id'], 4, '0', STR_PAD_LEFT); ?>
                    </a>
                </td>
                <td class="px-3 py-2 font-medium text-gray-900" style="line-height:1.2;">
                    <div><?php echo htmlspecialchars($item['name_en']); ?></div>
                    <?php if (!empty($item['name_ko'])): ?>
                    <div class="text-xs text-gray-500" style="margin-top:1px;"><?php echo htmlspecialchars($item['name_ko']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="px-2 py-2 text-center font-bold text-teal-700"><?php echo number_format($item['quantity']); ?></td>
                <td class="px-2 py-2 text-center text-xs font-semibold text-gray-700"><?php echo htmlspecialchars($row_unit); ?></td>
                <td class="px-2 py-2 text-right text-gray-600"><?php echo number_format($item['unit_price'], 2); ?></td>
                <td class="px-3 py-2 text-right font-bold text-gray-900"><?php echo number_format($item['unit_price'] * $item['quantity'], 2); ?></td>
                <td class="px-3 py-2 text-center">
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium <?php echo store_status_class($item['status']); ?>">
                        <?php echo store_status_label($item['status']); ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($total_pages > 1): ?>
<div class="flex justify-center gap-2 mt-5">
    <?php if ($page > 1): ?>
    <a href="?status=<?php echo $status; ?>&q=<?php echo urlencode($q); ?>&page=<?php echo $page-1; ?>"
       class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Previous</a>
    <?php endif; ?>
    <span class="px-4 py-2 text-sm text-gray-500"><?php echo $page; ?> / <?php echo $total_pages; ?></span>
    <?php if ($page < $total_pages): ?>
    <a href="?status=<?php echo $status; ?>&q=<?php echo urlencode($q); ?>&page=<?php echo $page+1; ?>"
       class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Next</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
