<?php
/**
 * 기본 주소 설정 API
 * POST /api/addresses/set-default.php?id={address_id}&user_id={user_id}
 * 테스트용 - 인증 없음
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

    // 주소 소유권 확인
    $check_sql = "SELECT id FROM delivery_addresses WHERE id = $address_id AND user_id = $user_id AND is_active = 1";
    $check_result = $conn->query($check_sql);

    if ($check_result->num_rows === 0) {
        throw new Exception('Address not found or access denied');
    }

    // 기존 기본 주소 해제
    $reset_sql = "UPDATE delivery_addresses SET is_default = 0 WHERE user_id = $user_id";
    if (!$conn->query($reset_sql)) {
        throw new Exception('Failed to reset default addresses: ' . $conn->error);
    }

    // 새로운 기본 주소 설정
    $update_sql = "UPDATE delivery_addresses SET is_default = 1 WHERE id = $address_id";
    if (!$conn->query($update_sql)) {
        throw new Exception('Failed to set default address: ' . $conn->error);
    }

    // users 테이블의 기본 주소 업데이트
    $user_update_sql = "UPDATE users SET default_delivery_address_id = $address_id WHERE id = $user_id";
    if (!$conn->query($user_update_sql)) {
        throw new Exception('Failed to update user default address: ' . $conn->error);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Default address updated successfully',
        'data' => ['address_id' => $address_id]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Set default address error: " . $e->getMessage());
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
