<?php
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

// 선택된 점포 (기본값: 첫 번째 활성 점포)
$selected_store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 1;

// 디버그 모드 (URL에 ?debug=1 추가 시 활성화)
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

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
        header("Location: index_hmart.php?store_id=" . $first_store['id']);
        exit;
    }
}

// ===================================================================
// 동적 레이아웃 시스템
// ===================================================================

/**
 * 기본 레이아웃 구조 반환 (레이아웃 테이블이 없을 때)
 */
function getDefaultLayout($conn, $store_id) {
    $layout = ['rows' => []];
    
    try {
        // 기본 섹션들을 조회하여 간단한 레이아웃 구성
        $sections_query = "SELECT * FROM display_sections WHERE is_active = 1 ORDER BY display_order ASC LIMIT 6";
        $sections_result = $conn->query($sections_query);
        
        if ($sections_result && $sections_result->num_rows > 0) {
            $sections = $sections_result->fetch_all(MYSQLI_ASSOC);
            
            // 2개씩 그룹핑하여 행 생성
            $row_count = 1;
            for ($i = 0; $i < count($sections); $i += 2) {
                $row = [
                    'id' => 'default_' . $row_count,
                    'row_name' => '기본 레이아웃 행 ' . $row_count,
                    'row_order' => $row_count,
                    'margin_top' => 0,
                    'margin_bottom' => 20,
                    'columns' => []
                ];
                
                // 첫 번째 섹션
                if (isset($sections[$i])) {
                    $column1 = [
                        'id' => 'default_col_' . $i,
                        'column_order' => 1,
                        'column_width' => 6, // Bootstrap col-lg-6
                        'section_id' => $sections[$i]['id'],
                        'section_name' => $sections[$i]['name'],
                        'section_type' => isset($sections[$i]['section_type']) ? $sections[$i]['section_type'] : 'grid',
                        'layout_type' => isset($sections[$i]['layout_type']) ? $sections[$i]['layout_type'] : 'grid',
                        'items_per_slide' => isset($sections[$i]['items_per_slide']) ? (int)$sections[$i]['items_per_slide'] : 4
                    ];
                    
                    // 섹션 상품 데이터 추가
                    $column1['products'] = getBasicSectionProducts($conn, $sections[$i]['id'], $store_id);
                    $row['columns'][] = $column1;
                }
                
                // 두 번째 섹션 (있으면)
                if (isset($sections[$i + 1])) {
                    $column2 = [
                        'id' => 'default_col_' . ($i + 1),
                        'column_order' => 2,
                        'column_width' => 6, // Bootstrap col-lg-6
                        'section_id' => $sections[$i + 1]['id'],
                        'section_name' => $sections[$i + 1]['name'],
                        'section_type' => isset($sections[$i + 1]['section_type']) ? $sections[$i + 1]['section_type'] : 'grid',
                        'layout_type' => isset($sections[$i + 1]['layout_type']) ? $sections[$i + 1]['layout_type'] : 'grid',
                        'items_per_slide' => isset($sections[$i + 1]['items_per_slide']) ? (int)$sections[$i + 1]['items_per_slide'] : 4
                    ];
                    
                    // 섹션 상품 데이터 추가
                    $column2['products'] = getBasicSectionProducts($conn, $sections[$i + 1]['id'], $store_id);
                    $row['columns'][] = $column2;
                } else {
                    // 섹션이 하나만 있으면 전체 너비 사용
                    $row['columns'][0]['column_width'] = 12;
                }
                
                $layout['rows'][] = $row;
                $row_count++;
            }
        }
    } catch (Exception $e) {
        // 오류 발생시 빈 레이아웃 반환
        error_log("Default layout error: " . $e->getMessage());
    }
    
    return $layout;
}

/**
 * 기본 섹션 상품 조회 (간단한 버전)
 */
