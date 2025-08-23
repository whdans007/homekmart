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
    error_log("[PRODUCT_NAME_UPDATE] " . $message);
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

debugLog("Starting product name update process");

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
$language = $_POST['language'] ?? '';
$product_name = trim($_POST['product_name'] ?? '');

debugLog("Parameters - product_id: $product_id, language: $language, product_name: $product_name");

if (empty($product_id) || empty($language) || empty($product_name)) {
    debugLog("Missing required parameters");
    sendResponse(false, '필수 정보가 누락되었습니다.');
}

// 언어 코드 검증
if (!in_array($language, ['en', 'ko'])) {
    sendResponse(false, '올바르지 않은 언어 코드입니다.');
}

// 상품명 길이 검증
if (strlen($product_name) > 255) {
    sendResponse(false, '상품명은 255자 이내로 입력해주세요.');
}

// XSS 방지를 위한 특수문자 필터링
$product_name = htmlspecialchars($product_name, ENT_QUOTES, 'UTF-8');

try {
    debugLog("Attempting database connection");
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    debugLog("Database connected successfully");
    
    // deleted_at 컬럼 존재 여부 확인
    debugLog("Checking for deleted_at column");
    $deleted_at_check = $conn->query("SHOW COLUMNS FROM products LIKE 'deleted_at'");
    $has_deleted_at = $deleted_at_check && $deleted_at_check->num_rows > 0;
    debugLog("deleted_at column exists: " . ($has_deleted_at ? 'yes' : 'no'));
    
    // 상품 존재 여부 확인 쿼리 구성
    if ($has_deleted_at) {
        $check_sql = "SELECT id, name_en, name_ko FROM products WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    } else {
        $check_sql = "SELECT id, name_en, name_ko FROM products WHERE id = ?";
    }
    
    debugLog("Preparing product existence check query: " . $check_sql);
    $check_stmt = $conn->prepare($check_sql);
    
    if (!$check_stmt) {
        throw new Exception("쿼리 준비 실패: " . $conn->error);
    }
    
    debugLog("Query prepared successfully");
    $check_stmt->bind_param("i", $product_id);
    
    debugLog("Executing product check query");
    if (!$check_stmt->execute()) {
        throw new Exception("쿼리 실행 실패: " . $check_stmt->error);
    }
    
    debugLog("Query executed successfully");
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        sendResponse(false, '존재하지 않는 상품입니다.');
    }
    
    $product = $check_result->fetch_assoc();
    $check_stmt->close();
    
    debugLog("Product found - ID: {$product['id']}, name_en: '{$product['name_en']}', name_ko: '{$product['name_ko']}'");
    
    // 현재 상품명과 동일한지 확인
    $current_name = $language === 'en' ? $product['name_en'] : $product['name_ko'];
    debugLog("Current name ($language): '$current_name', New name: '$product_name'");
    
    if ($current_name === $product_name) {
        debugLog("No change detected - names are identical");
        sendResponse(false, '변경된 내용이 없습니다.');
    }
    
    // 상품명 업데이트
    debugLog("Starting product name update");
    $column_name = $language === 'en' ? 'name_en' : 'name_ko';
    debugLog("Updating column: $column_name");
    
    // 컬럼 존재 여부 확인
    $updated_at_check = $conn->query("SHOW COLUMNS FROM products LIKE 'updated_at'");
    $has_updated_at = $updated_at_check && $updated_at_check->num_rows > 0;
    debugLog("updated_at column exists: " . ($has_updated_at ? 'yes' : 'no'));
    
    $last_modified_by_check = $conn->query("SHOW COLUMNS FROM products LIKE 'last_modified_by'");
    $has_last_modified_by = $last_modified_by_check && $last_modified_by_check->num_rows > 0;
    debugLog("last_modified_by column exists: " . ($has_last_modified_by ? 'yes' : 'no'));
    
    // 업데이트 쿼리 구성
    $update_sql = "UPDATE products SET {$column_name} = ?";
    
    if ($has_updated_at) {
        $update_sql .= ", updated_at = NOW()";
    }
    
    if ($has_last_modified_by) {
        $update_sql .= ", last_modified_by = ?";
        $update_stmt = $conn->prepare($update_sql . " WHERE id = ?");
        if (!$update_stmt) {
            throw new Exception("업데이트 쿼리 준비 실패 (with last_modified_by): " . $conn->error);
        }
        $user_id = $_SESSION['user_id'];
        debugLog("Binding parameters with user_id: $user_id");
        $update_stmt->bind_param("sii", $product_name, $user_id, $product_id);
    } else {
        $update_stmt = $conn->prepare($update_sql . " WHERE id = ?");
        if (!$update_stmt) {
            throw new Exception("업데이트 쿼리 준비 실패: " . $conn->error);
        }
        debugLog("Binding parameters without user_id");
        $update_stmt->bind_param("si", $product_name, $product_id);
    }
    
    debugLog("Executing update query: $update_sql");
    if (!$update_stmt->execute()) {
        throw new Exception("상품명 업데이트 실행 실패: " . $update_stmt->error);
    }
    
    debugLog("Update executed, affected rows: " . $update_stmt->affected_rows);
    
    if ($update_stmt->affected_rows === 0) {
        debugLog("No rows affected - possibly no change or invalid ID");
        sendResponse(false, '상품명 업데이트에 실패했습니다. (영향받은 행이 없음)');
    }
    
    $update_stmt->close();
    $conn->close();
    
    // 성공 응답
    $language_text = $language === 'en' ? '영어' : '한글';
    sendResponse(true, "{$language_text} 상품명이 성공적으로 업데이트되었습니다.", [
        'product_id' => $product_id,
        'language' => $language,
        'new_name' => $product_name
    ]);
    
} catch (Exception $e) {
    $errorMessage = "Product name update error: " . $e->getMessage() . " (File: " . $e->getFile() . ", Line: " . $e->getLine() . ")";
    error_log($errorMessage);
    debugLog($errorMessage);
    
    // 개발 환경에서는 구체적인 오류 메시지 표시
    sendResponse(false, '데이터베이스 오류: ' . $e->getMessage());
}
?>