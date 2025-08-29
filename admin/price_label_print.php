<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('navigation.price_label_print') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// Check product management permission
if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: index.php');
    exit;
}

$errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
    
    if (empty($cart_items)) {
        $errors[] = t('price_label.no_products_selected');
    }
    
    if (empty($errors)) {
        // 세션에 가격표 출력 데이터 저장 (기본 설정 사용)
        $_SESSION['price_label_data'] = [
            'label_size' => 'price_card',
            'price_type' => 'selling',
            'include_barcode' => true,
            'items' => $cart_items,
            'created_at' => time()
        ];
        
        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => t('price_label.preview_redirect')
        ];
        
        // 미리보기 페이지로 리다이렉트
        header("Location: price_label_preview.php");
        exit;
    }
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

?>

<div class="container mx-auto px-2 sm:px-3 md:px-4 py-8">
    <div class="w-full mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="index.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-home mr-1"></i>
                            <?php echo t('common.dashboard'); ?>
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo t('navigation.price_label_print'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-tags mr-2 text-primary-500"></i>
                    <?php echo t('price_label.title'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    <?php echo t('price_label.description'); ?>
                </p>
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
                                <h3 class="text-sm font-medium text-red-800"><?php echo t('price_label.resolve_errors'); ?></h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" id="price-label-form">
                    <!-- 상품 검색 및 추가 -->
                    <div class="space-y-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo t('price_label.search_and_add'); ?></h3>
                            
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <div class="flex">
                                        <input type="text" id="product_search" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-l-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                               placeholder="<?php echo t('price_label.search_placeholder'); ?>"
                                               autocomplete="off">
                                        <div class="px-3 py-2 bg-green-50 border border-l-0 border-gray-300 rounded-r-md flex items-center">
                                            <i class="fas fa-barcode text-green-600" title="<?php echo t('price_label.barcode_scan_ready'); ?>"></i>
                                        </div>
                                    </div>
                                    <div id="product_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                                        <!-- 검색 결과가 여기에 표시됩니다 -->
                                    </div>
                                </div>
                                <button type="button" id="product_search_btn" 
                                        class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 whitespace-nowrap">
                                    <i class="fas fa-search mr-1"></i>
                                    <?php echo t('common.search'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- 선택된 상품 목록 -->
                    <div class="mt-8">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                <i class="fas fa-list mr-2 text-primary-500"></i>
                                <?php echo t('price_label.selected_products'); ?>
                            </h3>
                            
                            <div id="cart_empty" class="text-center text-gray-500 py-8">
                                <i class="fas fa-tags text-4xl mb-4"></i>
                                <p><?php echo t('price_label.empty_cart'); ?></p>
                                <p class="text-sm"><?php echo t('price_label.empty_cart_instruction'); ?></p>
                            </div>
                            
                            <div id="cart_items" class="hidden">
                                <!-- 테이블 형태 상품 목록 -->
                                <div class="border rounded-md overflow-hidden cart-table-wrapper">
                                    <table class="w-full cart-table">
                                        <thead class="bg-gray-100">
                                            <tr>
                                                <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700"><?php echo t('price_label.sku'); ?></th>
                                                <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700"><?php echo t('price_label.product_name'); ?></th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700"><?php echo t('price_label.selling_price'); ?></th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700"><?php echo t('price_label.print_quantity'); ?></th>
                                                <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700"><?php echo t('common.remarks'); ?></th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700"><?php echo t('price_label.delete'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="cart_list">
                                            <!-- Cart items will be added here -->
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- 총 출력매수 -->
                                <div class="mt-4 pt-3 border-t border-gray-300">
                                    <div class="flex justify-between text-lg font-medium">
                                        <span><?php echo t('price_label.total_quantity'); ?></span>
                                        <span id="cart_total">0 <?php echo t('price_label.quantity'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 출력 버튼 -->
                        <div class="mt-8 flex justify-center space-x-3">
                            <button type="submit" id="print_preview_btn" 
                                    class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:hover:bg-gray-400 whitespace-nowrap"
                                    disabled>
                                <i class="fas fa-eye mr-1"></i>
                                <?php echo t('price_label.preview'); ?>
                            </button>
                            
                            <a href="index.php" 
                               class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                <i class="fas fa-arrow-left mr-1"></i>
                                <?php echo t('common.back'); ?>
                            </a>
                        </div>
                    </div>
                    
                    <input type="hidden" name="cart_items" id="cart_items_input" value="">
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 상품 목록 모달 -->
<div id="product-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-h-[70vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900">
                    <i class="fas fa-box mr-2 text-green-500"></i>
                    <?php echo t('price_label.product_list'); ?>
                </h3>
                <button type="button" id="close-product-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="p-3 border-b border-gray-200">
                    <input type="text" id="modal-product-search" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           placeholder="<?php echo t('price_label.filter_placeholder'); ?>">
                </div>
                <div id="product-list" class="flex-1 overflow-y-auto p-3 space-y-2 max-h-80">
                    <!-- 상품 목록이 여기에 표시됩니다 -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// JavaScript translations object
const translations = <?php echo get_js_translations([
    'price_label.loading',
    'price_label.no_products', 
    'price_label.product_load_error',
    'price_label.no_results',
    'price_label.search_error',
    'price_label.product_added',
    'price_label.remove_confirm',
    'price_label.exact_match',
    'price_label.stock',
    'common.items',
    'common.currency',
    'price_label.barcode_error',
    'price_label.no_sku',
    'price_label.print_content_empty',
    'price_label.barcode_generation_error'
]); ?>;

document.addEventListener('DOMContentLoaded', function() {
    let cart = [];
    
    // 폼 제출 방지 (Enter 키 실수 방지)
    const priceLabelForm = document.getElementById('price-label-form');
    priceLabelForm.addEventListener('submit', function(e) {
        e.preventDefault(); // 실수로 Enter를 눌렀을 때 폼 제출 방지
        return false;
    });
    
    const productSearch = document.getElementById('product_search');
    const productSearchResults = document.getElementById('product_search_results');
    
    const cartEmpty = document.getElementById('cart_empty');
    const cartItems = document.getElementById('cart_items');
    const cartList = document.getElementById('cart_list');
    const cartTotal = document.getElementById('cart_total');
    const cartItemsInput = document.getElementById('cart_items_input');
    const printPreviewBtn = document.getElementById('print_preview_btn');
    
    console.log('DOM 요소들 초기화 완료:');
    console.log('- printPreviewBtn:', printPreviewBtn);
    console.log('- cartItemsInput:', cartItemsInput);
    console.log('- printPreviewBtn이 null인가?', printPreviewBtn === null);
    
    // 미리보기 버튼 클릭 이벤트
    printPreviewBtn.addEventListener('click', function(e) {
        e.preventDefault();
        console.log('미리보기 버튼 클릭됨, cart:', cart);
        
        if (cart.length === 0) {
            showNotification('<?php echo t("price_label.no_products_selected"); ?>', 'error');
            return;
        }
        
        // 프라이스카드 모달 열기
        window.openPriceCardModal();
    });
    
    let searchTimeout;
    
    // 모달 관련 요소들
    const productModal = document.getElementById('product-modal');
    const closeProductModal = document.getElementById('close-product-modal');
    const productSearchBtn = document.getElementById('product_search_btn');
    const productList = document.getElementById('product-list');
    const modalProductSearch = document.getElementById('modal-product-search');
    
    // 상품 검색 (타이핑 시 미리보기용)
    productSearch.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 1) {
            productSearchResults.classList.add('hidden');
            return;
        }
        
        // 짧은 검색어는 딜레이를 더 많이
        const delay = query.length < 3 ? 500 : 300;
        
        searchTimeout = setTimeout(function() {
            searchProducts(query);
        }, delay);
    });

    // Enter 키 이벤트 처리 - 폼 제출 방지 및 검색/추가 실행
    productSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault(); // 폼 제출 방지
            const query = this.value.trim();
            if (query.length > 0) {
                searchAndAutoAdd(query);
            }
        }
    });
    
    // 상품 검색 버튼 클릭 - 모달 표시
    productSearchBtn.addEventListener('click', function() {
        const query = productSearch.value.trim();
        if (query.length === 0) {
            // 빈 검색어일 때 전체 목록을 모달로 표시
            showProductModal();
        } else {
            // 검색어가 있으면 검색 실행
            searchProducts(query);
        }
    });
    
    // 모달 닫기 이벤트
    closeProductModal.addEventListener('click', function() {
        productModal.classList.add('hidden');
    });
    
    // 모달 외부 클릭시 닫기
    productModal.addEventListener('click', function(e) {
        if (e.target === productModal) {
            productModal.classList.add('hidden');
        }
    });
    
    // ESC 키로 모달 닫기
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            productModal.classList.add('hidden');
        }
    });
    
    // 모달 내 검색 기능
    modalProductSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const items = productList.querySelectorAll('.modal-product-item');
        
        items.forEach(function(item) {
            const name = (item.dataset.nameEn || '').toLowerCase() + ' ' + (item.dataset.nameKo || '').toLowerCase();
            const sku = item.dataset.sku.toLowerCase();
            
            if (name.includes(query) || sku.includes(query)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });
    
    // 검색 결과 외부 클릭시 닫기
    document.addEventListener('click', function(e) {
        if (!productSearch.contains(e.target) && !productSearchResults.contains(e.target)) {
            productSearchResults.classList.add('hidden');
        }
    });
    
    function showNotification(message, type = 'info') {
        // 기존 알림이 있으면 제거
        const existingNotification = document.querySelector('.notification');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        // 새 알림 생성
        const notification = document.createElement('div');
        notification.className = `notification fixed top-4 right-4 z-50 px-4 py-3 rounded-md shadow-lg max-w-sm ${
            type === 'success' ? 'bg-green-100 border border-green-200 text-green-800' : 
            type === 'error' ? 'bg-red-100 border border-red-200 text-red-800' : 
            'bg-blue-100 border border-blue-200 text-blue-800'
        }`;
        
        notification.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${
                    type === 'success' ? 'fa-check-circle' : 
                    type === 'error' ? 'fa-exclamation-triangle' : 
                    'fa-info-circle'
                } mr-2"></i>
                <span class="text-sm font-medium">${message}</span>
                <button class="ml-3 text-gray-400 hover:text-gray-600" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // 3초 후 자동 제거
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 3000);
    }
    
    function searchProducts(query) {
        fetch('ajax_search_price_label_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.products) {
                // 바코드 정확 일치 검색의 경우 바로 장바구니에 추가
                if (data.products.length === 1 && data.products[0].exact_match) {
                    const product = data.products[0];
                    addProductToCart(product);
                    productSearch.value = ''; // 검색어 클리어
                    
                    // 성공 알림 표시
                    showNotification(`${product.name_ko || product.name_en} ${translations['price_label.product_added'] || '<?php echo t("price_label.product_added"); ?>'}`, 'success');
                    
                    // 검색 결과 숨기기 (약간의 지연을 두어 사용자가 확인할 수 있도록)
                    setTimeout(() => {
                        productSearchResults.classList.add('hidden');
                    }, 1000);
                } else {
                    displayProductResults(data.products);
                }
            } else {
                productSearchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">' + (translations['price_label.no_results'] || '<?php echo t("price_label.no_results"); ?>') + '</div>';
                productSearchResults.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }

    function searchAndAutoAdd(query) {
        fetch('ajax_search_price_label_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(response => response.json())
        .then(data => {
            console.log('searchAndAutoAdd 응답:', data);
            if (data.success && data.products && data.products.length > 0) {
                // 첫 번째 상품을 자동으로 장바구니에 추가
                const product = data.products[0];
                addProductToCart(product);
                productSearch.value = ''; // 검색어 클리어
                
                // 성공 알림 표시
                const productName = product.name_ko || product.name_en || 'Unknown';
                showNotification(`${productName} ${translations['price_label.product_added'] || '<?php echo t("price_label.product_added"); ?>'}`, 'success');
                
                // 검색 결과 숨기기
                productSearchResults.classList.add('hidden');
                
                // 포커스를 검색창에 유지
                setTimeout(() => {
                    productSearch.focus();
                }, 100);
            } else {
                console.log('검색 결과 없음 또는 실패:', data);
                // 검색 결과가 없을 때 일반 검색 표시
                displayProductResults([]);
                showNotification(translations['price_label.no_results'] || '<?php echo t("price_label.no_results"); ?>', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification(translations['price_label.search_error'] || '<?php echo t("price_label.search_error"); ?>', 'error');
        });
    }
    
    function displayProductResults(products) {
        let html = '';
        products.forEach(function(product) {
            // 일반 상품 정보 표시
            const sellingPrice = product.current_selling_price || product.selling_price;
            const costPrice = product.current_cost_price || product.cost_price || 0;
            
            html += `
                <div class="p-3 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0 product-item" 
                     data-id="${product.id}" 
                     data-sku="${product.sku}"
                     data-name-ko="${product.name_ko || ''}"
                     data-name-en="${product.name_en || ''}"
                     data-selling-price="${sellingPrice}"
                     data-cost-price="${costPrice}"
                     data-pieces-per-box="${product.pieces_per_box || 1}"
                     data-stock="${product.stock || 0}">
                    <div class="font-medium text-gray-900">
                        ${product.name_en || product.name_ko || 'N/A'}
                        ${product.exact_match ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 ml-2">' + (translations['price_label.exact_match'] || '<?php echo t("price_label.exact_match"); ?>') + '</span>' : ''}
                    </div>
                    <div class="text-sm text-gray-600">${product.name_ko && product.name_en && product.name_ko !== product.name_en ? product.name_ko : ''}</div>
                    <div class="text-xs text-gray-500 mt-1">
                        <?php echo t('price_label.sku'); ?>: ${product.sku} | <?php echo t('price_label.selling_price'); ?>: ${Number(sellingPrice).toLocaleString()}<?php echo t('common.currency'); ?> | <?php echo t('price_label.stock'); ?>: ${product.stock}<?php echo t('common.items'); ?>
                        ${product.brand_name ? ' | ' + product.brand_name : ''}
                    </div>
                </div>
            `;
        });
        
        productSearchResults.innerHTML = html;
        productSearchResults.classList.remove('hidden');
        
        // 등록된 상품 선택 이벤트만 바인딩
        document.querySelectorAll('.product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                addToCart(this);
            });
        });
    }
    
    function addProductToCart(product) {
        console.log('addProductToCart 호출됨:', product);
        const sellingPrice = product.current_selling_price || product.selling_price;
        const costPrice = product.current_cost_price || product.cost_price || 0;
        
        // 이미 장바구니에 있는지 확인
        const existingIndex = cart.findIndex(item => item.product_id == product.id);
        
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1; // 출력매수 1개씩 증가
            console.log('기존 상품 수량 증가:', cart[existingIndex]);
        } else {
            const newItem = {
                product_id: product.id,
                sku: product.sku,
                name_ko: product.name_ko,
                name_en: product.name_en,
                selling_price: sellingPrice,
                cost_price: costPrice,
                quantity: 1, // 기본 출력매수는 1개
                pieces_per_box: product.pieces_per_box || 1,
                stock: product.stock || 0,
                remarks: '' // 상품별 비고란 추가
            };
            cart.push(newItem);
            console.log('새 상품 장바구니에 추가:', newItem);
        }
        
        console.log('장바구니 업데이트 전 cart 길이:', cart.length);
        console.log('장바구니 업데이트 전 cart 내용:', cart);
        updateCart();
        console.log('장바구니 업데이트 후 cart 길이:', cart.length);
    }

    function addToCart(item) {
        const productId = item.dataset.id;
        const sku = item.dataset.sku;
        const nameKo = item.dataset.nameKo;
        const nameEn = item.dataset.nameEn;
        const sellingPrice = parseFloat(item.dataset.sellingPrice);
        const costPrice = parseFloat(item.dataset.costPrice) || 0;
        const piecesPerBox = parseInt(item.dataset.piecesPerBox) || 1;
        const stock = parseInt(item.dataset.stock) || 0;
        
        // 이미 장바구니에 있는지 확인
        const existingIndex = cart.findIndex(item => item.product_id == productId);
        
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1; // 출력매수 1개씩 증가
        } else {
            cart.push({
                product_id: productId,
                sku: sku,
                name_ko: nameKo,
                name_en: nameEn,
                selling_price: sellingPrice,
                cost_price: costPrice,
                quantity: 1, // 기본 출력매수는 1개
                pieces_per_box: piecesPerBox,
                stock: stock,
                remarks: '' // 상품별 비고란 추가
            });
        }
        
        updateCart();
        productSearchResults.classList.add('hidden');
        productSearch.value = '';
    }
    
    function updateCart() {
        console.log('updateCart 시작 - cart 길이:', cart.length);
        if (cart.length === 0) {
            cartEmpty.classList.remove('hidden');
            cartItems.classList.add('hidden');
            console.log('빈 장바구니 화면 표시');
        } else {
            cartEmpty.classList.add('hidden');
            cartItems.classList.remove('hidden');
            console.log('장바구니 항목 표시');
            
            let html = '';
            cart.forEach(function(item, index) {
                html += `
                    <tr class="border-b hover:bg-gray-50">
                        <!-- SKU -->
                        <td class="px-2 py-3 text-xs font-mono text-gray-700 font-medium sku-column">${item.sku}</td>
                        
                        <!-- 상품명 (영문 위, 한글 아래) -->
                        <td class="px-2 py-3 product-name">
                            <div class="text-sm font-medium text-gray-900" title="${item.name_en || '-'}">${item.name_en || '-'}</div>
                            ${item.name_ko && item.name_ko !== item.name_en ? 
                                `<div class="text-sm text-gray-600 mt-1" title="${item.name_ko}">${item.name_ko}</div>` : 
                                ''
                            }
                        </td>
                        
                        <!-- 판매가 -->
                        <td class="px-2 py-3 text-center">
                            <div class="text-sm font-medium text-gray-700">
                                ${Number(item.selling_price).toLocaleString()}원
                            </div>
                        </td>
                        
                        <!-- 출력매수 -->
                        <td class="px-2 py-3 text-center">
                            <div class="flex items-center justify-center space-x-1 quantity-controls">
                                <button type="button" onclick="updateQuantity(${index}, -1)" 
                                        class="w-6 h-6 text-xs bg-gray-200 hover:bg-gray-300 rounded flex items-center justify-center">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <span class="text-sm font-medium px-2 min-w-[24px] text-center">${item.quantity}</span>
                                <button type="button" onclick="updateQuantity(${index}, 1)" 
                                        class="w-6 h-6 text-xs bg-gray-200 hover:bg-gray-300 rounded flex items-center justify-center">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </td>
                        
                        <!-- 비고 -->
                        <td class="px-2 py-3">
                            <input type="text" 
                                   class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500" 
                                   placeholder="비고"
                                   value="${item.remarks || ''}"
                                   onchange="updateRemarks(${index}, this.value)"
                                   maxlength="100">
                        </td>
                        
                        <!-- 삭제버튼 -->
                        <td class="px-2 py-3 text-center">
                            <button type="button" onclick="removeFromCart(${index})" 
                                    class="text-red-400 hover:text-red-600 w-6 h-6 rounded hover:bg-red-50 flex items-center justify-center">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            cartList.innerHTML = html;
            
            const totalQuantity = cart.reduce((sum, item) => sum + item.quantity, 0);
            cartTotal.textContent = totalQuantity + ' 매';
        }
        
        cartItemsInput.value = JSON.stringify(cart);
        console.log('cartItemsInput에 데이터 설정 완료, updatePrintButton 호출 예정');
        updatePrintButton();
        console.log('updatePrintButton 호출 완료');
    }
    
    function updatePrintButton() {
        console.log('updatePrintButton 호출됨, cart.length:', cart.length);
        console.log('printPreviewBtn 요소:', printPreviewBtn);
        console.log('printPreviewBtn이 null인가?', printPreviewBtn === null);
        
        if (printPreviewBtn) {
            console.log('버튼 업데이트 전 disabled 상태:', printPreviewBtn.disabled);
            console.log('버튼 업데이트 전 className:', printPreviewBtn.className);
            
            const isDisabled = cart.length === 0;
            printPreviewBtn.disabled = isDisabled;
            
            // CSS 클래스도 강제로 업데이트
            if (isDisabled) {
                printPreviewBtn.className = printPreviewBtn.className.replace('bg-blue-500', 'bg-gray-400').replace('hover:bg-blue-600', 'hover:bg-gray-400');
            } else {
                printPreviewBtn.className = printPreviewBtn.className.replace('bg-gray-400', 'bg-blue-500').replace('hover:bg-gray-400', 'hover:bg-blue-600');
            }
            
            console.log('버튼 업데이트 후 disabled 상태:', printPreviewBtn.disabled);
            console.log('버튼 업데이트 후 className:', printPreviewBtn.className);
            console.log('cart.length === 0?', cart.length === 0);
        } else {
            console.error('printPreviewBtn 요소를 찾을 수 없습니다!');
        }
    }
    
    // 전체 상품 목록 로드
    function showProductModal() {
        productModal.classList.remove('hidden');
        modalProductSearch.value = '';
        loadAllProducts();
    }
    
    function loadAllProducts() {
        productList.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin mr-2"></i>' + (translations['price_label.loading'] || '<?php echo t("price_label.loading"); ?>') + '</div>';
        
        fetch('ajax_search_price_label_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=&limit=100&show_all=1' // 전체 목록 요청
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.products) {
                displayModalProductList(data.products);
            } else {
                productList.innerHTML = '<div class="text-center py-4 text-gray-500">' + (translations['price_label.no_products'] || '<?php echo t("price_label.no_products"); ?>') + '</div>';
            }
        })
        .catch(error => {
            console.error(translations.error_products, error);
            productList.innerHTML = '<div class="text-center py-4 text-red-500">' + (translations['price_label.product_load_error'] || '<?php echo t("price_label.product_load_error"); ?>') + '</div>';
        });
    }
    
    // 모달 상품 목록 표시
    function displayModalProductList(products) {
        let html = '';
        products.forEach(function(product) {
            html += `
                <div class="modal-product-item p-3 border border-gray-200 rounded-md hover:bg-green-50 cursor-pointer transition-colors duration-200" 
                     data-id="${product.id}" 
                     data-sku="${product.sku}"
                     data-name-ko="${product.name_ko || ''}"
                     data-name-en="${product.name_en || ''}"
                     data-selling-price="${product.selling_price}"
                     data-cost-price="${product.cost_price || 0}"
                     data-pieces-per-box="${product.pieces_per_box || 1}"
                     data-stock="${product.stock || 0}">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-sm truncate">
                                ${product.name_en || product.name_ko || 'N/A'}
                                ${product.stock < 5 && product.stock > 0 ? '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 ml-1"><?php echo t("price_label.low_stock"); ?></span>' : ''}
                                ${product.stock === 0 ? '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 ml-1"><?php echo t("price_label.out_of_stock"); ?></span>' : ''}
                            </div>
                            ${product.name_ko && product.name_en && product.name_ko !== product.name_en ? 
                                `<div class="text-xs text-gray-600 mt-1 truncate">${product.name_ko}</div>` : ''}
                            <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-2">
                                <span><i class="fas fa-barcode mr-1"></i>${product.sku}</span>
                                <span class="text-green-600 font-medium"><i class="fas fa-won-sign mr-1"></i>${Number(product.selling_price).toLocaleString()}<?php echo t('common.currency'); ?></span>
                                <span><i class="fas fa-box-open mr-1"></i><?php echo t('price_label.stock'); ?>: ${product.stock}<?php echo t('common.items'); ?></span>
                                ${product.brand_name ? `<span><i class="fas fa-tag mr-1"></i>${product.brand_name}</span>` : ''}
                            </div>
                        </div>
                        <div class="text-green-500 ml-2">
                            <i class="fas fa-plus-circle text-lg"></i>
                        </div>
                    </div>
                </div>
            `;
        });
        
        productList.innerHTML = html;
        
        // 클릭 이벤트 추가
        productList.querySelectorAll('.modal-product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                addToCartFromModal(this);
            });
        });
    }
    
    // 모달에서 상품을 장바구니에 추가
    function addToCartFromModal(item) {
        const productId = item.dataset.id;
        const sku = item.dataset.sku;
        const nameKo = item.dataset.nameKo;
        const nameEn = item.dataset.nameEn;
        const sellingPrice = parseFloat(item.dataset.sellingPrice);
        const costPrice = parseFloat(item.dataset.costPrice) || 0;
        const piecesPerBox = parseInt(item.dataset.piecesPerBox) || 1;
        const stock = parseInt(item.dataset.stock) || 0;
        
        // 기존 addToCart 함수와 동일한 로직
        const existingIndex = cart.findIndex(item => item.product_id == productId);
        
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1; // 출력매수는 1개씩 증가
        } else {
            cart.push({
                product_id: productId,
                sku: sku,
                name_ko: nameKo,
                name_en: nameEn,
                selling_price: sellingPrice,
                cost_price: costPrice,
                quantity: 1, // 기본 출력매수는 1개
                pieces_per_box: piecesPerBox,
                stock: stock,
                remarks: '' // 상품별 비고란 추가
            });
        }
        
        updateCart();
        
        // 모달 닫기
        productModal.classList.add('hidden');
    }
    
    window.removeFromCart = function(index) {
        if (confirm(translations['price_label.remove_confirm'] || '<?php echo t("price_label.remove_confirm"); ?>')) {
            cart.splice(index, 1);
            updateCart();
        }
    };
    
    window.updateQuantity = function(index, change) {
        cart[index].quantity += change;
        if (cart[index].quantity <= 0) {
            cart.splice(index, 1);
        }
        updateCart();
    };
    
    window.updateRemarks = function(index, value) {
        cart[index].remarks = value;
        cartItemsInput.value = JSON.stringify(cart);
    };
    
    // 프라이스카드 모달 열기
    window.openPriceCardModal = function() {
        if (cart.length === 0) {
            showNotification('<?php echo t("price_label.no_products_selected"); ?>', 'error');
            return;
        }
        
        document.getElementById('priceCardModal').classList.remove('hidden');
        generatePriceCardContent();
    }
    
    // 프라이스카드 모달 닫기
    window.closePriceCardModal = function() {
        document.getElementById('priceCardModal').classList.add('hidden');
    }
    
    // 프라이스카드 내용 생성
    function generatePriceCardContent() {
        const container = document.getElementById('priceCardContent');
        container.innerHTML = '<div class="text-center py-8 text-gray-500"><?php echo t("price_label.loading"); ?></div>';
        
        // 장바구니 상품들로 프라이스카드 생성
        let cardsHtml = '';
        
        cart.forEach(item => {
            const sku = item.sku || 'N/A';
            let productNameEn = item.name_en || '';
            let productNameKo = item.name_ko || '';
            const sellingPrice = item.selling_price || 0;
            const quantity = item.quantity || 1;
            
            // 수량만큼 프라이스카드 생성
            for (let i = 0; i < quantity; i++) {
                // 영문 상품명 2줄 처리
                let productNameEnLine1 = '';
                let productNameEnLine2 = '';
                
                if (productNameEn.length > 20) {
                    // 단어 단위로 분할하되, 공백이 없으면 강제 분할
                    const words = productNameEn.split(' ');
                    let line1 = '';
                    let line2 = '';
                    
                    for (let word of words) {
                        if ((line1 + ' ' + word).trim().length <= 20) {
                            line1 = (line1 + ' ' + word).trim();
                        } else {
                            line2 = (line2 + ' ' + word).trim();
                        }
                    }
                    
                    // 첫 번째 줄이 너무 길면 강제 분할
                    if (line1.length > 20) {
                        productNameEnLine1 = line1.substring(0, 20);
                        productNameEnLine2 = line1.substring(20) + ' ' + line2;
                    } else {
                        productNameEnLine1 = line1;
                        productNameEnLine2 = line2;
                    }
                    
                    // 두 번째 줄도 길면 자르기
                    if (productNameEnLine2.length > 20) {
                        productNameEnLine2 = productNameEnLine2.substring(0, 17) + '...';
                    }
                } else {
                    productNameEnLine1 = productNameEn;
                    productNameEnLine2 = '';
                }
                
                // 한글 길이 제한
                let displayNameKo = productNameKo;
                if (displayNameKo.length > 20) {
                    displayNameKo = displayNameKo.substring(0, 17) + '...';
                }
                
                cardsHtml += `
                    <table>
                        <tr class="name-row">
                            <td colspan="2" class="name-cell">
                                <div class="product-name-en-line1">${productNameEnLine1}</div>
                                ${productNameEnLine2 ? `<div class="product-name-en-line2">${productNameEnLine2}</div>` : ''}
                                <div class="product-name-ko">${displayNameKo}</div>
                            </td>
                        </tr>
                        <tr class="divider-row">
                            <td colspan="2" class="divider-line"></td>
                        </tr>
                        <tr class="content-row">
                            <td class="barcode-cell">
                                <svg class="price-card-barcode" data-sku="${sku}"></svg>
                            </td>
                            <td class="price-cell">
                                ${typeof sellingPrice === 'string' ? parseInt(parseFloat(sellingPrice)).toLocaleString('ko-KR') : parseInt(sellingPrice).toLocaleString('ko-KR')}
                            </td>
                        </tr>
                    </table>
                `;
            }
        });
        
        container.innerHTML = cardsHtml;
        
        // 바코드 생성
        setTimeout(() => {
            generatePriceCardBarcodes();
        }, 100);
    }
    
    // 프라이스카드 바코드 생성 (Rongta TSC 최적화)
    function generatePriceCardBarcodes() {
        const barcodeElements = document.querySelectorAll('.price-card-barcode');
        barcodeElements.forEach(element => {
            const sku = element.getAttribute('data-sku');
            if (sku && sku !== 'N/A') {
                try {
                    // EAN-13 형식 확인 (13자리 숫자)
                    if (/^\d{13}$/.test(sku)) {
                        // EAN-13 바코드 생성
                        JsBarcode(element, sku, {
                            format: "EAN13",
                            width: 1.0,
                            height: 28,
                            displayValue: true,
                            fontSize: 8,
                            fontOptions: "bold",
                            textMargin: 1,
                            margin: 1,
                            background: "#ffffff",
                            lineColor: "#000000"
                        });
                    } else if (/^\d{12}$/.test(sku)) {
                        // UPC-A (12자리) - EAN-13으로 변환하여 생성
                        const ean13 = '0' + sku; // 앞에 0 추가
                        JsBarcode(element, ean13, {
                            format: "EAN13",
                            width: 1.0,
                            height: 28,
                            displayValue: true,
                            fontSize: 8,
                            fontOptions: "bold",
                            textMargin: 1,
                            margin: 1,
                            background: "#ffffff",
                            lineColor: "#000000"
                        });
                    } else if (/^\d{8}$/.test(sku)) {
                        // EAN-8 바코드 생성
                        JsBarcode(element, sku, {
                            format: "EAN8",
                            width: 1.2,
                            height: 28,
                            displayValue: true,
                            fontSize: 8,
                            fontOptions: "bold",
                            textMargin: 1,
                            margin: 1,
                            background: "#ffffff",
                            lineColor: "#000000"
                        });
                    } else {
                        // 기타 형식은 CODE128로 처리
                        JsBarcode(element, sku, {
                            format: "CODE128",
                            width: 1.2,
                            height: 28,
                            displayValue: true,
                            fontSize: 8,
                            fontOptions: "bold",
                            textMargin: 1,
                            margin: 1,
                            background: "#ffffff",
                            lineColor: "#000000"
                        });
                    }
                } catch (e) {
                    console.error('바코드 생성 실패:', sku, e);
                    // 실패 시 CODE128로 재시도
                    try {
                        JsBarcode(element, sku, {
                            format: "CODE128",
                            width: 1.2,
                            height: 28,
                            displayValue: true,
                            fontSize: 8,
                            fontOptions: "bold",
                            textMargin: 1,
                            margin: 1,
                            background: "#ffffff",
                            lineColor: "#000000"
                        });
                    } catch (e2) {
                        element.innerHTML = '<text style="font-size: 10px; font-weight: bold;"><?php echo t("price_label.barcode_error"); ?></text>';
                    }
                }
            } else {
                element.innerHTML = '<text style="font-size: 10px; color: #666;"><?php echo t("price_label.no_sku"); ?></text>';
            }
        });
    }
    
    // 프라이스카드 인쇄
    window.printPriceCards = function() {
        const container = document.getElementById('priceCardContent');
        
        // 바코드 생성 완료를 기다리는 함수
        function waitForBarcodes(callback, maxAttempts = 15) {
            let attempts = 0;
            
            function check() {
                attempts++;
                
                if (checkBarcodesReady(container)) {
                    callback();
                } else if (attempts < maxAttempts) {
                    setTimeout(check, 200);
                } else {
                    callback(); // 타임아웃되어도 인쇄 시도
                }
            }
            
            check();
        }
        
        waitForBarcodes(() => {
            const printContent = container.innerHTML;
            
            // 내용 확인
            if (!printContent || printContent.trim() === '') {
                alert('<?php echo t("price_label.print_content_empty"); ?>');
                return;
            }
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title>Price Cards - Rongta TSC</title>
                    <style>
                        @media print {
                            @page {
                                size: 72mm 30mm;
                                margin: 0;
                            }
                            
                            body {
                                margin: 0;
                                padding: 0;
                                font-family: "Arial Black", Arial, sans-serif;
                                font-size: 12px;
                                line-height: 1.2;
                                -webkit-print-color-adjust: exact;
                                color-adjust: exact;
                                width: 72mm;
                                height: 30mm;
                                display: flex;
                                flex-direction: column;
                                align-items: center;
                                overflow: hidden;
                            }
                            
                            table {
                                margin-top: 0 !important;
                                height: 28mm !important;
                                padding: 1mm !important;
                            }
                        }
                        
                        body {
                            font-family: "Arial Black", Arial, sans-serif;
                            margin: 0;
                            padding: 0;
                            width: 72mm;
                            height: 30mm;
                            display: flex;
                            flex-direction: column;
                            align-items: center;
                            overflow: hidden;
                        }
                        
                        table {
                            border-collapse: collapse;
                            width: 70mm;
                            height: 28mm;
                            border: none;
                            margin: 0 auto;
                            page-break-inside: avoid;
                            background: white;
                            padding: 1mm;
                        }
                        
                        td {
                            border: none;
                            padding: 0.5mm;
                            vertical-align: middle;
                        }
                        
                        .name-row {
                            height: 16mm;
                        }
                        
                        .name-cell {
                            text-align: center;
                            padding: 0.3mm;
                        }
                        
                        .product-name-en-line1 {
                            font-size: 11pt;
                            font-weight: bold;
                            line-height: 1.0;
                            margin-bottom: 0.2mm;
                        }
                        
                        .product-name-en-line2 {
                            font-size: 11pt;
                            font-weight: bold;
                            line-height: 1.0;
                            margin-bottom: 0.2mm;
                        }
                        
                        .product-name-ko {
                            font-size: 10pt;
                            font-weight: bold;
                            line-height: 1.0;
                        }
                        
                        .divider-row {
                            height: 1mm;
                        }
                        
                        .divider-line {
                            height: 1mm;
                            border-bottom: 1px solid #000;
                            padding: 0;
                        }
                        
                        .content-row {
                            height: 11mm;
                        }
                        
                        .barcode-cell {
                            width: 42mm;
                            text-align: center;
                            padding: 0.5mm;
                        }
                        
                        .price-cell {
                            width: 28mm;
                            text-align: center;
                            font-size: 26pt;
                            font-weight: bold;
                            padding: 0.5mm;
                        }
                        
                        .price-card-barcode {
                            width: 32mm !important;
                            height: 10mm !important;
                            display: block;
                            margin: 0 auto;
                        }
                        
                        /* 바코드 텍스트 스타일 */
                        .price-card-barcode text {
                            font-weight: bold !important;
                            font-family: "Arial Black", Arial, sans-serif !important;
                        }
                        
                        /* TSC 프린터 최적화 */
                        * {
                            -webkit-print-color-adjust: exact;
                            color-adjust: exact;
                        }
                    </style>
                </head>
                <body>
                    ${printContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            
            // 프린트 윈도우가 로드된 후 인쇄
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                }, 100);
            };
        });
    }
    
    // 바코드 생성 완료를 확인하는 함수
    function checkBarcodesReady(container) {
        const barcodes = container.querySelectorAll('.price-card-barcode');
        if (barcodes.length === 0) return false;
        
        let allReady = true;
        barcodes.forEach(barcode => {
            const svgElement = barcode.querySelector('svg');
            if (!svgElement || svgElement.children.length === 0) {
                allReady = false;
            }
        });
        
        return allReady;
    }
    
    // 숫자 형식화 함수
    function number_format(number) {
        if (typeof number === 'string') {
            return parseInt(parseFloat(number)).toLocaleString('ko-KR');
        }
        return parseInt(number).toLocaleString('ko-KR');
    }
    
    // 모달 외부 클릭시 닫기
    document.getElementById('priceCardModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closePriceCardModal();
        }
    });
});
</script>

<!-- Price Card Print Modal -->
<div id="priceCardModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900"><?php echo t('price_label.print_price_cards'); ?></h3>
                <button onclick="closePriceCardModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="flex items-center justify-center gap-4 mb-4 p-3 bg-gray-50 rounded-lg">
                <button onclick="printPriceCards()" class="px-6 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-semibold">
                    <i class="fas fa-print mr-2"></i><?php echo t('price_label.print_price_cards'); ?>
                </button>
            </div>
            
            <!-- 프라이스카드 미리보기 -->
            <div id="priceCardContent" class="border rounded-lg p-4 bg-gray-50 text-center" style="max-height: 600px; overflow-y: auto;">
                <!-- 프라이스카드들이 여기에 생성됩니다 -->
            </div>
            
            <style>
                /* 미리보기용 CSS - 실제 크기 표시 */
                #priceCardContent table {
                    width: 70mm;
                    height: 28mm;
                    border: 1px solid #000;
                    margin: 5mm auto;
                    background: white;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                    border-collapse: collapse;
                }
                
                #priceCardContent .name-cell {
                    height: 11mm;
                    text-align: center;
                    padding: 0.3mm;
                    vertical-align: middle;
                }
                
                #priceCardContent .product-name-en-line1 {
                    font-size: 9pt;
                    font-weight: bold;
                    line-height: 1.0;
                    margin-bottom: 0.2mm;
                }
                
                #priceCardContent .product-name-en-line2 {
                    font-size: 9pt;
                    font-weight: bold;
                    line-height: 1.0;
                    margin-bottom: 0.2mm;
                }
                
                #priceCardContent .product-name-ko {
                    font-size: 8pt;
                    font-weight: normal;
                    line-height: 1.0;
                }
                
                #priceCardContent .divider-row {
                    height: 1mm;
                }
                
                #priceCardContent .divider-line {
                    height: 1mm;
                    border-bottom: 1px solid #000;
                    padding: 0;
                }
                
                #priceCardContent .content-row {
                    height: 16mm;
                }
                
                #priceCardContent .barcode-cell {
                    width: 42mm;
                    text-align: center;
                    padding: 1mm;
                    vertical-align: middle;
                }
                
                #priceCardContent .price-cell {
                    width: 28mm;
                    text-align: center;
                    border-left: 1px solid #000;
                    font-size: 14pt;
                    font-weight: bold;
                    padding: 1mm;
                    vertical-align: middle;
                }
                
                #priceCardContent .price-card-barcode {
                    width: 38mm !important;
                    height: 14mm !important;
                    display: block;
                    margin: 0 auto;
                }
                
                #priceCardContent td {
                    border: none;
                    padding: 0.5mm;
                }
            </style>
        </div>
    </div>
</div>

<!-- JsBarcode 라이브러리 -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>