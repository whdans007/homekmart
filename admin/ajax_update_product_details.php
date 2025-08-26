<?php
// 디버깅을 위한 에러 리포팅 활성화
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';

// JSON 응답 헤더 설정
header('Content-Type: application/json');

// 디버깅을 위한 로그 함수
function debugLog($message) {
    error_log("[PRODUCT_DETAILS_UPDATE] " . $message);
}

// 응답 함수
function sendResponse($success, $message, $data = null) {
    debugLog("Response: " . ($success ? 'SUCCESS' : 'ERROR') . " - " . $message);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

debugLog("Starting product details update process");

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    debugLog("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    sendResponse(false, 'Invalid request method');
}

debugLog("POST request received");

// 로그인 확인
if (!is_logged_in()) {
    debugLog("User not logged in");
    sendResponse(false, '로그인이 필요합니다.');
}

debugLog("User is logged in, user_id: " . ($_SESSION['user_id'] ?? 'unknown'));

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    debugLog("User lacks purchase_management permission");
    sendResponse(false, '권한이 없습니다.');
}

debugLog("User has purchase_management permission");

// 필수 파라미터 확인
$product_id = $_POST['product_id'] ?? '';

debugLog("Product ID: $product_id");

if (empty($product_id)) {
    debugLog("Missing product_id");
    sendResponse(false, '상품 ID가 누락되었습니다.');
}

// 업데이트할 필드들 수집
$update_fields = [];
$params = [];
$param_types = '';

// 각 필드 처리
// 영어 이름
if (isset($_POST['name_en'])) {
    $name_en = trim($_POST['name_en']);
    if (!empty($name_en)) {
        $update_fields[] = "name_en = ?";
        $params[] = $name_en;
        $param_types .= 's';
        debugLog("Updating name_en: $name_en");
    }
}

// 한글 이름
if (isset($_POST['name_ko'])) {
    $name_ko = trim($_POST['name_ko']);
    if (!empty($name_ko)) {
        $update_fields[] = "name_ko = ?";
        $params[] = $name_ko;
        $param_types .= 's';
        debugLog("Updating name_ko: $name_ko");
    }
}

// 바코드
if (isset($_POST['barcode'])) {
    $barcode = trim($_POST['barcode']);
    $update_fields[] = "barcode = ?";
    $params[] = $barcode ?: null;
    $param_types .= 's';
    debugLog("Updating barcode: " . ($barcode ?: 'null'));
}

// 브랜드
if (isset($_POST['brand_id'])) {
    $brand_id = $_POST['brand_id'];
    if ($brand_id === '' || $brand_id === 'null') {
        $update_fields[] = "brand_id = NULL";
        debugLog("Setting brand_id to NULL");
    } else {
        $update_fields[] = "brand_id = ?";
        $params[] = intval($brand_id);
        $param_types .= 'i';
        debugLog("Updating brand_id: $brand_id");
    }
}

// 카테고리
if (isset($_POST['category_id'])) {
    $category_id = $_POST['category_id'];
    if ($category_id === '' || $category_id === 'null') {
        $update_fields[] = "category_id = NULL";
        debugLog("Setting category_id to NULL");
    } else {
        $update_fields[] = "category_id = ?";
        $params[] = intval($category_id);
        $param_types .= 'i';
        debugLog("Updating category_id: $category_id");
    }
}

// 박스당 수량
if (isset($_POST['pieces_per_box'])) {
    $pieces_per_box = intval($_POST['pieces_per_box']);
    if ($pieces_per_box > 0) {
        $update_fields[] = "pieces_per_box = ?";
        $params[] = $pieces_per_box;
        $param_types .= 'i';
        debugLog("Updating pieces_per_box: $pieces_per_box");
    }
}

// 설명
if (isset($_POST['description'])) {
    $description = trim($_POST['description']);
    $update_fields[] = "description = ?";
    $params[] = $description ?: null;
    $param_types .= 's';
    debugLog("Updating description: " . ($description ? substr($description, 0, 50) . '...' : 'null'));
}

// 상태
if (isset($_POST['is_active'])) {
    $is_active = intval($_POST['is_active']);
    $update_fields[] = "is_active = ?";
    $params[] = $is_active;
    $param_types .= 'i';
    debugLog("Updating is_active: $is_active");
}

