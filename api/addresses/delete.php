<?php
/**
 * 주소 삭제 API (소프트 삭제)
 * DELETE /api/addresses?id={address_id}
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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

    // 주소 소유권 및 존재 확인
    $check_sql = "SELECT is_default FROM delivery_addresses WHERE id = ? AND user_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$address_id, $user_id]);
    $address = $check_stmt->fetch();

    if (!$address) {
        $pdo->rollBack();
        apiError(404, 'Address not found or access denied');
    }

    // 기본 주소인 경우 users 테이블의 참조 해제
    if ($address['is_default']) {
        $user_update_sql = "UPDATE users SET default_delivery_address_id = NULL WHERE id = ?";
        $user_stmt = $pdo->prepare($user_update_sql);
        $user_stmt->execute([$user_id]);
    }

    // 소프트 삭제 (is_active = FALSE)
    $delete_sql = "UPDATE delivery_addresses SET is_active = FALSE WHERE id = ?";
    $delete_stmt = $pdo->prepare($delete_sql);
    $delete_stmt->execute([$address_id]);

    $pdo->commit();

    apiSuccess(null, 'Address deleted successfully');

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Address delete error: " . $e->getMessage());
    apiError(500, 'Failed to delete address');
}
?>
