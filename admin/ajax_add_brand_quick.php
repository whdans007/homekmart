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

// 매입관리 권한 확인 (브랜드 관리 권한도 체크)
if (!has_permission('purchase_management') && !has_permission('brand_management')) {
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
        throw new Exception("데이터베이스 연결 실패 - mysqli 연결 객체가 null입니다.");
    }
    
    // 연결 상태 확인
    if ($conn->connect_error) {
        throw new Exception("데이터베이스 연결 실패: " . $conn->connect_error);
    }
    
    // 먼저 테이블 컬럼 확인하여 중복 체크 쿼리 구성
    $columns_check = $conn->query("SHOW COLUMNS FROM brands");
    if (!$columns_check) {
        throw new Exception("테이블 구조 확인 실패: " . $conn->error);
    }
    
    $has_name = false;
    $has_name_ko = false;
    $has_name_en = false;
    $column_list = [];
    
    while ($column = $columns_check->fetch_assoc()) {
        $column_list[] = $column['Field'];
        if ($column['Field'] == 'name') $has_name = true;
        if ($column['Field'] == 'name_ko') $has_name_ko = true;
        if ($column['Field'] == 'name_en') $has_name_en = true;
    }
    
    // 디버깅을 위한 컬럼 리스트 로깅
    error_log("Brands table columns: " . implode(", ", $column_list));
    
    // 중복 확인 - 테이블 구조에 따라 동적으로 쿼리 생성
    $select_fields = "id";
    $where_conditions = [];
    $bind_types = "";
    $bind_values = [];
    
    if ($has_name_ko) {
        $select_fields .= ", name_ko";
        $where_conditions[] = "name_ko = ?";
        $bind_types .= "s";
        $bind_values[] = &$name;
    }
    if ($has_name_en) {
        $select_fields .= ", name_en";
        $where_conditions[] = "name_en = ?";
        $bind_types .= "s";
        $bind_values[] = &$name;
    }
    if ($has_name) {
        $select_fields .= ", name";
        $where_conditions[] = "name = ?";
        $bind_types .= "s";
        $bind_values[] = &$name;
    }
    
    if (empty($where_conditions)) {
        throw new Exception("brands 테이블에 name 관련 컬럼이 없습니다.");
    }
    
    $check_sql = "SELECT $select_fields FROM brands WHERE " . implode(" OR ", $where_conditions);
    $check_stmt = $conn->prepare($check_sql);
    
    // bind_param 호출을 위한 배열 준비
    $bind_params = array($bind_types);
    foreach ($bind_values as $key => $value) {
        $bind_params[] = &$bind_values[$key];
    }
    call_user_func_array(array($check_stmt, 'bind_param'), $bind_params);
    
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($existing_brand = $check_result->fetch_assoc()) {
        // 이미 존재하는 브랜드 반환
        $brand_name = isset($existing_brand['name_ko']) ? $existing_brand['name_ko'] : 
                      (isset($existing_brand['name_en']) ? $existing_brand['name_en'] : 
                      (isset($existing_brand['name']) ? $existing_brand['name'] : ''));
        
        echo json_encode([
            'success' => true,
            'exists' => true,
            'message' => '기존 브랜드를 선택했습니다.',
            'data' => [
                'id' => $existing_brand['id'],
                'name' => $brand_name
            ]
        ]);
        $check_stmt->close();
        $conn->close();
        exit;
    }
    $check_stmt->close();
    
    // created_at 컬럼 존재 여부 확인 (위에서 이미 확인한 컬럼 리스트 재사용)
    $has_created_at = in_array('created_at', $column_list);
    
    error_log("Column check - has_name: " . ($has_name ? 'true' : 'false') . 
              ", has_name_ko: " . ($has_name_ko ? 'true' : 'false') . 
              ", has_name_en: " . ($has_name_en ? 'true' : 'false') . 
              ", has_created_at: " . ($has_created_at ? 'true' : 'false'));
    
    // 새 브랜드 추가 - 테이블 구조에 따라 동적으로 쿼리 생성
    $insert_sql = "";
    $insert_stmt = null;
    
    if ($has_name_ko && $has_name_en) {
        // name_ko와 name_en이 모두 있는 경우
        // name_en은 NULL을 허용하므로 빈 문자열이면 NULL로 저장
        $name_en = !empty($name) ? $name : null;
        if ($has_created_at) {
            $insert_sql = "INSERT INTO brands (name_ko, name_en, created_at) VALUES (?, ?, NOW())";
        } else {
            $insert_sql = "INSERT INTO brands (name_ko, name_en) VALUES (?, ?)";
        }
        error_log("Preparing SQL: " . $insert_sql);
        $insert_stmt = $conn->prepare($insert_sql);
        if (!$insert_stmt) {
            throw new Exception("Prepare 실패: " . $conn->error . " / SQL: " . $insert_sql);
        }
        $insert_stmt->bind_param("ss", $name, $name_en);
        error_log("Bound parameters - name_ko: '$name', name_en: '$name_en'");
    } else if ($has_name_ko) {
        // name_ko만 있는 경우
        if ($has_created_at) {
            $insert_sql = "INSERT INTO brands (name_ko, created_at) VALUES (?, NOW())";
        } else {
            $insert_sql = "INSERT INTO brands (name_ko) VALUES (?)";
        }
        error_log("Preparing SQL: " . $insert_sql);
        $insert_stmt = $conn->prepare($insert_sql);
        if (!$insert_stmt) {
            throw new Exception("Prepare 실패: " . $conn->error . " / SQL: " . $insert_sql);
        }
        $insert_stmt->bind_param("s", $name);
        error_log("Bound parameter - name_ko: '$name'");
    } else if ($has_name) {
        // name 컬럼이 있는 경우 (기본 스키마)
        if ($has_created_at) {
            $insert_sql = "INSERT INTO brands (name, created_at) VALUES (?, NOW())";
        } else {
            $insert_sql = "INSERT INTO brands (name) VALUES (?)";
        }
        error_log("Preparing SQL: " . $insert_sql);
        $insert_stmt = $conn->prepare($insert_sql);
        if (!$insert_stmt) {
            throw new Exception("Prepare 실패: " . $conn->error . " / SQL: " . $insert_sql);
        }
        $insert_stmt->bind_param("s", $name);
        error_log("Bound parameter - name: '$name'");
    } else {
        // 어떤 name 관련 컬럼도 없는 경우 - 에러 처리
        throw new Exception("brands 테이블에 name 관련 컬럼이 없습니다. 발견된 컬럼: " . implode(", ", $column_list));
    }
    
    error_log("Executing SQL...");
    if (!$insert_stmt->execute()) {
        throw new Exception("브랜드 추가 실패: " . $insert_stmt->error . " / SQL: " . $insert_sql);
    }
    
    $new_brand_id = $conn->insert_id;
    error_log("Brand added successfully with ID: " . $new_brand_id);
    
    $insert_stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'exists' => false,
        'message' => '새로운 브랜드가 추가되었습니다.',
        'data' => [
            'id' => $new_brand_id,
            'name' => $name
        ]
    ]);

} catch (Exception $e) {
    error_log("Brand add error: " . $e->getMessage());
    // 개발 환경에서는 상세 에러 메시지 표시
    $error_message = '브랜드 추가 중 오류가 발생했습니다.';
    // 개발 환경 체크 조건을 더 넓게 설정
    $is_dev = true; // 일단 모든 환경에서 상세 에러 표시
    if ($is_dev) {
        $error_message .= ' - ' . $e->getMessage();
    }
    echo json_encode(['success' => false, 'message' => $error_message, 'debug' => $e->getMessage()]);
}
?>