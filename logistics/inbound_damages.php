<?php
require_once __DIR__ . '/lib/auth.php';
// Design Ref: docs/02-design/features/inbound-damage-registration.design.md §4
$page_title = t('logistics.inbound_damages.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inbound_helper.php';

lc_require_staff();

$filters = [
    'search'    => trim($_GET['search'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to'   => trim($_GET['date_to'] ?? ''),
];
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;

$result = getFilteredInboundDamages($filters, $page, $limit);

$items       = $result['items'] ?? [];
$total       = $result['total'] ?? 0;
$total_pages = $result['total_pages'] ?? 1;
$summary     = $result['summary'] ?? ['count' => 0, 'total_cost_loss' => 0];
$db_error    = $result['error'] ?? null;
?>

<div class="flex items-center justify-between mb-4">
<h1 class="text-lg font-bold text-gray-900"><i class="fas fa-triangle-exclamation mr-2 text-red-500"></i><?php echo htmlspecialchars(t('logistics.inbound_damages.title')); ?></h1>
</div>

<!-- 요약 카드 -->
<div class="grid grid-cols-2 gap-3 mb-4" style="max-width:36rem">
    <div class="bg-white rounded-xl border border-gray-200 p-4">
<p class="text-xs text-gray-400 mb-1"><?php echo htmlspecialchars(t('logistics.inbound_damages.total_damage_records')); ?></p>
        <p class="text-xl font-bold text-gray-900"><?php echo number_format($summary['count']); ?></p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
<p class="text-xs text-gray-400 mb-1"><?php echo htmlspecialchars(t('logistics.inbound_damages.total_loss')); ?></p>
        <p class="text-xl font-bold text-red-600"><?php echo number_format($summary['total_cost_loss'], 2); ?></p>
    </div>
</div>

<!-- 필터 -->
<form method="get" class="bg-white rounded-lg border border-gray-200 px-3 py-2 mb-4">
    <div class="flex flex-wrap items-center gap-2">
        <input type="text" name="search" value="<?php echo htmlspecialchars($filters['search']); ?>"
               placeholder="<?php echo htmlspecialchars(t('logistics.inbound_damages.search_placeholder')); ?>"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 w-64">
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700 font-medium transition-colors">
            <i class="fas fa-search mr-1"></i><?php echo htmlspecialchars(t('logistics.inbound_damages.search')); ?>
        </button>
        <a href="<?php echo LC_BASE; ?>/inbound_damages.php" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-sm rounded-md hover:bg-gray-200 font-medium transition-colors">
            <i class="fas fa-redo mr-1"></i><?php echo htmlspecialchars(t('logistics.inbound_damages.reset')); ?>
        </a>
    </div>
</form>

<?php if ($db_error): ?>
<div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-4 text-sm text-red-700">
    <i class="fas fa-exclamation-circle mr-1"></i><?php echo htmlspecialchars($db_error); ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
    <div class="px-4 py-3 border-b border-gray-100 text-sm text-gray-500"><?php echo htmlspecialchars(t('logistics.inbound_damages.total_records', ['count' => number_format($total)])); ?></div>
    <?php if (empty($items)): ?>
    <div class="p-10 text-center text-gray-400">
        <i class="fas fa-box-open text-4xl mb-3 block"></i>
        <p><?php echo htmlspecialchars(t('logistics.inbound_damages.empty')); ?></p>
    </div>
    <?php else: ?>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium w-28"><?php echo htmlspecialchars(t('logistics.inbound_damages.inbound_date')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium w-32"><?php echo htmlspecialchars(t('logistics.inbound_damages.supplier')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.inbound_damages.product')); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-28"><?php echo htmlspecialchars(t('logistics.inbound_damages.damaged_quantity')); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-28"><?php echo htmlspecialchars(t('logistics.inbound_damages.loss_amount')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.inbound_damages.reason')); ?></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach ($items as $row): ?>
            <tr class="hover:bg-red-50 transition-colors">
                <td class="px-4 py-3 text-gray-500"><?php echo $row['inbound_date'] ? date('Y-m-d', strtotime($row['inbound_date'])) : '-'; ?></td>
                <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                <td class="px-4 py-3">
                    <div class="text-gray-900 font-medium"><?php echo htmlspecialchars($row['name_en']); ?></div>
                    <?php if (!empty($row['name_ko'])): ?>
                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($row['name_ko']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right font-semibold text-gray-700">
                    <?php echo number_format($row['quantity']); ?> <span class="text-xs text-gray-400"><?php echo htmlspecialchars($row['unit']); ?></span>
                </td>
                <td class="px-4 py-3 text-right font-semibold text-red-600"><?php echo number_format($row['cost_loss'], 2); ?></td>
                <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($row['reason']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="flex items-center justify-center gap-1 mb-4">
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
    <a href="<?php echo LC_BASE; ?>/inbound_damages.php?<?php echo http_build_query(array_merge($filters, ['page' => $p])); ?>"
       class="px-3 py-1.5 rounded-md text-sm font-medium <?php echo $p === $page ? 'bg-teal-600 text-white' : 'bg-white text-gray-600 border border-gray-300 hover:bg-teal-50'; ?>">
        <?php echo $p; ?>
    </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
