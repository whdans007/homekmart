<?php
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

// 선택된 점포 (기본값: 첫 번째 활성 점포)
$selected_store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 1;

// 점포 목록 조회
$stores_query = "SELECT * FROM stores ORDER BY name ASC";
$stores_result = $conn->query($stores_query);
$stores = $stores_result->fetch_all(MYSQLI_ASSOC);

// 선택된 점포 정보
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
        header("Location: index_dynamic.php?store_id=" . $first_store['id']);
        exit;
    }
}

// ===================================================================
// 동적 레이아웃 시스템
// ===================================================================

/**
 * 동적 레이아웃 구조 조회
 */
function getDynamicLayout($conn, $store_id) {
    $layout = ['rows' => []];
    
    // 활성화된 레이아웃 행들을 순서대로 조회
    $rows_query = "SELECT * FROM layout_rows WHERE is_active = 1 ORDER BY row_order ASC";
    $rows_result = $conn->query($rows_query);
    
    if ($rows_result && $rows_result->num_rows > 0) {
        while ($row = $rows_result->fetch_assoc()) {
            // 각 행의 컬럼과 할당된 섹션 조회
            $columns_query = "
                SELECT lc.*, ds.id as section_id, ds.name as section_name, ds.section_type, 
                       ds.layout_type, ds.is_active as section_active
                FROM layout_columns lc 
                LEFT JOIN display_sections ds ON lc.section_id = ds.id 
                WHERE lc.row_id = ? AND lc.is_active = 1 
                ORDER BY lc.column_order ASC
            ";
            $columns_stmt = $conn->prepare($columns_query);
            $columns_stmt->bind_param("i", $row['id']);
            $columns_stmt->execute();
            $columns_result = $columns_stmt->get_result();
            
            $columns = [];
            while ($column = $columns_result->fetch_assoc()) {
                // 컬럼에 할당된 섹션이 있고 활성화된 경우, 상품 데이터 조회
                if ($column['section_id'] && $column['section_active']) {
                    $column['products'] = getSectionProducts($conn, $column['section_id'], $store_id);
                }
                $columns[] = $column;
            }
            $columns_stmt->close();
            
            $row['columns'] = $columns;
            $layout['rows'][] = $row;
        }
    }
    
    return $layout;
}

/**
 * 섹션별 상품 조회
 */
function getSectionProducts($conn, $section_id, $store_id) {
    $products_query = "
        SELECT p.id, p.name_ko, p.name_en, p.description, p.category_id, p.barcode,
               p.image_url, p.brand, p.unit, p.origin_country,
               i.selling_price, i.cost_price, i.quantity,
               pd.display_order, pd.badge_text, pd.badge_color,
               c.name as category_name
        FROM product_displays pd
        INNER JOIN products p ON pd.product_id = p.id
        INNER JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE pd.section_id = ? 
          AND pd.store_id = ?
          AND pd.is_active = 1
          AND (pd.start_date IS NULL OR pd.start_date <= CURDATE())
          AND (pd.end_date IS NULL OR pd.end_date >= CURDATE())
        ORDER BY pd.display_order ASC, p.name_ko ASC
    ";
    
    $products_stmt = $conn->prepare($products_query);
    $products_stmt->bind_param("iii", $store_id, $section_id, $store_id);
    $products_stmt->execute();
    $products_result = $products_stmt->get_result();
    
    $products = [];
    while ($product = $products_result->fetch_assoc()) {
        $products[] = $product;
    }
    $products_stmt->close();
    
    return $products;
}

/**
 * 섹션 렌더링
 */
