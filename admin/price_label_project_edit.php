<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('price_label.edit_project') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 상품 관리 권한 확인
if (!has_permission('product_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$errors = [];
$success_message = '';
$project = null;
$project_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$project_items = [];

// POST 요청 처리 (프로젝트 저장)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_project'])) {
    // 디버깅용 로그
    error_log("POST data: " . print_r($_POST, true));
    
    $items_data = isset($_POST['items_data']) ? json_decode($_POST['items_data'], true) : [];
    error_log("Decoded items_data: " . print_r($items_data, true));
    
    if (empty($items_data)) {
        $errors[] = t('price_label.min_one_product');
        error_log("Error: No items data provided");
    } else {
        try {
            $conn = get_db_connection();
            $conn->autocommit(false); // 트랜잭션 시작
            
            $current_user_id = $_SESSION['user_id'] ?? 0;
            $current_store_id = $_SESSION['store_id'] ?? 0;
            
            if ($project_id > 0) {
                // 기존 프로젝트 업데이트
                $update_sql = "UPDATE price_label_projects SET updated_at = NOW() WHERE id = ? AND store_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ii", $project_id, $current_store_id);
                $update_stmt->execute();
                
                // 기존 아이템들 삭제
                $delete_sql = "DELETE FROM price_label_project_items WHERE project_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("i", $project_id);
                $delete_stmt->execute();
            } else {
                // 새 프로젝트 생성
                $project_name = date('Y-m-d H:i:s') . ' ' . t('price_label.title');
                $insert_sql = "INSERT INTO price_label_projects (store_id, created_by, project_name) VALUES (?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("iis", $current_store_id, $current_user_id, $project_name);
                $insert_stmt->execute();
                $project_id = $conn->insert_id;
            }
            
            // 프로젝트 아이템들 저장
            $item_sql = "INSERT INTO price_label_project_items (project_id, product_id, quantity, remarks) VALUES (?, ?, ?, ?)";
            $item_stmt = $conn->prepare($item_sql);
            
            foreach ($items_data as $item) {
                $item_stmt->bind_param("iiis", 
                    $project_id, 
                    $item['product_id'], 
                    $item['quantity'], 
                    $item['remarks']
                );
                $item_stmt->execute();
            }
            
            $conn->commit(); // 트랜잭션 커밋
            $conn->close();
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => t('price_label.save_project_success')
            ];
            
            header('Location: price_label_lists.php');
            exit;
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
                $conn->close();
            }
            $errors[] = t('price_label.save_error', ['error' => $e->getMessage()]);
        }
    }
}

// 기존 프로젝트 로드
if ($project_id > 0) {
    $conn = get_db_connection();
    $current_store_id = $_SESSION['store_id'] ?? 0;
    
    $project_sql = "SELECT p.*, u.username as creator_name 
                    FROM price_label_projects p
                    LEFT JOIN users u ON p.created_by = u.id
                    WHERE p.id = ? AND p.store_id = ?";
    $project_stmt = $conn->prepare($project_sql);
    $project_stmt->bind_param("ii", $project_id, $current_store_id);
    $project_stmt->execute();
    $project_result = $project_stmt->get_result();
    
    if ($project_result->num_rows === 0) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => t('price_label.project_not_found')
        ];
        header('Location: price_label_lists.php');
        exit;
    }
    
    $project = $project_result->fetch_assoc();
    
    // 프로젝트 아이템들 로드
    $items_sql = "SELECT 
                      pi.product_id,
                      pi.quantity,
                      pi.remarks,
                      p.sku,
                      p.name_en,
                      p.name_ko,
                      p.pieces_per_box,
                      COALESCE(i.selling_price, p.selling_price, 0) as selling_price,
                      COALESCE(i.cost_price, p.cost_price, 0) as cost_price,
                      COALESCE(i.quantity, 0) as stock,
                      COALESCE(b.name_ko, b.name_en, '') as brand_name,
                      COALESCE(c.name, '') as category_name
                  FROM price_label_project_items pi
                  JOIN products p ON pi.product_id = p.id
                  LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
                  LEFT JOIN brands b ON p.brand_id = b.id
                  LEFT JOIN categories c ON p.category_id = c.id
                  WHERE pi.project_id = ?
                  ORDER BY p.name_en, p.name_ko";
    
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("ii", $current_store_id, $project_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    while ($item_row = $items_result->fetch_assoc()) {
        $project_items[] = [
            'product_id' => (int)$item_row['product_id'],
            'sku' => $item_row['sku'],
            'name_en' => $item_row['name_en'],
            'name_ko' => $item_row['name_ko'],
            'selling_price' => (float)$item_row['selling_price'],
            'cost_price' => (float)$item_row['cost_price'],
            'stock' => (int)$item_row['stock'],
            'pieces_per_box' => (int)$item_row['pieces_per_box'],
            'quantity' => (int)$item_row['quantity'],
            'remarks' => $item_row['remarks'],
            'brand_name' => $item_row['brand_name'],
            'category_name' => $item_row['category_name']
        ];
    }
    
    $conn->close();
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<style>
/* 기존 price_label_print.php와 동일한 스타일 */
.responsive-table {
    overflow-x: auto;
    width: 100%;
}

.table-compact td, .table-compact th {
    padding: 0.5rem 0.75rem !important;
    font-size: 0.875rem;
}

#cartTable {
    width: 100% !important;
    table-layout: auto !important;
}

