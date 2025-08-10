<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('product.edit') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// 상품관리 권한 확인
if (!has_permission('product_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$product_id = $_GET['id'] ?? null;
if (!$product_id) {
    header("Location: product_management.php");
    exit;
}

$errors = [];
$product = null;
$categories = [];
$brands = [];
$stores = [];
$inventory_prices = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 상품 정보 가져오기
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '존재하지 않는 상품입니다.'];
        header("Location: product_management.php");
        exit;
    }

    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $brands = $pdo->query("SELECT id, name_ko FROM brands ORDER BY name_ko ASC")->fetchAll(PDO::FETCH_ASSOC);
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 지점별 재고 및 가격 정보 가져오기
    $stmt = $pdo->prepare("SELECT store_id, quantity, cost_price, selling_price FROM inventory WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $inventory_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inventory_data as $inv) {
        // 마진율 계산
        $margin_rate = 0;
        if ($inv['cost_price'] > 0) {
            $margin_rate = (($inv['selling_price'] - $inv['cost_price']) / $inv['cost_price']) * 100;
        }
        $inv['margin_rate'] = round($margin_rate, 2);
        
        // 박스원가 계산 (pieces_per_box가 있을 때)
        $box_cost = 0;
        if ($product['pieces_per_box'] > 1) {
            $box_cost = $inv['cost_price'] * $product['pieces_per_box'];
        }
        $inv['box_cost'] = $box_cost;
        
        $inventory_prices[$inv['store_id']] = $inv;
    }

} catch (PDOException $e) {
    $errors[] = "데이터베이스 연결에 실패했습니다: " . $e->getMessage();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 데이터 받아오기
    $updated_product = [];
    foreach ($product as $key => $value) {
        $updated_product[$key] = trim($_POST[$key] ?? $value);
    }

    // 유효성 검사
    if (empty($updated_product['sku'])) $errors[] = "SKU를 입력해주세요.";
    if (empty($updated_product['name_en'])) $errors[] = "상품명(영문)을 입력해주세요.";
    if (!empty($updated_product['pieces_per_box']) && (!is_numeric($updated_product['pieces_per_box']) || $updated_product['pieces_per_box'] < 1)) $errors[] = "박스포장 수량은 1 이상의 숫자여야 합니다.";

    // SKU 중복 확인 (자신 제외)
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ? AND id != ?");
        $stmt->execute([$updated_product['sku'], $product_id]);
        if ($stmt->fetch()) {
            $errors[] = "이미 사용 중인 SKU입니다.";
        }
    }

    if (empty($errors)) {
        try {
            $sql = "UPDATE products SET sku = :sku, name_ko = :name_ko, name_en = :name_en, description = :description, category_id = :category_id, brand_id = :brand_id, image_url = :image_url, is_active = :is_active, pieces_per_box = :pieces_per_box, last_modified_by_user_id = :user_id WHERE id = :id";
            $stmt = $pdo->prepare($sql);

            $params = [
                ':id' => $product_id,
                ':sku' => $updated_product['sku'],
                ':name_ko' => $updated_product['name_ko'],
                ':name_en' => $updated_product['name_en'] ?: null,
                ':description' => $updated_product['description'] ?: null,
                ':category_id' => $updated_product['category_id'] ?: null,
                ':brand_id' => $updated_product['brand_id'] ?: null,
                ':image_url' => $updated_product['image_url'] ?: null,
                ':is_active' => $updated_product['is_active'],
                ':pieces_per_box' => $updated_product['pieces_per_box'] ?: 1,
                ':user_id' => $_SESSION['user_id']
            ];

            $stmt->execute($params);

            // 지점별 판매가 업데이트
            if (isset($_POST['store_prices']) && is_array($_POST['store_prices'])) {
                $sql_inventory = "
                    INSERT INTO inventory (product_id, store_id, selling_price, quantity) 
                    VALUES (:product_id, :store_id, :selling_price, :quantity)
                    ON DUPLICATE KEY UPDATE selling_price = :selling_price;
                ";
                $stmt_inventory = $pdo->prepare($sql_inventory);

                foreach ($_POST['store_prices'] as $store_id => $price) {
                    // 가격이 입력된 경우에만 처리
                    if ($price !== '' && is_numeric($price)) {
                        // 기존 재고 수량 확인, 없으면 0
                        $current_quantity = $inventory_prices[$store_id]['quantity'] ?? 0;

                        $stmt_inventory->execute([
                            ':product_id' => $product_id,
                            ':store_id' => $store_id,
                            ':selling_price' => $price,
                            ':quantity' => $current_quantity
                        ]);
                    }
                }
            }

            $_SESSION['flash'] = ['type' => 'success', 'message' => '상품 정보가 성공적으로 수정되었습니다.'];
            header("Location: product_management.php");
            exit;

        } catch (PDOException $e) {
            $errors[] = "상품 수정 중 오류가 발생했습니다: " . $e->getMessage();
        }
    }
    // 오류 발생 시, POST된 데이터로 product 변수 업데이트
    $product = $updated_product;
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-3xl mx-auto">
        <!-- Page Header -->
        <div class="mb-8 sm:flex sm:items-center sm:justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">상품 정보 수정</h1>
                <p class="mt-2 text-sm text-gray-700">상품의 상세 정보를 수정합니다.</p>
            </div>
            <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                <a href="product_management.php" class="btn">
                    <i class="fas fa-arrow-left mr-2"></i>
                    상품 목록으로 돌아가기
                </a>
            </div>
        </div>

        <div class="bg-white shadow-lg rounded-lg">
            <div class="px-6 py-6">

                <?php if (!empty($errors)) : ?>
                    <div class="mb-6 rounded-lg bg-red-50 p-4 border border-red-200">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-times-circle text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">다음 오류를 해결해주세요.</h3>
                                <div class="mt-2 text-sm text-red-700">
                                    <ul role="list" class="list-disc pl-5 space-y-1">
                                        <?php foreach ($errors as $error) : ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form action="edit_product.php?id=<?php echo $product_id; ?>" method="post">
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <tbody class="divide-y divide-gray-200">
                                <!-- 상품 ID -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 bg-gray-50 w-48">
                                        상품 ID
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($product['id']); ?>
                                    </td>
                                </tr>
                                
                                <!-- SKU -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 bg-gray-50">
                                        <span class="text-red-500">*</span> SKU
                                    </td>
                                    <td class="px-6 py-4">
                                        <input type="text" name="sku" id="sku" value="<?php echo htmlspecialchars($product['sku']); ?>" required 
                                               class="block w-full max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                    </td>
                                </tr>
                                
                                <!-- 상품명 (한글) -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 bg-gray-50">
                                        상품명 (한글)
                                    </td>
                                    <td class="px-6 py-4">
                                        <input type="text" name="name_ko" id="name_ko" value="<?php echo htmlspecialchars($product['name_ko']); ?>" 
                                               class="block w-full max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                    </td>
                                </tr>
                                
                                <!-- 상품명 (영문) -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 bg-gray-50">
                                        <span class="text-red-500">*</span> 상품명 (영문)
                                    </td>
                                    <td class="px-6 py-4">
                                        <input type="text" name="name_en" id="name_en" value="<?php echo htmlspecialchars($product['name_en']); ?>" required 
                                               class="block w-full max-w-md rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                    </td>
                                </tr>
                                
                                <!-- 카테고리 -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 bg-gray-50">
                                        카테고리
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="relative max-w-md">
                                            <input type="text" id="category_search" 
                                                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                                   placeholder="카테고리 검색...">
                                            <input type="hidden" name="category_id" id="category_id" value="<?php echo htmlspecialchars($product['category_id']); ?>">
                                            <div id="category_results" class="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden border border-gray-200"></div>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- 브랜드 -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 bg-gray-50">
                                        브랜드
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="relative max-w-md">
                                            <input type="text" id="brand_search" 
                                                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                                   placeholder="브랜드 검색...">
                                            <input type="hidden" name="brand_id" id="brand_id" value="<?php echo htmlspecialchars($product['brand_id']); ?>">
                                            <div id="brand_results" class="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden border border-gray-200"></div>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- 박스당 수량 -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 bg-gray-50">
                                        박스당 수량
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="relative max-w-md">
                                            <input type="number" name="pieces_per_box" id="pieces_per_box" value="<?php echo htmlspecialchars($product['pieces_per_box'] ?? 1); ?>" min="1" 
                                                   class="block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="1">
                                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                                <span class="text-gray-500 sm:text-sm">개</span>
                                            </div>
                                        </div>
                                        <p class="mt-1 text-xs text-gray-500">한 박스에 들어있는 낱개 수량</p>
                                    </td>
                                </tr>
                                
                                <!-- 상품 설명 -->
                                <tr>
                                    <td class="px-6 py-4 align-top text-sm font-medium text-gray-900 bg-gray-50">
                                        상품 설명
                                    </td>
                                    <td class="px-6 py-4">
                                        <textarea id="description" name="description" rows="3" 
                                                  class="block w-full max-w-lg rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                                  placeholder="상품 설명 입력..."><?php echo htmlspecialchars($product['description']); ?></textarea>
                                    </td>
                                </tr>
                                
                                <!-- 이미지 URL -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 bg-gray-50">
                                        이미지 URL
                                    </td>
                                    <td class="px-6 py-4">
                                        <input type="url" name="image_url" id="image_url" value="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                               class="block w-full max-w-lg rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                               placeholder="https://example.com/image.png">
                                    </td>
                                </tr>
                                
                                <!-- 상태 -->
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 bg-gray-50">
                                        상태
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center space-x-6">
                                            <div class="flex items-center">
                                                <input id="is_active_true" name="is_active" type="radio" value="1" <?php echo ($product['is_active'] == 1) ? 'checked' : ''; ?> 
                                                       class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                <label for="is_active_true" class="ml-2 text-sm text-gray-700">활성</label>
                                            </div>
                                            <div class="flex items-center">
                                                <input id="is_active_false" name="is_active" type="radio" value="0" <?php echo ($product['is_active'] == 0) ? 'checked' : ''; ?> 
                                                       class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                <label for="is_active_false" class="ml-2 text-sm text-gray-700">비활성</label>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- 지점별 원가 및 판매가 -->
                                <?php if (!empty($stores)): ?>
                                <tr>
                                    <td class="px-6 py-4 align-top text-sm font-medium text-gray-900 bg-gray-50">
                                        지점별 가격 정보
                                    </td>
                                    <td class="px-6 py-4">
                                        <!-- 테이블 헤더 -->
                                        <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                                            <table class="min-w-full divide-y divide-gray-300">
                                                <thead class="bg-gray-50">
                                                    <tr>
                                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">지점</th>
                                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">원가</th>
                                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">마진(%)</th>
                                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">판매가</th>
                                                        <?php if ($product['pieces_per_box'] > 1): ?>
                                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">박스원가</th>
                                                        <?php endif; ?>
                                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">재고</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="bg-white divide-y divide-gray-200">
                                                    <?php foreach ($stores as $store): ?>
                                                        <tr class="hover:bg-gray-50">
                                                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                                                <?php echo htmlspecialchars($store['name']); ?>
                                                            </td>
                                                            <td class="px-4 py-3 text-sm text-gray-900 text-right">
                                                                <?php if (isset($inventory_prices[$store['id']]['cost_price'])): ?>
                                                                    <?php echo number_format($inventory_prices[$store['id']]['cost_price'], 2); ?>
                                                                <?php else: ?>
                                                                    <span class="text-gray-400">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="px-4 py-3 text-sm text-gray-900 text-right">
                                                                <?php if (isset($inventory_prices[$store['id']]['margin_rate'])): ?>
                                                                    <span class="<?php echo $inventory_prices[$store['id']]['margin_rate'] < 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                                                        <?php echo number_format($inventory_prices[$store['id']]['margin_rate'], 2); ?>%
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="text-gray-400">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="px-4 py-3 text-sm text-right">
                                                                <input type="number" name="store_prices[<?php echo $store['id']; ?>]" id="store_price_<?php echo $store['id']; ?>" 
                                                                       value="<?php echo htmlspecialchars($inventory_prices[$store['id']]['selling_price'] ?? ''); ?>" 
                                                                       class="block w-24 text-right rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm" 
                                                                       placeholder="판매가">
                                                            </td>
                                                            <?php if ($product['pieces_per_box'] > 1): ?>
                                                            <td class="px-4 py-3 text-sm text-gray-900 text-right">
                                                                <?php if (isset($inventory_prices[$store['id']]['box_cost']) && $inventory_prices[$store['id']]['box_cost'] > 0): ?>
                                                                    <?php echo number_format($inventory_prices[$store['id']]['box_cost'], 2); ?>
                                                                    <div class="text-xs text-gray-500">
                                                                        (<?php echo $product['pieces_per_box']; ?>개입)
                                                                    </div>
                                                                <?php else: ?>
                                                                    <span class="text-gray-400">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <?php endif; ?>
                                                            <td class="px-4 py-3 text-sm text-gray-500 text-right">
                                                                <?php if (isset($inventory_prices[$store['id']]['quantity'])): ?>
                                                                    <?php echo number_format($inventory_prices[$store['id']]['quantity']); ?>개
                                                                <?php else: ?>
                                                                    <span class="text-gray-400">0개</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <p class="mt-2 text-xs text-gray-500">판매가를 비워두면 기본 판매가가 적용됩니다.</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 액션 버튼 -->
                    <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200 mt-8">
                        <a href="product_management.php" class="btn">
                            <i class="fas fa-times mr-2"></i>
                            취소
                        </a>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save mr-2"></i>
                            변경사항 저장
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 카테고리 추가 Modal -->
<div id="add-category-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-primary-100">
                <i class="fas fa-sitemap text-primary-600 text-xl"></i>
            </div>
            <h3 class="text-lg leading-6 font-medium text-gray-900 mt-4">새 카테고리 추가</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">'<span id="new-category-name-modal" class="font-semibold"></span>' 카테고리를 새로 추가하시겠습니까?</p>
            </div>
            <div class="items-center px-4 py-3 space-x-3">
                <button id="cancel-add-category" class="btn">취소</button>
                <button id="confirm-add-category" class="btn-success">
                    <i class="fas fa-plus mr-1"></i>
                    추가
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 브랜드 추가 Modal -->
<div id="add-brand-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-primary-100">
                <i class="fas fa-tags text-primary-600 text-xl"></i>
            </div>
            <h3 class="text-lg leading-6 font-medium text-gray-900 mt-4">새 브랜드 추가</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">'<span id="new-brand-name-modal" class="font-semibold"></span>' 브랜드를 새로 추가하시겠습니까?</p>
            </div>
            <div class="items-center px-4 py-3 space-x-3">
                <button id="cancel-add-brand" class="btn">취소</button>
                <button id="confirm-add-brand" class="btn-success">
                    <i class="fas fa-plus mr-1"></i>
                    추가
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const categorySearch = document.getElementById('category_search');
    const categoryIdInput = document.getElementById('category_id');
    const categoryResults = document.getElementById('category_results');
    const allCategories = <?php echo json_encode($categories); ?>;

    const brandSearch = document.getElementById('brand_search');
    const brandIdInput = document.getElementById('brand_id');
    const brandResults = document.getElementById('brand_results');
    const allBrands = <?php echo json_encode($brands); ?>;

    // 초기 값 설정 (카테고리)
    if (categoryIdInput.value) {
        const initialCategory = allCategories.find(c => c.id == categoryIdInput.value);
        if (initialCategory) {
            categorySearch.value = initialCategory.name;
        }
    }

    // 초기 값 설정 (브랜드)
    if (brandIdInput.value) {
        const initialBrand = allBrands.find(b => b.id == brandIdInput.value);
        if (initialBrand) {
            brandSearch.value = initialBrand.name_ko;
        }
    }

    // 카테고리 검색 로직
    categorySearch.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        if (!searchTerm) {
            categoryResults.innerHTML = '';
            categoryResults.classList.add('hidden');
            categoryIdInput.value = '';
            return;
        }

        const filteredCategories = allCategories.filter(c => c.name.toLowerCase().includes(searchTerm));
        
        categoryResults.innerHTML = '';
        if (filteredCategories.length > 0) {
            filteredCategories.forEach(c => {
                const div = document.createElement('div');
                div.textContent = c.name;
                div.className = 'cursor-pointer bg-white hover:bg-indigo-50 p-3 border-b border-gray-100 last:border-b-0';
                div.addEventListener('click', function() {
                    categorySearch.value = c.name;
                    categoryIdInput.value = c.id;
                    categoryResults.classList.add('hidden');
                });
                categoryResults.appendChild(div);
            });
        } else {
            const div = document.createElement('div');
            div.innerHTML = `
                <div class="p-3 text-center">
                    <span class="text-gray-500">검색 결과 없음</span>
                    <button type="button" id="add-new-category-btn" class="ml-2 text-indigo-600 hover:text-indigo-800 font-medium">
                        <i class="fas fa-plus mr-1"></i>새로 추가
                    </button>
                </div>
            `;
            div.className = 'bg-gray-50';
            categoryResults.appendChild(div);

            document.getElementById('add-new-category-btn').addEventListener('click', function() {
                document.getElementById('new-category-name-modal').textContent = categorySearch.value;
                document.getElementById('add-category-modal').classList.remove('hidden');
            });
        }
        categoryResults.classList.remove('hidden');
    });

    // 브랜드 검색 로직
    brandSearch.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        if (!searchTerm) {
            brandResults.innerHTML = '';
            brandResults.classList.add('hidden');
            brandIdInput.value = '';
            return;
        }

        const filteredBrands = allBrands.filter(b => b.name_ko.toLowerCase().includes(searchTerm));
        
        brandResults.innerHTML = '';
        if (filteredBrands.length > 0) {
            filteredBrands.forEach(b => {
                const div = document.createElement('div');
                div.textContent = b.name_ko;
                div.className = 'cursor-pointer bg-white hover:bg-indigo-50 p-3 border-b border-gray-100 last:border-b-0';
                div.addEventListener('click', function() {
                    brandSearch.value = b.name_ko;
                    brandIdInput.value = b.id;
                    brandResults.classList.add('hidden');
                });
                brandResults.appendChild(div);
            });
        } else {
            const div = document.createElement('div');
            div.innerHTML = `
                <div class="p-3 text-center">
                    <span class="text-gray-500">검색 결과 없음</span>
                    <button type="button" id="add-new-brand-btn" class="ml-2 text-indigo-600 hover:text-indigo-800 font-medium">
                        <i class="fas fa-plus mr-1"></i>새로 추가
                    </button>
                </div>
            `;
            div.className = 'bg-gray-50';
            brandResults.appendChild(div);

            document.getElementById('add-new-brand-btn').addEventListener('click', function() {
                document.getElementById('new-brand-name-modal').textContent = brandSearch.value;
                document.getElementById('add-brand-modal').classList.remove('hidden');
            });
        }
        brandResults.classList.remove('hidden');
    });

    // 카테고리 Modal-related event listeners
    document.getElementById('cancel-add-category').addEventListener('click', () => {
        document.getElementById('add-category-modal').classList.add('hidden');
    });

    document.getElementById('confirm-add-category').addEventListener('click', () => {
        const newCategoryName = categorySearch.value;
        
        fetch('ajax_add_category.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `name=${encodeURIComponent(newCategoryName)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update UI
                categoryIdInput.value = data.data.id;
                categorySearch.value = data.data.name;
                allCategories.push({id: data.data.id, name: data.data.name}); // Update local cache
                categoryResults.classList.add('hidden');
                document.getElementById('add-category-modal').classList.add('hidden');
                alert('새 카테고리가 추가되고 선택되었습니다.');
            } else {
                alert('오류: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('카테고리 추가 중 오류가 발생했습니다.');
        });
    });

    // 브랜드 Modal-related event listeners
    document.getElementById('cancel-add-brand').addEventListener('click', () => {
        document.getElementById('add-brand-modal').classList.add('hidden');
    });

    document.getElementById('confirm-add-brand').addEventListener('click', () => {
        const newBrandName = brandSearch.value;
        
        fetch('ajax_add_brand.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `name_ko=${encodeURIComponent(newBrandName)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update UI
                brandIdInput.value = data.data.id;
                brandSearch.value = data.data.name_ko;
                allBrands.push({id: data.data.id, name_ko: data.data.name_ko}); // Update local cache
                brandResults.classList.add('hidden');
                document.getElementById('add-brand-modal').classList.add('hidden');
                alert('새 브랜드가 추가되고 선택되었습니다.');
            } else {
                alert('오류: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('브랜드 추가 중 오류가 발생했습니다.');
        });
    });

    // Hide results when clicking outside
    document.addEventListener('click', function(e) {
        if (!categorySearch.contains(e.target) && !categoryResults.contains(e.target) &&
            !brandSearch.contains(e.target) && !brandResults.contains(e.target)) {
            categoryResults.classList.add('hidden');
            brandResults.classList.add('hidden');
        }
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>