function getBasicSectionProducts($conn, $section_id, $store_id) {
    $products = [];
    
    try {
        $products_query = "
            SELECT p.id, p.name_ko, p.name_en, p.description, p.barcode,
                   p.image_url, p.brand, p.unit,
                   i.selling_price, i.cost_price, i.quantity,
                   c.name as category_name
            FROM product_displays pd
            INNER JOIN products p ON pd.product_id = p.id
            INNER JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE pd.section_id = ? 
            ORDER BY pd.display_order ASC, p.name_ko ASC
            LIMIT 12
        ";
        
        $stmt = $conn->prepare($products_query);
        $stmt->bind_param("ii", $store_id, $section_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result) {
            $products = $result->fetch_all(MYSQLI_ASSOC);
        }
        
        $stmt->close();
    } catch (Exception $e) {
        error_log("Basic section products error: " . $e->getMessage());
    }
    
    return $products;
}

/**
 * 동적 레이아웃 구조 조회
 */
function getDynamicLayout($conn, $store_id) {
    $layout = ['rows' => []];
    
    // 모든 레이아웃 테이블 존재 여부 확인
    $tables_to_check = ['layout_rows', 'layout_columns', 'layout_presets'];
    foreach ($tables_to_check as $table) {
        try {
            $table_check = $conn->query("SHOW TABLES LIKE '$table'");
            if (!$table_check || $table_check->num_rows === 0) {
                // 필수 테이블이 없으면 기본 레이아웃 반환
                return getDefaultLayout($conn, $store_id);
            }
        } catch (Exception $e) {
            // 테이블 확인 중 오류 발생시 기본 레이아웃 사용
            return getDefaultLayout($conn, $store_id);
        }
    }
    
    try {
        // 활성화된 레이아웃 행들을 순서대로 조회
        $rows_query = "SELECT * FROM layout_rows WHERE is_active = 1 ORDER BY row_order ASC";
        $rows_result = $conn->query($rows_query);
    } catch (Exception $e) {
        // 쿼리 오류시 기본 레이아웃 반환
        return getDefaultLayout($conn, $store_id);
    }
    
    if ($rows_result && $rows_result->num_rows > 0) {
        while ($row = $rows_result->fetch_assoc()) {
            try {
                // 각 행의 컬럼과 할당된 섹션 조회
                $columns_query = "
                    SELECT lc.*, ds.id as section_id, ds.name as section_name, ds.section_type, 
                           ds.layout_type, ds.items_per_slide, ds.is_active as section_active
                    FROM layout_columns lc 
                    LEFT JOIN display_sections ds ON lc.section_id = ds.id 
                    WHERE lc.row_id = ? AND lc.is_active = 1 
                    ORDER BY lc.column_order ASC
                ";
                $columns_stmt = $conn->prepare($columns_query);
                
                if (!$columns_stmt) {
                    error_log("getDynamicLayout: Failed to prepare columns query for row " . $row['id']);
                    continue; // 이 행을 건너뛰고 다음 행으로
                }
                
                $columns_stmt->bind_param("i", $row['id']);
                
                if (!$columns_stmt->execute()) {
                    error_log("getDynamicLayout: Failed to execute columns query for row " . $row['id']);
                    $columns_stmt->close();
                    continue;
                }
                
                $columns_result = $columns_stmt->get_result();
                $columns = [];
                
                while ($column = $columns_result->fetch_assoc()) {
                    // 컬럼에 할당된 섹션이 있고 활성화된 경우, 상품 데이터 조회
                    if ($column['section_id'] && $column['section_active']) {
                        $column['products'] = getSectionProducts($conn, $column['section_id'], $store_id);
                    } else {
                        // 섹션이 없거나 비활성화된 경우 빈 배열
                        $column['products'] = [];
                    }
                    $columns[] = $column;
                }
                $columns_stmt->close();
                
                $row['columns'] = $columns;
                $layout['rows'][] = $row;
                
            } catch (Exception $e) {
                error_log("getDynamicLayout row processing error: " . $e->getMessage() . " (row_id=" . $row['id'] . ")");
                // 이 행에서 오류가 발생해도 다음 행을 계속 처리
                continue;
            }
        }
    }
    
    // 레이아웃이 비어있으면 기본 레이아웃 반환
    if (empty($layout['rows'])) {
        error_log("getDynamicLayout: No valid rows found, falling back to default layout");
        return getDefaultLayout($conn, $store_id);
    }
    
    return $layout;
}

