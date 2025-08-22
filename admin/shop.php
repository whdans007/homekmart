<?php
$page_title = "HOME K MART - 쇼핑몰";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 쇼핑몰 접근 권한 확인
require_permission('shop_access');

require_once __DIR__ . '/../config/db_config.php';

$products = [];
$categories = [];
$brands = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 카테고리 목록 가져오기
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // 브랜드 목록 가져오기
    $brands = $pdo->query("SELECT id, name_ko as name FROM brands ORDER BY name_ko ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 상품 목록 가져오기 (재고가 있는 상품만)
    $stmt = $pdo->query("
        SELECT 
            p.id, p.name, p.selling_price, p.description, p.image_url,
            c.name as category_name,
            b.name_ko as brand_name,
            SUM(COALESCE(i.quantity, 0)) as total_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN inventory i ON p.id = i.product_id
        WHERE p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00'
        GROUP BY p.id, p.name, p.selling_price, p.description, p.image_url, c.name, b.name_ko
        HAVING total_stock > 0
        ORDER BY p.name ASC
    ");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "상품 정보를 불러오는 데 실패했습니다.";
    error_log($e->getMessage());
}
?>

<style>
.product-card {
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
}
.product-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
</style>

<!-- Page header -->
<div class="mb-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">HOME K MART</h1>
            <p class="mt-2 text-lg text-gray-600">편리한 쇼핑의 시작</p>
        </div>
        <div class="flex items-center space-x-4">
            <div class="text-sm text-gray-600">
                <i class="fas fa-user mr-1"></i>
                <?php echo htmlspecialchars($_SESSION['full_name']); ?>님 환영합니다
            </div>
            <?php if (has_permission('admin_access')): ?>
            <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-cog mr-2"></i>
                관리자 모드
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Search and Filter Section -->
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="search" class="block text-sm font-medium text-gray-700 mb-2">상품 검색</label>
            <input type="text" id="search" placeholder="상품명을 입력하세요" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500">
        </div>
        <div>
            <label for="category-filter" class="block text-sm font-medium text-gray-700 mb-2">카테고리</label>
            <select id="category-filter" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500">
                <option value="">전체 카테고리</option>
                <?php foreach ($categories as $category): ?>
                <option value="<?php echo htmlspecialchars($category['name']); ?>">
                    <?php echo htmlspecialchars($category['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="brand-filter" class="block text-sm font-medium text-gray-700 mb-2">브랜드</label>
            <select id="brand-filter" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500">
                <option value="">전체 브랜드</option>
                <?php foreach ($brands as $brand): ?>
                <option value="<?php echo htmlspecialchars($brand['name']); ?>">
                    <?php echo htmlspecialchars($brand['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end">
            <button id="reset-filters" class="w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-redo mr-2"></i>필터 초기화
            </button>
        </div>
    </div>
</div>

<?php if ($error_message): ?>
    <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-circle text-red-400"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        </div>
    </div>
<?php elseif (empty($products)): ?>
    <div class="text-center py-12">
        <div class="flex flex-col items-center">
            <i class="fas fa-shopping-cart text-6xl text-gray-300 mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">현재 판매 중인 상품이 없습니다</h3>
            <p class="text-gray-500">재고가 있는 상품이 등록되면 여기에 표시됩니다.</p>
        </div>
    </div>
<?php else: ?>
    <!-- Products Grid -->
    <div id="products-container" class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <?php foreach ($products as $product): ?>
        <div class="product-card bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden" 
             data-name="<?php echo htmlspecialchars(strtolower($product['name'])); ?>"
             data-category="<?php echo htmlspecialchars($product['category_name'] ?? ''); ?>"
             data-brand="<?php echo htmlspecialchars($product['brand_name'] ?? ''); ?>">
            
            <!-- Product Image -->
            <div class="aspect-w-1 aspect-h-1 w-full overflow-hidden bg-gray-200">
                <?php if ($product['image_url']): ?>
                    <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                         class="h-48 w-full object-cover object-center group-hover:opacity-75">
                <?php else: ?>
                    <div class="h-48 w-full flex items-center justify-center bg-gray-100">
                        <i class="fas fa-image text-gray-400 text-3xl"></i>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Product Info -->
            <div class="p-4">
                <div class="mb-2">
                    <?php if ($product['brand_name']): ?>
                    <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold">
                        <?php echo htmlspecialchars($product['brand_name']); ?>
                    </p>
                    <?php endif; ?>
                    <h3 class="text-sm font-medium text-gray-900 line-clamp-2">
                        <?php echo htmlspecialchars($product['name']); ?>
                    </h3>
                </div>
                
                <?php if ($product['description']): ?>
                <p class="text-xs text-gray-600 mb-3 line-clamp-2">
                    <?php echo htmlspecialchars($product['description']); ?>
                </p>
                <?php endif; ?>
                
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-lg font-semibold text-gray-900">
                            <?php echo number_format($product['selling_price']); ?>원
                        </p>
                        <p class="text-xs text-gray-500">
                            재고: <?php echo $product['total_stock']; ?>개
                        </p>
                    </div>
                    <div class="flex space-x-1">
                        <button class="product-detail-btn p-2 text-gray-400 hover:text-primary-600 transition-colors" 
                                data-product-id="<?php echo $product['id']; ?>"
                                title="상세 정보">
                            <i class="fas fa-info-circle"></i>
                        </button>
                        <button class="add-to-cart-btn p-2 text-gray-400 hover:text-green-600 transition-colors" 
                                data-product-id="<?php echo $product['id']; ?>"
                                title="장바구니에 추가">
                            <i class="fas fa-cart-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Load more button (for future pagination) -->
    <div class="mt-8 text-center">
        <p class="text-sm text-gray-500">
            총 <?php echo count($products); ?>개의 상품이 있습니다.
        </p>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search');
    const categoryFilter = document.getElementById('category-filter');
    const brandFilter = document.getElementById('brand-filter');
    const resetButton = document.getElementById('reset-filters');
    const productCards = document.querySelectorAll('.product-card');
    
    // 필터링 함수
    function filterProducts() {
        const searchTerm = searchInput.value.toLowerCase();
        const selectedCategory = categoryFilter.value;
        const selectedBrand = brandFilter.value;
        
        let visibleCount = 0;
        
        productCards.forEach(card => {
            const name = card.dataset.name;
            const category = card.dataset.category;
            const brand = card.dataset.brand;
            
            const matchesSearch = !searchTerm || name.includes(searchTerm);
            const matchesCategory = !selectedCategory || category === selectedCategory;
            const matchesBrand = !selectedBrand || brand === selectedBrand;
            
            if (matchesSearch && matchesCategory && matchesBrand) {
                card.style.display = 'block';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        // 결과가 없는 경우 메시지 표시 (추후 구현)
        console.log(`${visibleCount}개의 상품이 표시됩니다.`);
    }
    
    // 이벤트 리스너
    searchInput.addEventListener('input', filterProducts);
    categoryFilter.addEventListener('change', filterProducts);
    brandFilter.addEventListener('change', filterProducts);
    
    // 필터 초기화
    resetButton.addEventListener('click', function() {
        searchInput.value = '';
        categoryFilter.value = '';
        brandFilter.value = '';
        filterProducts();
    });
    
    // 상품 상세 정보 버튼
    document.querySelectorAll('.product-detail-btn').forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.dataset.productId;
            // 상품 상세 정보 모달 또는 페이지로 이동 (추후 구현)
            alert('상품 상세 정보 (상품 ID: ' + productId + ')');
        });
    });
    
    // 장바구니 추가 버튼
    document.querySelectorAll('.add-to-cart-btn').forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.dataset.productId;
            // 장바구니 추가 기능 (추후 구현)
            alert('장바구니에 추가되었습니다! (상품 ID: ' + productId + ')');
        });
    });
});
</script>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>