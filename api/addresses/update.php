<?php
/**
 * 주소 수정 API
 * PUT /api/addresses/update.php?id={address_id}&user_id={user_id}
 * 테스트용 - 인증 없음
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

$input = file_get_contents('php://input');
$data = json_decode($input, true);

try {
    $conn = get_db_connection();
    $conn->autocommit(false);

    // 주소 소유권 확인
    $check_sql = "SELECT id FROM delivery_addresses WHERE id = $address_id AND user_id = $user_id";
    $check_result = $conn->query($check_sql);

    if ($check_result->num_rows === 0) {
        throw new Exception('Address not found or access denied');
    }

    // is_default가 true인 경우, 기존 기본 주소 해제
    if (isset($data['is_default']) && $data['is_default'] === true) {
        $update_default_sql = "UPDATE delivery_addresses SET is_default = 0 WHERE user_id = $user_id AND id != $address_id";
        $conn->query($update_default_sql);
    }

    // 업데이트할 필드 구성
    $update_fields = [];
    $allowed_fields = [
        'address_name', 'house_number', 'street', 'barangay', 'city', 'province',
        'postal_code', 'detailed_address', 'landmark', 'delivery_notes',
        'latitude', 'longitude', 'is_default'
    ];

    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $value = $data[$field];

            if ($value === null) {
                $update_fields[] = "$field = NULL";
            } elseif ($field === 'is_default') {
                $update_fields[] = "$field = " . ($value ? 1 : 0);
            } elseif ($field === 'latitude' || $field === 'longitude') {
                $update_fields[] = "$field = " . floatval($value);
            } else {
                $escaped_value = $conn->real_escape_string($value);
                $update_fields[] = "$field = '$escaped_value'";
            }
        }
    }

    if (empty($update_fields)) {
        throw new Exception('No fields to update');
    }

    // 주소 업데이트
    $update_sql = "UPDATE delivery_addresses SET " . implode(', ', $update_fields) . " WHERE id = $address_id";

    if (!$conn->query($update_sql)) {
        throw new Exception('Failed to update address: ' . $conn->error);
    }

    // 기본 주소 변경 시 users 테이블 업데이트
    if (isset($data['is_default']) && $data['is_default'] === true) {
        $user_update_sql = "UPDATE users SET default_delivery_address_id = $address_id WHERE id = $user_id";
        $conn->query($user_update_sql);
    }

    $conn->commit();

    // 업데이트된 주소 조회
    $select_sql = "
        SELECT id, address_name, house_number, street, barangay, city, province,
               postal_code, detailed_address, landmark, delivery_notes,
               latitude, longitude, is_default, is_active, created_at, updated_at
        FROM delivery_addresses
        WHERE id = $address_id
    ";

    $result = $conn->query($select_sql);
    $address = $result->fetch_assoc();

    echo json_encode([
        'success' => true,
        'message' => 'Address updated successfully',
        'data' => [
            'id' => intval($address['id']),
            'address_name' => $address['address_name'],
            'house_number' => $address['house_number'],
            'street' => $address['street'],
            'barangay' => $address['barangay'],
            'city' => $address['city'],
            'province' => $address['province'],
            'postal_code' => $address['postal_code'],
            'detailed_address' => $address['detailed_address'],
            'landmark' => $address['landmark'],
            'delivery_notes' => $address['delivery_notes'],
            'latitude' => $address['latitude'] ? floatval($address['latitude']) : null,
            'longitude' => $address['longitude'] ? floatval($address['longitude']) : null,
            'is_default' => (bool)$address['is_default'],
            'is_active' => (bool)$address['is_active'],
            'created_at' => $address['created_at'],
            'updated_at' => $address['updated_at']
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Address update error: " . $e->getMessage());
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
