<?php // Cache-Busting Comment: 2025-07-24 10:00:00 AM 
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('product.management') . ' - ' . t('company.name');
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
        }
}

// 검색 및 페이징 변수
$search_term = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10; // 기본 10개
$limit = in_array($per_page, [10, 25, 50, 100, 200]) ? $per_page : 10; // 허용된 값만 사용
$offset = ($page - 1) * $limit;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 검색 조건
    $where_clause = '';
    $params = [];
    if (!empty($search_term)) {
        $where_clause = " WHERE p.name_ko LIKE ? OR p.sku LIKE ? OR b.name_ko LIKE ?";
        $params = ["%$search_term%", "%$search_term%", "%$search_term%"];
    }

    // 전체 상품 수 계산
    $total_stmt = $pdo->prepare("SELECT COUNT(p.id) FROM products p LEFT JOIN brands b ON p.brand_id = b.id" . $where_clause);
    $total_stmt->execute($params);
    $total_products = $total_stmt->fetchColumn();
    $total_pages = ceil($total_products / $limit);

    // 상품 목록 가져오기
    $sql = "
        SELECT
            p.id, p.sku, p.name_ko, p.name_en, p.is_active, p.pieces_per_box,
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

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- Flash messages -->
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="mb-6">
            <?php 
            $flash = $_SESSION['flash'];
            $alert_class = $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800';
            $icon_class = $flash['type'] === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400';
            ?>
            <div class="<?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <div class="alert-content">
                    <div class="alert-icon-wrapper">
                        <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> alert-icon"></i>
                    </div>
                    <div class="alert-message">
                        <p class="alert-text"><?php echo htmlspecialchars($flash['message']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- 검색 및 필터 -->
    <div class="mb-6">
        <form action="product_management.php" method="get" class="space-y-4 sm:space-y-0 sm:flex sm:items-center sm:space-x-4">
            <div class="relative flex-1">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
                <input type="search" name="search" placeholder="<?php echo t('product.search_placeholder'); ?>" class="block w-full rounded-md border-gray-300 pl-10 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="flex items-center space-x-2">
                <label for="per_page" class="text-sm text-gray-700 whitespace-nowrap"><?php echo t('product.display_count'); ?>:</label>
                <select name="per_page" id="per_page" class="rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" onchange="this.form.submit()">
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
        <div class="alert-error">
            <div class="alert-content">
                <div class="alert-icon-wrapper">
                    <i class="fas fa-exclamation-circle alert-icon"></i>
                </div>
                <div class="alert-message">
                    <p class="alert-text"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            </div>
        </div>
    <?php elseif (empty($products)): ?>
        <div class="empty-state">
            <i class="fas fa-box-open empty-state-icon"></i>
            <h2 class="empty-state-title"><?php echo t('product.no_products'); ?></h2>
            <p class="empty-state-description"><?php echo t('product.no_products_desc'); ?></p>
            <div class="mt-6">
                <a href="add_product.php" class="btn-primary">
                    <i class="fas fa-plus mr-2"></i> <?php echo t('product.add_new_product'); ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
            <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
                <h3 class="text-lg leading-6 font-semibold text-gray-900">
                    <?php echo t('product.list'); ?> 
                    <?php if (!$error_message && isset($total_products)): ?>
                        <span class="text-sm font-normal text-gray-500">(총 <?php echo number_format($total_products); ?>개)</span>
                    <?php endif; ?>
                </h3>
                <div class="flex items-center space-x-2">
                    <button type="button" id="open-export-modal-btn" class="inline-flex items-center px-4 py-2 border border-green-600 rounded-md shadow-sm text-sm font-medium text-green-700 bg-white hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200">
                        <i class="fas fa-file-excel mr-2"></i>엑셀 다운로드
                    </button>
                    <a href="add_product.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                        <i class="fas fa-plus mr-2"></i><?php echo t('product.add_new_product'); ?>
                    </a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">SKU</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                <div><?php echo t('product.name_ko'); ?></div>
                                <div class="text-xs text-gray-500 normal-case"><?php echo t('product.name_en'); ?></div>
                            </th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('product.brand'); ?></th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('product.category'); ?></th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('product.pieces_per_box'); ?></th>
                            <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('product.status'); ?></th>
                            <th scope="col" class="px-6 py-4 relative text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                <span class="sr-only"><?php echo t('common.actions'); ?></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <?php foreach ($products as $product): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer product-row" data-product-id="<?php echo $product['id']; ?>">
                                <td class="px-6 py-4 whitespace-nowrap font-mono text-gray-500"><?php echo htmlspecialchars($product['sku']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['name_ko']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($product['name_en']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($product['brand_name'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                                        <?php echo number_format($product['pieces_per_box'] ?? 1); ?><?php echo t('purchase.pieces'); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php if ($product['is_active']): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800"><?php echo t('product.active'); ?></span>
                                    <?php else: ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800"><?php echo t('product.inactive'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="edit_product.php?id=<?php echo $product['id']; ?>" class="text-primary-600 hover:text-primary-900" onclick="event.stopPropagation();"><?php echo t('common.edit'); ?></a>
                                    <a href="delete_product.php?id=<?php echo $product['id']; ?>" class="text-red-600 hover:text-red-900 ml-4" onclick="event.stopPropagation(); return confirmDelete('<?php echo htmlspecialchars($product['name_ko'], ENT_QUOTES); ?>')"><?php echo t('common.delete'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 페이징 -->
        <?php if ($total_pages > 1): ?>
            <nav class="mt-6 flex items-center justify-between border-t border-gray-200 px-4 sm:px-0">
                <!-- 페이지 정보 및 모바일 네비게이션 -->
                <div class="flex-1 flex justify-between sm:hidden">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_term); ?>&per_page=<?php echo $per_page; ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i class="fas fa-arrow-left mr-2"></i> <?php echo t('product.previous'); ?>
                        </a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    
                    <span class="text-sm text-gray-700 flex items-center">
                        <span class="font-medium"><?php echo $page; ?></span> / <span class="font-medium"><?php echo $total_pages; ?></span>
                    </span>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search_term); ?>&per_page=<?php echo $per_page; ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <?php echo t('product.next'); ?> <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                </div>
            </nav>
            <nav class="flex items-center justify-between border-t border-gray-200 px-4 sm:px-0">
                <div class="-mt-px flex w-0 flex-1">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_term); ?>&per_page=<?php echo $per_page; ?>" class="inline-flex items-center border-t-2 border-transparent pt-4 pr-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700">
                            <i class="fas fa-arrow-left mr-3"></i> <?php echo t('product.previous'); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="hidden md:-mt-px md:flex">
                    <?php
                    // 스마트 페이지네이션 로직
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    // 시작 부분 조정 (5개 페이지 유지)
                    if ($end_page - $start_page < 4) {
                        if ($start_page == 1) {
                            $end_page = min($total_pages, $start_page + 4);
                        } else {
                            $start_page = max(1, $end_page - 4);
                        }
                    }
                    ?>
                    
                    <!-- 첫 페이지 표시 (현재 페이지가 4보다 클 때) -->
                    <?php if ($start_page > 1): ?>
                        <a href="?page=1&search=<?php echo urlencode($search_term); ?>&per_page=<?php echo $per_page; ?>" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 inline-flex items-center border-t-2 px-4 pt-4 text-sm font-medium">
                            1
                        </a>
                        <?php if ($start_page > 2): ?>
                            <span class="border-transparent text-gray-500 inline-flex items-center border-t-2 px-4 pt-4 text-sm font-medium">
                                ...
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- 메인 페이지 번호들 -->
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_term); ?>&per_page=<?php echo $per_page; ?>" class="<?php echo ($i == $page) ? 'border-primary-500 text-primary-600 bg-primary-50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> inline-flex items-center border-t-2 px-4 pt-4 text-sm font-medium">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <!-- 마지막 페이지 표시 (현재 페이지가 끝에서 4보다 작을 때) -->
                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <span class="border-transparent text-gray-500 inline-flex items-center border-t-2 px-4 pt-4 text-sm font-medium">
                                ...
                            </span>
                        <?php endif; ?>
                        <a href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search_term); ?>&per_page=<?php echo $per_page; ?>" class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 inline-flex items-center border-t-2 px-4 pt-4 text-sm font-medium">
                            <?php echo $total_pages; ?>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="-mt-px flex w-0 flex-1 justify-end">
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search_term); ?>&per_page=<?php echo $per_page; ?>" class="inline-flex items-center border-t-2 border-transparent pt-4 pl-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700">
                            <?php echo t('product.next'); ?> <i class="fas fa-arrow-right ml-3"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- 엑셀 다운로드 컬럼 선택 Modal -->
