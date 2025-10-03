<?php
/**
 * 프로필 조회 API
 * GET /api/auth/profile
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

try {
    $pdo = getApiDbConnection();

    $sql = "SELECT
                u.id,
                u.username,
                u.email,
                u.full_name,
                u.phone,
                u.role,
                u.auth_provider,
                u.profile_image_url,
                u.preferred_language,
                u.phone_verified,
                u.is_delivery_available,
                u.default_delivery_address_id,
                u.created_at,
                da.address_name as default_address_name,
                da.city as default_city
            FROM users u
            LEFT JOIN delivery_addresses da ON u.default_delivery_address_id = da.id
            WHERE u.id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        apiError(404, 'User not found');
    }

    apiSuccess($user);

} catch (PDOException $e) {
    error_log("Profile fetch error: " . $e->getMessage());
    apiError(500, 'Failed to fetch profile');
}
?>
