<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '출고 수정 - ' . t('company.name');
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
$outbound_id = (int)($_GET['id'] ?? 0);

if (!$outbound_id) {
    header('Location: logistics_outbound.php');
    exit;
}

// 기존 데이터 조회
$tbl_check = $conn->query("SHOW TABLES LIKE 'logistics_outbound'");
if (!$tbl_check || $tbl_check->num_rows === 0) {
    header('Location: logistics_outbound.php');
    exit;
}

$row_stmt = $conn->prepare("SELECT * FROM logistics_outbound WHERE outbound_id = ?");
$row_stmt->bind_param('i', $outbound_id);
$row_stmt->execute();
$outbound = $row_stmt->get_result()->fetch_assoc();

if (!$outbound) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '존재하지 않는 출고 내역입니다.'];
    header('Location: logistics_outbound.php');
    exit;
}

// 지점 목록
$stores_res = $conn->query("SELECT id, name FROM stores WHERE name != 'WHEREHOUSE (물류센터)' ORDER BY name ASC");
$stores = $stores_res ? $stores_res->fetch_all(MYSQLI_ASSOC) : [];

// 상품 목록
$products_res = $conn->query("SELECT id, name_ko, sku FROM products WHERE status = 'active' OR status IS NULL ORDER BY name_ko ASC LIMIT 500");
$products = $products_res ? $products_res->fetch_all(MYSQLI_ASSOC) : [];

$status_labels = [
    'pending'   => '대기',
    'approved'  => '승인',
    'delivered' => '출고완료',
    'cancelled' => '취소',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $outbound_date = trim($_POST['outbound_date'] ?? '');
    $dest_store_id = (int)($_POST['dest_store_id'] ?? 0);
    $product_id    = (int)($_POST['product_id'] ?? 0);
    $quantity      = (int)($_POST['quantity'] ?? 0);
    $box_quantity  = (int)($_POST['box_quantity'] ?? 0);
    $unit_price    = (float)($_POST['unit_price'] ?? 0);
    $status        = $_POST['status'] ?? 'pending';
    $notes         = trim($_POST['notes'] ?? '');
    $total_amount  = $unit_price * $quantity;

    if (!$outbound_date) $errors[] = '출고일을 입력해주세요.';
    if (!$dest_store_id) $errors[] = '출고 지점을 선택해주세요.';
    if (!$product_id)    $errors[] = '상품을 선택해주세요.';
    if ($quantity <= 0)  $errors[] = '수량을 입력해주세요.';
    if (!array_key_exists($status, $status_labels)) $errors[] = '유효하지 않은 상태입니다.';

    if (empty($errors)) {
        $upd = $conn->prepare(
            "UPDATE logistics_outbound SET
             outbound_date=?, dest_store_id=?, product_id=?,
             quantity=?, box_quantity=?, unit_price=?, total_amount=?,
             status=?, notes=?
             WHERE outbound_id=?"
        );
        $upd->bind_param(
            'siiiddsssi',
            $outbound_date, $dest_store_id, $product_id,
            $quantity, $box_quantity, $unit_price, $total_amount,
            $status, $notes, $outbound_id
        );
        if ($upd->execute()) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => '출고 내역이 수정되었습니다.'];
            header('Location: logistics_outbound.php');
            exit;
        } else {
            $errors[] = '수정 중 오류: ' . $conn->error;
        }
    }

    // POST 실패 시 입력값 유지
    $outbound = array_merge($outbound, $_POST);
}
?>

<div class="max-w-3xl mx-auto px-4 py-6">

    <a href="logistics_outbound.php" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i> 출고 목록으로
    </a>

    <h1 class="text-2xl font-bold text-gray-900 mb-6 flex items-center gap-2">
        <i class="fas fa-edit text-teal-600"></i> 출고 수정
        <span class="text-base text-gray-400 font-normal">#<?php echo $outbound_id; ?></span>
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
                       value="<?php echo htmlspecialchars($outbound['outbound_date']); ?>"
                       required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
            </div>

            <!-- 상태 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">상태</label>
                <select name="status" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
                    <?php foreach ($status_labels as $val => $lbl): ?>
                    <option value="<?php echo $val; ?>" <?php echo $outbound['status'] === $val ? 'selected' : ''; ?>>
                        <?php echo $lbl; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 출고 지점 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">출고 지점 <span class="text-red-500">*</span></label>
                <select name="dest_store_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
                    <option value="">-- 지점 선택 --</option>
                    <?php foreach ($stores as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo $outbound['dest_store_id'] == $s['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($s['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 상품 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">상품 <span class="text-red-500">*</span></label>
                <select name="product_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
                    <option value="">-- 상품 선택 --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $outbound['product_id'] == $p['id'] ? 'selected' : ''; ?>>
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
                       value="<?php echo htmlspecialchars($outbound['quantity']); ?>"
                       required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
            </div>

            <!-- 박스 수량 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">박스 수량</label>
                <input type="number" name="box_quantity" min="0"
                       value="<?php echo htmlspecialchars($outbound['box_quantity']); ?>"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
            </div>

            <!-- 단가 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">단가</label>
                <input type="number" name="unit_price" min="0" step="0.01"
                       value="<?php echo htmlspecialchars($outbound['unit_price']); ?>"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
            </div>

            <!-- 비고 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">비고</label>
                <input type="text" name="notes"
                       value="<?php echo htmlspecialchars($outbound['notes'] ?? ''); ?>"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
            </div>
        </div>

        <!-- 등록일 표시 -->
        <div class="text-xs text-gray-400 border-t pt-3">
            등록일시: <?php echo htmlspecialchars($outbound['created_at']); ?>
        </div>

        <div class="flex justify-end gap-3">
            <a href="logistics_outbound.php"
               class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md hover:bg-gray-200">취소</a>
            <button type="submit"
                    class="px-6 py-2 bg-teal-600 text-white text-sm font-medium rounded-md hover:bg-teal-700 shadow-sm">
                <i class="fas fa-save mr-2"></i> 수정 저장
            </button>
        </div>
    </form>
</div>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>
