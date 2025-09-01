<?php
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

// 선택된 점포 (기본값: 첫 번째 활성 점포)
$selected_store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 1;

// 점포 목록 조회 (is_active 컬럼 없이)
$stores_query = "SELECT * FROM stores ORDER BY name ASC";
$stores_result = $conn->query($stores_query);
$stores = $stores_result->fetch_all(MYSQLI_ASSOC);

// 선택된 점포 정보 (is_active 조건 제거)
$store_stmt = $conn->prepare("SELECT * FROM stores WHERE id = ?");
$store_stmt->bind_param("i", $selected_store_id);
$store_stmt->execute();
$store_result = $store_stmt->get_result();
$current_store = $store_result->fetch_assoc();
$store_stmt->close();

if (!$current_store) {
    // 기본 점포로 리다이렉트
    $first_store_query = "SELECT id FROM stores ORDER BY id ASC LIMIT 1";
    $first_store_result = $conn->query($first_store_query);
    if ($first_store = $first_store_result->fetch_assoc()) {
        header("Location: index_hmart.php?store_id=" . $first_store['id']);
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link href="css/shop.css" rel="stylesheet">
</head>
<body>
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="container">
            <div class="flex justify-between py-2">
                <div class="flex text-xs">
                    <a href="../admin/login.php">관리자 로그인</a>
                    <a href="#customer-service">고객센터</a>
                    <a href="#stores">매장찾기</a>
                </div>
                <div class="text-xs">
                    <span>오늘 오전 10시까지 주문 시 당일배송!</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Header -->
    <header class="main-header">
        <div class="container">
            <div class="flex items-center justify-between py-4">
                <!-- Logo -->
                <a href="index_hmart.php" class="logo">
                    <i class="fas fa-shopping-basket" style="margin-right: 8px;"></i>
                    HOME K MART
                </a>

                <!-- Store Selector -->
                <div class="store-selector">
                    <div class="current-store" onclick="toggleStoreDropdown()">
                        <i class="fas fa-map-marker-alt"></i>
                        <div class="store-info">
                            <div class="store-label">현재 매장</div>
                            <div class="store-name"><?php echo htmlspecialchars($current_store['name']); ?></div>
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </div>
                    
                    <div class="store-dropdown" id="storeDropdown">
                        <div class="dropdown-header">
                            <h4>매장 선택</h4>
                            <button class="close-btn" onclick="toggleStoreDropdown()">×</button>
                        </div>
                        <div class="store-list">
                            <?php foreach ($stores as $store): ?>
                            <div class="store-item <?php echo $store['id'] == $selected_store_id ? 'selected' : ''; ?>" 
                                 onclick="selectStore(<?php echo $store['id']; ?>)">
                                <div class="store-item-info">
                                    <div class="store-item-name"><?php echo htmlspecialchars($store['name']); ?></div>
                                    <?php if ($store['address']): ?>
                                    <div class="store-item-address"><?php echo htmlspecialchars($store['address']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="store-item-status">
                                    <span class="status-badge active">영업중</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="search-bar">
                    <input type="text" id="searchInput" placeholder="찾으시는 상품을 검색해보세요">
                    <button class="search-btn" onclick="performSearch()">
                        <i class="fas fa-search"></i>
                    </button>
                </div>

                <!-- Header Actions -->
                <div class="header-actions">
                    <button class="cart-icon" onclick="openCart()">
                        <i class="fas fa-shopping-cart"></i>
                        <span class="cart-badge" id="cartBadge">0</span>
                    </button>
                    <button class="login-btn" onclick="location.href='../admin/login.php'">
                        로그인
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Navigation Menu -->
    <nav class="nav-menu">
        <div class="container">
            <ul>
                <li><a href="#" class="active">전체상품</a></li>
                <li><a href="#fresh">신선식품</a></li>
                <li><a href="#grocery">가공식품</a></li>
                <li><a href="#frozen">냉동식품</a></li>
                <li><a href="#daily">생활용품</a></li>
                <li><a href="#special">특가상품</a></li>
            </ul>
        </div>
    </nav>

    <!-- Banner Slider -->
    <div class="banner-slider">
        <div class="banner-slide active">
            <div class="banner-content">
                <h2>HOME K MART에 오신 것을 환영합니다!</h2>
                <p>신선한 한국 식품과 다양한 생활용품을 만나보세요</p>
                <button class="banner-btn" onclick="scrollToProducts()">지금 쇼핑하기</button>
            </div>
        </div>
    </div>

    <main>
        <?php if (empty($sections)): ?>
        <!-- No Sections Available -->
        <div class="container">
            <div class="section" style="text-align: center; padding: 80px 0;">
                <i class="fas fa-shopping-cart" style="font-size: 64px; color: #ddd; margin-bottom: 20px;"></i>
                <h2 style="color: var(--hmart-gray); margin-bottom: 16px;">준비 중입니다</h2>
                <p style="color: var(--hmart-gray);">관리자가 상품을 준비하고 있습니다. 잠시만 기다려주세요!</p>
                <div style="margin-top: 30px;">
                    <a href="../admin/display_sections.php" class="banner-btn">관리자 페이지로 이동</a>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Display Sections -->
        <?php foreach ($sections as $section): ?>
            <?php
            // 섹션별 상품 조회
            $products_query = "
                SELECT pd.*, p.name_ko as product_name, p.barcode, p.description as product_description,
                       p.image_url, b.name_ko as brand_name, c.name as category_name,
                       i.selling_price, i.cost_price
                FROM product_displays pd
                JOIN products p ON pd.product_id = p.id
                LEFT JOIN brands b ON p.brand_id = b.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
                WHERE pd.section_id = ? AND pd.store_id = ? AND pd.is_active = 1
                    AND (pd.start_date IS NULL OR pd.start_date <= CURDATE())
                    AND (pd.end_date IS NULL OR pd.end_date >= CURDATE())
                ORDER BY pd.display_order ASC, pd.created_at ASC
                " . ($section['max_products'] ? " LIMIT " . (int)$section['max_products'] : "");
            
            $products_stmt = $conn->prepare($products_query);
            $products_stmt->bind_param("iii", $selected_store_id, $section['id'], $selected_store_id);
            $products_stmt->execute();
            $products_result = $products_stmt->get_result();
            $products = $products_result->fetch_all(MYSQLI_ASSOC);
            $products_stmt->close();
            ?>
            
            <?php if (!empty($products)): ?>
            <div class="section" id="section-<?php echo $section['id']; ?>">
                <div class="container">
                    <h2 class="section-title"><?php echo htmlspecialchars($section['name']); ?></h2>
                    
                    <?php if ($section['description']): ?>
                    <p style="text-align: center; color: var(--hmart-gray); margin-bottom: 40px;">
                        <?php echo htmlspecialchars($section['description']); ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php 
                    // 레이아웃 유형에 따른 컨테이너 클래스 설정
                    $layout_class = 'product-grid'; // 기본값
                    $card_class = 'product-card';
                    
                    switch($section['layout_type']) {
                        case 'list':
                            $layout_class = 'product-list';
                            $card_class = 'product-card-list';
                            break;
                        case 'carousel':
                            $layout_class = 'product-carousel';
                            $card_class = 'product-card-carousel';
                            break;
                        case 'banner':
                            $layout_class = 'product-banner';
                            $card_class = 'product-card-banner';
                            break;
                        case 'grid':
                        default:
                            $layout_class = 'product-grid';
                            $card_class = 'product-card';
                            break;
                    }
                    ?>
                    
                    <div class="<?php echo $layout_class; ?>" <?php if($section['layout_type'] == 'carousel'): ?>id="carousel-<?php echo $section['id']; ?>"<?php endif; ?>>
                        <?php foreach ($products as $product): ?>
                        <div class="<?php echo $card_class; ?>" data-product-id="<?php echo $product['product_id']; ?>"<?php if($section['layout_type'] == 'banner' && $product['image_url']): ?> style="background-image: url('<?php echo htmlspecialchars($product['image_url']); ?>');"<?php endif; ?>>
                            
                            <?php if ($section['layout_type'] != 'banner'): ?>
                            <!-- 일반 레이아웃의 이미지 영역 -->
                            <div class="product-image">
                                <?php if ($product['image_url']): ?>
                                <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($product['product_name']); ?>"
                                     style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?>
                                <i class="fas fa-image"></i>
                                <?php endif; ?>
                                
                                <?php if ($product['badge_text']): ?>
                                <div class="discount-badge"><?php echo htmlspecialchars($product['badge_text']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="product-info">
                                <?php if ($product['category_name']): ?>
                                <div class="product-category"><?php echo htmlspecialchars($product['category_name']); ?></div>
                                <?php endif; ?>
                                
                                <div class="product-name">
                                    <?php echo htmlspecialchars($product['custom_title'] ?: $product['product_name']); ?>
                                </div>
                                
                                <?php if ($product['custom_description'] || $product['product_description']): ?>
                                <div class="product-description">
                                    <?php echo htmlspecialchars($product['custom_description'] ?: $product['product_description']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="product-price">
                                    <?php if ($section['layout_type'] == 'list'): ?>
                                    <!-- 목록형에서는 가격을 더 크게 표시 -->
                                    <div>
                                        <span class="price-current" style="font-size: 20px;">
                                            <?php echo number_format($product['selling_price'] ?? 0); ?>
                                        </span>
                                        <?php if ($product['brand_name']): ?>
                                        <div style="font-size: 13px; color: var(--hmart-gray); margin-top: 2px;">
                                            브랜드: <?php echo htmlspecialchars($product['brand_name']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                    <!-- 기본 가격 표시 -->
                                    <span class="price-current">
                                        <?php echo number_format($product['selling_price'] ?? 0); ?>
                                    </span>
                                    <?php if ($product['brand_name']): ?>
                                    <span style="font-size: 12px; color: var(--hmart-gray);">
                                        <?php echo htmlspecialchars($product['brand_name']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <button class="add-cart-btn" 
                                        onclick="addToCart(<?php echo $product['product_id']; ?>, '<?php echo htmlspecialchars($product['custom_title'] ?: $product['product_name']); ?>', <?php echo $product['selling_price'] ?? 0; ?>)">
                                    <i class="fas fa-cart-plus" style="margin-right: 8px;"></i>
                                    <?php echo $section['layout_type'] == 'carousel' ? '담기' : '장바구니 담기'; ?>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Special Features -->
        <div class="section feature-section">
            <div class="container">
                <h2 class="section-title">HOME K MART 특별 서비스</h2>
                <div class="feature-grid">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="feature-title">당일 배송</div>
                        <div class="feature-desc">오전 10시까지 주문하시면 당일 배송해드립니다</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-leaf"></i>
                        </div>
                        <div class="feature-title">신선 보장</div>
                        <div class="feature-desc">엄선된 신선 식품만을 취급합니다</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div class="feature-title">한국 전통</div>
                        <div class="feature-desc">정통 한국 식품과 생활용품을 만나보세요</div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-section">
                    <h3>HOME K MART</h3>
                    <ul>
                        <li><a href="#about">회사소개</a></li>
                        <li><a href="#careers">채용정보</a></li>
                        <li><a href="#press">보도자료</a></li>
                        <li><a href="#investor">투자정보</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>고객서비스</h3>
                    <ul>
                        <li><a href="#help">도움말</a></li>
                        <li><a href="#contact">문의하기</a></li>
                        <li><a href="#delivery">배송안내</a></li>
                        <li><a href="#return">교환/환불</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>매장정보</h3>
                    <ul>
                        <?php foreach ($stores as $store): ?>
                        <li><a href="?store_id=<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['name']); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>소셜미디어</h3>
                    <ul>
                        <li><a href="#facebook"><i class="fab fa-facebook"></i> Facebook</a></li>
                        <li><a href="#instagram"><i class="fab fa-instagram"></i> Instagram</a></li>
                        <li><a href="#youtube"><i class="fab fa-youtube"></i> YouTube</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2024 HOME K MART. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="js/shop.js"></script>
    <script>
        // Store selector functionality
        function toggleStoreDropdown() {
            const dropdown = document.getElementById('storeDropdown');
            const currentStore = document.querySelector('.current-store');
            
            dropdown.classList.toggle('open');
            currentStore.classList.toggle('open');
        }

        function selectStore(storeId) {
            window.location.href = 'index_hmart.php?store_id=' + storeId;
        }

        // Search functionality
        function performSearch() {
            const query = document.getElementById('searchInput').value;
            if (query.trim()) {
                alert('검색 기능: "' + query + '" (추후 구현)');
            }
        }

        // Cart functionality
        let cart = JSON.parse(localStorage.getItem('hmart_cart') || '[]');

        function updateCartBadge() {
            const badge = document.getElementById('cartBadge');
            const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
            badge.textContent = totalItems;
        }

        function addToCart(productId, productName, price) {
            const existingItem = cart.find(item => item.id === productId);
            
            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                cart.push({
                    id: productId,
                    name: productName,
                    price: price,
                    quantity: 1
                });
            }
            
            localStorage.setItem('hmart_cart', JSON.stringify(cart));
            updateCartBadge();
            
            // Show notification
            alert(productName + '이(가) 장바구니에 추가되었습니다!');
        }

        function openCart() {
            if (cart.length === 0) {
                alert('장바구니가 비어있습니다.');
                return;
            }
            
            // 주문 페이지로 이동
            window.location.href = 'order.php';
        }

        function scrollToProducts() {
            const firstSection = document.querySelector('.section:not(.feature-section)');
            if (firstSection) {
                firstSection.scrollIntoView({ behavior: 'smooth' });
            }
        }

        // 캐러셀 초기화 함수
        function initializeCarousels() {
            const carousels = document.querySelectorAll('.product-carousel');
            carousels.forEach(carousel => {
                if (carousel.children.length > 0) {
                    // 터치 이벤트를 위한 변수
                    let isDown = false;
                    let startX;
                    let scrollLeft;

                    // 마우스 이벤트
                    carousel.addEventListener('mousedown', (e) => {
                        isDown = true;
                        startX = e.pageX - carousel.offsetLeft;
                        scrollLeft = carousel.scrollLeft;
                        carousel.style.cursor = 'grabbing';
                    });

                    carousel.addEventListener('mouseleave', () => {
                        isDown = false;
                        carousel.style.cursor = 'grab';
                    });

                    carousel.addEventListener('mouseup', () => {
                        isDown = false;
                        carousel.style.cursor = 'grab';
                    });

                    carousel.addEventListener('mousemove', (e) => {
                        if (!isDown) return;
                        e.preventDefault();
                        const x = e.pageX - carousel.offsetLeft;
                        const walk = (x - startX) * 2;
                        carousel.scrollLeft = scrollLeft - walk;
                    });

                    // 터치 이벤트
                    carousel.addEventListener('touchstart', (e) => {
                        startX = e.touches[0].pageX - carousel.offsetLeft;
                        scrollLeft = carousel.scrollLeft;
                    });

                    carousel.addEventListener('touchmove', (e) => {
                        if (!startX) return;
                        const x = e.touches[0].pageX - carousel.offsetLeft;
                        const walk = (x - startX) * 2;
                        carousel.scrollLeft = scrollLeft - walk;
                    });

                    // 기본 커서 설정
                    carousel.style.cursor = 'grab';
                }
            });
        }

        // Initialize cart badge
        updateCartBadge();
        
        // Initialize carousels
        initializeCarousels();

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const storeSelector = document.querySelector('.store-selector');
            const dropdown = document.getElementById('storeDropdown');
            
            if (!storeSelector.contains(event.target)) {
                dropdown.classList.remove('open');
                document.querySelector('.current-store').classList.remove('open');
            }
        });
    </script>
</body>
</html>

<?php $conn->close(); ?>