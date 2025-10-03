<?php
/**
 * 기본 주소 설정 API
 * POST /api/addresses/set-default?id={address_id}
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

if (!isset($_GET['id'])) {
    apiError(400, 'Address ID is required');
}

$address_id = intval($_GET['id']);

try {
    $pdo = getApiDbConnection();
    $pdo->beginTransaction();

    // 주소 소유권 확인
    $check_sql = "SELECT id FROM delivery_addresses WHERE id = ? AND user_id = ? AND is_active = TRUE";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$address_id, $user_id]);

    if (!$check_stmt->fetch()) {
        $pdo->rollBack();
        apiError(404, 'Address not found or access denied');
    }

    // 기존 기본 주소 해제
    $reset_sql = "UPDATE delivery_addresses SET is_default = FALSE WHERE user_id = ?";
    $reset_stmt = $pdo->prepare($reset_sql);
    $reset_stmt->execute([$user_id]);

    // 새로운 기본 주소 설정
    $update_sql = "UPDATE delivery_addresses SET is_default = TRUE WHERE id = ?";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute([$address_id]);

    // users 테이블의 기본 주소 업데이트
    $user_update_sql = "UPDATE users SET default_delivery_address_id = ? WHERE id = ?";
    $user_stmt = $pdo->prepare($user_update_sql);
    $user_stmt->execute([$address_id, $user_id]);

    $pdo->commit();

    apiSuccess(['address_id' => $address_id], 'Default address updated successfully');

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Set default address error: " . $e->getMessage());
    apiError(500, 'Failed to set default address');
}
?>