/**
 * 섹션별 상품 조회
 */
function getSectionProducts($conn, $section_id, $store_id) {
    $products = [];
    
    try {
        // 실제 상품 정보 조회 - 완전한 JOIN 쿼리
        $products_query = "
            SELECT p.id, p.name_ko, p.name_en, p.image_url, p.description,
                   b.name_ko as brand_name, c.name as category_name,
                   i.selling_price, i.cost_price, i.quantity,
                   pd.display_order, pd.badge_text, pd.badge_color
            FROM product_displays pd
            INNER JOIN products p ON pd.product_id = p.id
            LEFT JOIN brands b ON p.brand_id = b.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
            WHERE pd.section_id = ? 
              AND pd.is_active = 1
            ORDER BY pd.display_order ASC, p.name_ko ASC
            LIMIT 20
        ";
        
        $products_stmt = $conn->prepare($products_query);
        if (!$products_stmt) {
            throw new Exception("Query preparation failed: " . $conn->error);
        }
        
        $products_stmt->bind_param("ii", $store_id, $section_id);
        
        if (!$products_stmt->execute()) {
            throw new Exception("Query execution failed: " . $products_stmt->error);
        }
        
        $products_result = $products_stmt->get_result();
        if (!$products_result) {
            throw new Exception("Failed to get result: " . $products_stmt->error);
        }
        
        while ($product = $products_result->fetch_assoc()) {
            // 이미지 URL 처리
            $image_url = '';
            if ($product['image_url'] && !empty($product['image_url'])) {
                // 외부 URL인지 확인 (http로 시작하면 외부 URL)
                if (strpos($product['image_url'], 'http') === 0) {
                    $image_url = $product['image_url'];
                } else {
                    // 로컬 파일 경로인 경우
                    $image_url = '/homekmart/admin/uploads/' . $product['image_url'];
                }
            } else {
                // 플레이스홀더 이미지
                $product_name_encoded = urlencode($product['name_ko'] ?: 'Product');
                $image_url = 'https://via.placeholder.com/200x200/f8f8f8/666666?text=' . $product_name_encoded;
            }
            
            $products[] = [
                'id' => (int)$product['id'],
                'name_ko' => $product['name_ko'],
                'name_en' => $product['name_en'],
                'image_url' => $image_url,
                'description' => $product['description'],
                'brand' => $product['brand_name'],
                'category' => $product['category_name'],
                'selling_price' => (float)($product['selling_price'] ?: 0),
                'cost_price' => (float)($product['cost_price'] ?: 0),
                'quantity' => (int)($product['quantity'] ?: 0),
                'display_order' => (int)$product['display_order'],
                'badge_text' => $product['badge_text'],
                'badge_color' => $product['badge_color'] ?: 'red'
            ];
        }
        $products_stmt->close();
        
        // 디버그 로그
        error_log("getSectionProducts: section_id=$section_id, store_id=$store_id, found " . count($products) . " products");
        
    } catch (Exception $e) {
        error_log("getSectionProducts error: " . $e->getMessage() . " (section_id=$section_id, store_id=$store_id)");
        
        // 오류 발생 시 기본 데이터 반환 (레이아웃 깨짐 방지)
        $products = [
            [
                'id' => 0,
                'name_ko' => '상품 로딩 중...',
                'name_en' => 'Loading products...',
                'image_url' => 'https://via.placeholder.com/200x200/f8f8f8/666666?text=Loading',
                'description' => '상품 정보를 불러오는 중입니다.',
                'brand' => '',
                'category' => '',
                'selling_price' => 0,
                'cost_price' => 0,
                'quantity' => 0,
                'display_order' => 1,
                'badge_text' => '',
                'badge_color' => 'blue'
            ]
        ];
    }
    
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
    
    // 섹션 이름을 기반으로 ID 생성
    $section_id = '';
    if (strpos($section_name, '특가') !== false || strpos($section_name, '베너') !== false) {
        $section_id = 'weekly-deals';
    } elseif (strpos($section_name, '신상품') !== false) {
        $section_id = 'new-products';
    } elseif (strpos($section_name, '목록형') !== false) {
        $section_id = 'fresh';
    } elseif (strpos($section_name, '슬라이드형') !== false) {
        $section_id = 'processed';
    } else {
        $section_id = 'section-' . $column['section_id'];
    }
    
    // 섹션 유형별 아이콘과 색상
    $type_config = [
        'banner' => ['icon' => 'fa-fire', 'color' => 'var(--hmart-red)'],
        'featured' => ['icon' => 'fa-star', 'color' => '#FF6B35'],
        'new_products' => ['icon' => 'fa-sparkles', 'color' => '#28a745'],
        'category' => ['icon' => 'fa-leaf', 'color' => '#6f42c1'],
        'custom' => ['icon' => 'fa-cog', 'color' => '#6c757d']
    ];
    
    $config = $type_config[$column['section_type']] ?? $type_config['custom'];
    
    $html = '<section id="' . $section_id . '" class="product-section section">';
    
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
            $items_per_slide = isset($column['items_per_slide']) ? (int)$column['items_per_slide'] : 4;
            $html .= renderCarouselLayout($products, $column['section_id'], $items_per_slide);
            break;
        case 'banner':
            $html .= renderBannerLayout($products);
            break;
        case 'grid':
        default:
            $html .= renderGridLayout($products);
            break;
    }
    
    $html .= '</section>';
    
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
 * 슬라이드 개수에 따른 Bootstrap 컬럼 클래스 계산
 */
