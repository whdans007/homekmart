<?php
// 세션 시작 및 기본 헬퍼 로드
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('product.add_new') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
?>

<style>
/* Input 필드 스타일 개선 */
input[type="text"], 
input[type="number"], 
input[type="url"], 
textarea, 
select {
    background-color: #ffffff !important;
    border: 2px solid #d1d5db !important;
    transition: all 0.2s ease-in-out !important;
}

input[type="text"]:focus, 
input[type="number"]:focus, 
input[type="url"]:focus, 
textarea:focus, 
select:focus {
    background-color: #ffffff !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}

input[type="text"]:hover, 
input[type="number"]:hover, 
input[type="url"]:hover, 
textarea:hover, 
select:hover {
    border-color: #9ca3af !important;
}

/* Placeholder 텍스트 스타일 */
input::placeholder,
textarea::placeholder {
    color: #9ca3af !important;
}
</style>

<?php

// 접근 권한 확인
if (!in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('product.access_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$errors = [];
$product = [
    'sku' => '', 'name_ko' => '', 'name_en' => '', 'description' => '',
    'category_id' => null, 'brand_id' => null, 'image_url' => '', 'is_active' => 1,
    'pieces_per_box' => 1
];
$categories = [];
$brands = [];
$margin_rules = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $brands = $pdo->query("SELECT id, name_ko FROM brands ORDER BY name_ko ASC")->fetchAll(PDO::FETCH_ASSOC);
    $margin_rules_data = $pdo->query("SELECT category_id, margin_percentage FROM margin_rules")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($margin_rules_data as $rule) {
        $margin_rules[$rule['category_id']] = $rule['margin_percentage'];
    }

} catch (PDOException $e) {
    $errors[] = str_replace('{error}', $e->getMessage(), t('product.database_connection_failed'));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 데이터 받아오기
    foreach ($product as $key => $value) {
        if (isset($_POST[$key])) {
            $product[$key] = trim($_POST[$key]);
        }
    }

    // 유효성 검사
    if (empty($product['sku'])) $errors[] = t('product.sku_required');
    if (empty($product['name_en'])) $errors[] = t('product.name_en_required');
    if (!empty($product['pieces_per_box']) && (!is_numeric($product['pieces_per_box']) || $product['pieces_per_box'] < 1)) $errors[] = t('product.pieces_per_box_numeric');

    // SKU 중복 확인
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->execute([$product['sku']]);
        if ($stmt->fetch()) {
            $errors[] = t('product.sku_exists');
        }
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO products (sku, name_ko, name_en, description, category_id, brand_id, image_url, is_active, pieces_per_box, is_vat_applicable, last_modified_by_user_id) VALUES (:sku, :name_ko, :name_en, :description, :category_id, :brand_id, :image_url, :is_active, :pieces_per_box, :is_vat_applicable, :user_id)";
            $stmt = $pdo->prepare($sql);

            $params = [
                ':sku' => $product['sku'],
                ':name_ko' => $product['name_ko'],
                ':name_en' => $product['name_en'] ?: null,
                ':description' => $product['description'] ?: null,
                ':category_id' => $product['category_id'] ?: null,
                ':brand_id' => $product['brand_id'] ?: null,
                ':image_url' => $product['image_url'] ?: null,
                ':is_active' => $product['is_active'],
                ':pieces_per_box' => $product['pieces_per_box'] ?: 1,
                ':is_vat_applicable' => isset($_POST['is_vat_applicable']) ? (int)$_POST['is_vat_applicable'] : 1,
                ':user_id' => $_SESSION['user_id']
            ];

            $stmt->execute($params);

            $_SESSION['flash'] = ['type' => 'success', 'message' => t('product.added_successfully')];
            header("Location: product_management.php");
            exit;

        } catch (PDOException $e) {
            $errors[] = str_replace('{error}', $e->getMessage(), t('product.add_error'));
        }
    }
}
?>

