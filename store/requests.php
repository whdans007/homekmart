<?php
// Design Ref: docs/02-design/features/store-request-board.design.md §4.2
$page_title = 'Requests';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/store_request_helper.php';

$store_id = store_current_store_id();
$status   = $_GET['status'] ?? 'all';

$requests = [];
try {
    $conn = get_store_db();

    $sql    = "SELECT id, title, status, created_at FROM lc_store_requests WHERE store_id = ?";
    $types  = 'i';
    $params = [$store_id];

    if (in_array($status, ['pending', 'in_progress', 'done'], true)) {
        $sql .= " AND status = ?";
        $types .= 's';
        $params[] = $status;
    }
    $sql .= " ORDER BY created_at DESC";

    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $requests = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $conn->close();
} catch (Exception $e) { /* 조회 실패 시 빈 목록 */ }

$tabs = ['all' => 'All', 'pending' => 'Pending', 'in_progress' => 'In Progress', 'done' => 'Done'];
?>

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-bold text-gray-900"><i class="fas fa-comment-dots mr-2 text-teal-600"></i>Requests</h1>
    <a href="<?php echo STORE_BASE; ?>/request_new.php"
       class="px-4 py-2 bg-teal-600 text-white text-sm font-semibold rounded-lg hover:bg-teal-700">
        <i class="fas fa-plus mr-1"></i>New Request
    </a>
</div>

<div class="flex flex-wrap gap-2 mb-4">
    <?php foreach ($tabs as $key => $label): ?>
    <a href="<?php echo STORE_BASE; ?>/requests.php?status=<?php echo $key; ?>"
       class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
              <?php echo $status === $key ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-teal-50 hover:border-teal-400'; ?>">
        <?php echo $label; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <?php if (empty($requests)): ?>
    <div class="p-10 text-center text-gray-400">
        <i class="fas fa-inbox text-4xl mb-3 block"></i>
        <p>No requests found.</p>
    </div>
    <?php else: ?>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Title</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium w-32">Status</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-40">Created</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php foreach ($requests as $r): ?>
            <tr class="hover:bg-teal-50 cursor-pointer transition-colors"
                onclick="location.href='<?php echo STORE_BASE; ?>/request_detail.php?id=<?php echo $r['id']; ?>'">
                <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($r['title']); ?></td>
                <td class="px-4 py-3 text-center">
                    <span class="inline-block px-2 py-1 rounded-full text-xs font-semibold <?php echo store_request_status_class($r['status']); ?>">
                        <?php echo store_request_status_label($r['status']); ?>
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