/* 프로젝트 정보 입력 영역 */
.project-info-section {
    background-color: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

/* 모바일 반응형 */
@media (max-width: 767px) {
    .mobile-hidden { display: none !important; }
    
    .mobile-card {
        display: block;
        background-color: white;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        margin-bottom: 1rem;
        padding: 1rem;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
    }
}
</style>

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
                            <a href="price_label_lists.php" class="text-gray-400 hover:text-gray-600"><?php echo t('navigation.price_label_lists'); ?></a>
                        </div>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo $project ? t('price_label.project_edit') : t('price_label.new_project_short'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-tags mr-2 text-purple-500"></i>
                    <?php echo $project ? t('price_label.edit_project') : t('price_label.new_project'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    <?php echo $project ? t('price_label.edit_existing_project') : t('price_label.create_new_project'); ?>
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
                        <?php foreach ($errors as $error): ?>
                            <div class="flex mb-2">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-triangle text-red-400"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>


                <!-- 상품 검색 및 추가 -->
                <div class="space-y-6">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo t('price_label.search_products_add'); ?></h3>
                        
                        <div class="flex gap-2">
                            <div class="relative flex-1">
                                <div class="flex">
                                    <input type="text" id="product_search" 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-l-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500"
                                           placeholder="<?php echo t('price_label.search_barcode_placeholder'); ?>"
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
                            <i class="fas fa-list mr-2 text-purple-500"></i>
                            <?php echo t('price_label.selected_products_list'); ?>
                        </h3>
                        
                        <div id="cart_empty" class="text-center text-gray-500 py-8">
                            <i class="fas fa-tags text-4xl mb-4"></i>
                            <p><?php echo t('price_label.no_selected_products'); ?></p>
                            <p class="text-sm"><?php echo t('price_label.add_products_instruction'); ?></p>
                        </div>
                        
                        <div id="cart_items" class="hidden">
                            <!-- 테이블 형태 상품 목록 -->
                            <div class="border rounded-md overflow-hidden cart-table-wrapper">
                                <table class="w-full cart-table table-compact" id="cartTable">
                                    <thead class="bg-gray-100">
                                        <tr>
                                            <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700"><?php echo t('price_label.sku'); ?></th>
                                            <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700"><?php echo t('price_label.product_name_header'); ?></th>
                                            <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700"><?php echo t('price_label.selling_price_header'); ?></th>
                                            <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700"><?php echo t('price_label.print_quantity_header'); ?></th>
                                            <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700"><?php echo t('price_label.remarks_header'); ?></th>
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
                                    <span><?php echo t('price_label.total_print_quantity'); ?></span>
                                    <span id="cart_total">0 <?php echo t('price_label.sheets_unit'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 저장 버튼 -->
                    <div class="mt-8 flex justify-center space-x-3">
                        <form method="POST" id="saveForm" class="inline">
                            <input type="hidden" name="items_data" id="itemsDataInput">
                            <button type="submit" name="save_project" id="save_project_btn" 
                                    class="px-6 py-3 bg-purple-600 text-white rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:hover:bg-gray-400 font-semibold"
                                    disabled>
                                <i class="fas fa-save mr-2"></i>
                                <?php echo $project ? t('price_label.project_update') : t('price_label.project_save'); ?>
                            </button>
                        </form>
                        
                        <a href="price_label_lists.php" 
                           class="inline-flex items-center px-6 py-3 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            <?php echo t('price_label.back_to_lists'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 상품 목록 모달 (기존과 동일) -->
<div id="product-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-h-[70vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900">
                    <i class="fas fa-box mr-2 text-green-500"></i>
                    <?php echo t('price_label.product_list_modal'); ?>
                </h3>
                <button type="button" id="close-product-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="p-3 border-b border-gray-200">
                    <input type="text" id="modal-product-search" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-purple-500 focus:border-purple-500"
                           placeholder="<?php echo t('price_label.filter_product_sku'); ?>">
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
    'price_label.sheets_unit',
    'price_label.min_one_product',
    'price_label.server_response_error',
    'price_label.product_added_success',
    'price_label.products_found',
    'price_label.project_loaded_success'
]); ?>;

document.addEventListener('DOMContentLoaded', function() {
    let cart = [];
    const projectId = <?php echo $project_id; ?>;
    
    const productSearch = document.getElementById('product_search');
    const productSearchResults = document.getElementById('product_search_results');
    const cartEmpty = document.getElementById('cart_empty');
    const cartItems = document.getElementById('cart_items');
    const cartList = document.getElementById('cart_list');
    const cartTotal = document.getElementById('cart_total');
    const saveProjectBtn = document.getElementById('save_project_btn');
    
    // 기존 프로젝트 로드
    if (projectId > 0) {
        loadExistingProject();
    }
    
    // 장바구니 변경 시 저장 버튼 활성화/비활성화 체크
    updateSaveButton();
    
    // 저장 버튼 클릭 이벤트 (폼 제출 전 데이터 준비)
    document.getElementById('saveForm').addEventListener('submit', function(e) {
        if (cart.length === 0) {
            e.preventDefault();
            showNotification(translations['price_label.min_one_product'] || '<?php echo t("price_label.min_one_product"); ?>', 'error');
            return false;
        }
        
        // 장바구니 데이터를 hidden input에 설정
        const itemsData = cart.map(item => ({
            product_id: item.product_id,
            quantity: item.quantity,
            remarks: item.remarks || ''
        }));
        
        console.log('Preparing to submit:', itemsData);
        document.getElementById('itemsDataInput').value = JSON.stringify(itemsData);
        
        // 폼이 제출됨
        return true;
    });
    
    // 검색 버튼 클릭 이벤트
    document.getElementById('product_search_btn').addEventListener('click', function() {
        const query = productSearch.value.trim();
        if (query.length > 0) {
            searchProducts(query);
        }
    });
    
    let searchTimeout;
    
    // 상품 검색 (바코드 스캐너 최적화)
    productSearch.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        
        if (query.length < 1) {
            productSearchResults.classList.add('hidden');
            return;
        }
        
        // 바코드로 보이는 패턴인지 확인 (숫자만으로 구성되고 8자 이상)
        const isBarcodePattern = /^\d{8,}$/.test(query);
        
        if (isBarcodePattern && query.length >= 10) {
            // 바코드 패턴이고 10자 이상이면 즉시 검색
            searchProducts(query);
        } else if (isBarcodePattern) {
            // 짧은 바코드는 약간의 지연 후 검색
            searchTimeout = setTimeout(function() {
                searchProducts(query);
            }, 200);
        } else {
            // 일반 검색은 기존 지연시간 적용
            const delay = query.length < 3 ? 500 : 300;
            searchTimeout = setTimeout(function() {
                searchProducts(query);
            }, delay);
        }
    });

    // Enter 키 이벤트 처리
    productSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const query = this.value.trim();
            if (query.length > 0) {
                searchAndAutoAdd(query);
            }
        }
    });
    
    // 검색 결과 외부 클릭시 닫기
    document.addEventListener('click', function(e) {
        if (!productSearch.contains(e.target) && !productSearchResults.contains(e.target)) {
            productSearchResults.classList.add('hidden');
        }
    });
    
    function showNotification(message, type = 'info') {
        const existingNotification = document.querySelector('.notification');
        if (existingNotification) {
            existingNotification.remove();
        }
        
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
        
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }
    
    function loadExistingProject() {
        // PHP에서 직접 로드한 데이터를 사용
        <?php if (!empty($project_items)): ?>
        cart = <?php echo json_encode($project_items); ?>;
        updateCart();
        updateSaveButton();
        showNotification(translations['price_label.project_loaded_success'] || '<?php echo t("price_label.project_loaded_success"); ?>', 'success');
        <?php else: ?>
        console.log('No project items to load');
        <?php endif; ?>
    }
    
    function searchProducts(query) {
        fetch('ajax_search_price_label_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(response => response.text())
        .then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON Parse Error:', e);
                console.error('Response text:', text);
                throw new Error(translations['price_label.server_response_error'] || '<?php echo t("price_label.server_response_error"); ?>');
            }
        })
        .then(data => {
            if (data.success && data.products) {
                if (data.products.length === 1 && data.products[0].exact_match) {
                    const product = data.products[0];
                    addProductToCart(product);
                    productSearch.value = '';
                    showNotification(`${product.name_ko || product.name_en} ${translations['price_label.product_added'] || '<?php echo t("price_label.product_added"); ?>'}`, 'success');
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
        // 바코드 패턴 확인 (숫자만으로 구성되고 8자 이상)
        const isBarcodePattern = /^\d{8,}$/.test(query);
        
        fetch('ajax_search_price_label_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(response => response.text())
        .then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON Parse Error:', e);
                console.error('Response text:', text);
                throw new Error(translations['price_label.server_response_error'] || '<?php echo t("price_label.server_response_error"); ?>');
            }
        })
        .then(data => {
            if (data.success && data.products && data.products.length > 0) {
                const product = data.products[0];
                
                // 바코드로 정확히 일치하는 경우나 바코드 패턴인 경우 즉시 추가
                if (product.exact_match || isBarcodePattern) {
                    addProductToCart(product);
                    productSearch.value = '';
                    const productName = product.name_ko || product.name_en || 'Unknown';
                    const message = (translations['price_label.product_added_success'] || '<?php echo t("price_label.product_added_success"); ?>').replace('{product}', productName);
                    showNotification(message, 'success');
                    productSearchResults.classList.add('hidden');
                    setTimeout(() => {
                        productSearch.focus();
                    }, 100);
                } else {
                    // 일반 검색의 경우 검색 결과 표시
                    displayProductResults(data.products);
                    const message = (translations['price_label.products_found'] || '<?php echo t("price_label.products_found"); ?>').replace('{count}', data.products.length);
                    showNotification(message, 'info');
                }
            } else {
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
                        <?php echo t('price_label.sku'); ?>: ${product.sku} | <?php echo t('price_label.selling_price'); ?>: ${Number(sellingPrice).toLocaleString()} | <?php echo t('price_label.stock'); ?>: ${product.stock}${translations['common.items'] || '<?php echo t("common.items"); ?>'}
                        ${product.brand_name ? ' | ' + product.brand_name : ''}
                    </div>
                </div>
            `;
        });
        
        productSearchResults.innerHTML = html;
        productSearchResults.classList.remove('hidden');
        
        document.querySelectorAll('.product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                addToCart(this);
            });
        });
    }
    
    function addProductToCart(product) {
        const sellingPrice = product.current_selling_price || product.selling_price;
        const costPrice = product.current_cost_price || product.cost_price || 0;
        
        const existingIndex = cart.findIndex(item => item.product_id == product.id);
        
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1;
        } else {
            const newItem = {
                product_id: product.id,
                sku: product.sku,
                name_ko: product.name_ko,
                name_en: product.name_en,
                selling_price: sellingPrice,
                cost_price: costPrice,
                quantity: 1,
                pieces_per_box: product.pieces_per_box || 1,
                stock: product.stock || 0,
                remarks: ''
            };
            cart.push(newItem);
        }
        
        updateCart();
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
        
        const existingIndex = cart.findIndex(item => item.product_id == productId);
        
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1;
        } else {
            cart.push({
                product_id: productId,
                sku: sku,
                name_ko: nameKo,
                name_en: nameEn,
                selling_price: sellingPrice,
                cost_price: costPrice,
                quantity: 1,
                pieces_per_box: piecesPerBox,
                stock: stock,
                remarks: ''
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
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-2 py-3 text-xs font-mono text-gray-700 font-medium">${item.sku}</td>
                        <td class="px-2 py-3">
                            <div class="text-sm font-medium text-gray-900" title="${item.name_en || '-'}">${item.name_en || '-'}</div>
                            ${item.name_ko && item.name_ko !== item.name_en ? 
                                `<div class="text-sm text-gray-600 mt-1" title="${item.name_ko}">${item.name_ko}</div>` : 
                                ''
                            }
                        </td>
                        <td class="px-2 py-3 text-center">
                            <div class="text-sm font-medium text-gray-700">
                                ${Number(item.selling_price).toLocaleString()}
                            </div>
                        </td>
                        <td class="px-2 py-3 text-center">
                            <div class="flex items-center justify-center space-x-1">
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
                        <td class="px-2 py-3">
                            <input type="text" 
                                   class="w-full px-2 py-1 text-xs border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-purple-500 focus:border-purple-500" 
                                   placeholder="<?php echo t('price_label.remarks'); ?>"
                                   value="${item.remarks || ''}"
                                   onchange="updateRemarks(${index}, this.value)"
                                   maxlength="100">
                        </td>
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
            cartTotal.textContent = totalQuantity + ' ' + (translations['price_label.sheets_unit'] || '<?php echo t("price_label.sheets_unit"); ?>');
        }
        
        updateSaveButton();
    }
    
    function updateSaveButton() {
        const hasItems = cart.length > 0;
        
        saveProjectBtn.disabled = !hasItems;
        
        if (saveProjectBtn.disabled) {
            saveProjectBtn.className = saveProjectBtn.className.replace('bg-purple-600', 'bg-gray-400').replace('hover:bg-purple-700', 'hover:bg-gray-400');
        } else {
            saveProjectBtn.className = saveProjectBtn.className.replace('bg-gray-400', 'bg-purple-600').replace('hover:bg-gray-400', 'hover:bg-purple-700');
        }
    }
    
    // saveProject 함수는 더 이상 필요하지 않음 (전통적인 폼 제출로 변경됨)
    
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
    };
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>