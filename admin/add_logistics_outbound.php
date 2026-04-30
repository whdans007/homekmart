<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '출고 등록 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

$is_logistics = is_logistics_department();
if (!$is_logistics && !has_permission('logistics_outbound_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: index.php');
    exit;
}

$conn = get_db_connection();
$errors = [];
$success = false;

// 지점 목록 (물류센터 제외)
$stores_res = $conn->query("SELECT id, name FROM stores WHERE name != 'WHEREHOUSE (물류센터)' ORDER BY name ASC");
$stores = $stores_res ? $stores_res->fetch_all(MYSQLI_ASSOC) : [];

// 상품 목록 (검색용 AJAX 대신 select2 방식 – 간소화)
$products_res = $conn->query("SELECT id, name_ko, sku FROM products WHERE status = 'active' OR status IS NULL ORDER BY name_ko ASC LIMIT 500");
$products = $products_res ? $products_res->fetch_all(MYSQLI_ASSOC) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $outbound_date  = trim($_POST['outbound_date'] ?? '');
    $dest_store_id  = (int)($_POST['dest_store_id'] ?? 0);
    $product_id     = (int)($_POST['product_id'] ?? 0);
    $quantity       = (int)($_POST['quantity'] ?? 0);
    $box_quantity   = (int)($_POST['box_quantity'] ?? 0);
    $unit_price     = (float)($_POST['unit_price'] ?? 0);
    $notes          = trim($_POST['notes'] ?? '');
    $total_amount   = $unit_price * $quantity;

    if (!$outbound_date) $errors[] = '출고일을 입력해주세요.';
    if (!$dest_store_id) $errors[] = '출고 지점을 선택해주세요.';
    if (!$product_id)    $errors[] = '상품을 선택해주세요.';
    if ($quantity <= 0)  $errors[] = '수량을 입력해주세요.';

    if (empty($errors)) {
        // 테이블 존재 확인
        $tbl_check = $conn->query("SHOW TABLES LIKE 'logistics_outbound'");
        if (!$tbl_check || $tbl_check->num_rows === 0) {
            $errors[] = 'logistics_outbound 테이블이 없습니다. SQL 마이그레이션을 먼저 실행해 주세요.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO logistics_outbound
                 (outbound_date, dest_store_id, product_id, quantity, box_quantity,
                  unit_price, total_amount, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
            );
            $stmt->bind_param(
                'siiiddssi',
                $outbound_date, $dest_store_id, $product_id,
                $quantity, $box_quantity, $unit_price, $total_amount,
                $notes, $_SESSION['user_id']
            );
            if ($stmt->execute()) {
                $_SESSION['flash'] = ['type' => 'success', 'message' => '출고가 등록되었습니다.'];
                header('Location: logistics_outbound.php');
                exit;
            } else {
                $errors[] = '등록 중 오류가 발생했습니다: ' . $conn->error;
            }
        }
    }
}
?>

<div class="max-w-3xl mx-auto px-4 py-6">

    <a href="logistics_outbound.php" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i> 출고 목록으로
    </a>

    <h1 class="text-2xl font-bold text-gray-900 mb-6 flex items-center gap-2">
        <i class="fas fa-dolly text-teal-600"></i> 출고 등록
    </h1>

    <?php if ($errors): ?>
    <div class="mb-4 bg-red-50 border border-red-200 rounded-lg p-4">
        <ul class="text-sm text-red-700 list-disc pl-5 space-y-1">
            <?php foreach ($errors as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" class="bg-white shadow rounded-lg p-6 space-y-6">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <!-- 출고일 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">출고일 <span class="text-red-500">*</span></label>
                <input type="date" name="outbound_date"
                       value="<?php echo htmlspecialchars($_POST['outbound_date'] ?? date('Y-m-d')); ?>"
                       required
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
            </div>

            <!-- 출고 지점 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">출고 지점 <span class="text-red-500">*</span></label>
                <select name="dest_store_id" required
                        class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
                    <option value="">-- 지점 선택 --</option>
                    <?php foreach ($stores as $s): ?>
                    <option value="<?php echo $s['id']; ?>"
                            <?php echo (($_POST['dest_store_id'] ?? '') == $s['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 상품 -->
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">상품 <span class="text-red-500">*</span></label>
                <select name="product_id" required
                        class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
                    <option value="">-- 상품 선택 --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?php echo $p['id']; ?>"
                            <?php echo (($_POST['product_id'] ?? '') == $p['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['name_ko']); ?>
                        <?php if ($p['sku']): ?>(<?php echo htmlspecialchars($p['sku']); ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 수량 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">출고 수량 (낱개) <span class="text-red-500">*</span></label>
                <input type="number" name="quantity" min="1"
                       value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>"
                       required
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500"
                       placeholder="0">
            </div>

            <!-- 박스 수량 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">박스 수량 <span class="text-gray-400 text-xs">(선택)</span></label>
                <input type="number" name="box_quantity" min="0"
                       value="<?php echo htmlspecialchars($_POST['box_quantity'] ?? ''); ?>"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500"
                       placeholder="0">
            </div>

            <!-- 단가 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">단가</label>
                <input type="number" name="unit_price" min="0" step="0.01"
                       value="<?php echo htmlspecialchars($_POST['unit_price'] ?? ''); ?>"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500"
                       placeholder="0.00">
            </div>

            <!-- 비고 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">비고</label>
                <input type="text" name="notes"
                       value="<?php echo htmlspecialchars($_POST['notes'] ?? ''); ?>"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500"
                       placeholder="메모 (선택)">
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
            <a href="logistics_outbound.php"
               class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">취소</a>
            <button type="submit"
                    class="px-6 py-2 bg-teal-600 text-white text-sm font-medium rounded-md hover:bg-teal-700 shadow-sm">
                <i class="fas fa-save mr-2"></i> 출고 등록
            </button>
        </div>
    </form>
</div>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>
