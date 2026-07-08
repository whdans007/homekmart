<?php
// Design Ref: docs/02-design/features/store-request-board.design.md §4.1
$page_title = 'New Request';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/store_request_helper.php';

$store_id = store_current_store_id();
$errors   = [];
$title    = trim($_POST['title'] ?? '');
$content  = trim($_POST['content'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    store_verify_csrf();

    if ($title === '') { $errors[] = 'Please enter a title.'; }
    if ($content === '') { $errors[] = 'Please enter the request content.'; }

    if (empty($errors)) {
        try {
            $conn = get_store_db();
            $uid  = store_current_user_id();
            $st = $conn->prepare(
                "INSERT INTO lc_store_requests (store_id, title, content, status, created_by) VALUES (?,?,?,'pending',?)"
            );
            $st->bind_param('issi', $store_id, $title, $content, $uid);
            $st->execute();
            $new_id = $conn->insert_id;
            $st->close();
            $conn->close();

            store_set_flash('success', 'Your request has been submitted.');
            header('Location: ' . STORE_BASE . '/request_detail.php?id=' . $new_id);
            exit;
        } catch (Exception $e) {
            $errors[] = 'DB Error: ' . $e->getMessage();
        }
    }
}
?>

<div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-bold text-gray-900"><i class="fas fa-comment-dots mr-2 text-teal-600"></i>New Request</h1>
        <a href="<?php echo STORE_BASE; ?>/requests.php" class="text-sm text-gray-500 hover:text-gray-700">
            <i class="fas fa-arrow-left mr-1"></i>Back to list
        </a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <?php foreach ($errors as $e): ?>
        <p class="text-sm text-red-700"><i class="fas fa-exclamation-circle mr-1"></i><?php echo htmlspecialchars($e); ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(store_csrf_token()); ?>">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
            <input type="text" name="title" required maxlength="200" value="<?php echo htmlspecialchars($title); ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Content</label>
            <textarea name="content" required rows="6"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 resize-none"><?php echo htmlspecialchars($content); ?></textarea>
        </div>

        <div class="flex justify-end gap-3">
            <a href="<?php echo STORE_BASE; ?>/requests.php"
               class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">Cancel</a>
            <button type="submit"
                    class="px-4 py-2 bg-teal-600 text-white text-sm font-semibold rounded-lg hover:bg-teal-700">
                <i class="fas fa-paper-plane mr-1"></i>Submit Request
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
