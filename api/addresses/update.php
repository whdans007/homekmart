<?php
/**
 * 주소 수정 API
 * PUT /api/addresses?id={address_id}
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

if (!isset($_GET['id'])) {
    apiError(400, 'Address ID is required');
}

$address_id = intval($_GET['id']);
$data = getRequestBody();

try {
    $pdo = getApiDbConnection();
    $pdo->beginTransaction();

    // 주소 소유권 확인
    $check_sql = "SELECT id FROM delivery_addresses WHERE id = ? AND user_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$address_id, $user_id]);

    if (!$check_stmt->fetch()) {
        $pdo->rollBack();
        apiError(404, 'Address not found or access denied');
    }

    // is_default가 true인 경우, 기존 기본 주소 해제
    if (isset($data['is_default']) && $data['is_default'] === true) {
        $update_default_sql = "UPDATE delivery_addresses SET is_default = FALSE WHERE user_id = ? AND id != ?";
        $update_default_stmt = $pdo->prepare($update_default_sql);
        $update_default_stmt->execute([$user_id, $address_id]);
    }

    // 업데이트할 필드 구성
    $update_fields = [];
    $update_params = [];

    $allowed_fields = [
        'address_name', 'house_number', 'street', 'barangay', 'city', 'province',
        'postal_code', 'detailed_address', 'landmark', 'delivery_notes',
        'latitude', 'longitude', 'is_default'
    ];

    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $update_fields[] = "$field = ?";
            $update_params[] = $data[$field];
        }
    }

    if (empty($update_fields)) {
        $pdo->rollBack();
        apiError(400, 'No fields to update');
    }

    // 주소 업데이트
    $update_sql = "UPDATE delivery_addresses SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $update_params[] = $address_id;

    $stmt = $pdo->prepare($update_sql);
    $stmt->execute($update_params);

    // 기본 주소 변경 시 users 테이블 업데이트
    if (isset($data['is_default']) && $data['is_default'] === true) {
        $user_update_sql = "UPDATE users SET default_delivery_address_id = ? WHERE id = ?";
        $user_stmt = $pdo->prepare($user_update_sql);
        $user_stmt->execute([$address_id, $user_id]);
    }

    // 업데이트된 주소 조회
    $select_sql = "SELECT * FROM delivery_addresses WHERE id = ?";
    $select_stmt = $pdo->prepare($select_sql);
    $select_stmt->execute([$address_id]);
    $address = $select_stmt->fetch();

    $pdo->commit();

    apiSuccess($address, 'Address updated successfully');

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Address update error: " . $e->getMessage());
    apiError(500, 'Failed to update address');
}
?>
