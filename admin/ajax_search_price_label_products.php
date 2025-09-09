<?php
// 모든 에러 출력 차단
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 출력 버퍼링 시작
ob_start();

// 세션 및 권한 확인
ensure_logged_in();
if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    ob_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

// 버퍼 내용 삭제하고 헤더 설정
ob_clean();
header('Content-Type: application/json');

$response = ['success' => false, 'products' => [], 'debug' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $query = trim($_POST['q'] ?? '');
    $limit = min(max(1, (int)($_POST['limit'] ?? 10)), 100); // 1-100 범위로 제한
    $show_all = $_POST['show_all'] ?? '';
    
    $response['debug']['query'] = $query;
    $response['debug']['method'] = $_SERVER['REQUEST_METHOD'];
    $response['debug']['post_data'] = $_POST;
    
    // 검색어가 너무 짧으면 종료
    if (strlen($query) < 1) {
        $response['debug']['error'] = 'Query too short';
        echo json_encode($response);
        exit;
    }
    
    try {
        $conn = get_db_connection();
        if (!$conn) {
            $response['debug']['error'] = 'Database connection failed';
            throw new Exception('데이터베이스 연결 실패');
        }
        $response['debug']['database'] = 'Connected';
        
        // 현재 사용자의 점포 ID 확인
        $current_store_id = null;
        if (!empty($_SESSION['user_id'])) {
            $user_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $_SESSION['user_id']);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            if ($user_row = $user_result->fetch_assoc()) {
                $current_store_id = $user_row['store_id'];
            }
            $user_stmt->close();
        }
        
        // super_admin의 경우 점포 선택 가능
        if ($_SESSION['role'] === 'super_admin') {
            $current_store_id = $_POST['store_id'] ?? $current_store_id;
        }
        
        // inventory 테이블에 cost_price 및 box_price 컬럼이 있는지 확인
        $column_check = $conn->prepare("SHOW COLUMNS FROM inventory LIKE 'cost_price'");
        $column_check->execute();
        $has_cost_price_column = $column_check->fetch();
        $column_check->close();
        
        $box_column_check = $conn->prepare("SHOW COLUMNS FROM inventory LIKE 'box_price'");
        $box_column_check->execute();
        $has_box_price_column = $box_column_check->fetch();
        $box_column_check->close();
        
        $search_query = "%{$query}%";
        
        // 쿼리 구성
        $select_fields = "
            p.id,
            p.sku,
            p.name_ko,
            p.name_en,
            p.barcode,
            p.pieces_per_box,
            b.name_ko as brand_name,
            COALESCE(i.quantity, 0) as stock";
        
        // 점포별 원가가 있는 경우
        if ($has_cost_price_column) {
            $select_fields .= ", COALESCE(i.cost_price, p.cost_price) as cost_price";
        } else {
            $select_fields .= ", p.cost_price";
        }
        
        // 점포별 판매가
        $select_fields .= ", COALESCE(i.selling_price, p.selling_price) as selling_price";
        
        // 점포별 박스가격이 있는 경우
        if ($has_box_price_column) {
            $select_fields .= ", i.box_price";
        } else {
            $select_fields .= ", NULL as box_price";
        }
        
        $from_clause = "
            FROM products p 
            LEFT JOIN inventory i ON p.id = i.product_id" . ($current_store_id ? " AND i.store_id = ?" : "")."
            LEFT JOIN brands b ON p.brand_id = b.id";
        
        // 바코드 우선 검색
        $exact_barcode_sql = "SELECT " . $select_fields . " " . $from_clause . "
            WHERE p.is_active = 1 AND p.barcode = ?
            LIMIT 1";
        
        $stmt = $conn->prepare($exact_barcode_sql);
        if ($current_store_id) {
            $stmt->bind_param("is", $current_store_id, $query);
        } else {
            $stmt->bind_param("s", $query);
        }
        
        $stmt->execute();
        $barcode_result = $stmt->get_result();
        
        if ($barcode_result->num_rows > 0) {
            // 바코드로 정확히 일치하는 상품이 있음
            $products = [$barcode_result->fetch_assoc()];
            $products[0]['exact_match'] = true;
        } else {
            // 상품명이나 SKU로 검색
            $sql = "SELECT " . $select_fields . " " . $from_clause . "
                WHERE p.is_active = 1 
                    AND (p.sku LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?)
                ORDER BY 
                    CASE 
                        WHEN p.sku LIKE ? THEN 1 
                        WHEN p.name_ko LIKE ? THEN 2 
                        WHEN p.name_en LIKE ? THEN 3 
                        ELSE 4 
                    END,
                    p.name_ko ASC, p.name_en ASC
                LIMIT " . (int)$limit;
            
            $params = [];
            if ($current_store_id) {
                $params[] = $current_store_id;
            }
            $params = array_merge($params, [
                $search_query, $search_query, $search_query,  // WHERE 조건
                $search_query, $search_query, $search_query   // ORDER BY 조건
            ]);
            
            $products = null; // 다음 블록에서 실행하도록 설정
        }
        
        $stmt->close();
        
        if ($products === null) {
            $stmt = $conn->prepare($sql);
            
            if (!empty($params)) {
                $types = str_repeat('s', count($params));
                if ($current_store_id && count($params) > 0) {
                    $types = 'i' . substr($types, 1); // 첫 번째 파라미터는 store_id (integer)
                }
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $products = [];
            while ($row = $result->fetch_assoc()) {
                $products[] = $row;
            }
            $stmt->close();
        }
        
        $response['debug']['product_count'] = count($products);
        if (!empty($products)) {
            $response['success'] = true;
            $response['products'] = $products;
        }
        
        $conn->close();
        
    } catch (Exception $e) {
        error_log("Price label product search error: " . $e->getMessage());
        $response['message'] = '검색 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

echo json_encode($response);
?>