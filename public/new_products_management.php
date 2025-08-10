<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('product.new_products_management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 상품관리 권한 확인
if (!has_permission('product_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$pdo = null;
$products = [];
$error_message = '';

// 현재 사용자의 점포 정보 가져오기
$current_store_name = t('store.main_store');
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $conn = get_db_connection();
        $user_stmt = $conn->prepare("SELECT s.name as store_name, s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $current_store_name = $user_row['store_name'] ?? t('store.main_store');
            $current_store_id = $user_row['store_id'];
        }
        $user_stmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("Store info error: " . $e->getMessage());
    }
}

// 검색 및 페이징 변수
$search_term = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$limit = in_array($per_page, [10, 25, 50, 100, 200]) ? $per_page : 10;
$offset = ($page - 1) * $limit;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 7일 이내 등록된 상품만 조회하는 조건 추가
    $where_clause = " WHERE DATE(p.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $params = [];
    
    // 검색 조건 추가
    if (!empty($search_term)) {
        $where_clause .= " AND (p.name_ko LIKE ? OR p.sku LIKE ? OR b.name_ko LIKE ?)";
        $params = ["%$search_term%", "%$search_term%", "%$search_term%"];
    }

    // 전체 신상품 수 계산
    $total_stmt = $pdo->prepare("SELECT COUNT(p.id) FROM products p LEFT JOIN brands b ON p.brand_id = b.id" . $where_clause);
    $total_stmt->execute($params);
    $total_products = $total_stmt->fetchColumn();
    $total_pages = ceil($total_products / $limit);

    // 신상품 목록 가져오기
    $sql = "
        SELECT 
            p.id, p.sku, p.name_ko, p.name_en, p.is_active, p.pieces_per_box, p.barcode, p.created_at,
            c.name as category_name, b.name_ko as brand_name, b.name_en as brand_name_en
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        " . $where_clause . "
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);

    $current_param = 1;
    foreach ($params as $param) {
        $stmt->bindValue($current_param++, $param, PDO::PARAM_STR);
    }
    $stmt->bindValue($current_param++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($current_param, $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('product.load_error') . ": " . $e->getMessage();
}

?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo t('product.new_products_management'); ?></h1>
            <p class="mt-2 text-sm text-gray-600"><?php echo t('product.registered_last_7_days'); ?></p>
        </div>
        <div class="flex space-x-4">
            <a href="product_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-box-open mr-2"></i>
                <?php echo t('product.management'); ?>
            </a>
            <a href="add_product.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-plus mr-2"></i>
                <?php echo t('product.add_new_product'); ?>
            </a>
        </div>
    </div>

    <!-- 검색 및 필터 -->
    <div class="bg-white shadow rounded-lg p-4 mb-6">
        <form method="GET" class="flex items-center space-x-3">
            <div class="flex-1">
                <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_term); ?>" 
                       placeholder="<?php echo t('product.search_placeholder'); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
            </div>
            <button type="submit" class="px-3 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 text-sm">
                <i class="fas fa-search mr-1"></i><?php echo t('common.search'); ?>
            </button>
            <a href="new_products_management.php" class="px-3 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm">
                <i class="fas fa-times mr-1"></i><?php echo t('common.clear'); ?>
            </a>
            <div class="flex items-center space-x-2">
                <label for="per_page" class="text-sm text-gray-700 whitespace-nowrap"><?php echo t('product.display_count'); ?>:</label>
                <select name="per_page" id="per_page" onchange="this.form.submit()" class="px-2 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
                    <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>><?php echo t('product.items_10'); ?></option>
                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>><?php echo t('product.items_25'); ?></option>
                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>><?php echo t('product.items_50'); ?></option>
                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>><?php echo t('product.items_100'); ?></option>
                    <option value="200" <?php echo $per_page == 200 ? 'selected' : ''; ?>><?php echo t('product.items_200'); ?></option>
                </select>
            </div>
        </form>
    </div>

    <?php if ($error_message): ?>
    <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-red-400"></i>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-red-800"><?php echo t('common.error'); ?></h3>
                <div class="mt-2 text-sm text-red-700">
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 신상품 테이블 -->
    <div class="bg-white shadow overflow-hidden rounded-lg border-2 border-gray-400">
        
        <?php if (empty($products)): ?>
        <div class="px-6 py-12 text-center">
            <i class="fas fa-box-open text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo t('product.no_new_products'); ?></h3>
            <p class="text-gray-600 mb-4"><?php echo t('product.no_products_desc'); ?></p>
            <a href="add_product.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                <i class="fas fa-plus mr-2"></i>
                <?php echo t('product.add_new_product'); ?>
            </a>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?php echo t('product.registration_date'); ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?php echo t('product.sku'); ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?php echo t('product.name'); ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?php echo t('product.brand'); ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?php echo t('product.category'); ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?php echo t('product.status'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($products as $product): ?>
                    <tr class="hover:bg-gray-50 cursor-pointer product-row" data-product-id="<?php echo $product['id']; ?>">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900">
                            <?php echo htmlspecialchars($product['sku']); ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm">
                                <?php if (!empty($product['name_en'])): ?>
                                <div class="font-medium text-gray-900 mb-1">
                                    <?php echo htmlspecialchars($product['name_en']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($product['name_ko'])): ?>
                                <div class="text-gray-700 <?php echo empty($product['name_en']) ? 'font-medium text-gray-900' : ''; ?>">
                                    <?php echo htmlspecialchars($product['name_ko']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (empty($product['name_en']) && empty($product['name_ko'])): ?>
                                <div class="text-gray-400">-</div>
                                <?php endif; ?>
                                <?php if (!empty($product['barcode'])): ?>
                                <div class="text-xs text-gray-500 font-mono mt-1">
                                    <?php echo htmlspecialchars($product['barcode']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <?php echo htmlspecialchars($product['brand_name'] ?: '-'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <?php echo htmlspecialchars($product['category_name'] ?: '-'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <div class="flex flex-col">
                                <span class="font-medium"><?php echo date('Y-m-d', strtotime($product['created_at'])); ?></span>
                                <span class="text-xs text-gray-500"><?php echo date('H:i:s', strtotime($product['created_at'])); ?></span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($product['is_active']): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    <?php echo t('product.active'); ?>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    <i class="fas fa-times-circle mr-1"></i>
                                    <?php echo t('product.inactive'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- 페이징 -->
        <?php if ($total_pages > 1): ?>
        <div class="bg-white px-6 py-3 border-t border-gray-200 flex items-center justify-between">
            <div class="flex-1 flex justify-between sm:hidden">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <?php echo t('product.previous'); ?>
                    </a>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <?php echo t('product.next'); ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-gray-700">
                        <?php 
                        $start = ($page - 1) * $limit + 1;
                        $end = min($page * $limit, $total_products);
                        echo "총 {$total_products}개 중 {$start}-{$end}개 표시";
                        ?>
                    </p>
                </div>
                <div>
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($page > 1):
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="relative inline-flex items-center px-4 py-2 border border-primary-500 bg-primary-50 text-sm font-medium text-primary-600">
                                    <?php echo $i; ?>
                                </span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- 상품 상세 정보 모달 (product_management.php에서 재사용) -->
<div id="product-details-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 overflow-y-auto h-full w-full hidden z-50 flex items-center justify-center p-4">
    <div class="relative bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[85vh] overflow-y-auto mx-auto my-8">
        <div class="flex items-center justify-between p-4 border-b">
            <div>
                <h3 class="text-lg font-semibold text-gray-800" id="modal-product-name"><?php echo t('product.details'); ?></h3>
            </div>
            <div class="flex items-center space-x-2">
                <button id="edit-product-btn" class="inline-flex items-center px-2 py-1 border border-transparent text-xs leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 hidden">
                    <i class="fas fa-edit mr-1"></i>
                    <?php echo t('common.edit'); ?>
                </button>
                <button id="close-modal-btn" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1 inline-flex items-center">
                    <i class="fas fa-times"></i>
                    <span class="sr-only"><?php echo t('common.close'); ?></span>
                </button>
            </div>
        </div>
        
        <div class="p-4">
            <div id="modal-loading" class="text-center py-20">
                <i class="fas fa-spinner fa-spin text-4xl text-gray-400 mb-4"></i>
                <p class="text-gray-600"><?php echo t('product.loading_info'); ?></p>
            </div>
            
            <div id="modal-body-wrapper" class="hidden">
                <div class="grid grid-cols-1 gap-4 mb-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="font-medium text-gray-900 mb-3"><?php echo t('product.basic_info'); ?></h4>
                        <table class="w-full text-sm">
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600 w-1/3"><?php echo t('product.name_en'); ?>:</td>
                                <td class="py-1.5" id="modal-name-en"></td>
                            </tr>
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600"><?php echo t('product.name_ko'); ?>:</td>
                                <td class="py-1.5" id="modal-name-ko"></td>
                            </tr>
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600"><?php echo t('product.sku'); ?>:</td>
                                <td class="py-1.5 font-mono text-xs" id="modal-sku"></td>
                            </tr>
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600"><?php echo t('product.barcode'); ?>:</td>
                                <td class="py-1.5 font-mono text-xs" id="modal-barcode"></td>
                            </tr>
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600"><?php echo t('product.brand'); ?>:</td>
                                <td class="py-1.5" id="modal-brand"></td>
                            </tr>
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600"><?php echo t('product.category'); ?>:</td>
                                <td class="py-1.5" id="modal-category"></td>
                            </tr>
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600"><?php echo t('product.box_packaging'); ?>:</td>
                                <td class="py-1.5" id="modal-box-info"></td>
                            </tr>
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600 align-top"><?php echo t('product.description'); ?>:</td>
                                <td class="py-1.5 whitespace-pre-wrap" id="modal-description"></td>
                            </tr>
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600"><?php echo t('product.status'); ?>:</td>
                                <td class="py-1.5" id="modal-status"></td>
                            </tr>
                            <tr class="border-b border-gray-200 last:border-b-0">
                                <td class="py-1.5 font-medium text-gray-600 align-top"><?php echo t('product.last_modified'); ?>:</td>
                                <td class="py-1.5 text-xs" id="modal-last-modified"></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <div id="modal-inventory-wrapper" class="bg-gray-50 rounded-lg p-4">
                    <h4 class="font-medium text-gray-900 mb-3"><?php echo t('product.inventory_pricing'); ?></h4>
                </div>
                
                <div id="modal-image-content" class="mt-6 flex justify-start items-center">
                    <img id="modal-image" src="" alt="상품 이미지" class="w-[30px] h-[30px] rounded-md border bg-gray-100 object-contain">
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('product-details-modal');
    const closeModalBtn = document.getElementById('close-modal-btn');
    const editProductBtn = document.getElementById('edit-product-btn');
    
    const modalContent = {
        name: document.getElementById('modal-product-name'),
        nameEn: document.getElementById('modal-name-en'),
        nameKo: document.getElementById('modal-name-ko'),
        sku: document.getElementById('modal-sku'),
        barcode: document.getElementById('modal-barcode'),
        description: document.getElementById('modal-description'),
        brand: document.getElementById('modal-brand'),
        category: document.getElementById('modal-category'),
        boxInfo: document.getElementById('modal-box-info'),
        inventoryWrapper: document.getElementById('modal-inventory-wrapper'),
        image: document.getElementById('modal-image'),
        status: document.getElementById('modal-status'),
        lastModified: document.getElementById('modal-last-modified'),
        loading: document.getElementById('modal-loading'),
        bodyWrapper: document.getElementById('modal-body-wrapper'),
        imageContent: document.getElementById('modal-image-content')
    };

    function showModal() {
        modal.classList.remove('hidden');
    }

    function hideModal() {
        modal.classList.add('hidden');
        modalContent.loading.style.display = 'block';
        modalContent.bodyWrapper.classList.add('hidden');
        editProductBtn.classList.add('hidden');
    }

    // 모달 외부 클릭 시 닫기
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            hideModal();
        }
    });

    // 닫기 버튼 클릭
    closeModalBtn.addEventListener('click', hideModal);

    // ESC 키로 모달 닫기
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
            hideModal();
        }
    });

    // 상품 행 클릭 이벤트
    document.querySelectorAll('.product-row').forEach(row => {
        row.addEventListener('click', function() {
            const productId = this.dataset.productId;
            showModal();
            loadProductDetails(productId);
        });
    });

    // 수정 버튼 클릭 이벤트
    editProductBtn.addEventListener('click', function() {
        const productId = editProductBtn.dataset.productId;
        if (productId) {
            window.location.href = `edit_product.php?id=${productId}`;
        }
    });

    function loadProductDetails(productId) {
        console.log('Loading product details for ID:', productId); // 디버깅
        
        fetch('ajax_get_product_details.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `product_id=${encodeURIComponent(productId)}`
        })
        .then(response => {
            console.log('Response status:', response.status); // 디버깅
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data); // 디버깅
            if (data.success) {
                const product = data.product;
                
                // 기본 정보 채우기
                modalContent.name.textContent = '<?php echo t("product.details"); ?>';
                modalContent.nameEn.textContent = product.name_en || '-';
                modalContent.nameKo.textContent = product.name_ko || '-';
                modalContent.sku.textContent = product.sku || 'N/A';
                modalContent.description.textContent = product.description || '<?php echo t("product.js_no_description"); ?>';
                modalContent.brand.textContent = product.brand_name_ko || 'N/A';
                modalContent.category.textContent = product.category_name || 'N/A';
                modalContent.barcode.textContent = product.barcode || '<?php echo t("product.js_no_barcode"); ?>';
                
                const piecesPerBox = product.pieces_per_box || 1;
                modalContent.boxInfo.innerHTML = `<span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">${piecesPerBox}<?php echo t('product.js_pieces_per_box'); ?></span>`;
                
                // 재고 정보
                modalContent.inventoryWrapper.innerHTML = '<h4 class="font-medium text-gray-900 mb-3"><?php echo t('product.inventory_pricing'); ?></h4>';
                if (data.inventory && data.inventory.length > 0) {
                    const inventoryTable = document.createElement('table');
                    inventoryTable.className = 'w-full text-sm border border-gray-200 rounded-lg overflow-hidden';
                    
                    let inventoryHTML = `
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-3 py-1.5 text-left font-medium text-gray-700 text-xs"><?php echo t('product.store'); ?></th>
                                <th class="px-3 py-1.5 text-left font-medium text-gray-700 text-xs"><?php echo t('product.cost_price'); ?></th>
                                <th class="px-3 py-1.5 text-left font-medium text-gray-700 text-xs"><?php echo t('product.selling_price'); ?></th>
                                <th class="px-3 py-1.5 text-left font-medium text-gray-700 text-xs"><?php echo t('product.stock'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                    `;
                    
                    data.inventory.forEach(inv => {
                        inventoryHTML += `
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-1.5 text-xs">${inv.store_name || 'N/A'}</td>
                                <td class="px-3 py-1.5 text-xs font-mono">${Number(inv.cost_price || 0).toLocaleString()}</td>
                                <td class="px-3 py-1.5 text-xs font-mono">${Number(inv.selling_price || 0).toLocaleString()}</td>
                                <td class="px-3 py-1.5 text-xs">${Number(inv.stock_quantity || 0).toLocaleString()}</td>
                            </tr>
                        `;
                    });
                    
                    inventoryHTML += '</tbody>';
                    inventoryTable.innerHTML = inventoryHTML;
                    modalContent.inventoryWrapper.appendChild(inventoryTable);
                } else {
                    modalContent.inventoryWrapper.innerHTML += `<p class="text-slate-500 text-sm"><?php echo t('product.js_no_inventory_info'); ?></p>`;
                }
                
                // 이미지
                if (product.image_url) {
                    modalContent.image.src = product.image_url;
                    modalContent.image.alt = product.name_ko;
                    modalContent.imageContent.classList.remove('hidden');
                } else {
                    modalContent.imageContent.classList.add('hidden');
                }
                
                // 상태
                modalContent.status.innerHTML = product.is_active 
                    ? `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><i class="fas fa-check-circle mr-1"></i><?php echo t('product.js_active_status'); ?></span>`
                    : `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"><i class="fas fa-times-circle mr-1"></i><?php echo t('product.js_inactive_status'); ?></span>`;
                
                // 최종 수정일
                const lastModifiedDate = product.updated_at ? new Date(product.updated_at).toLocaleString('ko-KR') : 'N/A';
                modalContent.lastModified.innerHTML = `<?php echo t('product.js_last_modified'); ?>: ${lastModifiedDate} <br> by ${product.last_modified_by || 'N/A'}`;
                
                // 수정 버튼 활성화
                editProductBtn.dataset.productId = productId;
                editProductBtn.classList.remove('hidden');
                
                modalContent.loading.style.display = 'none';
                modalContent.bodyWrapper.classList.remove('hidden');
            } else {
                alert('<?php echo t("product.js_error_load_product"); ?>: ' + (data.message || '<?php echo t("common.error"); ?>'));
                hideModal();
            }
        })
        .catch(error => {
            console.error('Error loading product details:', error);
            alert('<?php echo t("product.js_error_load_product"); ?>: ' + error.message);
            hideModal();
        });
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>