<!-- 헤더 -->
<div class="mb-6">
            <div class="flex items-center space-x-3 mb-4">
                <a href="product_management.php" class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-600 bg-white rounded-lg border hover:bg-gray-50 transition-colors">
                    <i class="fas fa-arrow-left mr-2"></i>
                    <?php echo t('product.back_to_list'); ?>
                </a>
            </div>
            <div class="text-left">
                <h1 class="text-2xl font-bold text-gray-900 mb-1"><?php echo t('product.add_new'); ?></h1>
                <p class="text-gray-600"><?php echo t('product.enter_info'); ?></p>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg border">
            <div class="px-6 py-6">

                <?php if (!empty($errors)) : ?>
                    <div class="mb-6 bg-red-50 border-l-4 border-red-400 p-4 rounded-r-lg">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800 mb-2"><?php echo t('product.solve_errors'); ?></h3>
                                <ul class="text-sm text-red-700 space-y-1">
                                    <?php foreach ($errors as $error) : ?>
                                        <li class="flex items-start">
                                            <span class="text-red-400 mr-2">•</span>
                                            <?php echo htmlspecialchars($error); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form action="add_product.php" method="post" class="space-y-8">
                    <!-- 바코드 검색 섹션 -->
                    <div class="bg-blue-50 rounded-lg p-5 border border-blue-200">
                        <div class="flex items-center mb-3">
                            <div class="bg-blue-100 rounded-full p-2 mr-3">
                                <i class="fas fa-barcode text-blue-600 text-sm"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo t('product.barcode_search'); ?></h3>
                                <p class="text-sm text-gray-600"><?php echo t('product.barcode_search_desc'); ?></p>
                            </div>
                        </div>
                        <div class="flex items-stretch gap-3">
                            <div class="flex-grow">
                                <label for="barcode_search" class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('product.barcode'); ?></label>
                                <input type="text" name="barcode_search" id="barcode_search" class="block w-full h-10 px-3 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm" placeholder="<?php echo t('product.barcode_placeholder'); ?>">
                            </div>
                            <div class="flex items-end gap-2">
                                <button type="button" id="barcode-search-db-btn" class="inline-flex items-center px-4 py-2 h-10 border border-gray-300 rounded-lg bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                                    <i class="fas fa-database text-gray-500 mr-2 text-sm"></i>
                                    <?php echo t('product.my_db_search'); ?>
                                </button>
                                <button type="button" id="barcode-search-web-btn" class="inline-flex items-center px-4 py-2 h-10 border border-transparent rounded-lg bg-blue-600 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                                    <i class="fas fa-globe mr-2 text-sm"></i>
                                    <?php echo t('product.web_search'); ?>
                                </button>
                            </div>
                        </div>
                        <div id="barcode-result" class="mt-4 hidden">
                            <div id="barcode-error" class="hidden bg-red-50 border-l-4 border-red-400 p-4 rounded-r-lg">
                                <div class="flex">
                                    <i class="fas fa-exclamation-circle text-red-400 mr-2 mt-0.5 text-sm"></i>
                                    <p class="text-sm text-red-800"></p>
                                </div>
                            </div>
                            <div id="barcode-success" class="hidden bg-green-50 border-l-4 border-green-400 p-4 rounded-r-lg flex items-start gap-4">
                                <img id="product-image-preview" src="" alt="<?php echo t('product.image'); ?>" class="h-16 w-16 rounded-lg object-cover border">
                                <div>
                                    <div class="flex items-center mb-1">
                                        <i class="fas fa-check-circle text-green-500 mr-2 text-sm"></i>
                                        <p class="font-medium text-green-800"><?php echo t('product.info_loaded'); ?></p>
                                    </div>
                                    <p class="text-sm text-green-700"><?php echo t('product.form_filled'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 기본 정보 섹션 -->
                    <div>
                        <div class="flex items-center mb-4">
                            <div class="bg-gray-100 rounded-full p-2 mr-3">
                                <i class="fas fa-info-circle text-gray-600 text-sm"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900"><?php echo t('product.basic_info_section'); ?></h3>
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div>
                                <label for="sku" class="block text-sm font-medium text-gray-700 mb-2">SKU <span class="text-red-500">*</span></label>
                                <input type="text" name="sku" id="sku" value="<?php echo htmlspecialchars($product['sku']); ?>" required class="block w-full h-10 px-3 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                            </div>
                            <div class="lg:col-span-2">
                                <label for="name_ko" class="block text-sm font-medium text-gray-700 mb-2"><?php echo t('product.name_ko'); ?></label>
                                <div class="flex rounded-lg shadow-sm">
                                    <input type="text" name="name_ko" id="name_ko" value="<?php echo htmlspecialchars($product['name_ko']); ?>" class="block w-full flex-1 h-10 px-3 rounded-l-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm">
                                    <button type="button" id="translate-btn" class="relative -ml-px inline-flex items-center px-3 py-2 h-10 rounded-r-lg border border-gray-300 bg-gray-50 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-1 focus:ring-blue-500 transition-colors">
                                        <i class="fas fa-language text-gray-500 mr-2 text-sm"></i>
                                        <span><?php echo t('product.translate'); ?></span>
                                    </button>
                                </div>
                            </div>
                            <div class="lg:col-span-3">
                                <label for="name_en" class="block text-sm font-medium text-gray-700 mb-2"><?php echo t('product.name_en'); ?> <span class="text-red-500">*</span></label>
                                <input type="text" name="name_en" id="name_en" value="<?php echo htmlspecialchars($product['name_en']); ?>" required class="block w-full h-10 px-3 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- 분류 및 박스포장 정보 섹션 -->
                    <div>
                        <div class="flex items-center mb-4">
                            <div class="bg-purple-100 rounded-full p-2 mr-3">
                                <i class="fas fa-tags text-purple-600 text-sm"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900"><?php echo t('product.category_packaging_info'); ?></h3>
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div>
                                <label for="category_search" class="block text-sm font-medium text-gray-700 mb-2"><?php echo t('product.category'); ?></label>
                                <div class="relative">
                                    <input type="text" id="category_search" class="block w-full h-10 px-3 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm" placeholder="<?php echo t('product.category_search_placeholder'); ?>">
                                    <input type="hidden" name="category_id" id="category_id" value="<?php echo htmlspecialchars($product['category_id']); ?>">
                                    <div id="category_results" class="absolute z-20 mt-1 w-full bg-white shadow-lg max-h-60 rounded-lg py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden"></div>
                                </div>
                            </div>
                            <div>
                                <label for="brand_search" class="block text-sm font-medium text-gray-700 mb-2"><?php echo t('product.brand'); ?></label>
                                <div class="relative">
                                    <input type="text" id="brand_search" class="block w-full h-10 px-3 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm" placeholder="<?php echo t('product.brand_search_placeholder'); ?>">
                                    <input type="hidden" name="brand_id" id="brand_id" value="<?php echo htmlspecialchars($product['brand_id']); ?>">
                                    <div id="brand_results" class="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-lg py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden"></div>
                                </div>
                            </div>
                            <div>
                                <label for="pieces_per_box" class="block text-sm font-medium text-gray-700 mb-2"><?php echo t('product.pieces_per_box'); ?></label>
                                <div class="relative rounded-lg shadow-sm">
                                    <input type="number" name="pieces_per_box" id="pieces_per_box" value="<?php echo htmlspecialchars($product['pieces_per_box']); ?>" min="1" class="block w-full h-10 px-3 rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm" placeholder="1">
                                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500 text-sm"><?php echo t('product.pieces'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 추가 정보 섹션 -->
                    <div>
                        <div class="flex items-center mb-4">
                            <div class="bg-indigo-100 rounded-full p-2 mr-3">
                                <i class="fas fa-align-left text-indigo-600 text-sm"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900"><?php echo t('product.additional_info'); ?></h3>
                        </div>
                        <div class="space-y-6">
                            <div>
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-2"><?php echo t('product.description'); ?> <span class="text-gray-400">(<?php echo t('forms.optional'); ?>)</span></label>
                                <textarea id="description" name="description" rows="4" class="block w-full px-3 py-2 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm" placeholder="<?php echo t('product.description_placeholder'); ?>"><?php echo htmlspecialchars($product['description']); ?></textarea>
                            </div>
                            <div>
                                <label for="image_url" class="block text-sm font-medium text-gray-700 mb-2"><?php echo t('product.image_url'); ?> <span class="text-gray-400">(<?php echo t('forms.optional'); ?>)</span></label>
                                <input type="url" name="image_url" id="image_url" value="<?php echo htmlspecialchars($product['image_url']); ?>" class="block w-full h-10 px-3 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm" placeholder="https://example.com/image.png">
                            </div>
                        </div>
                    </div>

                    <!-- 상태 섹션 -->
                    <div>
                        <div class="flex items-center mb-4">
                            <div class="bg-yellow-100 rounded-full p-2 mr-3">
                                <i class="fas fa-toggle-on text-yellow-600 text-sm"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900"><?php echo t('product.status'); ?></h3>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="flex items-center space-x-6">
                                <div class="flex items-center">
                                    <input id="is_active_true" name="is_active" type="radio" value="1" <?php echo ($product['is_active'] == 1) ? 'checked' : ''; ?> class="h-4 w-4 border-gray-300 text-green-600 focus:ring-green-500">
                                    <label for="is_active_true" class="ml-3 flex items-center text-sm font-medium text-gray-900">
                                        <span class="w-2 h-2 bg-green-400 rounded-full mr-2"></span>
                                        <?php echo t('product.active'); ?>
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input id="is_active_false" name="is_active" type="radio" value="0" <?php echo ($product['is_active'] == 0) ? 'checked' : ''; ?> class="h-4 w-4 border-gray-300 text-gray-600 focus:ring-gray-500">
                                    <label for="is_active_false" class="ml-3 flex items-center text-sm font-medium text-gray-900">
                                        <span class="w-2 h-2 bg-gray-400 rounded-full mr-2"></span>
                                        <?php echo t('product.inactive'); ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- VAT 적용 여부 섹션 -->
                    <div>
                        <div class="flex items-center mb-4">
                            <div class="bg-orange-100 rounded-full p-2 mr-3">
                                <i class="fas fa-receipt text-orange-600 text-sm"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900">VAT 적용 여부</h3>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="flex items-center space-x-6">
                                <div class="flex items-center">
                                    <input id="is_vat_applicable_true" name="is_vat_applicable" type="radio" value="1" <?php echo (isset($product['is_vat_applicable']) && $product['is_vat_applicable'] == 1) ? 'checked' : 'checked'; ?> class="h-4 w-4 border-gray-300 text-orange-600 focus:ring-orange-500">
                                    <label for="is_vat_applicable_true" class="ml-3 flex items-center text-sm font-medium text-gray-900">
                                        <span class="w-2 h-2 bg-orange-400 rounded-full mr-2"></span>
                                        VAT 적용 상품 (일반상품)
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input id="is_vat_applicable_false" name="is_vat_applicable" type="radio" value="0" <?php echo (isset($product['is_vat_applicable']) && $product['is_vat_applicable'] == 0) ? 'checked' : ''; ?> class="h-4 w-4 border-gray-300 text-gray-600 focus:ring-gray-500">
                                    <label for="is_vat_applicable_false" class="ml-3 flex items-center text-sm font-medium text-gray-900">
                                        <span class="w-2 h-2 bg-gray-400 rounded-full mr-2"></span>
                                        VAT 비적용 상품 (쌀, 미곡류 등)
                                    </label>
                                </div>
                            </div>
                            <div class="mt-3 text-xs text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                VAT 비적용 상품은 매입 시 VAT 포함/미포함 선택과 관계없이 원가에 VAT가 적용되지 않습니다.
                            </div>
                        </div>
                    </div>

                    <!-- 액션 버튼 -->
                    <div class="border-t border-gray-200 pt-6">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-500">
                                <span class="text-red-500">*</span> <?php echo t('product.required_fields_note'); ?>
                            </div>
                            <div class="flex gap-3">
                                <a href="product_management.php" class="inline-flex items-center px-4 py-2 h-10 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                                    <i class="fas fa-times mr-2 text-sm"></i>
                                    <?php echo t('common.cancel'); ?>
                                </a>
                                <button type="submit" class="inline-flex items-center px-6 py-2 h-10 border border-transparent rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                                    <i class="fas fa-plus mr-2 text-sm"></i>
                                    <?php echo t('product.add'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

<!-- 카테고리 추가 Modal -->
<div id="add-category-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-30">
    <div class="relative top-20 mx-auto p-6 border w-96 shadow-xl rounded-lg bg-white">
        <div class="text-center">
            <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-purple-100 mb-4">
                <i class="fas fa-sitemap text-purple-600 text-xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2"><?php echo t('category.add_new'); ?></h3>
            <p class="text-sm text-gray-500 mb-6">
                <?php echo str_replace('{name}', '\'<span id="new-category-name-modal" class="font-semibold text-gray-900"></span>\'', t('product.add_new_category_confirm')); ?>
            </p>
            <div class="flex gap-3 justify-center">
                <button id="cancel-add-category" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition-colors"><?php echo t('common.cancel'); ?></button>
                <button id="confirm-add-category" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition-colors"><?php echo t('common.add'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- 브랜드 추가 Modal -->
<div id="add-brand-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-30">
    <div class="relative top-20 mx-auto p-6 border w-96 shadow-xl rounded-lg bg-white">
        <div class="text-center">
            <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-blue-100 mb-4">
                <i class="fas fa-tags text-blue-600 text-xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2"><?php echo t('brand.add_new'); ?></h3>
            <p class="text-sm text-gray-500 mb-6">
                <?php echo str_replace('{name}', '\'<span id="new-brand-name-modal" class="font-semibold text-gray-900"></span>\'', t('product.add_new_brand_confirm')); ?>
            </p>
            <div class="flex gap-3 justify-center">
                <button id="cancel-add-brand" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition-colors"><?php echo t('common.cancel'); ?></button>
                <button id="confirm-add-brand" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors"><?php echo t('common.add'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Element Declarations ---
    const translateBtn = document.getElementById('translate-btn');
    const nameKoInput = document.getElementById('name_ko');
    const nameEnInput = document.getElementById('name_en');
    const barcodeDbSearchBtn = document.getElementById('barcode-search-db-btn');
    const barcodeWebSearchBtn = document.getElementById('barcode-search-web-btn');
    const barcodeInput = document.getElementById('barcode_search');
    const barcodeResultDiv = document.getElementById('barcode-result');
    const barcodeErrorDiv = document.getElementById('barcode-error');
    const barcodeSuccessDiv = document.getElementById('barcode-success');
    const imagePreview = document.getElementById('product-image-preview');

    // --- 번역 기능 ---
    translateBtn.addEventListener('click', function() {
        const textToTranslate = nameKoInput.value;
        if (!textToTranslate.trim()) {
            alert('<?php echo t('product.enter_korean_name'); ?>');
            return;
        }
        translateBtn.disabled = true;
        translateBtn.innerHTML = '<i class="fas fa-spinner fa-spin h-5 w-5 text-gray-400"></i><span><?php echo t('product.translating'); ?></span>';
        fetch('ajax_translate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `text=${encodeURIComponent(textToTranslate)}&target_lang=EN`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                nameEnInput.value = data.translated_text;
            } else {
                alert('<?php echo t('product.translation_failed'); ?>: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('<?php echo t('product.translation_error'); ?>');
        })
        .finally(() => {
            translateBtn.disabled = false;
            translateBtn.innerHTML = '<i class="fas fa-language h-5 w-5 text-gray-400"></i><span><?php echo t('product.translate'); ?></span>';
        });
    });

    // --- 검색 가능한 드롭다운 공통 함수 ---
    function setupSearchableDropdown(options) {
        const searchInput = document.getElementById(options.searchInputId);
        const idInput = document.getElementById(options.idInputId);
        const resultsContainer = document.getElementById(options.resultsContainerId);
        const allItems = options.items;
        const modal = document.getElementById(options.addModalId);
        const newNameSpan = document.getElementById(options.newNameSpanId);
        const confirmBtn = document.getElementById(options.confirmBtnId);
        const cancelBtn = document.getElementById(options.cancelBtnId);

        // 초기 값 설정
        if (idInput.value) {
            const initialItem = allItems.find(item => item.id == idInput.value);
            if (initialItem) {
                searchInput.value = initialItem.name_ko || initialItem.name;
            }
        }

        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            if (!searchTerm) {
                resultsContainer.innerHTML = '';
                resultsContainer.classList.add('hidden');
                idInput.value = '';
                return;
            }

            const filteredItems = allItems.filter(item => (item.name_ko || item.name).toLowerCase().includes(searchTerm));
            
            resultsContainer.innerHTML = '';
            if (filteredItems.length > 0) {
                filteredItems.forEach(item => {
                    const div = document.createElement('div');
                    div.textContent = item.name_ko || item.name;
                    div.className = 'cursor-pointer hover:bg-primary-100 p-2';
                    div.addEventListener('click', function() {
                        searchInput.value = item.name_ko || item.name;
                        idInput.value = item.id;
                        resultsContainer.classList.add('hidden');
                    });
                    resultsContainer.appendChild(div);
                });
            } else {
                const div = document.createElement('div');
                div.innerHTML = `<span class="italic text-gray-500 p-2"><?php echo t('product.no_results'); ?></span> <button type="button" class="text-primary-600 hover:underline ml-2 add-new-btn"><?php echo t('common.add'); ?></button>`;
                div.className = 'p-2';
                resultsContainer.appendChild(div);

                div.querySelector('.add-new-btn').addEventListener('click', function() {
                    newNameSpan.textContent = searchInput.value;
                    modal.classList.remove('hidden');
                });
            }
            resultsContainer.classList.remove('hidden');
        });

        cancelBtn.addEventListener('click', () => modal.classList.add('hidden'));

        confirmBtn.addEventListener('click', () => {
            const newName = searchInput.value;
            fetch(options.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `name=${encodeURIComponent(newName)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    idInput.value = data.data.id;
                    searchInput.value = data.data.name_ko || data.data.name;
                    allItems.push(data.data);
                    modal.classList.add('hidden');
                    resultsContainer.classList.add('hidden');
                    alert('<?php echo str_replace('{type}', "' + options.itemType + '", t('product.new_item_added')); ?>');
                } else {
                    alert('<?php echo t('common.error'); ?>: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert(options.itemType + ' <?php echo t('product.add_error_occurred'); ?>');
            });
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
                resultsContainer.classList.add('hidden');
            }
        });
    }

    // --- 카테고리 검색 설정 ---
    setupSearchableDropdown({
        searchInputId: 'category_search',
        idInputId: 'category_id',
        resultsContainerId: 'category_results',
        items: <?php echo json_encode($categories); ?>,
        addModalId: 'add-category-modal',
        newNameSpanId: 'new-category-name-modal',
        confirmBtnId: 'confirm-add-category',
        cancelBtnId: 'cancel-add-category',
        ajaxUrl: 'ajax_add_category.php',
        itemType: '<?php echo t('product.category_item'); ?>'
    });


    // --- 브랜드 검색 설정 ---
    setupSearchableDropdown({
        searchInputId: 'brand_search',
        idInputId: 'brand_id',
        resultsContainerId: 'brand_results',
        items: <?php echo json_encode($brands); ?>,
        addModalId: 'add-brand-modal',
        newNameSpanId: 'new-brand-name-modal',
        confirmBtnId: 'confirm-add-brand',
        cancelBtnId: 'cancel-add-brand',
        ajaxUrl: 'ajax_add_brand.php',
        itemType: '<?php echo t('product.brand_item'); ?>'
    });


    // --- 바코드 검색 기능 ---
    // 바코드 입력 필드의 내용이 변경되고 포커스가 벗어날 때 검색 실행
    barcodeInput.addEventListener('change', function() {
        if (barcodeInput.value.trim() !== '') {
            barcodeDbSearchBtn.click();
        }
    });

    function fillFormWithProductData(product) {
        document.getElementById('sku').value = product.sku || '';
        document.getElementById('name_ko').value = product.name_ko || '';
        document.getElementById('name_en').value = product.name_en || '';
        document.getElementById('description').value = product.description || '';
        document.getElementById('image_url').value = product.image_url || '';
        document.getElementById('category_id').value = product.category_id || '';
        document.getElementById('category_search').value = product.category_name || '';
        document.getElementById('brand_id').value = product.brand_id || '';
        document.getElementById('brand_search').value = product.brand_name_ko || '';
        
        if (product.image_url) {
            imagePreview.src = product.image_url;
            barcodeSuccessDiv.classList.remove('hidden');
            barcodeErrorDiv.classList.add('hidden');
        } else {
            barcodeSuccessDiv.classList.add('hidden');
        }
        barcodeResultDiv.classList.remove('hidden');
    }

    function handleBarcodeSearch(url, button) {
        const barcode = barcodeInput.value.trim();
        if (!barcode) {
            alert('<?php echo t('product.enter_barcode'); ?>');
            return;
        }

        const originalButtonHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        fetch(`${url}?barcode=${encodeURIComponent(barcode)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(result => {
                if (result.success) {
                    fillFormWithProductData(result.data);
                } else {
                    barcodeErrorDiv.querySelector('p').textContent = result.message;
                    barcodeErrorDiv.classList.remove('hidden');
                    barcodeSuccessDiv.classList.add('hidden');
                    barcodeResultDiv.classList.remove('hidden');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                barcodeErrorDiv.querySelector('p').textContent = '<?php echo str_replace('{error}', "' + error.message + '", t('product.search_error')); ?>';
                barcodeErrorDiv.classList.remove('hidden');
                barcodeSuccessDiv.classList.add('hidden');
                barcodeResultDiv.classList.remove('hidden');
            })
            .finally(() => {
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            });
    }

    barcodeDbSearchBtn.addEventListener('click', () => {
        handleBarcodeSearch('ajax_get_product_by_barcode.php', barcodeDbSearchBtn);
    });

    barcodeWebSearchBtn.addEventListener('click', () => {
        handleBarcodeSearch('ajax_search_barcode_online.php', barcodeWebSearchBtn);
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>