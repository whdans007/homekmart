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

// 매입관리 권한 확인 (카테고리 관리 권한도 체크)
if (!has_permission('purchase_management') && !has_permission('category_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['name'])) {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$name = trim($_POST['name']);

try {
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    // 테이블 컬럼 확인
    $columns_check = $conn->query("SHOW COLUMNS FROM categories");
    $has_name = false;
    $has_name_ko = false;
    $has_name_en = false;
    $has_created_at = false;
    
    while ($column = $columns_check->fetch_assoc()) {
        if ($column['Field'] == 'name') $has_name = true;
        if ($column['Field'] == 'name_ko') $has_name_ko = true;
        if ($column['Field'] == 'name_en') $has_name_en = true;
        if ($column['Field'] == 'created_at') $has_created_at = true;
    }
    
    // 중복 확인 - 동적으로 쿼리 생성
    $where_conditions = [];
    $bind_types = "";
    $bind_values = [];
    
    if ($has_name_ko) {
        $where_conditions[] = "name_ko = ?";
        $bind_types .= "s";
        $bind_values[] = &$name;
    }
    if ($has_name) {
        $where_conditions[] = "name = ?";
        $bind_types .= "s";
        $bind_values[] = &$name;
    }
    
    if (!empty($where_conditions)) {
        $check_sql = "SELECT id, " . 
                     ($has_name_ko ? "name_ko" : "") . 
                     ($has_name_ko && $has_name_en ? ", name_en" : "") . 
                     ($has_name && !$has_name_ko ? "name" : "") . 
                     " FROM categories WHERE " . implode(" OR ", $where_conditions);
        
        $check_stmt = $conn->prepare($check_sql);
        
        // bind_param 호출
        if (count($bind_values) > 0) {
            $bind_params = array($bind_types);
            foreach ($bind_values as $key => $value) {
                $bind_params[] = &$bind_values[$key];
            }
            call_user_func_array(array($check_stmt, 'bind_param'), $bind_params);
        }
        
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($existing_category = $check_result->fetch_assoc()) {
            // 이미 존재하는 카테고리 반환
            $category_name = isset($existing_category['name_ko']) ? $existing_category['name_ko'] : 
                           (isset($existing_category['name']) ? $existing_category['name'] : '');
            
            echo json_encode([
                'success' => true,
                'exists' => true,
                'message' => '기존 카테고리를 선택했습니다.',
                'data' => [
                    'id' => $existing_category['id'],
                    'name' => $category_name,
                    'name_ko' => isset($existing_category['name_ko']) ? $existing_category['name_ko'] : null,
                    'name_en' => isset($existing_category['name_en']) ? $existing_category['name_en'] : null
                ]
            ]);
            $check_stmt->close();
            $conn->close();
            exit;
        }
        $check_stmt->close();
    }
    
    // 새 카테고리 추가 - 동적으로 쿼리 생성
    if ($has_name_ko) {
        if ($has_created_at) {
            $insert_sql = "INSERT INTO categories (name_ko, created_at) VALUES (?, NOW())";
        } else {
            $insert_sql = "INSERT INTO categories (name_ko) VALUES (?)";
        }
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("s", $name);
    } else if ($has_name) {
        if ($has_created_at) {
            $insert_sql = "INSERT INTO categories (name, created_at) VALUES (?, NOW())";
        } else {
            $insert_sql = "INSERT INTO categories (name) VALUES (?)";
        }
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("s", $name);
    } else {
        throw new Exception("categories 테이블에 name 관련 컬럼이 없습니다.");
    }
    
    if (!$insert_stmt->execute()) {
        throw new Exception("카테고리 추가 실패: " . $insert_stmt->error);
    }
    
    $new_category_id = $conn->insert_id;
    $insert_stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'exists' => false,
        'message' => '새로운 카테고리가 추가되었습니다.',
        'data' => [
            'id' => $new_category_id,
            'name' => $name,
            'name_ko' => $has_name_ko ? $name : null,
            'name_en' => null
        ]
    ]);

} catch (Exception $e) {
    error_log("Category add error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '카테고리 추가 중 오류가 발생했습니다.']);
}
?>