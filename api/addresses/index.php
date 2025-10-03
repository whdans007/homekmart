<?php
/**
 * 주소 목록 조회 API
 * GET /api/addresses
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
                id,
                address_name,
                house_number,
                street,
                barangay,
                city,
                province,
                postal_code,
                detailed_address,
                landmark,
                delivery_notes,
                latitude,
                longitude,
                is_default,
                is_active,
                created_at
            FROM delivery_addresses
            WHERE user_id = ? AND is_active = TRUE
            ORDER BY is_default DESC, created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $addresses = $stmt->fetchAll();

    // 타입 변환
    foreach ($addresses as &$address) {
        $address['latitude'] = $address['latitude'] ? floatval($address['latitude']) : null;
        $address['longitude'] = $address['longitude'] ? floatval($address['longitude']) : null;
        $address['is_default'] = (bool) $address['is_default'];
        $address['is_active'] = (bool) $address['is_active'];
    }

    apiSuccess($addresses);

} catch (PDOException $e) {
    error_log("Addresses fetch error: " . $e->getMessage());
    apiError(500, 'Failed to fetch addresses');
}
?>