function renderSection($column, $products) {
    if (!$column['section_id'] || empty($products)) {
        return '<div class="empty-section"><p class="text-muted text-center py-4">표시할 상품이 없습니다.</p></div>';
    }
    
    $section_name = htmlspecialchars($column['section_name']);
    $layout_type = $column['layout_type'] ?? 'grid';
    
    // 섹션 유형별 아이콘과 색상
    $type_config = [
        'banner' => ['icon' => 'fa-image', 'color' => 'var(--hmart-red)'],
        'featured' => ['icon' => 'fa-star', 'color' => '#FF6B35'],
        'new_products' => ['icon' => 'fa-certificate', 'color' => '#28a745'],
        'category' => ['icon' => 'fa-folder', 'color' => '#6f42c1'],
        'custom' => ['icon' => 'fa-cog', 'color' => '#6c757d']
    ];
    
    $config = $type_config[$column['section_type']] ?? $type_config['custom'];
    
    $html = '<div class="product-section">';
    
    // 섹션 헤더
    $html .= '<div class="section-header">';
    $html .= '<h3 class="section-title">';
    $html .= '<i class="fas ' . $config['icon'] . ' me-2" style="color: ' . $config['color'] . ';"></i>';
    $html .= $section_name;
    $html .= '<span class="product-count">(' . count($products) . '개)</span>';
    $html .= '</h3>';
    $html .= '</div>';
    
    // 레이아웃 유형별 상품 렌더링
    switch ($layout_type) {
        case 'list':
            $html .= renderListLayout($products);
            break;
        case 'carousel':
            $html .= renderCarouselLayout($products, $column['section_id']);
            break;
        case 'banner':
            $html .= renderBannerLayout($products);
            break;
        case 'grid':
        default:
            $html .= renderGridLayout($products);
            break;
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * 격자형 레이아웃 렌더링
 */
function renderGridLayout($products) {
    $html = '<div class="product-grid row g-3">';
    
    foreach ($products as $product) {
        $html .= '<div class="col-lg-3 col-md-4 col-sm-6">';
        $html .= renderProductCard($product, 'grid');
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * 목록형 레이아웃 렌더링
 */
function renderListLayout($products) {
    $html = '<div class="product-list">';
    
    foreach ($products as $product) {
        $html .= '<div class="product-list-item mb-3">';
        $html .= renderProductCard($product, 'list');
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * 캐러셀 레이아웃 렌더링
 */
function renderCarouselLayout($products, $section_id) {
    $carousel_id = 'carousel-' . $section_id;
    
    $html = '<div class="product-carousel-container position-relative">';
    $html .= '<div id="' . $carousel_id . '" class="carousel slide" data-bs-ride="carousel">';
    $html .= '<div class="carousel-inner">';
    
    $chunks = array_chunk($products, 4); // 4개씩 그룹화
    $isFirst = true;
    
    foreach ($chunks as $chunk) {
        $html .= '<div class="carousel-item ' . ($isFirst ? 'active' : '') . '">';
        $html .= '<div class="row g-3">';
        
        foreach ($chunk as $product) {
            $html .= '<div class="col-lg-3 col-md-6">';
            $html .= renderProductCard($product, 'carousel');
            $html .= '</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        $isFirst = false;
    }
    
    $html .= '</div>';
    
    // 캐러셀 컨트롤
    if (count($chunks) > 1) {
        $html .= '<button class="carousel-control-prev" type="button" data-bs-target="#' . $carousel_id . '" data-bs-slide="prev">';
        $html .= '<span class="carousel-control-prev-icon"></span>';
        $html .= '</button>';
        $html .= '<button class="carousel-control-next" type="button" data-bs-target="#' . $carousel_id . '" data-bs-slide="next">';
        $html .= '<span class="carousel-control-next-icon"></span>';
        $html .= '</button>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

/**
 * 배너형 레이아웃 렌더링
 */
function renderBannerLayout($products) {
    $html = '<div class="product-banner-container">';
    
    foreach ($products as $index => $product) {
        if ($index >= 3) break; // 최대 3개만 표시
        
        $html .= '<div class="product-banner-item">';
        $html .= renderProductCard($product, 'banner');
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * 상품 카드 렌더링
 */
function renderProductCard($product, $layout = 'grid') {
    $product_name = htmlspecialchars($product['name_ko']);
    $price = number_format($product['selling_price'], 0);
    $image_url = $product['image_url'] ?: 'https://via.placeholder.com/200x200?text=' . urlencode($product_name);
    
    $card_class = 'product-card product-card-' . $layout;
    
    $html = '<div class="' . $card_class . '" data-product-id="' . $product['id'] . '">';
    
    // 상품 이미지
    $html .= '<div class="product-image">';
    if ($product['badge_text']) {
        $badge_color = $product['badge_color'] ?: 'red';
        $html .= '<span class="product-badge badge-' . $badge_color . '">' . htmlspecialchars($product['badge_text']) . '</span>';
    }
    $html .= '<img src="' . htmlspecialchars($image_url) . '" alt="' . $product_name . '" loading="lazy">';
    $html .= '</div>';
    
    // 상품 정보
    $html .= '<div class="product-info">';
    $html .= '<h4 class="product-name">' . $product_name . '</h4>';
    
    if ($product['name_en']) {
        $html .= '<p class="product-name-en">' . htmlspecialchars($product['name_en']) . '</p>';
    }
    
    $html .= '<div class="product-price">' . $price . '</div>';
    
    if ($product['brand']) {
        $html .= '<div class="product-brand">' . htmlspecialchars($product['brand']) . '</div>';
    }
    
    // 장바구니 버튼
    $html .= '<button class="add-to-cart-btn" onclick="addToCart(' . $product['id'] . ')">';
    $html .= '<i class="fas fa-cart-plus me-2"></i>장바구니';
    $html .= '</button>';
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

// 동적 레이아웃 데이터 조회
$dynamic_layout = getDynamicLayout($conn, $selected_store_id);
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($current_store['name'] ?? 'HOME K MART'); ?> - 온라인 쇼핑몰</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link href="css/shop.css" rel="stylesheet">
</head>
<body>
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="container">
            <div class="d-flex justify-content-between py-2">
                <div class="d-flex">
                    <a href="../admin/login.php" class="text-decoration-none text-white me-3">관리자 로그인</a>
                    <a href="#customer-service" class="text-decoration-none text-white me-3">고객센터</a>
                    <a href="#stores" class="text-decoration-none text-white">매장찾기</a>
                </div>
                <div class="text-white">
                    <span>오늘 오전 10시까지 주문 시 당일배송!</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Header -->
    <header class="main-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between py-4">
                <!-- Logo -->
                <a href="index_dynamic.php" class="logo text-decoration-none">
                    <i class="fas fa-shopping-basket me-2"></i>
                    <span class="logo-text">HOME K MART</span>
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
                    <button class="cart-icon position-relative" onclick="openCart()">
                        <i class="fas fa-shopping-cart"></i>
                        <span class="cart-badge position-absolute" id="cartBadge">0</span>
                    </button>
                    <button class="login-btn" onclick="location.href='../admin/login.php'">
                        로그인
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content: 동적 레이아웃 시스템 -->
    <main class="main-content">
        <div class="container">
            <?php if (!empty($dynamic_layout['rows'])): ?>
                <?php foreach ($dynamic_layout['rows'] as $row): ?>
                    <div class="layout-row mb-4" 
                         style="margin-top: <?php echo $row['margin_top']; ?>px; margin-bottom: <?php echo $row['margin_bottom']; ?>px;
                                <?php if ($row['background_color']): ?>background-color: <?php echo $row['background_color']; ?>;<?php endif; ?>">
                        
                        <?php if (!empty($row['columns'])): ?>
                            <div class="row g-4">
                                <?php foreach ($row['columns'] as $column): ?>
                                    <div class="col-lg-<?php echo $column['column_width']; ?> layout-column"
                                         style="<?php if ($column['background_color']): ?>background-color: <?php echo $column['background_color']; ?>;<?php endif; ?>
                                                padding: <?php echo $column['padding_y']; ?>px <?php echo $column['padding_x']; ?>px;
                                                <?php if ($column['border_radius']): ?>border-radius: <?php echo $column['border_radius']; ?>px;<?php endif; ?>
                                                <?php if ($column['min_height']): ?>min-height: <?php echo $column['min_height']; ?>px;<?php endif; ?>">
                                        
                                        <?php if ($column['section_id']): ?>
                                            <?php echo renderSection($column, $column['products']); ?>
                                        <?php else: ?>
                                            <div class="empty-column text-center py-4">
                                                <i class="fas fa-cube fa-3x text-muted mb-3" style="opacity: 0.3;"></i>
                                                <p class="text-muted">섹션이 배치되지 않았습니다.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- 레이아웃이 없는 경우 폴백 -->
                <div class="text-center py-5">
                    <i class="fas fa-th-large fa-4x text-muted mb-4" style="opacity: 0.3;"></i>
                    <h3 class="text-muted mb-3">레이아웃이 설정되지 않았습니다</h3>
                    <p class="text-muted mb-4">관리자에게 문의하여 메인 페이지 레이아웃을 설정해주세요.</p>
                    <a href="../admin/layout_builder.php" class="btn btn-primary">
                        <i class="fas fa-cogs me-2"></i>레이아웃 설정 (관리자)
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Cart Sidebar -->
    <div class="cart-sidebar" id="cartSidebar">
        <div class="cart-header">
            <h3>장바구니</h3>
            <button class="close-cart" onclick="closeCart()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="cart-content" id="cartContent">
            <div class="empty-cart">
                <i class="fas fa-shopping-cart fa-3x mb-3"></i>
                <p>장바구니가 비어있습니다.</p>
            </div>
        </div>
        <div class="cart-footer">
            <div class="cart-total">
                총 <span id="cartTotal">0</span>
            </div>
            <button class="checkout-btn" onclick="checkout()">
                <i class="fas fa-credit-card me-2"></i>주문하기
            </button>
        </div>
    </div>

    <!-- Backdrop -->
    <div class="backdrop" id="backdrop" onclick="closeCart()"></div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- 기존 JavaScript 함수들 포함 -->
    <script src="js/app.js"></script>
    
    <script>
    // 동적 레이아웃 전용 추가 스크립트
    document.addEventListener('DOMContentLoaded', function() {
        // 캐러셀 초기화
        const carousels = document.querySelectorAll('.carousel');
        carousels.forEach(carousel => {
            new bootstrap.Carousel(carousel, {
                interval: 5000,
                wrap: true
            });
        });
        
        // 이미지 레이지 로딩
        const images = document.querySelectorAll('img[loading="lazy"]');
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.src;
                    img.classList.remove('lazy');
                    observer.unobserve(img);
                }
            });
        });
        
        images.forEach(img => imageObserver.observe(img));
    });
    
    console.log('동적 레이아웃 시스템 로드 완료');
    </script>
</body>
</html>

<?php
$conn->close();
?>