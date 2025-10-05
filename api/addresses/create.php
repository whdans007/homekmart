<?php
/**
 * 주소 생성 API
 * POST /api/addresses/create.php
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

$input = file_get_contents('php://input');
$data = json_decode($input, true);

// 필수 필드 검증
if (!isset($data['user_id']) || !isset($data['address_name']) ||
    !isset($data['street']) || !isset($data['barangay']) ||
    !isset($data['city']) || !isset($data['province'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'Required fields: user_id, address_name, street, barangay, city, province']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$user_id = intval($data['user_id']);
$address_name = $data['address_name'];
$house_number = $data['house_number'] ?? null;
$street = $data['street'];
$barangay = $data['barangay'];
$city = $data['city'];
$province = $data['province'];
$postal_code = $data['postal_code'] ?? null;
$detailed_address = $data['detailed_address'] ?? null;
$landmark = $data['landmark'] ?? null;
$delivery_notes = $data['delivery_notes'] ?? null;
$latitude = isset($data['latitude']) ? floatval($data['latitude']) : null;
$longitude = isset($data['longitude']) ? floatval($data['longitude']) : null;
$is_default = isset($data['is_default']) ? (bool)$data['is_default'] : false;

try {
    $conn = get_db_connection();
    $conn->autocommit(false);

    // 기본 주소로 설정하는 경우, 기존 기본 주소를 해제
    if ($is_default) {
        $unset_default_sql = "UPDATE delivery_addresses SET is_default = 0 WHERE user_id = $user_id";
        $conn->query($unset_default_sql);
    }

    // Escape strings for SQL
    $address_name_escaped = $conn->real_escape_string($address_name);
    $house_number_escaped = $house_number ? "'" . $conn->real_escape_string($house_number) . "'" : 'NULL';
    $street_escaped = $conn->real_escape_string($street);
    $barangay_escaped = $conn->real_escape_string($barangay);
    $city_escaped = $conn->real_escape_string($city);
    $province_escaped = $conn->real_escape_string($province);
    $postal_code_escaped = $postal_code ? "'" . $conn->real_escape_string($postal_code) . "'" : 'NULL';
    $detailed_address_escaped = $detailed_address ? "'" . $conn->real_escape_string($detailed_address) . "'" : 'NULL';
    $landmark_escaped = $landmark ? "'" . $conn->real_escape_string($landmark) . "'" : 'NULL';
    $delivery_notes_escaped = $delivery_notes ? "'" . $conn->real_escape_string($delivery_notes) . "'" : 'NULL';
    $latitude_escaped = $latitude !== null ? $latitude : 'NULL';
    $longitude_escaped = $longitude !== null ? $longitude : 'NULL';
    $is_default_int = $is_default ? 1 : 0;

    // 주소 생성
    $insert_sql = "
        INSERT INTO delivery_addresses (
            user_id, address_name, house_number, street, barangay, city, province,
            postal_code, detailed_address, landmark, delivery_notes,
            latitude, longitude, is_default, is_active
        ) VALUES (
            $user_id, '$address_name_escaped', $house_number_escaped, '$street_escaped',
            '$barangay_escaped', '$city_escaped', '$province_escaped',
            $postal_code_escaped, $detailed_address_escaped, $landmark_escaped, $delivery_notes_escaped,
            $latitude_escaped, $longitude_escaped, $is_default_int, 1
        )
    ";

    if (!$conn->query($insert_sql)) {
        throw new Exception('Failed to create address: ' . $conn->error);
    }

    $address_id = $conn->insert_id;

    // 기본 주소인 경우 users 테이블 업데이트
    if ($is_default) {
        $user_update_sql = "UPDATE users SET default_delivery_address_id = $address_id WHERE id = $user_id";
        $conn->query($user_update_sql);
    }

    $conn->commit();

    // 생성된 주소 조회
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
        'message' => 'Address created successfully',
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
    error_log("Address creation error: " . $e->getMessage());
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