<div id="export-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 overflow-y-auto h-full w-full hidden z-50 flex items-center justify-center p-4">
    <div class="relative w-full max-w-lg bg-white rounded-lg shadow-xl">
        <div class="flex justify-between items-center p-4 border-b rounded-t-lg">
            <h3 class="text-lg font-semibold text-gray-800">
                <i class="fas fa-file-excel text-green-600 mr-2"></i>엑셀 다운로드 — 컬럼 선택
            </h3>
            <button type="button" id="close-export-modal-btn" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-4">
            <p class="text-sm text-gray-500 mb-3">
                내려받을 컬럼을 선택하세요.
                <?php if (!empty($search_term)): ?>
                    <span class="text-blue-600">(현재 검색 "<?php echo htmlspecialchars($search_term); ?>" 결과 전체)</span>
                <?php else: ?>
                    (전체 상품)
                <?php endif; ?>
            </p>
            <div class="flex items-center justify-between mb-2">
                <label class="inline-flex items-center text-sm font-medium text-gray-700">
                    <input type="checkbox" id="export-check-all" class="rounded border-gray-300 text-green-600 focus:ring-green-500 mr-2" checked>
                    전체 선택
                </label>
            </div>
            <div id="export-columns" class="grid grid-cols-2 gap-2 border rounded-md p-3 max-h-64 overflow-y-auto">
                <?php
                $export_columns = [
                    'sku'            => 'SKU',
                    'name_ko'        => '상품명(한글)',
                    'name_en'        => '상품명(영어)',
                    'brand_ko'       => '브랜드(한글)',
                    'brand_en'       => '브랜드(영어)',
                    'category'       => '카테고리',
                    'pieces_per_box' => '박스당 개수',
                    'vat'            => 'VAT 적용',
                    'status'         => '상태',
                    'created_at'     => '등록일',
                    'updated_at'     => '수정일',
                ];
                foreach ($export_columns as $ckey => $clabel): ?>
                    <label class="inline-flex items-center text-sm text-gray-700 py-1">
                        <input type="checkbox" name="export-col" value="<?php echo $ckey; ?>" class="export-col-check rounded border-gray-300 text-green-600 focus:ring-green-500 mr-2" checked>
                        <?php echo $clabel; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="flex justify-end space-x-3 mt-4">
                <button type="button" id="cancel-export-btn" class="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">취소</button>
                <button type="button" id="do-export-btn" class="px-4 py-2 text-sm text-white bg-green-600 rounded-md hover:bg-green-700">
                    <i class="fas fa-download mr-1"></i>다운로드
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 상품 상세 정보 Modal -->
<div id="product-details-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 overflow-y-auto h-full w-full hidden z-50 flex items-center justify-center p-4">
    <div class="relative w-full max-w-3xl max-h-[90vh] bg-white rounded-lg shadow-xl flex flex-col">
        <!-- Modal Header -->
        <div class="flex justify-between items-center p-4 border-b rounded-t-lg">
            <div>
                <h3 class="modal-title" id="modal-product-name"><?php echo t('product.details'); ?></h3>
                <div class="flex items-center space-x-1 text-sm text-blue-600 mt-1">
                    <i class="fas fa-store"></i>
                    <span><?php echo str_replace('{store}', htmlspecialchars($current_store_name), t('product.store_based')); ?></span>
                </div>
            </div>
            <button id="close-modal-btn" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 flex-grow overflow-y-auto">
            <!-- 로딩 스피너 -->
            <div id="modal-loading" class="text-center py-20">
                <i class="fas fa-spinner fa-spin text-4xl text-primary-600"></i>
                <p class="mt-3 text-gray-500"><?php echo t('product.loading_info'); ?></p>
            </div>
            
            <!-- 상세 정보 전체 래퍼 -->
            <div id="modal-body-wrapper" class="hidden">
                <!-- Basic Details Table -->
                <h4 class="text-lg font-semibold text-gray-800 mb-2"><?php echo t('product.basic_info'); ?></h4>
                <table class="w-full text-sm text-left text-gray-600 mb-6">
                    <tbody>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 w-1/3"><?php echo t('product.name_en'); ?></td>
                            <td class="px-4 py-2" id="modal-name-en"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 w-1/3"><?php echo t('product.name_ko'); ?></td>
                            <td class="px-4 py-2" id="modal-name-ko"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">SKU</td>
                            <td class="px-4 py-2 font-mono" id="modal-sku"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.brand'); ?></td>
                            <td class="px-4 py-2" id="modal-brand"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.category'); ?></td>
                            <td class="px-4 py-2" id="modal-category"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.box_packaging'); ?></td>
                            <td class="px-4 py-2" id="modal-box-info"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 align-top"><?php echo t('product.description'); ?></td>
                            <td class="px-4 py-2 whitespace-pre-wrap" id="modal-description"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.status'); ?></td>
                            <td class="px-4 py-2" id="modal-status"></td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.last_modified'); ?></td>
                            <td class="px-4 py-2" id="modal-last-modified"></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pricing by Store -->
                <h4 class="text-lg font-semibold text-gray-800 mb-2"><?php echo t('product.inventory_pricing'); ?></h4>
                <div id="modal-inventory-wrapper" class="mb-6">
                    <!-- JS will populate this -->
                </div>

                <!-- Lot Inventory Management -->
                <div class="flex justify-between items-center mb-2">
                    <h4 class="text-lg font-semibold text-gray-800">유통기한별 재고 (Lot) 관리</h4>
                    <button type="button" id="btn-add-lot" class="text-xs px-3 py-1 bg-green-50 text-green-700 border border-green-200 rounded hover:bg-green-100">
                        <i class="fas fa-plus mr-1"></i>새 로트 추가
                    </button>
                </div>
                <div id="modal-lot-wrapper" class="mb-6 overflow-x-auto">
                    <table class="w-full text-sm text-center text-gray-600 border">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 font-semibold">유통기한 (년-월-일)</th>
                                <th class="px-4 py-2 font-semibold text-right">수량</th>
                                <th class="px-4 py-2 font-semibold">동작</th>
                            </tr>
                        </thead>
                        <tbody id="lot-inventory-tbody">
                            <!-- JS will populate Lots here -->
                        </tbody>
                    </table>
                </div>

                <!-- Purchase History Section -->
                <h4 class="text-lg font-semibold text-gray-800 mb-2"><?php echo t('product.recent_purchase_history'); ?></h4>
                <div id="modal-purchase-history-wrapper" class="mb-4">
                    <div id="purchase-history-loading" class="text-center py-4">
                        <i class="fas fa-spinner fa-spin text-primary-600"></i>
                        <span class="ml-2 text-gray-500"><?php echo t('product.loading_info'); ?></span>
                    </div>
                    <div id="purchase-history-content" class="hidden">
                        <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3 mb-3">
                            <p class="text-sm text-yellow-800">
                                <i class="fas fa-info-circle mr-1"></i>
                                <?php echo t('product.purchase_history_info'); ?>
                            </p>
                        </div>
                        <table id="purchase-history-table" class="w-full text-sm border border-gray-200 rounded-md">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-700"><?php echo t('product.purchase_date'); ?></th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-700"><?php echo t('product.supplier'); ?></th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-700"><?php echo t('price_change.store'); ?></th>
                                    <th class="px-3 py-2 text-right font-semibold text-gray-700"><?php echo t('product.box_cost'); ?></th>
                                    <th class="px-3 py-2 text-right font-semibold text-gray-700"><?php echo t('product.unit_cost'); ?></th>
                                    <th class="px-3 py-2 text-center font-semibold text-gray-700"><?php echo t('product.select'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="purchase-history-tbody">
                                <!-- Purchase history will be dynamically added here -->
                            </tbody>
                        </table>
                        <div id="no-purchase-history" class="text-center py-4 text-gray-500 hidden">
                            <i class="fas fa-exclamation-circle text-2xl"></i>
                            <p class="mt-2"><?php echo t('product.no_purchase_history'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Pricing Update Section -->
                <div id="modal-pricing-section" class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-4 hidden">
                    <h5 class="font-semibold text-blue-800 mb-3">
                        <i class="fas fa-tag mr-1"></i>
                        판매가 설정
                    </h5>
                    
                    <!-- 원가 정보 -->
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-blue-700 mb-1">선택된 원가</label>
                        <div id="selected-cost-display" class="text-lg font-bold text-blue-900">₩ 0</div>
                    </div>

                    <!-- 박스원가 설정 -->
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-blue-700 mb-1">박스원가 설정</label>
                        <div class="flex items-center space-x-2">
                            <input type="number" id="new-box-price"
                                   class="flex-1 px-3 py-2 border border-blue-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="박스원가 입력" step="0.01" min="0">
                            <span class="text-sm text-gray-600">원</span>
                        </div>
                        <p class="mt-1 text-xs text-blue-600">매입 시 기본 단가로 사용됩니다.</p>
                    </div>
                    
                    <!-- 마진율 선택 -->
                    <div class="mb-3">
                        <div class="flex justify-between items-center mb-2">
                            <label class="block text-sm font-medium text-blue-700">마진율 선택</label>
                            <button id="configure-presets-btn" class="text-xs text-blue-600 hover:text-blue-800">
                                <i class="fas fa-cog mr-1"></i>설정
                            </button>
                        </div>
                        <div id="margin-presets-container" class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-2">
                            <!-- 동적으로 생성될 마진율 버튼들 -->
                        </div>
                        <div class="flex items-center space-x-2">
                            <input type="number" id="custom-margin-input" 
                                   class="w-20 px-2 py-1 text-sm border border-blue-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                   placeholder="직접입력" min="0" max="100" step="0.1">
                            <span class="text-sm text-gray-600">%</span>
                            <button id="apply-custom-margin-btn" class="px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded hover:bg-gray-200">적용</button>
                        </div>
                    </div>
                    
                    <!-- 계산된 판매가 -->
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-blue-700 mb-1">계산된 판매가</label>
                        <div class="flex items-center space-x-2">
                            <input type="number" id="new-selling-price" 
                                   class="flex-1 px-3 py-2 border border-blue-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                   placeholder="판매가">
                            <button id="apply-selling-price-btn" 
                                    class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:ring-2 focus:ring-blue-500">
                                적용
                            </button>
                        </div>
                    </div>
                    
                    <!-- 마진 정보 표시 -->
                    <div class="flex justify-between items-center text-xs">
                        <div class="flex space-x-4">
                            <span class="text-blue-600">
                                <i class="fas fa-calculator mr-1"></i>
                                실제 마진율: <span id="margin-rate">0%</span>
                            </span>
                            <span class="text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                권장 마진: <span id="recommended-margin-rate">30%</span>
                            </span>
                        </div>
                        <button id="cancel-pricing-btn" class="text-blue-600 hover:text-blue-800">
                            취소
                        </button>
                    </div>
                </div>

                <!-- Image at the bottom -->
                <div id="modal-image-content" class="mt-6 flex justify-start items-center">
                    <img id="modal-image" src="" alt="상품 이미지" class="w-[30px] h-[30px] rounded-md border bg-gray-100 object-contain">
                </div>

                <!-- 사진 관리 (mall 큐레이션과 공유되는 mall_product_images) -->
                <div class="mt-6 border-t pt-4">
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-sm font-medium text-gray-700">사진 관리</label>
                        <label class="px-3 py-1.5 text-xs bg-gray-100 text-gray-700 rounded hover:bg-gray-200 cursor-pointer">
                            업로드
                            <input type="file" id="product-image-upload-input" class="hidden" accept="image/jpeg,image/png,image/webp">
                        </label>
                    </div>
                    <p class="text-xs text-gray-500 mb-2">쇼핑몰(mall) 상품 이미지와 동일한 저장소를 공유합니다. 몰 큐레이션에서 삭제 후 다시 등록해도 여기 사진은 유지됩니다.</p>
                    <div id="product-images-grid" class="flex flex-wrap gap-2"></div>
                    <div id="product-images-empty" class="text-xs text-gray-400 hidden">등록된 사진이 없습니다</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 마진율 프리셋 설정 모달 -->
<div id="preset-config-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 overflow-y-auto h-full w-full hidden z-50 flex items-center justify-center p-4">
    <div class="relative w-full max-w-md bg-white rounded-lg shadow-xl">
        <!-- Modal Header -->
        <div class="flex justify-between items-center p-4 border-b rounded-t-lg">
            <h3 class="text-lg font-semibold text-gray-800"><?php echo t('product.margin_preset_setup'); ?></h3>
            <button id="close-preset-modal-btn" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-4">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <?php echo t('product.margin_presets_desc'); ?>
                </label>
                <input type="text" id="presets-input" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                       placeholder="<?php echo t('product.margin_presets_placeholder'); ?>">
                <p class="mt-1 text-xs text-gray-500">
                    <?php echo t('product.margin_presets_hint'); ?>
                </p>
            </div>
            
            <div class="flex justify-end space-x-3">
                <button id="cancel-preset-btn" class="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">
                    <?php echo t('product.cancel'); ?>
                </button>
                <button id="save-preset-btn" class="px-4 py-2 text-sm text-white bg-blue-600 rounded-md hover:bg-blue-700">
                    <?php echo t('product.save'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Translation strings for JavaScript
    const translations = {
        productDetails: <?php echo json_encode(t('product.js_product_details_title')); ?>,
        noDescription: <?php echo json_encode(t('product.js_no_description')); ?>,
        piecesPerBox: <?php echo json_encode(t('product.js_pieces_per_box')); ?>,
        activeStatus: <?php echo json_encode(t('product.js_active_status')); ?>,
        inactiveStatus: <?php echo json_encode(t('product.js_inactive_status')); ?>,
        lastModified: <?php echo json_encode(t('product.js_last_modified')); ?>,
        errorLoadProduct: <?php echo json_encode(t('product.js_error_load_product')); ?>,
        errorLoadPurchase: <?php echo json_encode(t('product.js_error_load_purchase')); ?>,
        noPurchaseHistory: <?php echo json_encode(t('product.js_no_purchase_history_msg')); ?>,
        errorApi: <?php echo json_encode(t('product.js_error_api')); ?>,
        selectPurchaseFirst: <?php echo json_encode(t('product.js_select_purchase_first')); ?>,
        enterValidPrice: <?php echo json_encode(t('product.js_enter_valid_price')); ?>,
        lowMarginWarning: <?php echo json_encode(t('product.js_low_margin_warning')); ?>,
        enterMarginRate: <?php echo json_encode(t('product.js_enter_margin_rate')); ?>,
        selectedText: <?php echo json_encode(t('product.js_selected_text')); ?>,
        selectText: <?php echo json_encode(t('product.js_select_text')); ?>,
        savingText: <?php echo json_encode(t('product.js_saving_text')); ?>,
        noPricingInfo: <?php echo json_encode(t('product.js_no_pricing_info')); ?>,
        noInventoryInfo: <?php echo json_encode(t('product.js_no_inventory_info')); ?>,
        deleteConfirm: <?php echo json_encode(t('product.js_delete_confirm')); ?>,
        deleteConfirmInventory: <?php echo json_encode(t('product.js_delete_confirm_inventory')); ?>,
        errorMarginRange: <?php echo json_encode(t('product.error_margin_range')); ?>,
        errorSavePresets: <?php echo json_encode(t('product.js_error_margin_presets')); ?>,
        store: <?php echo json_encode(t('price_change.store')); ?>,
        inventory: <?php echo json_encode(t('product.inventory_info')); ?>
    };
    
    // 현재 점포 정보
    const currentStoreId = <?php echo json_encode($current_store_id); ?>;
    const currentStoreName = <?php echo json_encode($current_store_name); ?>;

    // ── 엑셀 다운로드 (컬럼 선택) ────────────────────────────
    (function initExport() {
        const exportModal   = document.getElementById('export-modal');
        const openBtn       = document.getElementById('open-export-modal-btn');
        const closeBtn      = document.getElementById('close-export-modal-btn');
        const cancelBtn     = document.getElementById('cancel-export-btn');
        const doBtn         = document.getElementById('do-export-btn');
        const checkAll      = document.getElementById('export-check-all');
        const currentSearch = <?php echo json_encode($search_term); ?>;
        if (!exportModal || !openBtn) return;

        const colChecks = () => Array.from(document.querySelectorAll('.export-col-check'));
        const openExport  = () => exportModal.classList.remove('hidden');
        const closeExport = () => exportModal.classList.add('hidden');

        openBtn.addEventListener('click', openExport);
        closeBtn.addEventListener('click', closeExport);
        cancelBtn.addEventListener('click', closeExport);
        exportModal.addEventListener('click', (e) => { if (e.target === exportModal) closeExport(); });

        checkAll.addEventListener('change', () => {
            colChecks().forEach(c => { c.checked = checkAll.checked; });
        });
        colChecks().forEach(c => c.addEventListener('change', () => {
            checkAll.checked = colChecks().every(x => x.checked);
        }));

        doBtn.addEventListener('click', () => {
            const cols = colChecks().filter(c => c.checked).map(c => c.value);
            if (cols.length === 0) { alert('최소 1개 이상의 컬럼을 선택하세요.'); return; }
            const params = new URLSearchParams();
            if (currentSearch) params.append('search', currentSearch);
            cols.forEach(c => params.append('cols[]', c));
            window.location.href = 'export_products.php?' + params.toString();
            closeExport();
        });
    })();

    const modal = document.getElementById('product-details-modal');
    const closeModalBtn = document.getElementById('close-modal-btn');
    const productRows = document.querySelectorAll('.product-row');
    
    const modalContent = {
        name: document.getElementById('modal-product-name'),
        nameEn: document.getElementById('modal-name-en'),
        nameKo: document.getElementById('modal-name-ko'),
        sku: document.getElementById('modal-sku'),
        description: document.getElementById('modal-description'),
        brand: document.getElementById('modal-brand'),
        category: document.getElementById('modal-category'),
        boxInfo: document.getElementById('modal-box-info'),
        inventoryWrapper: document.getElementById('modal-inventory-wrapper'),
        totalStock: document.getElementById('modal-total-stock'),
        image: document.getElementById('modal-image'),
        status: document.getElementById('modal-status'),
        lastModified: document.getElementById('modal-last-modified'),
        loading: document.getElementById('modal-loading'),
        bodyWrapper: document.getElementById('modal-body-wrapper'),
        imageContent: document.getElementById('modal-image-content'),
        imagesGrid: document.getElementById('product-images-grid'),
        imagesEmpty: document.getElementById('product-images-empty'),
        imagesUploadInput: document.getElementById('product-image-upload-input')
    };

    function showModal() {
        modal.classList.remove('hidden');
    }

    function hideModal() {
        modal.classList.add('hidden');
        // Reset content
        modalContent.loading.style.display = 'block';
        modalContent.bodyWrapper.classList.add('hidden');
    }

    closeModalBtn.addEventListener('click', hideModal);
    modal.addEventListener('click', function(e) {
        // Close if clicking on the background overlay
        if (e.target === modal) {
            hideModal();
        }
    });

    productRows.forEach(row => {
        row.addEventListener('click', function() {
            const productId = this.dataset.productId;
            showModal();
            
            const url = `ajax_get_product_details.php?id=${productId}${currentStoreId ? `&store_id=${currentStoreId}` : ''}`;
            fetch(url)
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        const product = result.data;

                        // 현재 상품 데이터를 전역 변수에 저장 (판매가 설정에서 사용)
                        window.currentProductData = product;

                        // Populate modal with new resume style
                        modalContent.name.textContent = translations.productDetails; // 제목 고정
                        modalContent.nameEn.textContent = product.name_en || ' ';
                        modalContent.nameKo.textContent = product.name_ko || ' ';
                        modalContent.sku.textContent = product.sku || 'N/A';
                        modalContent.description.textContent = product.description || translations.noDescription;
                        modalContent.brand.textContent = product.brand_name_ko || 'N/A';
                        modalContent.category.textContent = product.category_name || 'N/A';
                        
                        // Box packaging information
                        const piecesPerBox = parseInt(product.pieces_per_box) || 1;
                        modalContent.boxInfo.innerHTML = `<span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">${piecesPerBox}${translations.piecesPerBox}</span>`;
                        
                        // Inventory and Pricing by Store
                        console.log('Product inventory data:', product.inventory);
                        modalContent.inventoryWrapper.innerHTML = '';
                        if (product.inventory && product.inventory.length > 0) {
                            const inventoryTable = document.createElement('table');
                            inventoryTable.className = 'w-full text-sm text-left text-gray-600 border';
                            inventoryTable.innerHTML = `
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 font-semibold">${translations.store}</th>
                                        <th class="px-4 py-2 font-semibold text-right">박스원가</th>
                                        <th class="px-4 py-2 font-semibold text-right"><?php echo t('product.cost_price'); ?></th>
                                        <th class="px-4 py-2 font-semibold text-right"><?php echo t('product.margin_rate'); ?>(%)</th>
                                        <th class="px-4 py-2 font-semibold text-right"><?php echo t('product.selling_price'); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            `;
                            const tbody = inventoryTable.querySelector('tbody');
                            product.inventory.forEach(inv => {
                                console.log('Processing inventory item:', inv);
                                const storeBoxPrice = inv.box_price ? `${parseFloat(inv.box_price).toLocaleString()}` : '-';
                                const storeCostPrice = inv.cost_price ? `${parseFloat(inv.cost_price).toLocaleString()}` : '-';
                                const storeSellingPrice = inv.selling_price ? `${parseFloat(inv.selling_price).toLocaleString()}` : '-';

                                // 마진율 계산
                                let marginRate = '-';
                                if (inv.cost_price && inv.selling_price && parseFloat(inv.cost_price) > 0) {
                                    const margin = ((parseFloat(inv.selling_price) - parseFloat(inv.cost_price)) / parseFloat(inv.cost_price)) * 100;
                                    marginRate = `${margin.toFixed(1)}%`;
                                }

                                console.log('Formatted prices - Box:', storeBoxPrice, 'Cost:', storeCostPrice, 'Margin:', marginRate, 'Selling:', storeSellingPrice);
                                const row = document.createElement('tr');
                                row.className = 'border-b';
                                row.innerHTML = `
                                    <td class="px-4 py-2">${inv.store_name}</td>
                                    <td class="px-4 py-2 text-right font-semibold text-purple-700">${storeBoxPrice}</td>
                                    <td class="px-4 py-2 text-right font-semibold text-green-700">${storeCostPrice}</td>
                                    <td class="px-4 py-2 text-right font-semibold text-orange-600">${marginRate}</td>
                                    <td class="px-4 py-2 text-right font-semibold text-blue-700">${storeSellingPrice}</td>
                                `;
                                tbody.appendChild(row);
                            });
                            modalContent.inventoryWrapper.appendChild(inventoryTable);
                        } else {
                            modalContent.inventoryWrapper.innerHTML = `<p class="text-slate-500">${translations.noPricingInfo}</p>`;
                        }

                        // Image
                        if (product.image_url) {
                            modalContent.image.src = product.image_url;
                            modalContent.image.alt = product.name_ko;
                            modalContent.imageContent.classList.remove('hidden');
                        } else {
                            // Hide image container if no image
                            modalContent.imageContent.classList.add('hidden');
                        }

                        // Meta
                        modalContent.status.innerHTML = product.is_active 
                            ? `<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">${translations.activeStatus}</span>`
                            : `<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">${translations.inactiveStatus}</span>`;
                        
                        const lastModifiedDate = new Date(product.updated_at).toLocaleString('ko-KR', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                        modalContent.lastModified.innerHTML = `${translations.lastModified}: ${lastModifiedDate} <br> by ${product.last_modified_by || 'N/A'}`;

                        // Load purchase history
                        loadPurchaseHistory(productId);

                        // Load lot inventory
                        loadLotInventory(productId);

                        // Load product images (사진 관리)
                        loadProductImages(productId);

                        // Show content
                        modalContent.loading.style.display = 'none';
                        modalContent.bodyWrapper.classList.remove('hidden');

                    } else {
                        alert(`${translations.errorApi}: ${result.message}`);
                        hideModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert(translations.errorLoadProduct);
                    hideModal();
                });
        });
    });

    // Purchase History 관련 함수들
    let currentProductId = null;
    let selectedPurchaseData = null;
    let marginPresets = [20, 25, 30, 35]; // 기본값

    // ── 사진 관리 (mall_product_images 공유) ────────────────────────────
    let currentImagesProductId = null;

    function renderProductImages(images) {
        modalContent.imagesGrid.innerHTML = '';
        modalContent.imagesEmpty.classList.toggle('hidden', images.length > 0);

        images.forEach((img, index) => {
            const thumb = document.createElement('div');
            thumb.className = 'relative';
            thumb.style.width = '64px';
            thumb.style.height = '64px';
            thumb.innerHTML = `
                <img src="${img.image_url}" style="width:64px;height:64px;object-fit:cover;border-radius:0.375rem;border:1px solid #e5e7eb;">
                <button type="button" class="product-image-delete-btn" data-image-id="${img.id}" title="삭제"
                        style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;line-height:16px;text-align:center;font-size:0.7rem;color:#fff;background:#dc2626;border-radius:9999px;">×</button>
                <div style="position:absolute;bottom:-6px;left:0;right:0;display:flex;justify-content:center;gap:2px;">
                    <button type="button" class="product-image-move-btn" data-image-id="${img.id}" data-direction="up" ${index === 0 ? 'disabled' : ''}
                            style="font-size:0.65rem;background:#fff;border:1px solid #e5e7eb;border-radius:0.25rem;padding:0 3px;${index === 0 ? 'opacity:0.3;' : 'cursor:pointer;'}">◀</button>
                    <button type="button" class="product-image-move-btn" data-image-id="${img.id}" data-direction="down" ${index === images.length - 1 ? 'disabled' : ''}
                            style="font-size:0.65rem;background:#fff;border:1px solid #e5e7eb;border-radius:0.25rem;padding:0 3px;${index === images.length - 1 ? 'opacity:0.3;' : 'cursor:pointer;'}">▶</button>
                </div>
            `;
            modalContent.imagesGrid.appendChild(thumb);
        });

        modalContent.imagesGrid.querySelectorAll('.product-image-delete-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                if (!confirm('이 사진을 삭제하시겠습니까?')) return;
                const params = new URLSearchParams();
                params.set('image_id', this.dataset.imageId);
                fetch('ajax_delete_product_image.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) { loadProductImages(currentImagesProductId); } else { alert(data.error?.message || '삭제 실패'); }
                    });
            });
        });

        modalContent.imagesGrid.querySelectorAll('.product-image-move-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                if (this.disabled) return;
                const params = new URLSearchParams();
                params.set('image_id', this.dataset.imageId);
                params.set('direction', this.dataset.direction);
                fetch('ajax_reorder_product_images.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) { loadProductImages(currentImagesProductId); } else { alert(data.error?.message || '순서 변경 실패'); }
                    });
            });
        });
    }

    function loadProductImages(productId) {
        currentImagesProductId = productId;
        fetch(`ajax_get_product_images.php?product_id=${productId}`)
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    renderProductImages(result.data);
                }
            })
            .catch(error => console.error('Error loading product images:', error));
    }

    modalContent.imagesUploadInput.addEventListener('change', function () {
        if (!this.files.length || !currentImagesProductId) return;
        const formData = new FormData();
        formData.append('product_id', currentImagesProductId);
        formData.append('image', this.files[0]);
        fetch('ajax_upload_product_image.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) { loadProductImages(currentImagesProductId); } else { alert(data.error?.message || '업로드 실패'); }
                modalContent.imagesUploadInput.value = '';
            })
            .catch(error => {
                console.error('Error uploading product image:', error);
                modalContent.imagesUploadInput.value = '';
            });
    });

    // 마진율 프리셋 로드
    function loadMarginPresets() {
        fetch('ajax_get_margin_presets.php')
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    marginPresets = result.data;
                    updateMarginPresetButtons();
                } else {
                    console.error(translations.errorMarginRange, result.message);
                    updateMarginPresetButtons(); // 기본값 사용
                }
            })
            .catch(error => {
                console.error(translations.errorMarginRange, error);
                updateMarginPresetButtons(); // 기본값 사용
            });
    }

    // 마진율 프리셋 버튼 업데이트
    function updateMarginPresetButtons() {
        const container = document.getElementById('margin-presets-container');
        container.innerHTML = '';
        
        marginPresets.forEach(preset => {
            const button = document.createElement('button');
            button.className = 'margin-preset-btn px-3 py-2 text-sm border border-blue-300 rounded-md hover:bg-blue-100 focus:bg-blue-200';
            button.dataset.margin = preset;
            button.textContent = `${preset}%`;
            container.appendChild(button);
        });
    }

    // 토스트 알림 함수
    function showToast(message, type = 'info') {
        // 기존 토스트 제거
        const existingToast = document.querySelector('.toast-notification');
        if (existingToast) {
            existingToast.remove();
        }

        const toast = document.createElement('div');
        toast.className = `toast-notification fixed top-4 right-4 z-50 px-4 py-3 rounded-md shadow-lg max-w-sm transition-all duration-300 ${
            type === 'success' ? 'bg-green-500 text-white' : 
            type === 'error' ? 'bg-red-500 text-white' : 
            'bg-blue-500 text-white'
        }`;
        
        toast.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>
                <span>${message}</span>
                <button class="ml-3 text-white hover:text-gray-200" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        document.body.appendChild(toast);

        // 3초 후 자동 제거
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3000);
    }

    function loadPurchaseHistory(productId) {
        currentProductId = productId;
        const loadingDiv = document.getElementById('purchase-history-loading');
        const contentDiv = document.getElementById('purchase-history-content');
        const noHistoryDiv = document.getElementById('no-purchase-history');
        
        // 로딩 상태 표시
        loadingDiv.style.display = 'block';
        contentDiv.classList.add('hidden');
        
        const purchaseUrl = `ajax_get_purchase_history.php?product_id=${productId}${currentStoreId ? `&store_id=${currentStoreId}` : ''}`;
        fetch(purchaseUrl)
            .then(response => response.json())
            .then(result => {
                loadingDiv.style.display = 'none';
                
                if (result.success) {
                    if (result.data && result.data.length > 0) {
                        populatePurchaseHistory(result.data);
                        contentDiv.classList.remove('hidden');
                        noHistoryDiv.classList.add('hidden');
                    } else {
                        // 매입 이력이 없는 경우
                        contentDiv.classList.add('hidden');
                        noHistoryDiv.classList.remove('hidden');
                        if (result.message) {
                            noHistoryDiv.innerHTML = `
                                <div class="text-center py-4 text-gray-500">
                                    <i class="fas fa-info-circle text-2xl"></i>
                                    <p class="mt-2">${result.message}</p>
                                </div>
                            `;
                        }
                    }
                } else {
                    // API 오류인 경우
                    contentDiv.classList.add('hidden');
                    noHistoryDiv.innerHTML = `
                        <div class="text-center py-4 text-red-500">
                            <i class="fas fa-exclamation-triangle text-2xl"></i>
                            <p class="mt-2">오류: ${result.message}</p>
                        </div>
                    `;
                    noHistoryDiv.classList.remove('hidden');
                }
            })
            .catch(error => {
                console.error(translations.errorLoadPurchase, error);
                loadingDiv.innerHTML = `<p class="text-red-500"><i class="fas fa-exclamation-triangle mr-2"></i>${translations.errorLoadProduct}</p>`;
                showToast(translations.errorLoadPurchase, 'error');
            });
    }

    function populatePurchaseHistory(historyData) {
        const tbody = document.getElementById('purchase-history-tbody');
        tbody.innerHTML = '';
        
        historyData.forEach((item, index) => {
            const row = document.createElement('tr');
            row.className = 'border-b hover:bg-gray-50 cursor-pointer';
            row.dataset.purchaseIndex = index;
            
            row.innerHTML = `
                <td class="px-3 py-2">${item.purchase_date_formatted}</td>
                <td class="px-3 py-2">${item.supplier_name}</td>
                <td class="px-3 py-2">${item.store_name || currentStoreName}</td>
                <td class="px-3 py-2 text-right font-mono">${item.box_cost_formatted || '-'}</td>
                <td class="px-3 py-2 text-right font-mono">${item.unit_cost_per_piece_formatted}</td>
                <td class="px-3 py-2 text-center">
                    <button class="select-purchase-btn px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200">
                        ${translations.selectText}
                    </button>
                </td>
            `;
            
            // 선택 버튼 이벤트
            const selectBtn = row.querySelector('.select-purchase-btn');
            selectBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                selectPurchaseForPricing(item, selectBtn);
            });
            
            tbody.appendChild(row);
        });
    }

    function selectPurchaseForPricing(purchaseData, buttonElement) {
        selectedPurchaseData = purchaseData;
        
        // 이전 선택 해제
        document.querySelectorAll('.select-purchase-btn').forEach(btn => {
            btn.textContent = translations.selectText;
            btn.className = 'select-purchase-btn px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200';
        });
        
        // 현재 버튼 선택 상태로 변경
        buttonElement.textContent = translations.selectedText;
        buttonElement.className = 'select-purchase-btn px-2 py-1 bg-green-100 text-green-700 rounded text-xs';
        
        // 가격 설정 섹션 표시
        const pricingSection = document.getElementById('modal-pricing-section');
        const costDisplay = document.getElementById('selected-cost-display');
        const sellingPriceInput = document.getElementById('new-selling-price');
        const boxPriceInput = document.getElementById('new-box-price');
        const recommendedMarginRate = document.getElementById('recommended-margin-rate');

        costDisplay.textContent = purchaseData.unit_cost_per_piece_formatted;

        // 현재 상품의 박스원가를 박스원가 입력 필드에 설정
        // purchaseData에서 현재 점포의 박스원가 찾기
        const currentStoreInventory = window.currentProductData?.inventory?.find(inv =>
            inv.store_id == currentStoreId || inv.store_name === purchaseData.store_name
        );
        if (currentStoreInventory && currentStoreInventory.box_price) {
            boxPriceInput.value = parseFloat(currentStoreInventory.box_price);
        } else {
            // 기본값으로 매입 단가 설정
            boxPriceInput.value = parseFloat(purchaseData.unit_cost_per_piece) || '';
        }
        
        // 권장 마진율 표시 (마진 관리 시스템에서 받은 데이터 사용)
        if (purchaseData.margin_rate) {
            recommendedMarginRate.textContent = `${purchaseData.margin_rate}%`;
            // 권장 마진율로 기본 판매가 계산
            applyMarginRate(purchaseData.margin_rate);
        } else {
            // 기본값 사용
            sellingPriceInput.value = purchaseData.suggested_selling_price;
            updateMarginRate();
        }
        
        // 마진 버튼 이벤트 리스너 추가
        setupMarginButtons();
        
        pricingSection.classList.remove('hidden');
    }

    function applyMarginRate(marginRate) {
        if (!selectedPurchaseData) return;
        
        const costPrice = parseFloat(selectedPurchaseData.unit_cost_per_piece);
        const sellingPrice = Math.round(costPrice * (1 + marginRate / 100));
        
        document.getElementById('new-selling-price').value = sellingPrice;
        updateMarginRate();
        
        // 선택된 마진 버튼 강조
        document.querySelectorAll('.margin-preset-btn').forEach(btn => {
            btn.classList.remove('bg-blue-200', 'border-blue-500');
            btn.classList.add('border-blue-300');
            if (parseFloat(btn.dataset.margin) === marginRate) {
                btn.classList.add('bg-blue-200', 'border-blue-500');
                btn.classList.remove('border-blue-300');
            }
        });
    }

    function setupMarginButtons() {
        // 기존 이벤트 리스너 제거 (중복 방지)
        document.querySelectorAll('.margin-preset-btn').forEach(btn => {
            btn.replaceWith(btn.cloneNode(true));
        });
        
        const customMarginBtn = document.getElementById('apply-custom-margin-btn');
        const customMarginInput = document.getElementById('custom-margin-input');
        
        customMarginBtn.replaceWith(customMarginBtn.cloneNode(true));
        customMarginInput.replaceWith(customMarginInput.cloneNode(true));
        
        // 새로운 이벤트 리스너 추가
        document.querySelectorAll('.margin-preset-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const marginRate = parseFloat(this.dataset.margin);
                applyMarginRate(marginRate);
                
                // 커스텀 입력 필드 초기화
                document.getElementById('custom-margin-input').value = '';
            });
        });
        
        // 커스텀 마진 적용 버튼
        document.getElementById('apply-custom-margin-btn').addEventListener('click', function() {
            const customMargin = parseFloat(document.getElementById('custom-margin-input').value);
            if (isNaN(customMargin) || customMargin < 0 || customMargin > 100) {
                showToast(translations.errorMarginRange, 'error');
                return;
            }
            
            applyMarginRate(customMargin);
            
            // 프리셋 버튼 선택 해제
            document.querySelectorAll('.margin-preset-btn').forEach(btn => {
                btn.classList.remove('bg-blue-200', 'border-blue-500');
                btn.classList.add('border-blue-300');
            });
        });
        
        // 커스텀 마진 입력 시 엔터키 처리
        document.getElementById('custom-margin-input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('apply-custom-margin-btn').click();
            }
        });
    }

    function updateMarginRate() {
        if (!selectedPurchaseData) return;
        
        const costPrice = parseFloat(selectedPurchaseData.unit_cost_per_piece);
        const sellingPrice = parseFloat(document.getElementById('new-selling-price').value) || 0;
        const marginRate = costPrice > 0 ? ((sellingPrice - costPrice) / costPrice * 100).toFixed(1) : 0;
        
        document.getElementById('margin-rate').textContent = `${marginRate}%`;
    }

    // 이벤트 리스너
    document.getElementById('new-selling-price').addEventListener('input', updateMarginRate);
    
    document.getElementById('apply-selling-price-btn').addEventListener('click', function() {
        if (!selectedPurchaseData || !currentProductId) {
            showToast(translations.selectPurchaseFirst, 'error');
            return;
        }
        
        const sellingPrice = parseFloat(document.getElementById('new-selling-price').value);
        const boxPrice = parseFloat(document.getElementById('new-box-price').value);

        if (!sellingPrice || sellingPrice <= 0) {
            showToast(translations.enterValidPrice, 'error');
            return;
        }

        // 매우 낮은 마진율 경고
        const costPrice = parseFloat(selectedPurchaseData.unit_cost_per_piece);
        const marginRate = ((sellingPrice - costPrice) / costPrice * 100);
        if (marginRate < 5) {
            if (!confirm(translations.lowMarginWarning.replace('{rate}', marginRate.toFixed(1)))) {
                return;
            }
        }

        // 판매가 업데이트 API 호출
        const formData = new FormData();
        formData.append('product_id', currentProductId);
        formData.append('selling_price', sellingPrice);
        formData.append('cost_price', costPrice); // 선택된 원가 추가

        // 박스원가가 입력된 경우 추가
        if (boxPrice && boxPrice > 0) {
            formData.append('box_price', boxPrice);
        }

        if (currentStoreId) {
            formData.append('store_id', currentStoreId);
        }
        if (selectedPurchaseData && selectedPurchaseData.purchase_id) {
            formData.append('purchase_id', selectedPurchaseData.purchase_id);
        }
        
        fetch('ajax_update_selling_price.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showToast(result.message, 'success');
                
                // 상품 정보 새로고침 (지점별 재고 테이블 업데이트)
                const productId = currentProductId;
                const url = `ajax_get_product_details.php?id=${productId}${currentStoreId ? `&store_id=${currentStoreId}` : ''}`;
                fetch(url)
                    .then(response => response.json())
                    .then(refreshResult => {
                        if (refreshResult.success) {
                            const product = refreshResult.data;
                            
                            // 지점별 재고 및 가격 테이블 업데이트
                            modalContent.inventoryWrapper.innerHTML = '';
                            if (product.inventory && product.inventory.length > 0) {
                                const inventoryTable = document.createElement('table');
                                inventoryTable.className = 'w-full text-sm text-left text-gray-600 border';
                                inventoryTable.innerHTML = `
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 font-semibold">${translations.store}</th>
                                            <th class="px-3 py-2 font-semibold text-right">${translations.inventory}</th>
                                            <th class="px-3 py-2 font-semibold text-right">박스원가</th>
                                            <th class="px-3 py-2 font-semibold text-right"><?php echo t('product.cost_price'); ?></th>
                                            <th class="px-3 py-2 font-semibold text-right"><?php echo t('product.margin_rate'); ?>(%)</th>
                                            <th class="px-3 py-2 font-semibold text-right"><?php echo t('product.selling_price'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                `;
                                const tbody = inventoryTable.querySelector('tbody');
                                product.inventory.forEach(inv => {
                                    console.log('Refresh - Processing inventory item:', inv);
                                    const storeBoxPrice = inv.box_price ? `${parseFloat(inv.box_price).toLocaleString()}` : '-';
                                    const storeCostPrice = inv.cost_price ? `${parseFloat(inv.cost_price).toLocaleString()}` : '-';
                                    const storeSellingPrice = inv.selling_price ? `${parseFloat(inv.selling_price).toLocaleString()}` : '-';

                                    // 마진율 계산
                                    let marginRate = '-';
                                    if (inv.cost_price && inv.selling_price && parseFloat(inv.cost_price) > 0) {
                                        const margin = ((parseFloat(inv.selling_price) - parseFloat(inv.cost_price)) / parseFloat(inv.cost_price)) * 100;
                                        marginRate = `${margin.toFixed(1)}%`;
                                    }

                                    console.log('Refresh - Formatted prices - Box:', storeBoxPrice, 'Cost:', storeCostPrice, 'Margin:', marginRate, 'Selling:', storeSellingPrice);
                                    const row = document.createElement('tr');
                                    row.className = 'border-b';
                                    row.innerHTML = `
                                        <td class="px-3 py-2">${inv.store_name}</td>
                                        <td class="px-3 py-2 text-right">${parseInt(inv.quantity)}개</td>
                                        <td class="px-3 py-2 text-right font-semibold text-purple-700">${storeBoxPrice}</td>
                                        <td class="px-3 py-2 text-right font-semibold text-green-700">${storeCostPrice}</td>
                                        <td class="px-3 py-2 text-right font-semibold text-orange-600">${marginRate}</td>
                                        <td class="px-3 py-2 text-right font-semibold text-blue-700">${storeSellingPrice}</td>
                                    `;
                                    tbody.appendChild(row);
                                });
                                modalContent.inventoryWrapper.appendChild(inventoryTable);
                            } else {
                                modalContent.inventoryWrapper.innerHTML = `<p class="text-slate-500">${translations.noInventoryInfo}</p>`;
                            }
                        }
                    })
                    .catch(error => {
                        console.error(translations.errorLoadProduct, error);
                    });
                
                // 판매가 설정 섹션 숨기기
                document.getElementById('modal-pricing-section').classList.add('hidden');
                
                // 선택 초기화
                selectedPurchaseData = null;
                document.querySelectorAll('.select-purchase-btn').forEach(btn => {
                    btn.textContent = translations.selectText;
                    btn.className = 'select-purchase-btn px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200';
                });
                
            } else {
                showToast(`${translations.errorApi}: ${result.message}`, 'error');
            }
        })
        .catch(error => {
            console.error(translations.errorLoadProduct, error);
            showToast(translations.errorLoadProduct, 'error');
        });
    });
    
    document.getElementById('cancel-pricing-btn').addEventListener('click', function() {
        // 판매가 설정 섹션 숨기기
        document.getElementById('modal-pricing-section').classList.add('hidden');
        
        // 선택 초기화
        selectedPurchaseData = null;
        document.querySelectorAll('.select-purchase-btn').forEach(btn => {
            btn.textContent = translations.selectText;
            btn.className = 'select-purchase-btn px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200';
        });
        
        // 마진 선택 상태 초기화
        document.querySelectorAll('.margin-preset-btn').forEach(btn => {
            btn.classList.remove('bg-blue-200', 'border-blue-500');
            btn.classList.add('border-blue-300');
        });
        document.getElementById('custom-margin-input').value = '';
        document.getElementById('new-selling-price').value = '';
        document.getElementById('new-box-price').value = '';
    });
    
    // 페이지 로드 시 마진율 프리셋 로드
    loadMarginPresets();
    
    // 간단한 삭제 확인 함수
    window.confirmDelete = function(productName) {
        // 첫 번째 확인
        if (!confirm(translations.deleteConfirm.replace('{name}', productName))) {
            return false;
        }
        
        // 두 번째 확인
        if (!confirm(translations.deleteConfirmInventory)) {
            return false;
        }
        
        return true;
    };
    
    // 마진율 프리셋 설정 모달 관련
    const presetConfigModal = document.getElementById('preset-config-modal');
    const configurePresetsBtn = document.getElementById('configure-presets-btn');
    const closePresetModalBtn = document.getElementById('close-preset-modal-btn');
    const cancelPresetBtn = document.getElementById('cancel-preset-btn');
    const savePresetBtn = document.getElementById('save-preset-btn');
    const presetsInput = document.getElementById('presets-input');
    
    configurePresetsBtn.addEventListener('click', function() {
        // 현재 프리셋을 입력 필드에 표시
        presetsInput.value = marginPresets.join(', ');
        presetConfigModal.classList.remove('hidden');
    });
    
    closePresetModalBtn.addEventListener('click', function() {
        presetConfigModal.classList.add('hidden');
    });
    
    cancelPresetBtn.addEventListener('click', function() {
        presetConfigModal.classList.add('hidden');
    });
    
    presetConfigModal.addEventListener('click', function(e) {
        if (e.target === presetConfigModal) {
            presetConfigModal.classList.add('hidden');
        }
    });
    
    savePresetBtn.addEventListener('click', function() {
        const presetsValue = presetsInput.value.trim();
        if (!presetsValue) {
            showToast(translations.enterMarginRate, 'error');
            return;
        }
        
        // 저장 중 표시
        savePresetBtn.textContent = translations.savingText;
        savePresetBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('presets', presetsValue);
        
        fetch('ajax_save_margin_presets.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
            console.log('Save result:', result);
            if (result.success) {
                showToast(result.message, 'success');
                marginPresets = result.data;
                updateMarginPresetButtons();
                presetConfigModal.classList.add('hidden');
            } else {
                showToast(`${translations.errorApi}: ${result.message}`, 'error');
                console.error('Save failed:', result.message);
            }
        })
        .catch(error => {
            console.error(translations.errorSavePresets, error);
            showToast(`${translations.errorSavePresets}: ${error.message}`, 'error');
        })
        .finally(() => {
            savePresetBtn.textContent = <?php echo json_encode(t('product.save')); ?>;
            savePresetBtn.disabled = false;
        });
    });

    // Lot Inventory 관련 함수들
    const lotTbody = document.getElementById('lot-inventory-tbody');
    const btnAddLot = document.getElementById('btn-add-lot');

    function loadLotInventory(productId) {
        lotTbody.innerHTML = `<tr><td colspan="3" class="text-center py-4"><i class="fas fa-spinner fa-spin text-primary-600"></i></td></tr>`;
        
        fetch(`ajax_get_lot_inventory.php?product_id=${productId}&store_id=${currentStoreId}`)
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    renderLotInventory(result.data);
                } else {
                    lotTbody.innerHTML = `<tr><td colspan="3" class="text-center py-4 text-red-500">${result.error || 'Failed to load lots.'}</td></tr>`;
                }
            })
            .catch(err => {
                console.error(err);
                lotTbody.innerHTML = `<tr><td colspan="3" class="text-center py-4 text-red-500">통신 오류가 발생했습니다.</td></tr>`;
            });
    }

    function renderLotInventory(lots) {
        lotTbody.innerHTML = '';
        if (!lots || lots.length === 0) {
            lotTbody.innerHTML = `<tr><td colspan="3" class="text-center py-4 text-gray-500">등록된 유통기한 로트가 없습니다.</td></tr>`;
        } else {
            lots.forEach(lot => addLotRow(lot.id, lot.expiration_date, lot.quantity));
        }
        
        // Add "save changes" button if not already there
        if (!document.getElementById('btn-save-lots')) {
            const wrapper = document.getElementById('modal-lot-wrapper');
            const saveBtnContainer = document.createElement('div');
            saveBtnContainer.className = "flex justify-end mt-2";
            saveBtnContainer.innerHTML = `
                <button type="button" id="btn-save-lots" class="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700">
                    로트 재고 변경 저장
                </button>
            `;
            wrapper.appendChild(saveBtnContainer);

            document.getElementById('btn-save-lots').addEventListener('click', saveLotInventory);
        }
    }

    function addLotRow(id = 0, expDate = '', qty = 0) {
        // If empty row exists, remove it
        if (lotTbody.querySelector('td[colspan="3"]')) {
            lotTbody.innerHTML = '';
        }

        const tr = document.createElement('tr');
        tr.className = "border-b lot-row";
        tr.dataset.id = id;
        tr.innerHTML = `
            <td class="px-4 py-2">
                <input type="date" class="lot-date px-2 py-1 border border-gray-300 rounded focus:ring-primary-500 focus:border-primary-500 w-full" value="${expDate}" ${id > 0 ? '' : 'required'}>
            </td>
            <td class="px-4 py-2">
                <input type="number" class="lot-qty px-2 py-1 border border-gray-300 rounded focus:ring-primary-500 text-right w-full" value="${qty}" ${id > 0 ? '' : ''}>
            </td>
            <td class="px-4 py-2 text-center">
                <button type="button" class="text-red-500 hover:text-red-700 btn-remove-lot" title="삭제"><i class="fas fa-trash"></i></button>
            </td>
        `;
        
        tr.querySelector('.btn-remove-lot').addEventListener('click', function() {
            tr.remove();
            if (lotTbody.querySelectorAll('tr').length === 0) {
                lotTbody.innerHTML = `<tr><td colspan="3" class="text-center py-4 text-gray-500">등록된 유통기한 로트가 없습니다.</td></tr>`;
            }
        });
        
        lotTbody.appendChild(tr);
    }

    btnAddLot.addEventListener('click', function() {
        const defaultExpDate = new Date();
        defaultExpDate.setDate(defaultExpDate.getDate() + 30);
        addLotRow(0, defaultExpDate.toISOString().split('T')[0], 0);
    });

    function saveLotInventory() {
        if (!currentProductId) return;
        
        const btnSave = document.getElementById('btn-save-lots');
        btnSave.disabled = true;
        btnSave.innerHTML = `<i class="fas fa-spinner fa-spin mr-1"></i> 저장 중...`;

        const lotRows = lotTbody.querySelectorAll('.lot-row');
        const lotsData = [];
        let hasError = false;

        lotRows.forEach(row => {
            const date = row.querySelector('.lot-date').value;
            const qty = row.querySelector('.lot-qty').value;
            
            if (!date) {
                hasError = true;
                row.querySelector('.lot-date').classList.add('border-red-500');
            } else {
                row.querySelector('.lot-date').classList.remove('border-red-500');
                lotsData.push({
                    id: row.dataset.id,
                    expiration_date: date,
                    quantity: qty
                });
            }
        });

        if (hasError) {
            showToast('유통기한 날짜를 입력해주세요.', 'error');
            btnSave.disabled = false;
            btnSave.innerHTML = `로트 재고 변경 저장`;
            return;
        }

        const payload = {
            product_id: currentProductId,
            store_id: currentStoreId,
            lots: lotsData
        };

        fetch('ajax_update_lot_inventory.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                showToast(result.message, 'success');
                // Reload list to get updated IDs
                loadLotInventory(currentProductId);
                // Also optionally refresh the parent product table logic (if total stock might have changed)
                // Using existing fetch flow for modal is tricky because it re-renders pricing table, 
                // but we can just reload lots for now.
            } else {
                showToast(result.error || '저장 실패', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('에러가 발생했습니다.', 'error');
        })
        .finally(() => {
            btnSave.disabled = false;
            btnSave.innerHTML = `로트 재고 변경 저장`;
        });
    }

});
</script>