// 업데이트할 필드가 없는 경우
if (empty($update_fields)) {
    debugLog("No fields to update");
    sendResponse(false, '업데이트할 필드가 없습니다.');
}

try {
    debugLog("Attempting database connection");
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    debugLog("Database connected successfully");
    
    // 트랜잭션 시작
    $conn->autocommit(false);
    debugLog("Transaction started");
    
    // 상품 존재 여부 확인
    $check_sql = "SELECT id FROM products WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if (!$check_stmt) {
        throw new Exception("쿼리 준비 실패: " . $conn->error);
    }
    
    $check_stmt->bind_param("i", $product_id);
    
    if (!$check_stmt->execute()) {
        throw new Exception("쿼리 실행 실패: " . $check_stmt->error);
    }
    
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $check_stmt->close();
        throw new Exception("존재하지 않는 상품입니다.");
    }
    
    $check_stmt->close();
    debugLog("Product exists, proceeding with update");
    
    // updated_at과 last_modified_by 필드 추가
    $updated_at_check = $conn->query("SHOW COLUMNS FROM products LIKE 'updated_at'");
    $has_updated_at = $updated_at_check && $updated_at_check->num_rows > 0;
    
    $last_modified_by_check = $conn->query("SHOW COLUMNS FROM products LIKE 'last_modified_by'");
    $has_last_modified_by = $last_modified_by_check && $last_modified_by_check->num_rows > 0;
    
    if ($has_updated_at) {
        $update_fields[] = "updated_at = NOW()";
        debugLog("Adding updated_at field");
    }
    
    if ($has_last_modified_by) {
        $update_fields[] = "last_modified_by = ?";
        $params[] = $_SESSION['user_id'];
        $param_types .= 'i';
        debugLog("Adding last_modified_by field");
    }
    
    // 업데이트 쿼리 구성
    $update_sql = "UPDATE products SET " . implode(", ", $update_fields) . " WHERE id = ?";
    $params[] = $product_id;
    $param_types .= 'i';
    
    debugLog("Update SQL: $update_sql");
    debugLog("Param types: $param_types");
    debugLog("Param count: " . count($params));
    
    $update_stmt = $conn->prepare($update_sql);
    
    if (!$update_stmt) {
        throw new Exception("업데이트 쿼리 준비 실패: " . $conn->error);
    }
    
    // 파라미터 바인딩
    if (!empty($params)) {
        $bind_params = array($param_types);
        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        call_user_func_array(array($update_stmt, 'bind_param'), $bind_params);
    }
    
    debugLog("Executing update query");
    if (!$update_stmt->execute()) {
        throw new Exception("상품 정보 업데이트 실행 실패: " . $update_stmt->error);
    }
    
    debugLog("Update executed, affected rows: " . $update_stmt->affected_rows);
    
    $update_stmt->close();
    
    // 업데이트된 상품 정보 가져오기
    $fetch_sql = "
        SELECT 
            p.*,
            b.name_ko as brand_name_ko,
            c.name as category_name
        FROM products p
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.id = ?
    ";
    
    $fetch_stmt = $conn->prepare($fetch_sql);
    if (!$fetch_stmt) {
        throw new Exception("조회 쿼리 준비 실패: " . $conn->error);
    }
    
    $fetch_stmt->bind_param("i", $product_id);
    
    if (!$fetch_stmt->execute()) {
        throw new Exception("조회 쿼리 실행 실패: " . $fetch_stmt->error);
    }
    
    $result = $fetch_stmt->get_result();
    $updated_product = $result->fetch_assoc();
    $fetch_stmt->close();
    
    // 트랜잭션 커밋
    $conn->commit();
    debugLog("Transaction committed successfully");
    
    $conn->close();
    
    // 성공 응답
    sendResponse(true, "상품 정보가 성공적으로 업데이트되었습니다.", $updated_product);
    
} catch (Exception $e) {
    // 트랜잭션 롤백
    if (isset($conn) && $conn) {
        $conn->rollback();
        $conn->close();
    }
    
    $errorMessage = "Product details update error: " . $e->getMessage() . " (File: " . $e->getFile() . ", Line: " . $e->getLine() . ")";
    error_log($errorMessage);
    debugLog($errorMessage);
    
    sendResponse(false, $e->getMessage());
}
?>