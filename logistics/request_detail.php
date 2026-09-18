<?php
require_once __DIR__ . '/lib/auth.php';
// Design Ref: docs/02-design/features/store-request-board.design.md §4.5
$page_title = t('logistics.request_detail.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/../lib/store_request_helper.php';

lc_require_staff();

$id   = (int)($_GET['id'] ?? 0);
$conn = get_lc_db();

$st = $conn->prepare(
    "SELECT r.*, s.name AS store_name FROM lc_store_requests r JOIN stores s ON r.store_id = s.id WHERE r.id = ?"
);
$st->bind_param('i', $id);
$st->execute();
$request = $st->get_result()->fetch_assoc();
$st->close();

if (!$request) {
    $conn->close();
    lc_set_flash('error', t('logistics.request_detail.not_found'));
    header('Location: ' . LC_BASE . '/requests.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lc_verify_csrf();
    $action = $_POST['action'] ?? '';

    // Plan SC-5: 물류센터 측 댓글(답변) 작성
    if ($action === 'comment') {
        $content = trim($_POST['content'] ?? '');
        if ($content !== '') {
            $uid  = lc_current_user_id();
            $name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
            $st = $conn->prepare(
                "INSERT INTO lc_store_request_comments (request_id, author_side, author_id, author_name, content) VALUES (?, 'logistics', ?, ?, ?)"
            );
            $st->bind_param('iiss', $id, $uid, $name, $content);
            $st->execute();
            $st->close();
        }
    // Plan SC-6: 물류센터만 상태 변경 가능
    } elseif ($action === 'status') {
        $new_status = $_POST['status'] ?? '';
        if (in_array($new_status, ['pending', 'in_progress', 'done'], true)) {
            $st = $conn->prepare("UPDATE lc_store_requests SET status = ? WHERE id = ?");
            $st->bind_param('si', $new_status, $id);
            $st->execute();
            $st->close();
            lc_set_flash('success', t('logistics.request_detail.status_updated'));
        } else {
            lc_set_flash('error', t('logistics.request_detail.invalid_status'));
        }
    }

    $conn->close();
    header('Location: ' . LC_BASE . '/request_detail.php?id=' . $id);
    exit;
}

$st = $conn->prepare("SELECT * FROM lc_store_request_comments WHERE request_id = ? ORDER BY created_at ASC");
$st->bind_param('i', $id);
$st->execute();
$comments = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$conn->close();

$statuses = ['pending' => t('logistics.request_detail.pending'), 'in_progress' => t('logistics.request_detail.in_progress'), 'done' => t('logistics.request_detail.done')];
?>

<div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-bold text-gray-900"><i class="fas fa-comment-dots mr-2 text-teal-600"></i><?php echo htmlspecialchars(t('logistics.request_detail.title')); ?></h1>
        <a href="<?php echo LC_BASE; ?>/requests.php" class="text-sm text-gray-500 hover:text-gray-700">
            <i class="fas fa-arrow-left mr-1"></i><?php echo htmlspecialchars(t('logistics.request_detail.back_to_list')); ?>
        </a>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
        <div class="flex items-center justify-between mb-2">
            <div>
                <span class="text-xs font-semibold text-gray-500"><i class="fas fa-store mr-1"></i><?php echo htmlspecialchars($request['store_name']); ?></span>
                <h2 class="text-base font-bold text-gray-900"><?php echo htmlspecialchars($request['title']); ?></h2>
            </div>
            <span class="inline-block px-2 py-1 rounded-full text-xs font-semibold <?php echo store_request_status_class($request['status']); ?>">
                <?php echo store_request_status_label($request['status']); ?>
            </span>
        </div>
        <p class="text-xs text-gray-400 mb-3"><?php echo htmlspecialchars($request['created_at']); ?></p>
        <p class="text-sm text-gray-700 whitespace-pre-wrap mb-4"><?php echo htmlspecialchars($request['content']); ?></p>

        <form method="post" class="flex items-center gap-2 pt-3 border-t border-gray-100">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
            <input type="hidden" name="action" value="status">
            <label class="text-xs font-medium text-gray-500"><?php echo htmlspecialchars(t('logistics.request_detail.change_status')); ?></label>
            <select name="status" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                <?php foreach ($statuses as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $request['status'] === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-xs font-semibold rounded-lg hover:bg-teal-700">
                <?php echo htmlspecialchars(t('logistics.request_detail.save')); ?>
            </button>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-comments mr-1"></i><?php echo htmlspecialchars(t('logistics.request_detail.replies')); ?></h3>

        <div class="space-y-3 mb-4">
            <?php if (empty($comments)): ?>
            <p class="text-sm text-gray-400"><?php echo htmlspecialchars(t('logistics.request_detail.no_replies')); ?></p>
            <?php endif; ?>
            <?php foreach ($comments as $c): ?>
            <div class="rounded-lg p-3 <?php echo $c['author_side'] === 'logistics' ? 'bg-blue-50 border border-blue-100' : 'bg-teal-50 border border-teal-100'; ?>">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-semibold <?php echo $c['author_side'] === 'logistics' ? 'text-blue-700' : 'text-teal-700'; ?>">
                        <?php echo htmlspecialchars($c['author_side'] === 'logistics' ? t('logistics.request_detail.logistics_center') : t('logistics.request_detail.store')); ?> · <?php echo htmlspecialchars($c['author_name']); ?>
                    </span>
                    <span class="text-xs text-gray-400"><?php echo htmlspecialchars($c['created_at']); ?></span>
                </div>
                <p class="text-sm text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($c['content']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <form method="post" class="flex gap-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
            <input type="hidden" name="action" value="comment">
            <textarea name="content" required rows="2" placeholder="<?php echo htmlspecialchars(t('logistics.request_detail.write_reply')); ?>"
                      class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 resize-none"></textarea>
            <button type="submit"
                    class="px-4 py-2 bg-teal-600 text-white text-sm font-semibold rounded-lg hover:bg-teal-700 self-end">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
