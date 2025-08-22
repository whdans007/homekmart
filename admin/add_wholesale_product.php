<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('wholesale.add_product') . ' - ' . t('company.name');
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
$success_message = '';
$stores = [];

// 점포 목록 가져오기 (super_admin인 경우)
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($_SESSION['role'] === 'super_admin') {
        $store_stmt = $pdo->prepare("SELECT id, name FROM stores ORDER BY name");
        $store_stmt->execute();
        $stores = $store_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    $errors[] = t('add_wholesale_product.database_connection_error') . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력값 검증 (새로운 필드들 추가)
    $product_id = (int)($_POST['product_id'] ?? 0);
    $wholesale_name_ko = trim($_POST['wholesale_name_ko'] ?? '');
    $wholesale_name_en = trim($_POST['wholesale_name_en'] ?? '');
    $wholesale_skus_input = trim($_POST['wholesale_skus'] ?? '');
    $wholesale_description = trim($_POST['wholesale_description'] ?? '');
    $wholesale_price = trim($_POST['wholesale_price'] ?? '');
    $cost_price = trim($_POST['cost_price'] ?? '0');
    $min_quantity = (int)($_POST['min_quantity'] ?? 1);
    $store_id = $_SESSION['role'] === 'super_admin' ? (int)($_POST['store_id'] ?? 0) : $current_store_id;
    
    if (empty($product_id)) {
        $errors[] = t('add_wholesale_product.product_required');
    }
    
    // 도매 상품명 검증 (한국어 또는 영어 중 최소 하나는 필수)
    if (empty($wholesale_name_ko) && empty($wholesale_name_en)) {
        $errors[] = t('add_wholesale_product.product_name_required');
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
        $errors[] = t('add_wholesale_product.price_required');
    }
    
    if (!is_numeric($cost_price) || $cost_price < 0) {
        $errors[] = t('add_wholesale_product.cost_price_invalid');
    }
    
    if ($min_quantity <= 0) {
        $errors[] = t('add_wholesale_product.min_quantity_invalid');
    }
    
    
    if (empty($errors)) {
        try {
            // 중복 확인 (같은 점포에 같은 상품이 이미 등록되어 있는지)
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM wholesale_products WHERE product_id = ? AND store_id = ? AND is_active = 1");
            $check_stmt->execute([$product_id, $store_id]);
            
            if ($check_stmt->fetchColumn() > 0) {
                $errors[] = t('add_wholesale_product.product_already_exists');
            } else {
                // 스키마 호환성 확인
                try {
                    $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
                    $has_new_columns = $check_columns->rowCount() > 0;
                    
                    // cost_price 컬럼 존재 확인
                    $check_cost_column = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'cost_price'");
                    $has_cost_price_column = $check_cost_column->rowCount() > 0;
                } catch (PDOException $e) {
                    $has_new_columns = false;
                    $has_cost_price_column = false;
                }
                
                if ($has_new_columns) {
                    // 새로운 스키마 사용
                    if ($has_cost_price_column) {
                        // cost_price 컬럼이 있는 경우
                        $stmt = $pdo->prepare("
                            INSERT INTO wholesale_products 
                            (product_id, store_id, wholesale_name_ko, wholesale_name_en, wholesale_skus, wholesale_description, 
                             wholesale_price, cost_price, min_quantity, is_active, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                        ");
                        
                        $insert_success = $stmt->execute([
                            $product_id, 
                            $store_id, 
                            $wholesale_name_ko ?: null, 
                            $wholesale_name_en ?: null, 
                            $wholesale_skus_json, 
                            $wholesale_description ?: null, 
                            $wholesale_price,
                            $cost_price, 
                            $min_quantity
                        ]);
                    } else {
                        // cost_price 컬럼이 없는 경우 (기존 방식)
                        $stmt = $pdo->prepare("
                            INSERT INTO wholesale_products 
                            (product_id, store_id, wholesale_name_ko, wholesale_name_en, wholesale_skus, wholesale_description, 
                             wholesale_price, min_quantity, is_active, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                        ");
                        
                        $insert_success = $stmt->execute([
                            $product_id, 
                            $store_id, 
                            $wholesale_name_ko ?: null, 
                            $wholesale_name_en ?: null, 
                            $wholesale_skus_json, 
                            $wholesale_description ?: null, 
                            $wholesale_price, 
                            $min_quantity
                        ]);
                    }
                } else {
                    // 기존 스키마 사용 (새 필드들 없이)
                    $stmt = $pdo->prepare("
                        INSERT INTO wholesale_products 
                        (product_id, store_id, wholesale_price, min_quantity, is_active, created_at) 
                        VALUES (?, ?, ?, ?, 1, NOW())
                    ");
                    
                    $insert_success = $stmt->execute([
                        $product_id, 
                        $store_id, 
                        $wholesale_price, 
                        $min_quantity
                    ]);
                }
                
                if ($insert_success) {
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => t('add_wholesale_product.save_success')
                    ];
                    header('Location: wholesale_product_management.php');
                    exit;
                } else {
                    $errors[] = t('add_wholesale_product.save_error');
                }
            }
        } catch (PDOException $e) {
            $errors[] = t('add_wholesale_product.database_error') . $e->getMessage();
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
                            <span class="text-gray-600"><?php echo t('wholesale.add_product'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-plus-circle mr-2 text-primary-500"></i>
                    <?php echo t('wholesale.add_product'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars(t('add_wholesale_product.page_description')); ?></p>
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
                                <h3 class="text-sm font-medium text-red-800"><?php echo htmlspecialchars(t('add_wholesale_product.solve_errors')); ?></h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <!-- 기준 상품 선택 -->
                    <div>
                        <label for="product_search" class="block text-sm font-medium text-gray-700 mb-2">
                            <?php echo t('add_wholesale_product.product_selection'); ?> <span class="text-red-500"><?php echo t('add_wholesale_product.required_field'); ?></span>
                        </label>
                        <div class="relative">
                            <input type="text" id="product_search" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="<?php echo htmlspecialchars(t('add_wholesale_product.search_placeholder')); ?>"
                                   autocomplete="off">
                            <div id="product_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                                <!-- 검색 결과가 여기에 표시됩니다 -->
                            </div>
                        </div>
                        
                        <!-- 선택된 상품 정보 표시 -->
                        <div id="selected_product" class="mt-3 p-3 bg-gray-50 rounded-md hidden">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <div class="font-medium text-gray-900" id="selected_product_name"></div>
                                    <div class="text-sm text-gray-600" id="selected_product_info"></div>
                                </div>
                                <button type="button" id="clear_selection" class="text-red-500 hover:text-red-700">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            
                            <!-- 구매이력 표시 영역 -->
                            <div id="purchase_history_section" class="border-t border-gray-200 pt-3">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('add_wholesale_product.recent_purchase_history')); ?></h4>
                                    <div id="purchase_history_loading" class="text-xs text-gray-500 hidden">
                                        <i class="fas fa-spinner fa-spin mr-1"></i>
                                        <?php echo htmlspecialchars(t('add_wholesale_product.loading')); ?>
                                    </div>
                                </div>
                                <div id="purchase_history_content">
                                    <!-- 구매이력 테이블이 여기에 동적으로 추가됩니다 -->
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="product_id" id="product_id" value="<?php echo $_POST['product_id'] ?? ''; ?>">
                    </div>
                    
                    <!-- 도매 전용 상품 정보 -->
                    <?php
                    // 스키마 호환성 확인
                    try {
                        $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
                        $has_new_columns_ui = $check_columns->rowCount() > 0;
                    } catch (PDOException $e) {
                        $has_new_columns_ui = false;
                    }
                    ?>
                    
                    <?php if ($has_new_columns_ui): ?>
                    <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                        <h3 class="text-lg font-medium text-blue-900 mb-4">
                            <i class="fas fa-warehouse mr-2"></i>
                            <?php echo htmlspecialchars(t('add_wholesale_product.wholesale_product_info')); ?>
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- 도매 상품명 (한국어) -->
                            <div>
                                <label for="wholesale_name_ko" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('add_wholesale_product.wholesale_name_ko'); ?> <span class="text-red-500"><?php echo t('add_wholesale_product.required_field'); ?></span>
                                </label>
                                <input type="text" name="wholesale_name_ko" id="wholesale_name_ko" 
                                       value="<?php echo htmlspecialchars($_POST['wholesale_name_ko'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="<?php echo htmlspecialchars(t('add_wholesale_product.wholesale_name_ko_placeholder')); ?>">
                            </div>
                            
                            <!-- 도매 상품명 (영어) -->
                            <div>
                                <label for="wholesale_name_en" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('add_wholesale_product.wholesale_name_en'); ?>
                                </label>
                                <input type="text" name="wholesale_name_en" id="wholesale_name_en" 
                                       value="<?php echo htmlspecialchars($_POST['wholesale_name_en'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="<?php echo htmlspecialchars(t('add_wholesale_product.wholesale_name_en_placeholder')); ?>">
                            </div>
                        </div>
                        
                        <!-- 도매 SKU들 -->
                        <div class="mt-4">
                            <label for="wholesale_skus" class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.wholesale_sku'); ?> <span class="text-gray-500"><?php echo htmlspecialchars(t('add_wholesale_product.wholesale_sku_note')); ?></span>
                            </label>
                            <input type="text" name="wholesale_skus" id="wholesale_skus" 
                                   value="<?php echo htmlspecialchars($_POST['wholesale_skus'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="<?php echo htmlspecialchars(t('add_wholesale_product.wholesale_sku_placeholder')); ?>">
                            <p class="mt-1 text-sm text-gray-500"><?php echo htmlspecialchars(t('add_wholesale_product.wholesale_sku_description')); ?></p>
                        </div>
                        
                        <!-- 도매 상품 설명 -->
                        <div class="mt-4">
                            <label for="wholesale_description" class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.wholesale_description'); ?>
                            </label>
                            <textarea name="wholesale_description" id="wholesale_description" rows="3"
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                      placeholder="<?php echo htmlspecialchars(t('add_wholesale_product.wholesale_description_placeholder')); ?>"><?php echo htmlspecialchars($_POST['wholesale_description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- 스키마 업데이트 안내 -->
                    <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800"><?php echo htmlspecialchars(t('add_wholesale_product.schema_upgrade_needed')); ?></h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p><?php echo htmlspecialchars(t('add_wholesale_product.schema_upgrade_description')); ?></p>
                                    <p class="mt-1">
                                        <a href="schema_update_helper.php" class="font-medium underline">
                                            <?php echo htmlspecialchars(t('add_wholesale_product.schema_update_link')); ?>
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 현재 점포 표시 (수정 불가) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">
                            <?php echo t('add_wholesale_product.store'); ?>
                        </label>
                        <div class="mt-1 p-3 bg-gray-50 border border-gray-300 rounded-md">
                            <span class="text-gray-900 font-medium">
                                <?php echo htmlspecialchars($_SESSION['role'] === 'super_admin' ? 
                                    (isset($stores) && !empty($stores) ? $stores[0]['name'] : '본점') : 
                                    $current_store_name); ?>
                            </span>
                        </div>
                        <input type="hidden" name="store_id" value="<?php echo $_SESSION['role'] === 'super_admin' ? 
                            (isset($stores) && !empty($stores) ? $stores[0]['id'] : 1) : 
                            $current_store_id; ?>">
                    </div>

                    <!-- 마진율 설정 -->
                    <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                        <h3 class="text-lg font-medium text-green-900 mb-4">
                            <i class="fas fa-calculator mr-2"></i>
                            <?php echo t('add_wholesale_product.wholesale_price_calculator'); ?>
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                            <!-- 마진율 입력 -->
                            <div>
                                <label for="margin_rate" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('add_wholesale_product.margin_rate_percent'); ?>
                                </label>
                                <input type="number" id="margin_rate" step="0.1" min="0" max="100" value="15.0"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="15.0">
                            </div>
                            
                            <!-- 선택된 매입가 표시 -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    <?php echo t('add_wholesale_product.selected_purchase_price'); ?>
                                </label>
                                <div id="selected_cost_price" class="mt-1 p-2 bg-gray-100 border border-gray-300 rounded-md text-gray-900 font-medium">
                                    <?php echo htmlspecialchars(t('add_wholesale_product.js_select_purchase_price')); ?>
                                </div>
                            </div>
                            
                            <!-- 계산된 도매가 미리보기 -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    <?php echo t('add_wholesale_product.calculated_wholesale_price'); ?>
                                </label>
                                <div id="calculated_wholesale_price" class="mt-1 p-2 bg-blue-100 border border-blue-300 rounded-md text-blue-900 font-medium">
                                    -
                                </div>
                            </div>
                        </div>
                        
                        <!-- 계산 공식 표시 -->
                        <div class="mt-3 text-xs text-gray-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            <?php echo t('add_wholesale_product.calculation_formula'); ?>
                            <br>
                            <i class="fas fa-exclamation-triangle mr-1 text-yellow-500"></i>
                            <?php echo t('add_wholesale_product.calculation_note'); ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- 원가 -->
                        <div>
                            <label for="cost_price" class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.cost_price_per_box'); ?> <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="cost_price" id="cost_price" step="0.01" min="0" required
                                   value="<?php echo htmlspecialchars($_POST['cost_price'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="박스당 원가를 입력하세요">
                            <p class="mt-1 text-sm text-gray-500">도매가격 계산의 기준이 되는 원가입니다</p>
                        </div>
                        
                        <!-- 마진율 -->
                        <div>
                            <label for="margin_rate_input" class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.margin_rate_field'); ?> <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="margin_rate" id="margin_rate_input" step="0.1" min="0" max="1000" required
                                   value="<?php echo htmlspecialchars($_POST['margin_rate'] ?? '15'); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="15">
                            <p class="mt-1 text-sm text-gray-500">기본 15% 마진율</p>
                        </div>
                        
                        <!-- 도매가 -->
                        <div>
                            <label for="wholesale_price" class="block text-sm font-medium text-gray-700">
                                <?php echo t('wholesale.wholesale_price'); ?> <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="wholesale_price" id="wholesale_price" step="0.01" min="0" required
                                   value="<?php echo htmlspecialchars($_POST['wholesale_price'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="도매가를 입력하세요">
                            <p class="mt-1 text-sm margin-display" id="margin-info-add"></p>
                        </div>
                    </div>

                    <!-- 최소 주문수량 -->
                    <div>
                        <label for="min_quantity" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.min_quantity'); ?>
                        </label>
                        <input type="number" name="min_quantity" id="min_quantity" min="1" 
                               value="<?php echo htmlspecialchars($_POST['min_quantity'] ?? '1'); ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="최소 주문수량">
                        <p class="mt-1 text-sm text-gray-500">기본값: 1개</p>
                    </div>

                    <div class="flex justify-end space-x-4 pt-4">
                        <a href="wholesale_product_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            <?php echo t('common.cancel'); ?>
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-save mr-2"></i>
                            <?php echo t('common.save'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 번역 텍스트
    const translations = {
        selectPurchasePrice: '<?php echo htmlspecialchars(t('add_wholesale_product.js_select_purchase_price')); ?>',
        invalidMargin: '<?php echo htmlspecialchars(t('add_wholesale_product.js_invalid_margin')); ?>',
        calculatedMargin: '<?php echo htmlspecialchars(t('add_wholesale_product.js_calculated_margin')); ?>',
        expectedWholesalePrice: '<?php echo htmlspecialchars(t('add_wholesale_product.js_expected_wholesale_price')); ?>',
        priceAutoCalculated: '<?php echo htmlspecialchars(t('add_wholesale_product.js_price_auto_calculated')); ?>',
        directInput: '<?php echo htmlspecialchars(t('add_wholesale_product.js_direct_input')); ?>',
        marginApplied: '<?php echo htmlspecialchars(t('add_wholesale_product.js_margin_applied')); ?>',
        useButton: '<?php echo htmlspecialchars(t('add_wholesale_product.js_use_button')); ?>',
        appliedButton: '<?php echo htmlspecialchars(t('add_wholesale_product.js_applied_button')); ?>',
        useButtonTable: '<?php echo htmlspecialchars(t('add_wholesale_product.use_button_table')); ?>',
        purchaseDate: '<?php echo htmlspecialchars(t('add_wholesale_product.purchase_date')); ?>',
        supplier: '<?php echo htmlspecialchars(t('add_wholesale_product.supplier')); ?>',
        unitPriceBox: '<?php echo htmlspecialchars(t('add_wholesale_product.unit_price_box')); ?>',
        unitPricePerPiece: '<?php echo htmlspecialchars(t('add_wholesale_product.unit_price_per_piece')); ?>',
        quantity: '<?php echo htmlspecialchars(t('add_wholesale_product.quantity')); ?>',
        purchaseType: '<?php echo htmlspecialchars(t('add_wholesale_product.purchase_type')); ?>'
    };
    
    const productSearch = document.getElementById('product_search');
    const searchResults = document.getElementById('product_search_results');
    const selectedProduct = document.getElementById('selected_product');
    const productId = document.getElementById('product_id');
    const clearSelection = document.getElementById('clear_selection');
    const marginRateInput = document.getElementById('margin_rate');
    const selectedCostPriceDiv = document.getElementById('selected_cost_price');
    const calculatedWholesalePriceDiv = document.getElementById('calculated_wholesale_price');
    const wholesalePriceInput = document.getElementById('wholesale_price');
    const costPriceInput = document.getElementById('cost_price');
    
    let searchTimeout;
    let currentCostPrice = 0;
    
    // 상품 검색
    productSearch.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            searchResults.classList.add('hidden');
            return;
        }
        
        searchTimeout = setTimeout(function() {
            searchProducts(query);
        }, 300);
    });
    
    // 검색 결과 외부 클릭시 닫기
    document.addEventListener('click', function(e) {
        if (!productSearch.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.classList.add('hidden');
        }
    });
    
    // 선택 취소
    clearSelection.addEventListener('click', function() {
        productId.value = '';
        selectedProduct.classList.add('hidden');
        productSearch.value = '';
        resetPriceCalculation();
    });
    
    const marginRateInputAdd = document.getElementById('margin_rate_input');
    const marginInfoAdd = document.getElementById('margin-info-add');
    
    // 도매가 자동 계산 (원가와 마진율 기반)
    function calculateWholesalePriceAdd() {
        const costPrice = parseFloat(costPriceInput.value) || 0;
        const marginRate = parseFloat(marginRateInputAdd.value) || 0;
        
        if (costPrice > 0 && marginRate >= 0) {
            const calculatedPrice = Math.round(costPrice * (1 + marginRate / 100));
            wholesalePriceInput.value = calculatedPrice;
            updateMarginDisplayAdd();
        }
    }
    
    // 마진율 자동 계산 (원가와 도매가 기반)
    function calculateMarginRateAdd() {
        const costPrice = parseFloat(costPriceInput.value) || 0;
        const wholesalePrice = parseFloat(wholesalePriceInput.value) || 0;
        
        if (costPrice > 0 && wholesalePrice > 0) {
            const calculatedMarginRate = ((wholesalePrice / costPrice - 1) * 100).toFixed(1);
            marginRateInputAdd.value = calculatedMarginRate;
            updateMarginDisplayAdd();
        }
    }
    
    // 마진 정보 표시 업데이트
    function updateMarginDisplayAdd() {
        const costPrice = parseFloat(costPriceInput.value) || 0;
        const marginRate = parseFloat(marginRateInputAdd.value) || 0;
        const wholesalePrice = parseFloat(wholesalePriceInput.value) || 0;
        
        if (costPrice > 0 && wholesalePrice > 0) {
            const actualMarginRate = ((wholesalePrice / costPrice - 1) * 100).toFixed(1);
            
            let displayText = translations.calculatedMargin + actualMarginRate + '%';
            let colorClass = 'text-gray-500';
            
            // 마진율에 따른 색상 변경
            if (actualMarginRate < 10) {
                colorClass = 'text-red-500';
            } else if (actualMarginRate < 20) {
                colorClass = 'text-yellow-600';
            } else {
                colorClass = 'text-green-600';
            }
            
            marginInfoAdd.textContent = displayText;
            marginInfoAdd.className = `mt-1 text-sm margin-display ${colorClass}`;
        } else if (costPrice > 0 && marginRate > 0) {
            const calculatedPrice = Math.round(costPrice * (1 + marginRate / 100));
            marginInfoAdd.textContent = translations.expectedWholesalePrice + calculatedPrice.toLocaleString();
            marginInfoAdd.className = 'mt-1 text-sm margin-display text-blue-600';
        } else {
            marginInfoAdd.textContent = translations.priceAutoCalculated;
            marginInfoAdd.className = 'mt-1 text-sm margin-display text-gray-400';
        }
    }
    
    // 이벤트 리스너 등록
    costPriceInput.addEventListener('input', function() {
        const costPrice = parseFloat(this.value);
        if (costPrice > 0) {
            currentCostPrice = costPrice;
            selectedCostPriceDiv.innerHTML = `
                <span class="font-bold">${costPrice.toLocaleString()}</span>
                <br><small class="text-xs">' + translations.directInput + '</small>
            `;
            if (marginRateInputAdd.value) {
                calculateWholesalePriceAdd();
            } else {
                updateMarginDisplayAdd();
            }
        } else {
            resetPriceCalculation();
        }
    });
    
    marginRateInputAdd.addEventListener('input', function() {
        calculateWholesalePriceAdd();
        validateAndCalculateWholesalePrice();
    });
    
    wholesalePriceInput.addEventListener('input', function() {
        // 도매가가 직접 입력된 경우 마진율 재계산
        const timeoutId = setTimeout(function() {
            calculateMarginRateAdd();
        }, 500); // 0.5초 후 마진율 계산
        
        wholesalePriceInput.timeoutId = timeoutId;
    });
    
    // 페이지 로드 시 초기 계산
    updateMarginDisplayAdd();
    
    // 마진율 변경 시 도매가 재계산 (기존 기능 유지)
    marginRateInput.addEventListener('input', function() {
        validateAndCalculateWholesalePrice();
    });
    
    function searchProducts(query) {
        fetch('ajax_search_products_simple.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            if (data.success && data.products) {
                displaySearchResults(data.products);
            } else {
                const message = data.message || '검색 결과가 없습니다.';
                searchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">' + message + '</div>';
                searchResults.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            searchResults.innerHTML = '<div class="p-3 text-sm text-red-500">검색 중 오류가 발생했습니다: ' + error.message + '</div>';
            searchResults.classList.remove('hidden');
        });
    }
    
    function displaySearchResults(products) {
        let html = '';
        products.forEach(function(product) {
            html += `
                <div class="p-3 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0 product-item" 
                     data-id="${product.id}" 
                     data-sku="${product.sku}"
                     data-name-ko="${product.name_ko || ''}"
                     data-name-en="${product.name_en || ''}">
                    <div class="font-medium text-gray-900">${product.name_en || product.name_ko || 'N/A'}</div>
                    <div class="text-sm text-gray-600">${product.name_ko && product.name_en ? product.name_ko : ''}</div>
                    <div class="text-xs text-gray-500 mt-1">
                        SKU: ${product.sku} | 박스당: ${Number(product.pieces_per_box || 1)}개 | 원가: ${Number(product.cost_price || 0).toLocaleString()} | 판매가: ${Number(product.selling_price || 0).toLocaleString()}
                    </div>
                </div>
            `;
        });
        
        searchResults.innerHTML = html;
        searchResults.classList.remove('hidden');
        
        // 상품 선택 이벤트
        document.querySelectorAll('.product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                selectProduct(this);
            });
        });
    }
    
    function selectProduct(item) {
        const id = item.dataset.id;
        const sku = item.dataset.sku;
        const nameKo = item.dataset.nameKo;
        const nameEn = item.dataset.nameEn;
        
        productId.value = id;
        
        document.getElementById('selected_product_name').textContent = nameEn || nameKo || 'N/A';
        // 원가, 판매가, 박스포장 정보도 포함하여 표시
        const piecesPerBox = item.querySelector('.text-xs').textContent.match(/박스당: ([0-9,]+)개/)?.[1] || '1';
        const costPrice = item.querySelector('.text-xs').textContent.match(/원가: ([0-9,]+)/)?.[1] || '0';
        const sellingPrice = item.querySelector('.text-xs').textContent.match(/판매가: ([0-9,]+)/)?.[1] || '0';
        document.getElementById('selected_product_info').textContent = `SKU: ${sku}${nameKo && nameEn ? ' | ' + nameKo : ''} | 박스당: ${piecesPerBox}개 | 원가: ${costPrice} | 판매가: ${sellingPrice}`;
        
        // 도매용 필드들을 기본 상품 정보로 자동 입력 (사용자가 수정 가능)
        if (!document.getElementById('wholesale_name_ko').value && nameKo) {
            document.getElementById('wholesale_name_ko').value = nameKo;
        }
        if (!document.getElementById('wholesale_name_en').value && nameEn) {
            document.getElementById('wholesale_name_en').value = nameEn;
        }
        if (!document.getElementById('wholesale_skus').value && sku) {
            document.getElementById('wholesale_skus').value = sku;
        }
        
        selectedProduct.classList.remove('hidden');
        searchResults.classList.add('hidden');
        productSearch.value = nameEn || nameKo || sku;
        
        // 구매이력 조회
        loadPurchaseHistory(id);
    }
    
    // 구매이력 조회 함수
    function loadPurchaseHistory(productId) {
        const loadingElement = document.getElementById('purchase_history_loading');
        const contentElement = document.getElementById('purchase_history_content');
        
        loadingElement.classList.remove('hidden');
        
        // 점포 ID 가져오기 (super_admin인 경우 선택된 점포, 아닌 경우 현재 점포)
        const storeIdElement = document.getElementById('store_id');
        const storeId = storeIdElement ? storeIdElement.value : '';
        
        const url = `ajax_get_purchase_history.php?product_id=${productId}${storeId ? '&store_id=' + storeId : ''}`;
        
        fetch(url)
        .then(response => response.json())
        .then(data => {
            loadingElement.classList.add('hidden');
            
            if (data.success && data.data && data.data.length > 0) {
                displayPurchaseHistory(data.data);
            } else {
                contentElement.innerHTML = `
                    <div class="text-center py-4 text-gray-500">
                        <i class="fas fa-info-circle mr-2"></i>
                        구매이력이 없습니다.
                    </div>
                `;
            }
        })
        .catch(error => {
            loadingElement.classList.add('hidden');
            contentElement.innerHTML = `
                <div class="text-center py-4 text-red-500">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    구매이력 조회 중 오류가 발생했습니다.
                </div>
            `;
            console.error('구매이력 조회 오류:', error);
        });
    }
    
    // 구매이력 표시 함수
    function displayPurchaseHistory(historyData) {
        const contentElement = document.getElementById('purchase_history_content');
        
        let html = `
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' + translations.purchaseDate + '</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">공급업체</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">매입가(박스)</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">개당단가</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">수량</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">타입</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">' + translations.useButtonTable + '</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
        `;
        
        historyData.forEach((item, index) => {
            html += `
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 whitespace-nowrap text-gray-900">${item.purchase_date_formatted}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-gray-900">${item.supplier_name}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-right text-gray-900 font-medium">${Number(item.unit_price).toLocaleString()}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-right text-gray-500 text-xs">${Number(item.unit_cost_per_piece).toLocaleString()}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-center text-gray-900">${item.quantity}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-center text-gray-900">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${item.purchase_type === 'box' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'}">
                            ${item.purchase_type === 'box' ? '박스' : '낱개'}
                        </span>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap text-center">
                        <button type="button" 
                                class="inline-flex items-center px-2 py-1 border border-transparent text-xs font-medium rounded text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 use-price-btn"
                                data-cost-price="${item.unit_price}"
                                data-supplier="${item.supplier_name}"
                                data-date="${item.purchase_date_formatted}">
                            ' + translations.useButtonTable + '
                        </button>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        contentElement.innerHTML = html;
        
        // 사용 버튼 이벤트 바인딩
        bindUsePriceButtons();
    }
    
    // 가격 계산 초기화
    function resetPriceCalculation() {
        currentCostPrice = 0;
        selectedCostPriceDiv.textContent = translations.selectPurchasePrice;
        calculatedWholesalePriceDiv.textContent = '-';
        wholesalePriceInput.value = '';
    }
    
    // 마진율 검증 및 도매가 계산
    function validateAndCalculateWholesalePrice() {
        const marginRate = parseFloat(marginRateInput.value);
        
        // 마진율 검증
        if (isNaN(marginRate) || marginRate < 0 || marginRate > 100) {
            marginRateInput.classList.add('border-red-500');
            calculatedWholesalePriceDiv.innerHTML = '<span class="text-red-600">' + translations.invalidMargin + '</span>';
            return;
        } else {
            marginRateInput.classList.remove('border-red-500');
        }
        
        // 도매가 계산
        if (currentCostPrice > 0) {
            calculateWholesalePrice(currentCostPrice, marginRate);
        }
    }
    
    // 도매가 계산 함수
    function calculateWholesalePrice(costPrice, marginRate) {
        const wholesalePrice = Math.round(costPrice * (1 + marginRate / 100));
        
        calculatedWholesalePriceDiv.innerHTML = `
            <span class="font-bold">${wholesalePrice.toLocaleString()}</span>
            <br><small class="text-xs">' + translations.marginApplied.replace('{rate}', marginRate) + '</small>
        `;
        
        return wholesalePrice;
    }
    
    // 매입가 선택 함수
    function selectCostPrice(costPrice, supplier, date) {
        currentCostPrice = parseFloat(costPrice);
        
        selectedCostPriceDiv.innerHTML = `
            <span class="font-bold">${Number(costPrice).toLocaleString()}</span>
            <br><small class="text-xs">${supplier} (${date})</small>
        `;
        
        // 원가 입력 필드에도 반영
        costPriceInput.value = costPrice;
        
        // 마진율 계산 및 표시
        const marginRate = parseFloat(marginRateInput.value);
        if (!isNaN(marginRate)) {
            const wholesalePrice = calculateWholesalePrice(currentCostPrice, marginRate);
            
            // 계산된 도매가를 입력 필드에도 적용
            wholesalePriceInput.value = wholesalePrice;
        }
    }
    
    // 사용 버튼 이벤트 바인딩 함수
    function bindUsePriceButtons() {
        document.querySelectorAll('.use-price-btn').forEach(button => {
            button.addEventListener('click', function() {
                const costPrice = this.dataset.costPrice;
                const supplier = this.dataset.supplier;
                const date = this.dataset.date;
                
                selectCostPrice(costPrice, supplier, date);
                
                // 선택된 행 하이라이트
                document.querySelectorAll('.use-price-btn').forEach(btn => {
                    btn.closest('tr').classList.remove('bg-primary-50', 'border-primary-200');
                });
                this.closest('tr').classList.add('bg-primary-50', 'border-primary-200');
                
                // 버튼 상태 변경
                document.querySelectorAll('.use-price-btn').forEach(btn => {
                    btn.textContent = translations.useButton;
                    btn.classList.remove('bg-green-600', 'hover:bg-green-700');
                    btn.classList.add('bg-primary-600', 'hover:bg-primary-700');
                });
                this.textContent = translations.appliedButton;
                this.classList.remove('bg-primary-600', 'hover:bg-primary-700');
                this.classList.add('bg-green-600', 'hover:bg-green-700');
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>