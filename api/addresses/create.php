<?php
/**
 * 주소 추가 API
 * POST /api/addresses
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

$data = getRequestBody();

// 필수 필드 검증
validateRequired($data, ['address_name', 'street', 'barangay', 'city', 'province']);

try {
    $pdo = getApiDbConnection();
    $pdo->beginTransaction();

    // is_default가 true인 경우, 기존 기본 주소 해제
    if (isset($data['is_default']) && $data['is_default'] === true) {
        $update_sql = "UPDATE delivery_addresses SET is_default = FALSE WHERE user_id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$user_id]);
    }

    // 새 주소 추가
    $insert_sql = "
        INSERT INTO delivery_addresses (
            user_id, address_name, house_number, street, barangay,
            city, province, postal_code, detailed_address, landmark,
            delivery_notes, latitude, longitude, is_default, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)
    ";

    $stmt = $pdo->prepare($insert_sql);
    $stmt->execute([
        $user_id,
        $data['address_name'],
        $data['house_number'] ?? null,
        $data['street'],
        $data['barangay'],
        $data['city'],
        $data['province'],
        $data['postal_code'] ?? null,
        $data['detailed_address'] ?? null,
        $data['landmark'] ?? null,
        $data['delivery_notes'] ?? null,
        $data['latitude'] ?? null,
        $data['longitude'] ?? null,
        isset($data['is_default']) && $data['is_default'] ? 1 : 0
    ]);

    $address_id = $pdo->lastInsertId();

    // 사용자의 기본 배달 주소 업데이트 (is_default가 true인 경우)
    if (isset($data['is_default']) && $data['is_default'] === true) {
        $user_update_sql = "UPDATE users SET default_delivery_address_id = ? WHERE id = ?";
        $user_stmt = $pdo->prepare($user_update_sql);
        $user_stmt->execute([$address_id, $user_id]);
    }

    // 생성된 주소 조회
    $select_sql = "SELECT * FROM delivery_addresses WHERE id = ?";
    $select_stmt = $pdo->prepare($select_sql);
    $select_stmt->execute([$address_id]);
    $address = $select_stmt->fetch();

    $pdo->commit();

    apiSuccess($address, 'Address added successfully');

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Address create error: " . $e->getMessage());
    apiError(500, 'Failed to add address');
}
?>
