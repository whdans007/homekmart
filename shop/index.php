<?php
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

// 선택된 점포 (기본값: 첫 번째 활성 점포)
$selected_store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 1;

// 점포 목록 조회
$stores_query = "SELECT * FROM stores WHERE is_active = 1 ORDER BY name ASC";
$stores_result = $conn->query($stores_query);

// 선택된 점포 정보
$store_stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND is_active = 1");
$store_stmt->bind_param("i", $selected_store_id);
$store_stmt->execute();
$store_result = $store_stmt->get_result();
$current_store = $store_result->fetch_assoc();
$store_stmt->close();

if (!$current_store) {
    // 기본 점포로 리다이렉트
    $first_store_query = "SELECT id FROM stores WHERE is_active = 1 ORDER BY id ASC LIMIT 1";
    $first_store_result = $conn->query($first_store_query);
    if ($first_store = $first_store_result->fetch_assoc()) {
        header("Location: index.php?store_id=" . $first_store['id']);
        exit;
    }
}

// 진열 섹션과 상품 조회
$sections_query = "
    SELECT ds.*, 
           COUNT(pd.id) as product_count
    FROM display_sections ds
    LEFT JOIN product_displays pd ON ds.id = pd.section_id 
        AND pd.store_id = ? 
        AND pd.is_active = 1
        AND (pd.start_date IS NULL OR pd.start_date <= CURDATE())
        AND (pd.end_date IS NULL OR pd.end_date >= CURDATE())
    WHERE ds.is_active = 1 AND ds.show_on_main = 1
    GROUP BY ds.id
    ORDER BY ds.display_order ASC
