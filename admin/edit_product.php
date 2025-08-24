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

<div class="container-fluid px-4 py-6">
    <div class="max-w-6xl mx-auto">
        <!-- Breadcrumb Navigation -->
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="product_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-cube mr-1"></i>
                            상품 관리
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600">상품 정보 수정</span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <!-- Main Content Card -->
        <div class="bg-white shadow-sm rounded-lg border">
            <!-- Card Header -->
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-edit mr-2 text-primary-500"></i>
                    상품 정보 수정
                </h1>
                <p class="mt-1 text-sm text-gray-600">상품의 기본 정보와 지점별 가격을 관리합니다.</p>
            </div>
            
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

                <form action="edit_product.php?id=<?php echo $product_id; ?>" method="post" class="space-y-6">
                    <!-- 기본 정보 섹션 -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <h3 class="text-lg font-medium text-blue-900 mb-4">
                            <i class="fas fa-info-circle mr-2"></i>
                            기본 정보
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- 상품 ID (읽기 전용) -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-hashtag mr-1 text-gray-400"></i>
                                    상품 ID
                                </label>
                                <div class="bg-gray-50 border border-gray-200 rounded-md px-3 py-2 text-sm text-gray-500">
                                    <?php echo htmlspecialchars($product['id']); ?>
                                </div>
                            </div>
                            
                            <!-- SKU -->
                            <div>
                                <label for="sku" class="block text-sm font-medium text-gray-700 mb-2">
                                    <span class="text-red-500">*</span> SKU
                                </label>
                                <input type="text" name="sku" id="sku" value="<?php echo htmlspecialchars($product['sku']); ?>" required 
                                       class="bg-gray-50 border border-gray-200 rounded-md px-3 py-3 text-sm text-gray-500 w-full font-mono">
                            </div>
                            
                            <!-- 상품명 (영문) -->
                            <div>
                                <label for="name_en" class="block text-sm font-medium text-gray-700 mb-2">
                                    <span class="text-red-500">*</span> 상품명 (영문)
                                </label>
                                <input type="text" name="name_en" id="name_en" value="<?php echo htmlspecialchars($product['name_en']); ?>" required 
                                       class="bg-gray-50 border border-gray-200 rounded-md px-3 py-3 text-sm text-gray-500 w-full">
                            </div>
                            
                            <!-- 상품명 (한글) -->
                            <div>
                                <label for="name_ko" class="block text-sm font-medium text-gray-700 mb-2">
                                    상품명 (한글)
                                </label>
                                <input type="text" name="name_ko" id="name_ko" value="<?php echo htmlspecialchars($product['name_ko']); ?>" 
                                       class="bg-gray-50 border border-gray-200 rounded-md px-3 py-3 text-sm text-gray-500 w-full">
                            </div>
                            
                            <!-- 카테고리 -->
                            <div>
                                <label for="category_search" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-sitemap mr-1 text-gray-400"></i>
                                    카테고리
                                </label>
                                <div class="relative">
                                    <input type="text" id="category_search" 
                                           class="bg-gray-50 border border-gray-200 rounded-md px-3 py-3 text-sm text-gray-500 w-full" 
                                           placeholder="카테고리 검색 또는 입력...">
                                    <input type="hidden" name="category_id" id="category_id" value="<?php echo htmlspecialchars($product['category_id']); ?>">
                                    <div id="category_results" class="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden border border-gray-200"></div>
                                </div>
                            </div>
                            
                            <!-- 브랜드 -->
                            <div>
                                <label for="brand_search" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-tags mr-1 text-gray-400"></i>
                                    브랜드
                                </label>
                                <div class="relative">
                                    <input type="text" id="brand_search" 
                                           class="bg-gray-50 border border-gray-200 rounded-md px-3 py-3 text-sm text-gray-500 w-full" 
                                           placeholder="브랜드 검색 또는 입력...">
                                    <input type="hidden" name="brand_id" id="brand_id" value="<?php echo htmlspecialchars($product['brand_id']); ?>">
                                    <div id="brand_results" class="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden border border-gray-200"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 추가 정보 -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
                            <!-- 박스당 수량 -->
                            <div>
                                <label for="pieces_per_box" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-box mr-1 text-gray-400"></i>
                                    박스당 수량
                                </label>
                                <div class="relative">
                                    <input type="number" name="pieces_per_box" id="pieces_per_box" value="<?php echo htmlspecialchars($product['pieces_per_box'] ?? 1); ?>" min="1" 
                                           class="bg-gray-50 border border-gray-200 rounded-md px-3 py-3 text-sm text-gray-500 w-full pr-10" placeholder="1">
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                        <span class="text-gray-500 sm:text-sm">개</span>
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">한 박스에 들어있는 낱개 수량</p>
                            </div>
                            
                            <!-- 상태 -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-toggle-on mr-1 text-gray-400"></i>
                                    상태
                                </label>
                                <div class="flex items-center space-x-6">
                                    <div class="flex items-center">
                                        <input id="is_active_true" name="is_active" type="radio" value="1" <?php echo ($product['is_active'] == 1) ? 'checked' : ''; ?> 
                                               class="h-4 w-4 border-gray-300 text-primary-600 focus:ring-primary-500">
                                        <label for="is_active_true" class="ml-2 text-sm text-gray-700">활성</label>
                                    </div>
                                    <div class="flex items-center">
                                        <input id="is_active_false" name="is_active" type="radio" value="0" <?php echo ($product['is_active'] == 0) ? 'checked' : ''; ?> 
                                               class="h-4 w-4 border-gray-300 text-primary-600 focus:ring-primary-500">
                                        <label for="is_active_false" class="ml-2 text-sm text-gray-700">비활성</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 상품 설명과 이미지 URL -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                            <!-- 상품 설명 -->
                            <div>
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-align-left mr-1 text-gray-400"></i>
                                    상품 설명
                                </label>
                                <textarea id="description" name="description" rows="3" 
                                          class="bg-gray-50 border border-gray-200 rounded-md px-3 py-3 text-sm text-gray-500 w-full" 
                                          placeholder="상품 설명 입력..."><?php echo htmlspecialchars($product['description']); ?></textarea>
                            </div>
                            
                            <!-- 이미지 URL -->
                            <div>
                                <label for="image_url" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-image mr-1 text-gray-400"></i>
                                    이미지 URL
                                </label>
                                <input type="url" name="image_url" id="image_url" value="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                       class="bg-gray-50 border border-gray-200 rounded-md px-3 py-3 text-sm text-gray-500 w-full" 
                                       placeholder="https://example.com/image.png">
                            </div>
                        </div>
                    </div>
                                
                    
                    <!-- 지점별 가격 정보 섹션 -->
                    <?php if (!empty($stores)): ?>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <h3 class="text-lg font-medium text-green-900 mb-4">
                            <i class="fas fa-store mr-2"></i>
                            지점별 가격 정보
                        </h3>
                        <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                            <table class="min-w-full divide-y divide-gray-300">
                                <thead class="bg-green-100">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-green-700 uppercase tracking-wider">
                                            <i class="fas fa-map-marker-alt mr-1"></i>
                                            지점명
                                        </th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-green-700 uppercase tracking-wider">
                                            <i class="fas fa-dollar-sign mr-1"></i>
                                            원가 (낱개)
                                        </th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-green-700 uppercase tracking-wider">
                                            <i class="fas fa-percentage mr-1"></i>
                                            마진율
                                        </th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-green-700 uppercase tracking-wider">
                                            <i class="fas fa-tag mr-1"></i>
                                            판매가 (낱개)
                                        </th>
                                        <?php if ($product['pieces_per_box'] > 1): ?>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-green-700 uppercase tracking-wider">
                                            <i class="fas fa-box mr-1"></i>
                                            박스원가
                                        </th>
                                        <?php endif; ?>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-green-700 uppercase tracking-wider">
                                            <i class="fas fa-warehouse mr-1"></i>
                                            재고수량
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($stores as $store): ?>
                                        <tr class="hover:bg-green-50">
                                            <td class="px-4 py-4 text-sm font-medium text-gray-900">
                                                <div class="flex items-center">
                                                    <div class="w-2 h-2 bg-green-500 rounded-full mr-2"></div>
                                                    <?php echo htmlspecialchars($store['name']); ?>
                                                </div>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-900 text-right font-mono">
                                                <?php if (isset($inventory_prices[$store['id']]['cost_price'])): ?>
                                                    <?php echo number_format($inventory_prices[$store['id']]['cost_price'], 2); ?>
                                                <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-900 text-right">
                                                <?php if (isset($inventory_prices[$store['id']]['margin_rate'])): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $inventory_prices[$store['id']]['margin_rate'] < 0 ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'; ?>">
                                                        <?php echo number_format($inventory_prices[$store['id']]['margin_rate'], 1); ?>%
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-right">
                                                <input type="number" name="store_prices[<?php echo $store['id']; ?>]" id="store_price_<?php echo $store['id']; ?>" 
                                                       value="<?php echo htmlspecialchars($inventory_prices[$store['id']]['selling_price'] ?? ''); ?>" 
                                                       class="block w-28 text-right rounded-md border-gray-300 focus:border-primary-500 focus:ring-primary-500 text-sm font-mono" 
                                                       placeholder="판매가" step="0.01">
                                            </td>
                                            <?php if ($product['pieces_per_box'] > 1): ?>
                                            <td class="px-4 py-4 text-sm text-gray-900 text-right">
                                                <?php if (isset($inventory_prices[$store['id']]['box_cost']) && $inventory_prices[$store['id']]['box_cost'] > 0): ?>
                                                    <div class="font-mono font-medium"><?php echo number_format($inventory_prices[$store['id']]['box_cost'], 2); ?></div>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        (<?php echo $product['pieces_per_box']; ?>개입)
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="px-4 py-4 text-sm text-gray-500 text-right">
                                                <?php if (isset($inventory_prices[$store['id']]['quantity'])): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                        <?php echo number_format($inventory_prices[$store['id']]['quantity']); ?>개
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                                        0개
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 p-3 bg-green-100 rounded-md">
                            <p class="text-sm text-green-700">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>참고:</strong> 판매가를 비워두면 기본 판매가가 적용됩니다. 원가와 마진율 정보는 재고 관리에서 설정됩니다.
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 액션 버튼 -->
                    <div class="flex justify-between items-center pt-6 border-t border-gray-200">
                        <a href="product_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            목록으로 돌아가기
                        </a>
                        
                        <div class="flex space-x-3">
                            <button type="reset" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                <i class="fas fa-undo mr-2"></i>
                                초기화
                            </button>
                            <button type="submit" class="inline-flex items-center px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                <i class="fas fa-save mr-2"></i>
                                변경사항 저장
                            </button>
                        </div>
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