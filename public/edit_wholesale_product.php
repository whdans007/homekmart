<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('wholesale.edit_product') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$errors = [];
$wholesale_product = null;
$product = null;
$stores = [];

// ID 확인
$wholesale_product_id = (int)($_GET['id'] ?? 0);
if ($wholesale_product_id <= 0) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.invalid_request')
    ];
    header('Location: wholesale_product_management.php');
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 도매상품 정보 가져오기 (스키마 호환성 처리)
    try {
        // 먼저 새로운 컬럼들이 존재하는지 확인
        $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
        $has_new_columns = $check_columns->rowCount() > 0;
        
        if ($has_new_columns) {
            $stmt = $pdo->prepare("
                SELECT wp.*, 
                       wp.wholesale_name_ko, wp.wholesale_name_en, wp.wholesale_skus, wp.wholesale_description,
                       p.sku, p.name_ko, p.name_en, p.selling_price, s.name as store_name
                FROM wholesale_products wp
                LEFT JOIN products p ON wp.product_id = p.id
                LEFT JOIN stores s ON wp.store_id = s.id
                WHERE wp.id = ? AND wp.is_active = 1
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT wp.*, 
                       NULL as wholesale_name_ko, NULL as wholesale_name_en, NULL as wholesale_skus, NULL as wholesale_description,
                       p.sku, p.name_ko, p.name_en, p.selling_price, s.name as store_name
                FROM wholesale_products wp
                LEFT JOIN products p ON wp.product_id = p.id
                LEFT JOIN stores s ON wp.store_id = s.id
                WHERE wp.id = ? AND wp.is_active = 1
            ");
        }
    } catch (PDOException $e) {
        // 컬럼이 없는 경우 기본 쿼리 사용
        $stmt = $pdo->prepare("
            SELECT wp.*, 
                   NULL as wholesale_name_ko, NULL as wholesale_name_en, NULL as wholesale_skus, NULL as wholesale_description,
                   p.sku, p.name_ko, p.name_en, p.selling_price, s.name as store_name
            FROM wholesale_products wp
            LEFT JOIN products p ON wp.product_id = p.id
            LEFT JOIN stores s ON wp.store_id = s.id
            WHERE wp.id = ? AND wp.is_active = 1
        ");
    }
    $stmt->execute([$wholesale_product_id]);
    $wholesale_product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$wholesale_product) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => t('wholesale.product_not_found')
        ];
        header('Location: wholesale_product_management.php');
        exit;
    }
    
    // 권한 확인 (super_admin이 아닌 경우 자신의 점포만 수정 가능)
    if ($_SESSION['role'] !== 'super_admin' && $wholesale_product['store_id'] != $current_store_id) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => t('wholesale.cannot_edit_other_store')
        ];
        header('Location: wholesale_product_management.php');
        exit;
    }
    
    // 점포 목록 가져오기 (super_admin인 경우)
    if ($_SESSION['role'] === 'super_admin') {
        $store_stmt = $pdo->prepare("SELECT id, name FROM stores ORDER BY name");
        $store_stmt->execute();
        $stores = $store_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    $errors[] = t('messages.database_error') . ': ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력값 검증 (새로운 필드들 추가)
    $wholesale_name_ko = trim($_POST['wholesale_name_ko'] ?? '');
    $wholesale_name_en = trim($_POST['wholesale_name_en'] ?? '');
    $wholesale_skus_input = trim($_POST['wholesale_skus'] ?? '');
    $wholesale_description = trim($_POST['wholesale_description'] ?? '');
    $wholesale_price = trim($_POST['wholesale_price'] ?? '');
    $store_id = $_SESSION['role'] === 'super_admin' ? (int)($_POST['store_id'] ?? 0) : $wholesale_product['store_id'];
    
    // 도매 상품명 검증 (한국어 또는 영어 중 최소 하나는 필수)
    if (empty($wholesale_name_ko) && empty($wholesale_name_en)) {
        $errors[] = t('wholesale.name_required');
    }
    
    // SKU 검증 및 JSON 변환
    $wholesale_skus_json = null;
    if (!empty($wholesale_skus_input)) {
        $skus_array = array_map('trim', explode(',', $wholesale_skus_input));
        $skus_array = array_filter($skus_array); // 빈 값 제거
        if (!empty($skus_array)) {
            $wholesale_skus_json = json_encode($skus_array);
        }
    }
    
    if (empty($wholesale_price) || !is_numeric($wholesale_price) || $wholesale_price <= 0) {
        $errors[] = t('wholesale.valid_price_required');
    }
    
    
    if ($_SESSION['role'] === 'super_admin' && empty($store_id)) {
        $errors[] = t('messages.select_store');
    }
    
    if (empty($errors)) {
        try {
            // 같은 점포에 같은 상품이 이미 등록되어 있는지 확인 (자신 제외)
            $check_stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM wholesale_products 
                WHERE product_id = ? AND store_id = ? AND is_active = 1 AND id != ?
            ");
            $check_stmt->execute([$wholesale_product['product_id'], $store_id, $wholesale_product_id]);
            
            if ($check_stmt->fetchColumn() > 0) {
                $errors[] = t('wholesale.product_already_exists');
            } else {
                // 스키마 호환성에 따른 UPDATE 쿼리 선택
                if ($has_new_columns) {
                    // 새로운 스키마 사용
                    $stmt = $pdo->prepare("
                        UPDATE wholesale_products 
                        SET store_id = ?, 
                            wholesale_name_ko = ?, 
                            wholesale_name_en = ?, 
                            wholesale_skus = ?, 
                            wholesale_description = ?,
                            wholesale_price = ?, 
                            updated_at = NOW() 
                        WHERE id = ?
                    ");
                    
                    $update_success = $stmt->execute([
                        $store_id, 
                        $wholesale_name_ko ?: null, 
                        $wholesale_name_en ?: null, 
                        $wholesale_skus_json, 
                        $wholesale_description ?: null,
                        $wholesale_price, 
                        $wholesale_product_id
                    ]);
                } else {
                    // 기존 스키마 사용 (새 필드들은 업데이트하지 않음)
                    $stmt = $pdo->prepare("
                        UPDATE wholesale_products 
                        SET store_id = ?, 
                            wholesale_price = ?
                        WHERE id = ?
                    ");
                    
                    $update_success = $stmt->execute([
                        $store_id, 
                        $wholesale_price, 
                        $wholesale_product_id
                    ]);
                }
                
                if ($update_success) {
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => t('wholesale.product_updated_success')
                    ];
                    header('Location: wholesale_product_management.php');
                    exit;
                } else {
                    $errors[] = t('wholesale.update_error');
                }
            }
        } catch (PDOException $e) {
            $errors[] = t('messages.database_error') . ': ' . $e->getMessage();
        }
    }
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-3xl mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_product_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-box mr-1"></i>
                            <?php echo t('navigation.wholesale_product_management'); ?>
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo t('common.edit_product'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-edit mr-2 text-primary-500"></i>
                    <?php echo t('wholesale.edit_product'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600"><?php echo t('wholesale.edit_product_description'); ?></p>
            </div>

            <div class="px-6 py-4">
                <?php if (isset($flash)): ?>
                    <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                                    <?php echo htmlspecialchars($flash['message']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800"><?php echo t('messages.fix_errors'); ?>:</h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($wholesale_product): ?>
                <form method="POST" class="space-y-6">
                    <!-- 기준 상품 정보 표시 (수정 불가) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <?php echo t('wholesale.base_product_info'); ?>
                        </label>
                        <div class="p-3 bg-gray-50 rounded-md border">
                            <div class="font-medium text-gray-900">
                                <?php echo htmlspecialchars($wholesale_product['name_en'] ?: $wholesale_product['name_ko']); ?>
                            </div>
                            <div class="text-sm text-gray-600 mt-1">
                                SKU: <?php echo htmlspecialchars($wholesale_product['sku']); ?>
                                <?php if ($wholesale_product['name_en'] && $wholesale_product['name_ko']): ?>
                                    | <?php echo htmlspecialchars($wholesale_product['name_ko']); ?>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                <?php echo t('product.selling_price'); ?>: <?php echo number_format($wholesale_product['selling_price']); ?><?php echo t('common.currency'); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 도매 전용 상품 정보 -->
                    <?php if ($has_new_columns): ?>
                    <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                        <h3 class="text-lg font-medium text-blue-900 mb-4">
                            <i class="fas fa-warehouse mr-2"></i>
                            <?php echo t('wholesale.wholesale_product_info'); ?>
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- 도매 상품명 (한국어) -->
                            <div>
                                <label for="wholesale_name_ko" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('wholesale.product_name_ko'); ?>
                                </label>
                                <input type="text" name="wholesale_name_ko" id="wholesale_name_ko" 
                                       value="<?php echo htmlspecialchars($_POST['wholesale_name_ko'] ?? $wholesale_product['wholesale_name_ko'] ?? $wholesale_product['name_ko']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="<?php echo t('wholesale.product_name_ko_placeholder'); ?>">
                            </div>
                            
                            <!-- 도매 상품명 (영어) -->
                            <div>
                                <label for="wholesale_name_en" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('wholesale.product_name_en'); ?>
                                </label>
                                <input type="text" name="wholesale_name_en" id="wholesale_name_en" 
                                       value="<?php echo htmlspecialchars($_POST['wholesale_name_en'] ?? $wholesale_product['wholesale_name_en'] ?? $wholesale_product['name_en']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="<?php echo t('wholesale.product_name_en_placeholder'); ?>">
                            </div>
                        </div>
                        
                        <!-- 도매 SKU들 -->
                        <div class="mt-4">
                            <label for="wholesale_skus" class="block text-sm font-medium text-gray-700">
                                <?php echo t('wholesale.sku'); ?> <span class="text-gray-500">(<?php echo t('wholesale.sku_multiple_note'); ?>)</span>
                            </label>
                            <?php
                            $current_skus = '';
                            if (!empty($_POST['wholesale_skus'])) {
                                $current_skus = $_POST['wholesale_skus'];
                            } elseif (!empty($wholesale_product['wholesale_skus'])) {
                                $skus_array = json_decode($wholesale_product['wholesale_skus'], true);
                                if (is_array($skus_array)) {
                                    $current_skus = implode(', ', $skus_array);
                                }
                            } else {
                                $current_skus = $wholesale_product['sku'];
                            }
                            ?>
                            <input type="text" name="wholesale_skus" id="wholesale_skus" 
                                   value="<?php echo htmlspecialchars($current_skus); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="<?php echo t('wholesale.sku_placeholder'); ?>">
                            <p class="mt-1 text-sm text-gray-500"><?php echo t('wholesale.sku_description'); ?></p>
                        </div>
                        
                        <!-- 도매 상품 설명 -->
                        <div class="mt-4">
                            <label for="wholesale_description" class="block text-sm font-medium text-gray-700">
                                <?php echo t('wholesale.product_description'); ?>
                            </label>
                            <textarea name="wholesale_description" id="wholesale_description" rows="3"
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                      placeholder="<?php echo t('wholesale.description_placeholder'); ?>"><?php echo htmlspecialchars($_POST['wholesale_description'] ?? $wholesale_product['wholesale_description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($_SESSION['role'] === 'super_admin' && !empty($stores)): ?>
                    <!-- 점포 선택 -->
                    <div>
                        <label for="store_id" class="block text-sm font-medium text-gray-700">
                            <?php echo t('store.select_store'); ?> <span class="text-red-500">*</span>
                        </label>
                        <select name="store_id" id="store_id" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value=""><?php echo t('store.select_store_option'); ?></option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>" 
                                        <?php echo ($wholesale_product['store_id'] == $store['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <!-- 현재 점포 표시 (수정 불가) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">
                            <?php echo t('store.store'); ?>
                        </label>
                        <div class="mt-1 p-3 bg-gray-50 border border-gray-300 rounded-md">
                            <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($wholesale_product['store_name']); ?></span>
                        </div>
                        <input type="hidden" name="store_id" value="<?php echo $wholesale_product['store_id']; ?>">
                    </div>
                    <?php endif; ?>

                    <!-- 도매가 -->
                    <div>
                        <label for="wholesale_price" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.wholesale_price'); ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="wholesale_price" id="wholesale_price" step="0.01" min="0" required
                               value="<?php echo htmlspecialchars($_POST['wholesale_price'] ?? $wholesale_product['wholesale_price']); ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="<?php echo t('wholesale.price_placeholder'); ?>">
                    </div>


                    <div class="flex justify-end space-x-4 pt-4">
                        <a href="wholesale_product_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            <?php echo t('common.cancel'); ?>
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-save mr-2"></i>
                            <?php echo t('common.save_changes'); ?>
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>