<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('navigation.wholesale_sales') . ' - ' . t('company.name');
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
    // 판매 처리 로직은 여기에 추가
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $store_id = $_SESSION['role'] === 'super_admin' ? (int)($_POST['store_id'] ?? 0) : $current_store_id;
    $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
    $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
    
    if (empty($customer_id)) {
        $errors[] = '거래처를 선택해주세요.';
    }
    
    if (empty($cart_items)) {
        $errors[] = '판매할 상품을 추가해주세요.';
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // 판매 기록 생성
            $total_amount = array_sum(array_column($cart_items, 'total_price'));
            
            $sale_stmt = $pdo->prepare("
                INSERT INTO wholesale_sales (customer_id, store_id, user_id, sale_date, total_amount, final_amount, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'confirmed', NOW())
            ");
            $sale_stmt->execute([$customer_id, $store_id, $_SESSION['user_id'], $sale_date, $total_amount, $total_amount]);
            $sale_id = $pdo->lastInsertId();
            
            // 판매 항목 추가
            foreach ($cart_items as $item) {
                $item_stmt = $pdo->prepare("
                    INSERT INTO wholesale_sale_items (sale_id, product_id, quantity, unit_price, total_price, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $item_stmt->execute([$sale_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total_price']]);
            }
            
            $pdo->commit();
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => '도매 판매가 성공적으로 등록되었습니다.'
            ];
            
            // 미리보기 페이지로 리다이렉트
            header("Location: wholesale_sale_preview.php?id=$sale_id");
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollback();
            $errors[] = '판매 등록 중 오류가 발생했습니다: ' . $e->getMessage();
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
    <div class="max-w-6xl mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_customer_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-handshake mr-1"></i>
                            도매판매
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo t('navigation.wholesale_sales'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-handshake mr-2 text-primary-500"></i>
                    <?php echo t('navigation.wholesale_sales'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600">거래처와 상품을 선택하여 도매 판매를 등록하세요.</p>
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

                <form method="POST" id="wholesale-sales-form">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- 좌측: 거래처 및 기본 정보 -->
                        <div class="space-y-6">
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h3 class="text-lg font-medium text-gray-900 mb-4">판매 정보</h3>
                                
                                <!-- 거래처 선택 -->
                                <div class="mb-4">
                                    <label for="customer_search" class="block text-sm font-medium text-gray-700 mb-2">
                                        거래처 선택 <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="text" id="customer_search" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                               placeholder="거래처명이나 전화번호를 입력해서 검색하세요..."
                                               autocomplete="off">
                                        <div id="customer_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                                            <!-- 검색 결과가 여기에 표시됩니다 -->
                                        </div>
                                    </div>
                                    
                                    <!-- 선택된 거래처 정보 표시 -->
                                    <div id="selected_customer" class="mt-3 p-3 bg-white border rounded-md hidden">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <div class="font-medium text-gray-900" id="selected_customer_name"></div>
                                                <div class="text-sm text-gray-600" id="selected_customer_info"></div>
                                            </div>
                                            <button type="button" id="clear_customer_selection" class="text-red-500 hover:text-red-700">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <input type="hidden" name="customer_id" id="customer_id" value="">
                                </div>

                                <?php if ($_SESSION['role'] === 'super_admin' && !empty($stores)): ?>
                                <!-- 점포 선택 -->
                                <div class="mb-4">
                                    <label for="store_id" class="block text-sm font-medium text-gray-700">
                                        점포 선택 <span class="text-red-500">*</span>
                                    </label>
                                    <select name="store_id" id="store_id" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                        <option value="">점포를 선택하세요</option>
                                        <?php foreach ($stores as $store): ?>
                                            <option value="<?php echo $store['id']; ?>" 
                                                    <?php echo ($store['id'] == $current_store_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($store['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                <!-- 현재 점포 표시 (수정 불가) -->
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700">
                                        점포
                                    </label>
                                    <div class="mt-1 p-3 bg-gray-50 border border-gray-300 rounded-md">
                                        <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($current_store_name); ?></span>
                                    </div>
                                    <input type="hidden" name="store_id" value="<?php echo $current_store_id; ?>">
                                </div>
                                <?php endif; ?>

                                <!-- 판매 날짜 -->
                                <div class="mb-4">
                                    <label for="sale_date" class="block text-sm font-medium text-gray-700">
                                        판매 날짜
                                    </label>
                                    <input type="date" name="sale_date" id="sale_date" value="<?php echo date('Y-m-d'); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                </div>
                            </div>

                            <!-- 상품 검색 및 추가 -->
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h3 class="text-lg font-medium text-gray-900 mb-4">상품 추가</h3>
                                
                                <div class="relative">
                                    <input type="text" id="product_search" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                           placeholder="상품명이나 SKU를 입력해서 검색하세요..."
                                           autocomplete="off">
                                    <div id="product_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                                        <!-- 검색 결과가 여기에 표시됩니다 -->
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 우측: 장바구니 -->
                        <div>
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h3 class="text-lg font-medium text-gray-900 mb-4">장바구니</h3>
                                
                                <div id="cart_empty" class="text-center text-gray-500 py-8">
                                    <i class="fas fa-shopping-cart text-4xl mb-4"></i>
                                    <p>장바구니가 비어있습니다.</p>
                                    <p class="text-sm">상품을 검색해서 추가해주세요.</p>
                                </div>
                                
                                <div id="cart_items" class="hidden">
                                    <div class="space-y-3" id="cart_list">
                                        <!-- 장바구니 항목들이 여기에 추가됩니다 -->
                                    </div>
                                    
                                    <div class="mt-6 pt-4 border-t border-gray-300">
                                        <div class="flex justify-between text-lg font-medium">
                                            <span>총 금액:</span>
                                            <span id="cart_total">0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 판매 완료 버튼 -->
                            <div class="mt-6">
                                <button type="submit" id="complete_sale_btn" 
                                        class="w-full inline-flex justify-center items-center px-6 py-3 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:bg-gray-400"
                                        disabled>
                                    <i class="fas fa-check mr-2"></i>
                                    판매 완료
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <input type="hidden" name="cart_items" id="cart_items_input" value="">
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let cart = [];
    const customerSearch = document.getElementById('customer_search');
    const customerSearchResults = document.getElementById('customer_search_results');
    const selectedCustomer = document.getElementById('selected_customer');
    const customerId = document.getElementById('customer_id');
    const clearCustomerSelection = document.getElementById('clear_customer_selection');
    
    const productSearch = document.getElementById('product_search');
    const productSearchResults = document.getElementById('product_search_results');
    
    const cartEmpty = document.getElementById('cart_empty');
    const cartItems = document.getElementById('cart_items');
    const cartList = document.getElementById('cart_list');
    const cartTotal = document.getElementById('cart_total');
    const cartItemsInput = document.getElementById('cart_items_input');
    const completeSaleBtn = document.getElementById('complete_sale_btn');
    
    let searchTimeout;
    
    // 거래처 검색
    customerSearch.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            customerSearchResults.classList.add('hidden');
            return;
        }
        
        searchTimeout = setTimeout(function() {
            searchCustomers(query);
        }, 300);
    });
    
    // 상품 검색
    productSearch.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            productSearchResults.classList.add('hidden');
            return;
        }
        
        searchTimeout = setTimeout(function() {
            searchProducts(query);
        }, 300);
    });
    
    // 거래처 선택 취소
    clearCustomerSelection.addEventListener('click', function() {
        customerId.value = '';
        selectedCustomer.classList.add('hidden');
        customerSearch.value = '';
        updateSaleButton();
    });
    
    // 검색 결과 외부 클릭시 닫기
    document.addEventListener('click', function(e) {
        if (!customerSearch.contains(e.target) && !customerSearchResults.contains(e.target)) {
            customerSearchResults.classList.add('hidden');
        }
        if (!productSearch.contains(e.target) && !productSearchResults.contains(e.target)) {
            productSearchResults.classList.add('hidden');
        }
    });
    
    function searchCustomers(query) {
        fetch('ajax_search_wholesale_customers.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.customers) {
                displayCustomerResults(data.customers);
            } else {
                customerSearchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">검색 결과가 없습니다.</div>';
                customerSearchResults.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
    
    function searchProducts(query) {
        fetch('ajax_search_wholesale_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.products) {
                displayProductResults(data.products);
            } else {
                productSearchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">검색 결과가 없습니다.</div>';
                productSearchResults.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
    
    function displayCustomerResults(customers) {
        let html = '';
        customers.forEach(function(customer) {
            html += `
                <div class="p-3 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0 customer-item" 
                     data-id="${customer.id}" 
                     data-name="${customer.name}"
                     data-phone="${customer.phone || ''}"
                     data-address="${customer.address || ''}">
                    <div class="font-medium text-gray-900">${customer.name}</div>
                    <div class="text-sm text-gray-600">${customer.phone || ''} ${customer.address || ''}</div>
                </div>
            `;
        });
        
        customerSearchResults.innerHTML = html;
        customerSearchResults.classList.remove('hidden');
        
        // 거래처 선택 이벤트
        document.querySelectorAll('.customer-item').forEach(function(item) {
            item.addEventListener('click', function() {
                selectCustomer(this);
            });
        });
    }
    
    function displayProductResults(products) {
        let html = '';
        products.forEach(function(product) {
            // 도매 SKU들 처리
            let displaySkus = '';
            if (product.wholesale_skus) {
                try {
                    const skuArray = JSON.parse(product.wholesale_skus);
                    displaySkus = Array.isArray(skuArray) ? skuArray.join(', ') : product.sku;
                } catch (e) {
                    displaySkus = product.sku;
                }
            } else {
                displaySkus = product.sku;
            }
            
            html += `
                <div class="p-3 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0 product-item" 
                     data-id="${product.id}" 
                     data-sku="${displaySkus}"
                     data-name-ko="${product.display_name_ko || ''}"
                     data-name-en="${product.display_name_en || ''}"
                     data-wholesale-price="${product.wholesale_price}"
                     data-min-quantity="${product.min_quantity}">
                    <div class="font-medium text-gray-900">
                        ${product.display_name_en || product.display_name_ko || 'N/A'}
                        ${product.display_name_en !== product.name_en || product.display_name_ko !== product.name_ko ? 
                            '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-2">도매용</span>' : ''}
                    </div>
                    <div class="text-sm text-gray-600">${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? product.display_name_ko : ''}</div>
                    <div class="text-xs text-gray-500 mt-1">
                        SKU: ${displaySkus} | 도매가: ${Number(product.wholesale_price).toLocaleString()}원
                    </div>
                </div>
            `;
        });
        
        productSearchResults.innerHTML = html;
        productSearchResults.classList.remove('hidden');
        
        // 상품 선택 이벤트
        document.querySelectorAll('.product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                addToCart(this);
            });
        });
    }
    
    function selectCustomer(item) {
        const id = item.dataset.id;
        const name = item.dataset.name;
        const phone = item.dataset.phone;
        const address = item.dataset.address;
        
        customerId.value = id;
        
        document.getElementById('selected_customer_name').textContent = name;
        document.getElementById('selected_customer_info').textContent = `${phone} ${address}`;
        
        selectedCustomer.classList.remove('hidden');
        customerSearchResults.classList.add('hidden');
        customerSearch.value = name;
        updateSaleButton();
    }
    
    function addToCart(item) {
        const productId = item.dataset.id;
        const sku = item.dataset.sku;
        const nameKo = item.dataset.nameKo;
        const nameEn = item.dataset.nameEn;
        const wholesalePrice = parseFloat(item.dataset.wholesalePrice);
        const minQuantity = parseInt(item.dataset.minQuantity);
        
        // 이미 장바구니에 있는지 확인
        const existingIndex = cart.findIndex(item => item.product_id == productId);
        
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += minQuantity;
            cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
        } else {
            cart.push({
                product_id: productId,
                sku: sku, // 이미 도매 SKU들이 처리되어 전달됨
                name_ko: nameKo, // 도매 상품명 또는 기본 상품명
                name_en: nameEn, // 도매 상품명 또는 기본 상품명
                unit_price: wholesalePrice,
                quantity: minQuantity,
                total_price: wholesalePrice * minQuantity
            });
        }
        
        updateCart();
        productSearchResults.classList.add('hidden');
        productSearch.value = '';
    }
    
    function updateCart() {
        if (cart.length === 0) {
            cartEmpty.classList.remove('hidden');
            cartItems.classList.add('hidden');
        } else {
            cartEmpty.classList.add('hidden');
            cartItems.classList.remove('hidden');
            
            let html = '';
            cart.forEach(function(item, index) {
                html += `
                    <div class="bg-white p-3 rounded-md border">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <div class="font-medium text-sm">${item.name_en || item.name_ko}</div>
                                <div class="text-xs text-gray-500">${item.sku}</div>
                                <div class="text-xs text-gray-600 mt-1">
                                    단가: ${Number(item.unit_price).toLocaleString()}원
                                </div>
                            </div>
                            <button type="button" onclick="removeFromCart(${index})" class="text-red-500 hover:text-red-700 ml-2">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="flex items-center justify-between mt-2">
                            <div class="flex items-center space-x-2">
                                <button type="button" onclick="updateQuantity(${index}, -1)" class="w-6 h-6 text-xs bg-gray-200 rounded">-</button>
                                <span class="text-sm font-medium">${item.quantity}</span>
                                <button type="button" onclick="updateQuantity(${index}, 1)" class="w-6 h-6 text-xs bg-gray-200 rounded">+</button>
                            </div>
                            <div class="text-sm font-medium">
                                ${Number(item.total_price).toLocaleString()}원
                            </div>
                        </div>
                    </div>
                `;
            });
            
            cartList.innerHTML = html;
            
            const total = cart.reduce((sum, item) => sum + item.total_price, 0);
            cartTotal.textContent = Number(total).toLocaleString() + '원';
        }
        
        cartItemsInput.value = JSON.stringify(cart);
        updateSaleButton();
    }
    
    window.removeFromCart = function(index) {
        cart.splice(index, 1);
        updateCart();
    };
    
    window.updateQuantity = function(index, change) {
        cart[index].quantity += change;
        if (cart[index].quantity <= 0) {
            cart.splice(index, 1);
        } else {
            cart[index].total_price = cart[index].quantity * cart[index].unit_price;
        }
        updateCart();
    };
    
    function updateSaleButton() {
        const hasCustomer = customerId.value !== '';
        const hasItems = cart.length > 0;
        completeSaleBtn.disabled = !hasCustomer || !hasItems;
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>