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
    $errors[] = '데이터베이스 연결 오류: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력값 검증 (새로운 필드들 추가)
    $product_id = (int)($_POST['product_id'] ?? 0);
    $wholesale_name_ko = trim($_POST['wholesale_name_ko'] ?? '');
    $wholesale_name_en = trim($_POST['wholesale_name_en'] ?? '');
    $wholesale_skus_input = trim($_POST['wholesale_skus'] ?? '');
    $wholesale_description = trim($_POST['wholesale_description'] ?? '');
    $wholesale_price = trim($_POST['wholesale_price'] ?? '');
    $min_quantity = (int)($_POST['min_quantity'] ?? 1);
    $store_id = $_SESSION['role'] === 'super_admin' ? (int)($_POST['store_id'] ?? 0) : $current_store_id;
    
    if (empty($product_id)) {
        $errors[] = '상품을 선택해주세요.';
    }
    
    // 도매 상품명 검증 (한국어 또는 영어 중 최소 하나는 필수)
    if (empty($wholesale_name_ko) && empty($wholesale_name_en)) {
        $errors[] = '도매 상품명(한국어 또는 영어) 중 최소 하나는 입력해주세요.';
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
        $errors[] = '올바른 도매가를 입력해주세요.';
    }
    
    if ($min_quantity <= 0) {
        $errors[] = '최소 주문수량은 1 이상이어야 합니다.';
    }
    
    if ($_SESSION['role'] === 'super_admin' && empty($store_id)) {
        $errors[] = '점포를 선택해주세요.';
    }
    
    if (empty($errors)) {
        try {
            // 중복 확인 (같은 점포에 같은 상품이 이미 등록되어 있는지)
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM wholesale_products WHERE product_id = ? AND store_id = ? AND is_active = 1");
            $check_stmt->execute([$product_id, $store_id]);
            
            if ($check_stmt->fetchColumn() > 0) {
                $errors[] = '이미 해당 점포에 등록된 상품입니다.';
            } else {
                // 스키마 호환성 확인
                try {
                    $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
                    $has_new_columns = $check_columns->rowCount() > 0;
                } catch (PDOException $e) {
                    $has_new_columns = false;
                }
                
                if ($has_new_columns) {
                    // 새로운 스키마 사용
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
                        'message' => '도매상품이 성공적으로 등록되었습니다.'
                    ];
                    header('Location: wholesale_product_management.php');
                    exit;
                } else {
                    $errors[] = '도매상품 등록 중 오류가 발생했습니다.';
                }
            }
        } catch (PDOException $e) {
            $errors[] = '데이터베이스 오류: ' . $e->getMessage();
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
                <p class="mt-1 text-sm text-gray-600">기존 상품에서 도매상품을 등록해주세요.</p>
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
                                <h3 class="text-sm font-medium text-red-800">다음 오류를 해결해주세요:</h3>
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
                            상품 선택 <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="text" id="product_search" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="상품명이나 SKU를 입력해서 검색하세요..."
                                   autocomplete="off">
                            <div id="product_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                                <!-- 검색 결과가 여기에 표시됩니다 -->
                            </div>
                        </div>
                        
                        <!-- 선택된 상품 정보 표시 -->
                        <div id="selected_product" class="mt-3 p-3 bg-gray-50 rounded-md hidden">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-medium text-gray-900" id="selected_product_name"></div>
                                    <div class="text-sm text-gray-600" id="selected_product_info"></div>
                                </div>
                                <button type="button" id="clear_selection" class="text-red-500 hover:text-red-700">
                                    <i class="fas fa-times"></i>
                                </button>
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
                            도매 전용 상품 정보
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- 도매 상품명 (한국어) -->
                            <div>
                                <label for="wholesale_name_ko" class="block text-sm font-medium text-gray-700">
                                    도매 상품명 (한국어) <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="wholesale_name_ko" id="wholesale_name_ko" 
                                       value="<?php echo htmlspecialchars($_POST['wholesale_name_ko'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="도매용 한국어 상품명">
                            </div>
                            
                            <!-- 도매 상품명 (영어) -->
                            <div>
                                <label for="wholesale_name_en" class="block text-sm font-medium text-gray-700">
                                    도매 상품명 (영어)
                                </label>
                                <input type="text" name="wholesale_name_en" id="wholesale_name_en" 
                                       value="<?php echo htmlspecialchars($_POST['wholesale_name_en'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="도매용 영어 상품명">
                            </div>
                        </div>
                        
                        <!-- 도매 SKU들 -->
                        <div class="mt-4">
                            <label for="wholesale_skus" class="block text-sm font-medium text-gray-700">
                                도매 SKU <span class="text-gray-500">(여러 개인 경우 쉼표로 구분)</span>
                            </label>
                            <input type="text" name="wholesale_skus" id="wholesale_skus" 
                                   value="<?php echo htmlspecialchars($_POST['wholesale_skus'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="예: WS001, WS002, WS003">
                            <p class="mt-1 text-sm text-gray-500">도매용 별도 SKU를 등록할 수 있습니다. 여러 개인 경우 쉼표(,)로 구분해주세요.</p>
                        </div>
                        
                        <!-- 도매 상품 설명 -->
                        <div class="mt-4">
                            <label for="wholesale_description" class="block text-sm font-medium text-gray-700">
                                도매 상품 설명
                            </label>
                            <textarea name="wholesale_description" id="wholesale_description" rows="3"
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                      placeholder="도매 고객에게 표시될 상품 설명을 입력하세요"><?php echo htmlspecialchars($_POST['wholesale_description'] ?? ''); ?></textarea>
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
                                <h3 class="text-sm font-medium text-yellow-800">도매 전용 기능 업그레이드 필요</h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p>독립적인 도매 상품명과 다중 SKU 기능을 사용하려면 데이터베이스 스키마 업데이트가 필요합니다.</p>
                                    <p class="mt-1">
                                        <a href="schema_update_helper.php" class="font-medium underline">
                                            여기를 클릭하여 스키마를 업데이트하세요
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($_SESSION['role'] === 'super_admin' && !empty($stores)): ?>
                    <!-- 점포 선택 -->
                    <div>
                        <label for="store_id" class="block text-sm font-medium text-gray-700">
                            점포 선택 <span class="text-red-500">*</span>
                        </label>
                        <select name="store_id" id="store_id" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="">점포를 선택하세요</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>" 
                                        <?php echo (($_POST['store_id'] ?? $current_store_id) == $store['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <!-- 현재 점포 표시 (수정 불가) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">
                            점포
                        </label>
                        <div class="mt-1 p-3 bg-gray-50 border border-gray-300 rounded-md">
                            <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($current_store_name); ?></span>
                        </div>
                        <input type="hidden" name="store_id" value="<?php echo $current_store_id; ?>">
                    </div>
                    <?php endif; ?>

                    <!-- 도매가 -->
                    <div>
                        <label for="wholesale_price" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.wholesale_price'); ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="wholesale_price" id="wholesale_price" step="0.01" min="0" required
                               value="<?php echo htmlspecialchars($_POST['wholesale_price'] ?? ''); ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="도매가를 입력하세요">
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
    const productSearch = document.getElementById('product_search');
    const searchResults = document.getElementById('product_search_results');
    const selectedProduct = document.getElementById('selected_product');
    const productId = document.getElementById('product_id');
    const clearSelection = document.getElementById('clear_selection');
    
    let searchTimeout;
    
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
                        SKU: ${product.sku} | 가격: ${Number(product.selling_price || 0).toLocaleString()}원
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
        document.getElementById('selected_product_info').textContent = `SKU: ${sku}${nameKo && nameEn ? ' | ' + nameKo : ''}`;
        
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
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>