function getCarouselColumnClasses($items_per_slide) {
    switch ($items_per_slide) {
        case 1:
            return 'col-12';
        case 2:
            return 'col-lg-6 col-md-6';
        case 3:
            return 'col-lg-4 col-md-6';
        case 4:
            return 'col-lg-3 col-md-6'; // 기본값
        case 5:
            return 'col-lg-2 col-md-4'; // 5개는 특별 처리
        case 6:
            return 'col-lg-2 col-md-4';
        case 7:
        case 8:
        case 9:
        case 10:
            // 7개 이상은 flex 레이아웃 사용
            return 'carousel-flex-item';
        default:
            return 'col-lg-3 col-md-6';
    }
}

/**
 * 캐러셀 레이아웃 렌더링 (동적 개수 지원)
 */
function renderCarouselLayout($products, $section_id, $items_per_slide = 4) {
    $carousel_id = 'carousel-' . $section_id;
    
    // Bootstrap 컬럼 클래스 계산
    $col_classes = getCarouselColumnClasses($items_per_slide);
    
    $html = '<div class="product-carousel-container position-relative">';
    $html .= '<div id="' . $carousel_id . '" class="carousel slide" data-bs-ride="carousel">';
    $html .= '<div class="carousel-inner">';
    
    $chunks = array_chunk($products, $items_per_slide); // 동적 개수로 그룹화
    $isFirst = true;
    
    foreach ($chunks as $chunk) {
        $html .= '<div class="carousel-item ' . ($isFirst ? 'active' : '') . '">';
        
        // 7개 이상일 때는 flex 레이아웃 사용
        if ($items_per_slide >= 7) {
            $html .= '<div class="row flex-layout items-' . $items_per_slide . '">';
        } else {
            $html .= '<div class="row g-3">';
        }
        
        foreach ($chunk as $product) {
            $html .= '<div class="' . $col_classes . '">';
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

// 동적 레이아웃 데이터 조회 (안전한 처리)
// 레이아웃 테이블 존재 여부 먼저 확인
$layout_system_installed = false;
try {
    $table_check = $conn->query("SHOW TABLES LIKE 'layout_rows'");
    if ($table_check && $table_check->num_rows > 0) {
        $layout_system_installed = true;
    }
} catch (Exception $e) {
    $layout_system_installed = false;
}

// 레이아웃 시스템이 설치되어 있으면 동적 레이아웃 사용, 아니면 기본 레이아웃 사용
if ($layout_system_installed) {
    try {
        $dynamic_layout = getDynamicLayout($conn, $selected_store_id);
    } catch (Exception $e) {
        error_log("Dynamic layout loading error: " . $e->getMessage());
        $dynamic_layout = getDefaultLayout($conn, $selected_store_id);
    }
} else {
    // 레이아웃 시스템이 설치되지 않은 경우 기본 레이아웃만 사용
    $dynamic_layout = getDefaultLayout($conn, $selected_store_id);
}
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
            <div class="d-flex justify-content-between align-items-center py-2">
                <div>
                    <span>고객센터: 1588-1234</span>
                </div>
                <div class="d-flex">
                    <a href="#stores" class="text-decoration-none me-3">매장찾기</a>
                    <a href="#customer-service" class="text-decoration-none me-3">고객센터</a>
                    <a href="../admin/login.php" class="text-decoration-none me-3">로그인</a>
                    <a href="#" class="text-decoration-none">회원가입</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Header -->
    <header class="main-header">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between py-4">
                <!-- Logo -->
                <a href="index_hmart.php" class="logo text-decoration-none">
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
        
        <!-- Navigation Menu -->
        <nav class="nav-menu">
            <div class="container">
                <ul>
                    <li><a href="#" class="active">홈</a></li>
                    <li><a href="#weekly-deals">주간특가</a></li>
                    <li><a href="#new-products">신상품</a></li>
                    <li><a href="#fresh">신선식품</a></li>
                    <li><a href="#processed">가공식품</a></li>
                    <li><a href="#frozen">냉동식품</a></li>
                    <li><a href="#living">생활용품</a></li>
                </ul>
            </div>
        </nav>
    </header>

    <!-- Banner Slider -->
    <section class="banner-slider">
        <div class="banner-slide active">
            <div class="banner-content text-center py-5" style="background: linear-gradient(135deg, var(--hmart-red), var(--hmart-red-light));">
                <div class="container">
                    <h2 class="text-white mb-3">🛒 주간 특가 세일!</h2>
                    <p class="text-white mb-4">신선한 식재료와 생활용품을 특가로 만나보세요</p>
                    <button class="btn btn-light btn-lg" onclick="scrollToSection('weekly-deals')">특가 상품 보기</button>
                </div>
            </div>
        </div>
        <div class="banner-slide">
            <div class="banner-content text-center py-5" style="background: linear-gradient(135deg, var(--hmart-orange), #FF8A65);">
                <div class="container">
                    <h2 class="text-white mb-3">🚚 당일 배송 서비스</h2>
                    <p class="text-white mb-4">오전 11시 이전 주문 시 당일 배송! (신선식품 한정)</p>
                    <button class="btn btn-light btn-lg" onclick="scrollToSection('fresh')">신선식품 보기</button>
                </div>
            </div>
        </div>
        <div class="banner-slide">
            <div class="banner-content text-center py-5" style="background: linear-gradient(135deg, #4CAF50, #66BB6A);">
                <div class="container">
                    <h2 class="text-white mb-3">🎯 신상품 입고</h2>
                    <p class="text-white mb-4">매주 새로운 상품들을 만나보세요</p>
                    <button class="btn btn-light btn-lg" onclick="scrollToSection('new-products')">신상품 보기</button>
                </div>
            </div>
        </div>
    </section>

    <!-- Categories Section -->
    <section id="home" class="section">
        <div class="container">
            <h2 class="section-title">상품 카테고리</h2>
            <div class="row">
                <div class="col-md-2 col-sm-4 col-6 mb-4">
                    <div class="category-card text-center" onclick="scrollToSection('fresh')">
                        <div class="category-icon">
                            <i class="fas fa-carrot" style="color: var(--hmart-orange);"></i>
                        </div>
                        <div class="category-name">신선식품</div>
                        <div class="category-count">45개 상품</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-4">
                    <div class="category-card text-center" onclick="scrollToSection('processed')">
                        <div class="category-icon">
                            <i class="fas fa-cookie-bite" style="color: var(--hmart-red);"></i>
                        </div>
                        <div class="category-name">가공식품</div>
                        <div class="category-count">78개 상품</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-4">
                    <div class="category-card text-center" onclick="scrollToSection('frozen')">
                        <div class="category-icon">
                            <i class="fas fa-snowflake" style="color: #2196F3;"></i>
                        </div>
                        <div class="category-name">냉동식품</div>
                        <div class="category-count">32개 상품</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-4">
                    <div class="category-card text-center" onclick="scrollToSection('living')">
                        <div class="category-icon">
                            <i class="fas fa-pump-soap" style="color: #4CAF50;"></i>
                        </div>
                        <div class="category-name">생활용품</div>
                        <div class="category-count">56개 상품</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-4">
                    <div class="category-card text-center" onclick="scrollToSection('health')">
                        <div class="category-icon">
                            <i class="fas fa-heart" style="color: #E91E63;"></i>
                        </div>
                        <div class="category-name">건강/미용</div>
                        <div class="category-count">23개 상품</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-4">
                    <div class="category-card text-center" onclick="scrollToSection('kitchen')">
                        <div class="category-icon">
                            <i class="fas fa-utensils" style="color: var(--hmart-gray);"></i>
                        </div>
                        <div class="category-name">주방용품</div>
                        <div class="category-count">41개 상품</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Content: 동적 레이아웃 시스템 -->
    <main class="main-content">
        <?php if ($debug_mode): ?>
        <!-- 디버그 정보 -->
        <div class="container mt-3">
            <div class="alert alert-info">
                <h5><i class="fas fa-bug me-2"></i>디버그 정보</h5>
                <ul class="mb-0">
                    <li><strong>점포 ID:</strong> <?php echo $selected_store_id; ?> (<?php echo htmlspecialchars($current_store['name'] ?? 'Unknown'); ?>)</li>
                    <li><strong>레이아웃 시스템:</strong> <?php echo $layout_system_installed ? '설치됨' : '미설치 (기본 레이아웃 사용)'; ?></li>
                    <li><strong>레이아웃 행 수:</strong> <?php echo count($dynamic_layout['rows']); ?></li>
                    <li><strong>페이지 로드 시간:</strong> <?php echo date('Y-m-d H:i:s'); ?></li>
                </ul>
            </div>
        </div>
        <?php endif; ?>
        
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
    
    // 장바구니 사이드바 제어 함수들
    function openCart() {
        const cartSidebar = document.getElementById('cartSidebar');
        const backdrop = document.getElementById('backdrop');
        
        if (cartSidebar && backdrop) {
            cartSidebar.classList.add('show');
            backdrop.classList.add('show');
            document.body.style.overflow = 'hidden'; // 스크롤 방지
        }
    }
    
    function closeCart() {
        const cartSidebar = document.getElementById('cartSidebar');
        const backdrop = document.getElementById('backdrop');
        
        if (cartSidebar && backdrop) {
            cartSidebar.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.style.overflow = ''; // 스크롤 복원
        }
    }
    
    function addToCart(productId) {
        // AJAX로 장바구니에 상품 추가
        fetch('/homekmart/shop/api/cart.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'add',
                product_id: productId,
                quantity: 1,
                store_id: <?php echo $selected_store_id; ?>
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 성공 시 알림 표시
                showNotification('상품이 장바구니에 추가되었습니다.', 'success');
                updateCartCount();
            } else {
                showNotification(data.message || '장바구니 추가에 실패했습니다.', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('네트워크 오류가 발생했습니다.', 'error');
        });
    }
    
    function showNotification(message, type = 'info') {
        // 간단한 알림 표시
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'success' ? 'success' : 'danger'} position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
            </div>
        `;
        document.body.appendChild(notification);
        
        // 3초 후 자동 제거
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 3000);
    }
    
    function updateCartCount() {
        // 장바구니 개수 업데이트 (필요시 구현)
        console.log('Cart count updated');
    }
    
    function checkout() {
        // 주문 기능 (추후 구현)
        alert('주문 기능은 준비 중입니다.');
    }
    
    // ESC 키로 장바구니 닫기
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeCart();
        }
    });
    
    // 섹션으로 부드럽게 스크롤하는 함수
    function scrollToSection(sectionId) {
        const section = document.getElementById(sectionId);
        if (section) {
            // 네비게이션 메뉴의 활성 상태 업데이트
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            document.querySelector(`a[href="#${sectionId}"]`).classList.add('active');
            
            // 부드럽게 스크롤
            section.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }
    
    // 페이지 로드 시 네비게이션 메뉴 클릭 이벤트 등록
    document.addEventListener('DOMContentLoaded', function() {
        // 메뉴 클릭 이벤트
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href').substring(1);
                scrollToSection(targetId);
            });
        });
        
        // 스크롤 시 활성 메뉴 업데이트
        window.addEventListener('scroll', function() {
            const sections = document.querySelectorAll('section[id]');
            let current = '';
            
            sections.forEach(section => {
                const sectionTop = section.offsetTop - 100;
                if (window.pageYOffset >= sectionTop) {
                    current = section.getAttribute('id');
                }
            });
            
            if (current) {
                document.querySelectorAll('.nav-link').forEach(link => {
                    link.classList.remove('active');
                });
                const activeLink = document.querySelector(`a[href="#${current}"]`);
                if (activeLink) {
                    activeLink.classList.add('active');
                }
            }
        });
    });
    </script>

    <!-- Footer -->
    <footer class="footer mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-3">
                    <h3>회사 정보</h3>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-decoration-none footer-link">회사 소개</a></li>
                        <li><a href="#" class="text-decoration-none footer-link">매장 안내</a></li>
                        <li><a href="#" class="text-decoration-none footer-link">채용 정보</a></li>
                        <li><a href="#" class="text-decoration-none footer-link">투자자 정보</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h3>고객 서비스</h3>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-decoration-none footer-link">고객센터</a></li>
                        <li><a href="#" class="text-decoration-none footer-link">자주 묻는 질문</a></li>
                        <li><a href="#" class="text-decoration-none footer-link">배송 안내</a></li>
                        <li><a href="#" class="text-decoration-none footer-link">교환/반품 안내</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h3>쇼핑 정보</h3>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-decoration-none footer-link">이용약관</a></li>
                        <li><a href="#" class="text-decoration-none footer-link">개인정보처리방침</a></li>
                        <li><a href="#" class="text-decoration-none footer-link">전자금융거래약관</a></li>
                        <li><a href="#" class="text-decoration-none footer-link">청소년보호정책</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h3>소셜 미디어</h3>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-decoration-none footer-link"><i class="fab fa-facebook me-2"></i> Facebook</a></li>
                        <li><a href="#" class="text-decoration-none footer-link"><i class="fab fa-instagram me-2"></i> Instagram</a></li>
                        <li><a href="#" class="text-decoration-none footer-link"><i class="fab fa-youtube me-2"></i> YouTube</a></li>
                        <li><a href="#" class="text-decoration-none footer-link"><i class="fab fa-blog me-2"></i> Blog</a></li>
                    </ul>
                </div>
            </div>
            <div class="row mt-4 pt-4 border-top">
                <div class="col-12 text-center">
                    <p class="mb-0">&copy; 2024 HOME K MART. All rights reserved. | 대표전화: 1588-1234 | 사업자등록번호: 123-45-67890</p>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>

<?php
$conn->close();
?>