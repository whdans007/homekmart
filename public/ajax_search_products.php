<?php
// 오류 로깅 활성화
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Include error: ' . $e->getMessage()]);
    exit;
}

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit();
}

try {
    // GET 방식과 POST 방식 둘 다 지원
    $term = $_GET['term'] ?? $_POST['q'] ?? '';
    $limit = min(max(1, (int)($_GET['limit'] ?? $_POST['limit'] ?? 10)), 50);

    // 도매상품관리에서 사용하는 경우를 위한 success 형식 응답 지원
    $use_success_format = isset($_POST['q']);

    if (empty($term)) {
        if ($use_success_format) {
            echo json_encode(['success' => false, 'products' => []]);
        } else {
            echo json_encode([]);
        }
        exit();
    }

    $conn = get_db_connection();
    $data = [];

// 현재 로그인된 사용자의 점포 정보 조회
$user_store_id = null;
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    $user_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_row = $user_result->fetch_assoc()) {
        $user_store_id = $user_row['store_id'];
        $current_store_id = $user_row['store_id'];
    }
    $user_stmt->close();
}

try {
    // inventory 테이블에 cost_price 컬럼이 있는지 확인
    $column_check = $conn->prepare("SHOW COLUMNS FROM inventory LIKE 'cost_price'");
    $column_check->execute();
    $has_cost_price_column = $column_check->fetch();
    $column_check->close();
    
    // inventory 테이블에 box_price 컬럼이 있는지 확인
    $box_column_check = $conn->prepare("SHOW COLUMNS FROM inventory LIKE 'box_price'");
    $box_column_check->execute();
    $has_box_price_column = $box_column_check->fetch();
    $box_column_check->close();
    
    // 점포별 원가/박스단가를 포함한 쿼리 구성
    if (($has_cost_price_column || $has_box_price_column) && $user_store_id) {
        // 점포별 원가/박스단가가 있는 경우
        $select_fields = "p.id, p.sku, p.name_ko, p.name_en, p.barcode, p.pieces_per_box, b.name as brand_name";
        
        if ($has_cost_price_column) {
            $select_fields .= ", COALESCE(i.cost_price, p.cost_price) as cost_price";
        } else {
            $select_fields .= ", p.cost_price";
        }
        
        $select_fields .= ", COALESCE(i.selling_price, p.selling_price) as selling_price";
        
        if ($has_box_price_column) {
            $select_fields .= ", i.box_price";
        }
        
        $base_select = "SELECT " . $select_fields;
        $base_from = "FROM products p 
                      LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
                      LEFT JOIN brands b ON p.brand_id = b.id";
    } else {
        // 기본 상품 테이블의 원가 사용
        $base_select = "SELECT p.id, p.sku, p.name_ko, p.name_en, p.barcode, p.cost_price, p.selling_price, p.pieces_per_box, b.name as brand_name";
        $base_from = "FROM products p LEFT JOIN brands b ON p.brand_id = b.id";
    }
    
    // 바코드 검색을 우선으로 처리
    $sql = $base_select . " " . $base_from . " WHERE p.barcode = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    
    if (($has_cost_price_column || $has_box_price_column) && $user_store_id) {
        $stmt->bind_param('is', $user_store_id, $term);
    } else {
        $stmt->bind_param('s', $term);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        $product['exact_match'] = true; // 바코드는 정확히 일치
        $data[] = $product;
    } else {
        // 바코드와 일치하는 상품이 없으면, 상품명 또는 SKU로 검색
        $searchTerm = '%' . $term . '%';
        $sql = $base_select . " " . $base_from . " 
               WHERE p.name_ko LIKE ? OR p.name_en LIKE ? OR p.sku LIKE ?
               LIMIT " . (int)$limit;
        $stmt = $conn->prepare($sql);
        
        if (($has_cost_price_column || $has_box_price_column) && $user_store_id) {
            $stmt->bind_param('isss', $user_store_id, $searchTerm, $searchTerm, $searchTerm);
        } else {
            $stmt->bind_param('sss', $searchTerm, $searchTerm, $searchTerm);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }

    // 디버깅: 검색 결과 로그
    error_log("Product search results for store {$user_store_id}: " . json_encode($data));
    
    // 도매상품관리에서 사용하는 경우 success 형식으로 응답
    if ($use_success_format) {
        if (!empty($data)) {
            echo json_encode(['success' => true, 'products' => $data]);
        } else {
            echo json_encode(['success' => false, 'products' => [], 'message' => '검색 결과가 없습니다.']);
        }
    } else {
        echo json_encode($data);
    }

    $conn->close();
    
} catch (Exception $e) {
    error_log("Product search error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
    if (isset($use_success_format) && $use_success_format) {
        echo json_encode(['success' => false, 'message' => '검색 중 오류가 발생했습니다: ' . $e->getMessage()]);
    } else {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
