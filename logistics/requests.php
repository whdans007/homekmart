<?php
require_once __DIR__ . '/lib/auth.php';
// Design Ref: docs/02-design/features/store-request-board.design.md §4.4
$page_title = t('logistics.requests.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/../lib/store_request_helper.php';

lc_require_staff();

$status_filter = $_GET['status'] ?? 'all';
$store_filter  = (int)($_GET['store'] ?? 0);

$conn = get_lc_db();

$stores = $conn->query("SELECT id, name FROM stores ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

$conds  = [];
$params = [];
$types  = '';

if (in_array($status_filter, ['pending', 'in_progress', 'done'], true)) {
    $conds[] = 'r.status = ?';
    $types  .= 's';
    $params[] = $status_filter;
}
if ($store_filter > 0) {
    $conds[] = 'r.store_id = ?';
    $types  .= 'i';
    $params[] = $store_filter;
}

$sql = "SELECT r.id, r.title, r.status, r.created_at, s.name AS store_name
        FROM lc_store_requests r
        JOIN stores s ON r.store_id = s.id";
if (!empty($conds)) {
    $sql .= ' WHERE ' . implode(' AND ', $conds);
}
$sql .= ' ORDER BY r.created_at DESC';

if (!empty($params)) {
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $requests = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} else {
    $requests = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}
$conn->close();

$tabs = ['all' => t('logistics.requests.all'), 'pending' => t('logistics.requests.pending'), 'in_progress' => t('logistics.requests.in_progress'), 'done' => t('logistics.requests.done')];
?>

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-bold text-gray-900"><i class="fas fa-comment-dots mr-2 text-teal-600"></i><?php echo htmlspecialchars(t('logistics.requests.title')); ?></h1>
</div>

<div class="flex flex-wrap items-center gap-2 mb-4">
    <?php foreach ($tabs as $key => $label): ?>
    <a href="<?php echo LC_BASE; ?>/requests.php?status=<?php echo $key; ?><?php echo $store_filter ? '&store=' . $store_filter : ''; ?>"
       class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
              <?php echo $status_filter === $key ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-teal-50 hover:border-teal-400'; ?>">
        <?php echo $label; ?>
    </a>
    <?php endforeach; ?>

    <form method="get" class="ml-auto">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
        <select name="store" onchange="this.form.submit()"
                class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            <option value="0"><?php echo htmlspecialchars(t('logistics.requests.all_stores')); ?></option>
            <?php foreach ($stores as $s): ?>
            <option value="<?php echo $s['id']; ?>" <?php echo $store_filter === (int)$s['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <?php if (empty($requests)): ?>
    <div class="p-10 text-center text-gray-400">
        <i class="fas fa-inbox text-4xl mb-3 block"></i>
        <p><?php echo htmlspecialchars(t('logistics.requests.empty')); ?></p>
    </div>
    <?php else: ?>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium w-40"><?php echo htmlspecialchars(t('logistics.requests.store')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.requests.request_title')); ?></th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium w-32"><?php echo htmlspecialchars(t('logistics.requests.status')); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-40"><?php echo htmlspecialchars(t('logistics.requests.created')); ?></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach ($requests as $r): ?>
            <tr class="hover:bg-teal-50 cursor-pointer transition-colors"
                onclick="location.href='<?php echo LC_BASE; ?>/request_detail.php?id=<?php echo $r['id']; ?>'">
                <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($r['store_name']); ?></td>
                <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($r['title']); ?></td>
                <td class="px-4 py-3 text-center">
                    <span class="inline-block px-2 py-1 rounded-full text-xs font-semibold <?php echo store_request_status_class($r['status']); ?>">
                        <?php echo htmlspecialchars($tabs[$r['status']] ?? $r['status']); ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-right text-gray-500"><?php echo htmlspecialchars($r['created_at']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