";
$sections_stmt = $conn->prepare($sections_query);
$sections_stmt->bind_param("i", $selected_store_id);
$sections_stmt->execute();
$sections_result = $sections_stmt->get_result();
$sections = $sections_result->fetch_all(MYSQLI_ASSOC);
$sections_stmt->close();
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($current_store['name'] ?? 'HOME K MART'); ?> - 온라인 쇼핑몰</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Google AdSense -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-2783446549667436"
     crossorigin="anonymous"></script>
    <style>
        .store-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
        }
        
        .section-title {
            position: relative;
            margin: 3rem 0 2rem 0;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f8f9fa;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 60px;
            height: 2px;
            background: #667eea;
        }
        
        .product-card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: 100%;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            cursor: pointer;
        }
        
        .product-image {
            height: 200px;
            object-fit: cover;
            background: #f8f9fa;
        }
        
        .product-image-placeholder {
            height: 200px;
            background: linear-gradient(45deg, #f8f9fa 25%, transparent 25%),
                        linear-gradient(-45deg, #f8f9fa 25%, transparent 25%),
                        linear-gradient(45deg, transparent 75%, #f8f9fa 75%),
                        linear-gradient(-45deg, transparent 75%, #f8f9fa 75%);
            background-size: 20px 20px;
            background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }
        
        .badge-custom {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }
        
        .price-tag {
            font-size: 1.25rem;
            font-weight: bold;
            color: #dc3545;
        }
        
        .store-selector {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem;
            margin: -30px auto 2rem auto;
            max-width: 400px;
            position: relative;
            z-index: 100;
        }
        
        .banner-carousel {
            height: 300px;
            overflow: hidden;
            border-radius: 10px;
        }
        
        .banner-item {
            height: 300px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-align: center;
        }
        
        .grid-layout {
            display: grid;
            gap: 1.5rem;
        }
        
        @media (min-width: 576px) {
            .grid-layout { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (min-width: 768px) {
            .grid-layout { grid-template-columns: repeat(3, 1fr); }
        }
        
        @media (min-width: 992px) {
            .grid-layout { grid-template-columns: repeat(4, 1fr); }
        }
    </style>
</head>
<body>
    <!-- 헤더 -->
    <div class="store-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-0">
                        <i class="fas fa-store me-3"></i><?php echo htmlspecialchars($current_store['name'] ?? 'HOME K MART'); ?>
                    </h1>
                    <p class="mb-0 mt-2">
                        <?php if ($current_store['address']): ?>
                            <i class="fas fa-map-marker-alt me-2"></i><?php echo htmlspecialchars($current_store['address']); ?>
                        <?php endif; ?>
                        <?php if ($current_store['phone']): ?>
                            <i class="fas fa-phone ms-3 me-2"></i><?php echo htmlspecialchars($current_store['phone']); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <button class="btn btn-light btn-lg" onclick="showCart()">
                        <i class="fas fa-shopping-cart me-2"></i>장바구니
                        <span class="badge bg-danger ms-2" id="cartCount">0</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 점포 선택기 -->
    <div class="container">
        <div class="store-selector">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <label class="form-label mb-2">
                        <i class="fas fa-map-marker-alt me-2"></i>점포 선택
                    </label>
                    <select class="form-select" onchange="changeStore(this.value)">
                        <?php if ($stores_result): ?>
                            <?php $stores_result->data_seek(0); ?>
                            <?php while ($store = $stores_result->fetch_assoc()): ?>
                                <option value="<?php echo $store['id']; ?>" <?php echo $store['id'] == $selected_store_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['name']); ?>
                                    <?php if ($store['address']): ?> - <?php echo htmlspecialchars($store['address']); ?><?php endif; ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-4 text-md-end">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        점포별 가격이 다를 수 있습니다
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- 메인 콘텐츠 -->
    <div class="container">
        <?php if (!empty($sections)): ?>
            <?php foreach ($sections as $section): ?>
                <?php if ($section['product_count'] > 0): ?>
                    <?php
                    // 섹션별 상품 조회
                    $products_query = "
                        SELECT pd.*, p.name as product_name, p.barcode, p.description as product_description,
                               b.name as brand_name, c.name as category_name,
                               i.selling_price, i.cost_price
                        FROM product_displays pd
                        JOIN products p ON pd.product_id = p.id
                        LEFT JOIN brands b ON p.brand_id = b.id
                        LEFT JOIN categories c ON p.category_id = c.id
                        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
                        WHERE pd.section_id = ? AND pd.store_id = ? AND pd.is_active = 1
                              AND (pd.start_date IS NULL OR pd.start_date <= CURDATE())
                              AND (pd.end_date IS NULL OR pd.end_date >= CURDATE())
                        ORDER BY pd.display_order ASC
                        " . ($section['max_products'] ? "LIMIT " . $section['max_products'] : "");
                    
                    $products_stmt = $conn->prepare($products_query);
                    $products_stmt->bind_param("iii", $selected_store_id, $section['id'], $selected_store_id);
                    $products_stmt->execute();
                    $products_result = $products_stmt->get_result();
                    $products = $products_result->fetch_all(MYSQLI_ASSOC);
                    $products_stmt->close();
                    ?>
                    
                    <section class="mb-5">
                        <h2 class="section-title">
                            <?php echo htmlspecialchars($section['name']); ?>
                            <small class="text-muted ms-2"><?php echo count($products); ?>개 상품</small>
                        </h2>
                        
                        <div class="grid-layout <?php echo $section['custom_css_class']; ?>">
                            <?php foreach ($products as $product): ?>
                                <div class="card product-card" onclick="viewProduct(<?php echo $product['product_id']; ?>)">
                                    <?php if ($product['badge_text']): ?>
                                        <span class="badge bg-<?php echo $product['badge_color'] ?: 'primary'; ?> badge-custom">
                                            <?php echo htmlspecialchars($product['badge_text']); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['custom_image_url']): ?>
                                        <img src="<?php echo htmlspecialchars($product['custom_image_url']); ?>" 
                                             class="card-img-top product-image" alt="상품 이미지">
                                    <?php else: ?>
                                        <div class="product-image-placeholder">
                                            <i class="fas fa-image fa-3x"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="card-body">
                                        <h6 class="card-title mb-2">
                                            <?php echo $product['custom_title'] ?: htmlspecialchars($product['product_name']); ?>
                                        </h6>
                                        
                                        <p class="text-muted small mb-2">
                                            <?php echo htmlspecialchars($product['brand_name'] ?? ''); ?>
                                        </p>
                                        
                                        <?php if ($product['custom_description']): ?>
                                            <p class="card-text small text-primary mb-2">
                                                <?php echo htmlspecialchars($product['custom_description']); ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if ($product['selling_price']): ?>
                                            <div class="price-tag">
                                                <?php echo number_format($product['selling_price'], 0); ?>원
                                            </div>
                                        <?php else: ?>
                                            <div class="text-muted">
                                                가격 문의
                                            </div>
                                        <?php endif; ?>
                                        
                                        <button class="btn btn-primary btn-sm w-100 mt-2" onclick="addToCart(<?php echo $product['product_id']; ?>, event)">
                                            <i class="fas fa-cart-plus me-2"></i>장바구니 담기
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (count($products) >= ($section['max_products'] ?? 999)): ?>
                            <div class="text-center mt-4">
                                <a href="category.php?section_id=<?php echo $section['id']; ?>&store_id=<?php echo $selected_store_id; ?>" class="btn btn-outline-primary">
                                    더 많은 상품 보기 <i class="fas fa-arrow-right ms-2"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- 진열된 상품이 없을 때 -->
            <div class="text-center py-5">
                <i class="fas fa-shopping-cart fa-4x text-muted mb-4"></i>
                <h3 class="text-muted">준비 중입니다</h3>
                <p class="text-muted">곧 다양한 상품들과 만나실 수 있습니다.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- 푸터 -->
    <footer class="bg-dark text-white mt-5 py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5>HOME K MART</h5>
                    <p class="mb-0">신선하고 품질 좋은 상품을 합리적인 가격에 제공합니다.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">
                        <i class="fas fa-phone me-2"></i>고객센터: 1588-0000<br>
                        <small class="text-muted">평일 09:00-18:00, 주말 휴무</small>
                    </p>
                </div>
            </div>
            <hr class="my-3">
            <div class="text-center">
                <small class="text-muted">&copy; 2025 HOME K MART. All rights reserved.</small>
            </div>
        </div>
    </footer>

    <!-- 장바구니 모달 -->
    <div class="modal fade" id="cartModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">장바구니</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="cartItems">
                        <div class="text-center py-4">
                            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                            <p class="text-muted">장바구니가 비어있습니다.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="w-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <strong>총 금액: <span id="totalAmount">0</span>원</strong>
                        </div>
                        <button type="button" class="btn btn-primary w-100" onclick="checkout()">
                            주문하기 <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 장바구니 데이터 (로컬 스토리지 사용)
        let cart = JSON.parse(localStorage.getItem('cart') || '[]');
        
        function updateCartCount() {
            document.getElementById('cartCount').textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
        }
        
        function changeStore(storeId) {
            window.location.href = 'index.php?store_id=' + storeId;
        }
        
        function viewProduct(productId) {
            window.location.href = 'product.php?id=' + productId + '&store_id=<?php echo $selected_store_id; ?>';
        }
        
        function addToCart(productId, event) {
            event.stopPropagation();
            
            // 상품 정보 찾기 (현재 페이지에서)
            const productCard = event.target.closest('.product-card');
            const productName = productCard.querySelector('.card-title').textContent;
            const priceElement = productCard.querySelector('.price-tag');
            const price = priceElement ? parseFloat(priceElement.textContent.replace(/[^0-9]/g, '')) : 0;
            
            const existingItem = cart.find(item => item.productId === productId);
            
            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                cart.push({
                    productId: productId,
                    name: productName,
                    price: price,
                    quantity: 1,
                    storeId: <?php echo $selected_store_id; ?>
                });
            }
            
            localStorage.setItem('cart', JSON.stringify(cart));
            updateCartCount();
            
            // 성공 메시지
            const toast = document.createElement('div');
            toast.className = 'toast show position-fixed top-0 end-0 m-3';
            toast.style.zIndex = '9999';
            toast.innerHTML = `
                <div class="toast-body bg-success text-white">
                    <i class="fas fa-check me-2"></i>장바구니에 추가되었습니다.
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        function showCart() {
            updateCartDisplay();
            new bootstrap.Modal(document.getElementById('cartModal')).show();
        }
        
        function updateCartDisplay() {
            const cartItems = document.getElementById('cartItems');
            const totalAmount = document.getElementById('totalAmount');
            
            if (cart.length === 0) {
                cartItems.innerHTML = `
                    <div class="text-center py-4">
                        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                        <p class="text-muted">장바구니가 비어있습니다.</p>
                    </div>
                `;
                totalAmount.textContent = '0';
                return;
            }
            
            let html = '';
            let total = 0;
            
            cart.forEach((item, index) => {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                
                html += `
                    <div class="d-flex justify-content-between align-items-center border-bottom py-3">
                        <div class="flex-grow-1">
                            <h6 class="mb-1">${item.name}</h6>
                            <small class="text-muted">${item.price.toLocaleString()}원</small>
                        </div>
                        <div class="d-flex align-items-center">
                            <button class="btn btn-sm btn-outline-secondary" onclick="updateQuantity(${index}, -1)">-</button>
                            <span class="mx-3">${item.quantity}</span>
                            <button class="btn btn-sm btn-outline-secondary" onclick="updateQuantity(${index}, 1)">+</button>
                            <button class="btn btn-sm btn-outline-danger ms-3" onclick="removeFromCart(${index})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        <div class="text-end ms-3">
                            <strong>${itemTotal.toLocaleString()}원</strong>
                        </div>
                    </div>
                `;
            });
            
            cartItems.innerHTML = html;
            totalAmount.textContent = total.toLocaleString();
        }
        
        function updateQuantity(index, change) {
            cart[index].quantity += change;
            if (cart[index].quantity <= 0) {
                cart.splice(index, 1);
            }
            localStorage.setItem('cart', JSON.stringify(cart));
            updateCartCount();
            updateCartDisplay();
        }
        
        function removeFromCart(index) {
            cart.splice(index, 1);
            localStorage.setItem('cart', JSON.stringify(cart));
            updateCartCount();
            updateCartDisplay();
        }
        
        function checkout() {
            if (cart.length === 0) {
                alert('장바구니가 비어있습니다.');
                return;
            }
            
            window.location.href = 'order.php?store_id=<?php echo $selected_store_id; ?>';
        }
        
        // 페이지 로드 시 장바구니 개수 업데이트
        updateCartCount();
    </script>
</body>
</html>

<?php
$conn->close();
?>