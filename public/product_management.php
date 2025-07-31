<?php // Cache-Busting Comment: 2025-07-24 10:00:00 AM 
$page_title = "상품 관리 - HOME K MART";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 총괄관리자 또는 일반관리자만 접근 가능
if (!in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$pdo = null;
$products = [];
$error_message = '';

// 검색 및 페이징 변수
$search_term = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15; // 페이지당 상품 수
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
            p.id, p.sku, p.name_ko, p.name_en, p.is_active, p.pieces_per_box, p.barcode,
            c.name as category_name, b.name_ko as brand_name, b.name_en as brand_name_en,
            (SELECT SUM(quantity) FROM inventory WHERE product_id = p.id) as total_stock
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
    $error_message = "상품 정보를 불러오는 데 실패했습니다: " . $e->getMessage();
}

?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-900">상품 리스트</h1>
        <a href="add_product.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
            <i class="fas fa-plus mr-2"></i> 새 상품 추가
        </a>
    </div>

    <!-- 검색 및 필터 -->
    <div class="mb-6">
        <form action="product_management.php" method="get">
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
                <input type="search" name="search" placeholder="상품명, SKU, 브랜드명으로 검색..." class="block w-full rounded-md border-gray-300 pl-10 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
        </form>
    </div>

    <?php if ($error_message): ?>
        <div class="bg-red-50 border border-red-200 rounded-md p-4">
            <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php elseif (empty($products)): ?>
        <div class="text-center py-12">
            <i class="fas fa-box-open text-5xl text-gray-400"></i>
            <h2 class="mt-4 text-lg font-medium text-gray-900">상품이 없습니다.</h2>
            <p class="mt-1 text-sm text-gray-500">아직 등록된 상품이 없습니다. 첫 상품을 추가해보세요.</p>
            <div class="mt-6">
                <a href="add_product.php" class="inline-flex items-center rounded-md border border-transparent bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    <i class="fas fa-plus mr-2"></i> 새 상품 추가
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">상품명(한글)</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">브랜드</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">카테고리</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">박스당 수량</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">총 재고</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">상태</th>
                            <th scope="col" class="relative px-6 py-3">
                                <span class="sr-only">작업</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($products as $product): ?>
                            <tr class="hover:bg-gray-50 cursor-pointer product-row" data-product-id="<?php echo $product['id']; ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500"><?php echo htmlspecialchars($product['sku']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['name_ko']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($product['name_en']); ?></div>
                                    <?php if ($product['barcode']): ?>
                                        <div class="text-xs text-gray-400">바코드: <?php echo htmlspecialchars($product['barcode']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($product['brand_name'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                                        <?php echo number_format($product['pieces_per_box'] ?? 1); ?>개
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center"><?php echo number_format($product['total_stock'] ?? 0); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php if ($product['is_active']): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">활성</span>
                                    <?php else: ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">비활성</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="edit_product.php?id=<?php echo $product['id']; ?>" class="text-primary-600 hover:text-primary-900" onclick="event.stopPropagation();">수정</a>
                                    <a href="delete_product.php?id=<?php echo $product['id']; ?>" class="text-red-600 hover:text-red-900 ml-4" onclick="event.stopPropagation(); return confirm('정말로 이 상품을 삭제하시겠습니까?');">삭제</a>
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
                <div class="-mt-px flex w-0 flex-1">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_term); ?>" class="inline-flex items-center border-t-2 border-transparent pt-4 pr-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700">
                            <i class="fas fa-arrow-left mr-3"></i> 이전
                        </a>
                    <?php endif; ?>
                </div>
                <div class="hidden md:-mt-px md:flex">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_term); ?>" class="<?php echo ($i == $page) ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> inline-flex items-center border-t-2 px-4 pt-4 text-sm font-medium">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <div class="-mt-px flex w-0 flex-1 justify-end">
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search_term); ?>" class="inline-flex items-center border-t-2 border-transparent pt-4 pl-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700">
                            다음 <i class="fas fa-arrow-right ml-3"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- 상품 상세 정보 Modal -->
<div id="product-details-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 overflow-y-auto h-full w-full hidden z-50 flex items-center justify-center p-4">
    <div class="relative w-full max-w-3xl max-h-[90vh] bg-white rounded-lg shadow-xl flex flex-col">
        <!-- Modal Header -->
        <div class="flex justify-between items-center p-4 border-b rounded-t-lg">
            <h3 class="text-xl font-semibold text-gray-800" id="modal-product-name">상품 상세 정보</h3>
            <button id="close-modal-btn" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 flex-grow overflow-y-auto">
            <!-- 로딩 스피너 -->
            <div id="modal-loading" class="text-center py-20">
                <i class="fas fa-spinner fa-spin text-4xl text-primary-600"></i>
                <p class="mt-3 text-gray-500">정보를 불러오는 중...</p>
            </div>
            
            <!-- 상세 정보 전체 래퍼 -->
            <div id="modal-body-wrapper" class="hidden">
                <!-- Basic Details Table -->
                <h4 class="text-lg font-semibold text-gray-800 mb-2">기본 정보</h4>
                <table class="w-full text-sm text-left text-gray-600 mb-6">
                    <tbody>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 w-1/3">상품명 (영문)</td>
                            <td class="px-4 py-2" id="modal-name-en"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 w-1/3">상품명 (한글)</td>
                            <td class="px-4 py-2" id="modal-name-ko"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">SKU</td>
                            <td class="px-4 py-2 font-mono" id="modal-sku"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">바코드</td>
                            <td class="px-4 py-2 font-mono" id="modal-barcode"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">브랜드</td>
                            <td class="px-4 py-2" id="modal-brand"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">카테고리</td>
                            <td class="px-4 py-2" id="modal-category"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">원가</td>
                            <td class="px-4 py-2 font-semibold text-green-700" id="modal-cost-price"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">기본 판매가</td>
                            <td class="px-4 py-2 font-semibold text-blue-700" id="modal-selling-price"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">박스포장 정보</td>
                            <td class="px-4 py-2" id="modal-box-info"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 align-top">상품 설명</td>
                            <td class="px-4 py-2 whitespace-pre-wrap" id="modal-description"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">상태</td>
                            <td class="px-4 py-2" id="modal-status"></td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-semibold bg-gray-50">최근 수정</td>
                            <td class="px-4 py-2" id="modal-last-modified"></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Inventory and Pricing by Store -->
                <h4 class="text-lg font-semibold text-gray-800 mb-2">지점별 재고 및 가격</h4>
                <div id="modal-inventory-wrapper">
                    <!-- JS will populate this -->
                </div>
                <p id="modal-total-stock" class="font-bold mt-2 text-right"></p>

                <!-- Image at the bottom -->
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
    const productRows = document.querySelectorAll('.product-row');
    
    const modalContent = {
        name: document.getElementById('modal-product-name'),
        nameEn: document.getElementById('modal-name-en'),
        nameKo: document.getElementById('modal-name-ko'),
        sku: document.getElementById('modal-sku'),
        barcode: document.getElementById('modal-barcode'),
        description: document.getElementById('modal-description'),
        brand: document.getElementById('modal-brand'),
        category: document.getElementById('modal-category'),
        costPrice: document.getElementById('modal-cost-price'),
        sellingPrice: document.getElementById('modal-selling-price'),
        boxInfo: document.getElementById('modal-box-info'),
        inventoryWrapper: document.getElementById('modal-inventory-wrapper'),
        totalStock: document.getElementById('modal-total-stock'),
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
            
            fetch(`ajax_get_product_details.php?id=${productId}`)
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        const product = result.data;
                        
                        // Populate modal with new resume style
                        modalContent.name.textContent = '상품 상세 정보'; // 제목 고정
                        modalContent.nameEn.textContent = product.name_en || ' ';
                        modalContent.nameKo.textContent = product.name_ko || ' ';
                        modalContent.sku.textContent = product.sku || 'N/A';
                        modalContent.description.textContent = product.description || '등록된 상품 설명이 없습니다.';
                        modalContent.brand.textContent = product.brand_name_ko || 'N/A';
                        modalContent.category.textContent = product.category_name || 'N/A';
                        modalContent.costPrice.textContent = `₩ ${parseFloat(product.cost_price || 0).toFixed(0)}`;
                        modalContent.sellingPrice.textContent = `₩ ${parseFloat(product.selling_price || 0).toFixed(0)}`;
                        
                        // Barcode and Box packaging information
                        modalContent.barcode.textContent = product.barcode || '등록된 바코드 없음';
                        const piecesPerBox = parseInt(product.pieces_per_box) || 1;
                        modalContent.boxInfo.innerHTML = `<span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">${piecesPerBox}개/박스</span>`;
                        
                        // Inventory and Pricing by Store
                        modalContent.inventoryWrapper.innerHTML = '';
                        if (product.inventory && product.inventory.length > 0) {
                            const inventoryTable = document.createElement('table');
                            inventoryTable.className = 'w-full text-sm text-left text-gray-600 border';
                            inventoryTable.innerHTML = `
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 font-semibold">지점</th>
                                        <th class="px-4 py-2 font-semibold text-right">재고</th>
                                        <th class="px-4 py-2 font-semibold text-right">지점 판매가</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            `;
                            const tbody = inventoryTable.querySelector('tbody');
                            product.inventory.forEach(inv => {
                                const storePrice = inv.selling_price ? `₱ ${parseFloat(inv.selling_price).toFixed(2)}` : `(기본가) ₱ ${parseFloat(product.selling_price || 0).toFixed(2)}`;
                                const row = document.createElement('tr');
                                row.className = 'border-b';
                                row.innerHTML = `
                                    <td class="px-4 py-2">${inv.store_name}</td>
                                    <td class="px-4 py-2 text-right">${parseInt(inv.quantity)}개</td>
                                    <td class="px-4 py-2 text-right font-semibold text-blue-700">${storePrice}</td>
                                `;
                                tbody.appendChild(row);
                            });
                            modalContent.inventoryWrapper.appendChild(inventoryTable);
                        } else {
                            modalContent.inventoryWrapper.innerHTML = '<p class="text-slate-500">재고 정보 없음</p>';
                        }
                        modalContent.totalStock.textContent = `총 재고: ${product.total_stock}개`;

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
                            ? '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">활성</span>'
                            : '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">비활성</span>';
                        
                        const lastModifiedDate = new Date(product.updated_at).toLocaleString('ko-KR', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                        modalContent.lastModified.innerHTML = `최근 수정: ${lastModifiedDate} <br> by ${product.last_modified_by || 'N/A'}`;

                        // Show content
                        modalContent.loading.style.display = 'none';
                        modalContent.bodyWrapper.classList.remove('hidden');

                    } else {
                        alert('오류: ' + result.message);
                        hideModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('상품 정보를 불러오는 중 오류가 발생했습니다.');
                    hideModal();
                });
        });
    });
});
</script>
