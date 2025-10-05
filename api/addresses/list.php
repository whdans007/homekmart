<?php
/**
 * 주소 목록 조회 API
 * GET /api/addresses/list.php?user_id={user_id}
 * 테스트용 - 인증 없음
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'user_id is required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$user_id = intval($_GET['user_id']);

try {
    $conn = get_db_connection();

    $sql = "
        SELECT
            id, address_name, house_number, street, barangay, city, province,
            postal_code, detailed_address, landmark, delivery_notes,
            latitude, longitude, is_default, is_active, created_at, updated_at
        FROM delivery_addresses
        WHERE user_id = $user_id AND is_active = 1
        ORDER BY is_default DESC, created_at DESC
    ";

    $result = $conn->query($sql);

    $addresses = [];
    while ($row = $result->fetch_assoc()) {
        $addresses[] = [
            'id' => intval($row['id']),
            'address_name' => $row['address_name'],
            'house_number' => $row['house_number'],
            'street' => $row['street'],
            'barangay' => $row['barangay'],
            'city' => $row['city'],
            'province' => $row['province'],
            'postal_code' => $row['postal_code'],
            'detailed_address' => $row['detailed_address'],
            'landmark' => $row['landmark'],
            'delivery_notes' => $row['delivery_notes'],
            'latitude' => $row['latitude'] ? floatval($row['latitude']) : null,
            'longitude' => $row['longitude'] ? floatval($row['longitude']) : null,
            'is_default' => (bool)$row['is_default'],
            'is_active' => (bool)$row['is_active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $addresses,
        'count' => count($addresses)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Addresses fetch error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'Failed to fetch addresses']
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
