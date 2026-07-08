<?php
// Design Ref: docs/02-design/features/store-request-board.design.md §4.3
$page_title = 'Request Detail';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/store_request_helper.php';

$store_id = store_current_store_id();
$id       = (int)($_GET['id'] ?? 0);

$conn    = get_store_db();
$request = get_owned_store_request($conn, $id, $store_id);

if (!$request) {
    $conn->close();
    store_set_flash('error', 'Request not found.');
    header('Location: ' . STORE_BASE . '/requests.php');
    exit;
}

// Plan SC-5: 점포 측 댓글(답변) 작성
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comment') {
    store_verify_csrf();
    $content = trim($_POST['content'] ?? '');
    if ($content !== '') {
        $uid  = store_current_user_id();
        $name = store_current_name();
        $st = $conn->prepare(
            "INSERT INTO lc_store_request_comments (request_id, author_side, author_id, author_name, content) VALUES (?, 'store', ?, ?, ?)"
        );
        $st->bind_param('iiss', $id, $uid, $name, $content);
        $st->execute();
        $st->close();
    }
    $conn->close();
    header('Location: ' . STORE_BASE . '/request_detail.php?id=' . $id);
    exit;
}

$st = $conn->prepare("SELECT * FROM lc_store_request_comments WHERE request_id = ? ORDER BY created_at ASC");
$st->bind_param('i', $id);
$st->execute();
$comments = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$conn->close();
?>

<div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-bold text-gray-900"><i class="fas fa-comment-dots mr-2 text-teal-600"></i>Request Detail</h1>
        <a href="<?php echo STORE_BASE; ?>/requests.php" class="text-sm text-gray-500 hover:text-gray-700">
            <i class="fas fa-arrow-left mr-1"></i>Back to list
        </a>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-base font-bold text-gray-900"><?php echo htmlspecialchars($request['title']); ?></h2>
            <span class="inline-block px-2 py-1 rounded-full text-xs font-semibold <?php echo store_request_status_class($request['status']); ?>">
                <?php echo store_request_status_label($request['status']); ?>
            </span>
        </div>
        <p class="text-xs text-gray-400 mb-3"><?php echo htmlspecialchars($request['created_at']); ?></p>
        <p class="text-sm text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($request['content']); ?></p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-comments mr-1"></i>Replies</h3>

        <div class="space-y-3 mb-4">
            <?php if (empty($comments)): ?>
            <p class="text-sm text-gray-400">No replies yet.</p>
            <?php endif; ?>
            <?php foreach ($comments as $c): ?>
            <div class="rounded-lg p-3 <?php echo $c['author_side'] === 'logistics' ? 'bg-blue-50 border border-blue-100' : 'bg-teal-50 border border-teal-100'; ?>">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-semibold <?php echo $c['author_side'] === 'logistics' ? 'text-blue-700' : 'text-teal-700'; ?>">
                        <?php echo $c['author_side'] === 'logistics' ? 'Logistics Center' : 'Store'; ?> · <?php echo htmlspecialchars($c['author_name']); ?>
                    </span>
                    <span class="text-xs text-gray-400"><?php echo htmlspecialchars($c['created_at']); ?></span>
                </div>
                <p class="text-sm text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($c['content']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <form method="post" class="flex gap-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(store_csrf_token()); ?>">
            <input type="hidden" name="action" value="comment">
            <textarea name="content" required rows="2" placeholder="Write a reply..."
                      class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 resize-none"></textarea>
            <button type="submit"
                    class="px-4 py-2 bg-teal-600 text-white text-sm font-semibold rounded-lg hover:bg-teal-700 self-end">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
