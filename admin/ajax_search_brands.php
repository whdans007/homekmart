<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

// 로그인 확인
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

$search = $_GET['q'] ?? '';
$search = trim($search);

try {
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    // 테이블 컬럼 확인
    $columns_check = $conn->query("SHOW COLUMNS FROM brands");
    $has_name = false;
    $has_name_ko = false;
    $has_name_en = false;
    
    while ($column = $columns_check->fetch_assoc()) {
        if ($column['Field'] == 'name') $has_name = true;
        if ($column['Field'] == 'name_ko') $has_name_ko = true;
        if ($column['Field'] == 'name_en') $has_name_en = true;
    }
    
    $brands = [];
    
    // SELECT 필드 구성
    $select_fields = ["id"];
    if ($has_name_ko) $select_fields[] = "name_ko";
    if ($has_name_en) $select_fields[] = "name_en";
    if ($has_name) $select_fields[] = "name";
    
    if (!empty($search)) {
        // 검색어가 있는 경우
        $search_pattern = '%' . $search . '%';
        $search_start = $search . '%';
        
        // WHERE 조건 구성
        $where_conditions = [];
        $order_conditions = [];
        $bind_types = "";
        $bind_values = [];
        
        if ($has_name_ko) {
            $where_conditions[] = "name_ko LIKE ?";
            $order_conditions[] = "WHEN name_ko LIKE ? THEN 1";
            $bind_types .= "s";
            $bind_values[] = &$search_pattern;
        }
        if ($has_name_en) {
            $where_conditions[] = "name_en LIKE ?";
            $order_conditions[] = "WHEN name_en LIKE ? THEN 2";
            $bind_types .= "s";
            $bind_values[] = &$search_pattern;
        }
        if ($has_name) {
            $where_conditions[] = "name LIKE ?";
            $order_conditions[] = "WHEN name LIKE ? THEN 3";
            $bind_types .= "s";
            $bind_values[] = &$search_pattern;
        }
        
        // ORDER BY를 위한 추가 바인딩
        foreach ($where_conditions as $condition) {
            $bind_types .= "s";
            $bind_values[] = &$search_start;
        }
        
        $order_field = $has_name_ko ? "name_ko" : ($has_name_en ? "name_en" : "name");
        
        $sql = "SELECT " . implode(", ", $select_fields) . " 
                FROM brands 
                WHERE " . implode(" OR ", $where_conditions) . "
                ORDER BY 
                    CASE " . implode(" ", $order_conditions) . "
                        ELSE 4
                    END,
                    $order_field
                LIMIT 20";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare 실패: " . $conn->error);
        }
        
        // bind_param 호출
        $bind_params = array($bind_types);
        foreach ($bind_values as $key => $value) {
            $bind_params[] = &$bind_values[$key];
        }
        call_user_func_array(array($stmt, 'bind_param'), $bind_params);
    } else {
        // 검색어가 없는 경우 상위 20개
        $order_field = $has_name_ko ? "name_ko" : ($has_name_en ? "name_en" : "name");
        $sql = "SELECT " . implode(", ", $select_fields) . " FROM brands ORDER BY $order_field LIMIT 20";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare 실패: " . $conn->error);
        }
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $brand_name = "";
        if ($has_name_ko && !empty($row['name_ko'])) {
            $brand_name = $row['name_ko'];
        } else if ($has_name_en && !empty($row['name_en'])) {
            $brand_name = $row['name_en'];
        } else if ($has_name && !empty($row['name'])) {
            $brand_name = $row['name'];
        }
        
        $brands[] = [
            'id' => $row['id'],
            'name' => $brand_name,
            'name_ko' => $has_name_ko ? $row['name_ko'] : null,
            'name_en' => $has_name_en ? $row['name_en'] : null
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'brands' => $brands,
        'count' => count($brands)
    ]);
    
} catch (Exception $e) {
    error_log("Brand search error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '브랜드 검색 중 오류가 발생했습니다.']);
}
?>