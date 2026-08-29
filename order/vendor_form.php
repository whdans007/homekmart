<?php
require_once __DIR__ . '/partials/header.php';
ord_require_admin();

$id = (int)($_GET['id'] ?? 0);
$vendor = null;
$conn = get_ord_db();

if ($id) {
    $stmt = $conn->prepare("SELECT * FROM order_vendors WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $vendor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$vendor) { ord_set_flash('error', '업체를 찾을 수 없습니다.'); header('Location: ' . ORD_BASE . '/vendors.php'); exit; }
}
$page_title = $vendor ? '업체 수정' : '업체 등록';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ord_verify_csrf();
    $name     = trim($_POST['name'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $contact  = trim($_POST['contact_info'] ?? '');
    $active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        ord_set_flash('error', '업체명은 필수입니다.');
    } else {
        if ($id) {
            $stmt = $conn->prepare("UPDATE order_vendors SET name=?, description=?, contact_info=?, is_active=? WHERE id=?");
            $stmt->bind_param('sssii', $name, $desc, $contact, $active, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO order_vendors (name, description, contact_info, is_active) VALUES (?,?,?,?)");
            $stmt->bind_param('sssi', $name, $desc, $contact, $active);
        }
        $stmt->execute();
        $stmt->close();
        $conn->close();
        ord_set_flash('success', $id ? '업체가 수정되었습니다.' : '업체가 등록되었습니다.');
        header('Location: ' . ORD_BASE . '/vendors.php');
        exit;
    }
}
$conn->close();
?>

<div class="max-w-lg">
    <div class="flex items-center mb-6">
        <a href="<?php echo ORD_BASE; ?>/vendors.php" class="text-indigo-600 hover:text-indigo-800"><i class="fas fa-arrow-left"></i></a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ord_csrf_token()); ?>">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">업체명 <span class="text-red-500">*</span></label>
                <input type="text" name="name" required
                       value="<?php echo htmlspecialchars($vendor['name'] ?? $_POST['name'] ?? ''); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">연락처</label>
                <input type="text" name="contact_info"
                       value="<?php echo htmlspecialchars($vendor['contact_info'] ?? $_POST['contact_info'] ?? ''); ?>"
                       placeholder="이메일 또는 전화번호"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">설명</label>
                <textarea name="description" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 text-sm"><?php echo htmlspecialchars($vendor['description'] ?? $_POST['description'] ?? ''); ?></textarea>
            </div>

            <div class="flex items-center">
                <input type="checkbox" name="is_active" id="is_active" value="1"
                       <?php echo (!$vendor || $vendor['is_active']) ? 'checked' : ''; ?>
                       class="h-4 w-4 text-indigo-600 border-gray-300 rounded">
                <label for="is_active" class="ml-2 text-sm text-gray-700">활성 상태</label>
            </div>

            <div class="flex space-x-3 pt-2">
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    <?php echo $id ? '수정 저장' : '등록'; ?>
                </button>
                <a href="<?php echo ORD_BASE; ?>/vendors.php" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition-colors">취소</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
