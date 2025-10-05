<?php
/**
 * 주소 삭제 API (소프트 삭제)
 * DELETE /api/addresses/delete.php?id={address_id}&user_id={user_id}
 * 테스트용 - 인증 없음
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_GET['id']) || !isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'id and user_id are required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$address_id = intval($_GET['id']);
$user_id = intval($_GET['user_id']);

try {
    $conn = get_db_connection();
    $conn->autocommit(false);

    // 주소 소유권 및 존재 확인
    $check_sql = "SELECT is_default FROM delivery_addresses WHERE id = $address_id AND user_id = $user_id";
    $check_result = $conn->query($check_sql);

    if ($check_result->num_rows === 0) {
        throw new Exception('Address not found or access denied');
    }

    $address = $check_result->fetch_assoc();

    // 기본 주소인 경우 users 테이블의 참조 해제
    if ($address['is_default']) {
        $user_update_sql = "UPDATE users SET default_delivery_address_id = NULL WHERE id = $user_id";
        $conn->query($user_update_sql);
    }

    // 소프트 삭제 (is_active = FALSE)
    $delete_sql = "UPDATE delivery_addresses SET is_active = 0 WHERE id = $address_id";

    if (!$conn->query($delete_sql)) {
        throw new Exception('Failed to delete address: ' . $conn->error);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Address deleted successfully'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Address delete error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => $e->getMessage()]
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
