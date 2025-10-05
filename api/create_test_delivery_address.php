<?php
/**
 * 테스트용 배달 주소 생성
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

// 기존 배달 주소가 있는지 확인
$check_sql = "SELECT id FROM delivery_addresses WHERE user_id = 1 LIMIT 1";
$check_result = $conn->query($check_sql);

if ($check_result->num_rows > 0) {
    $existing = $check_result->fetch_assoc();
    echo json_encode([
        'success' => true,
        'message' => 'Delivery address already exists',
        'address_id' => intval($existing['id'])
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// 새 배달 주소 생성
$insert_sql = "
    INSERT INTO delivery_addresses (
        user_id, address_name, house_number, street, barangay, city, province,
        postal_code, detailed_address, landmark, is_default, is_active
    ) VALUES (
        1, 'Home', '123', 'Main Street', 'Barangay 1', 'Makati', 'Metro Manila',
        '1200', 'Near the big mall', 'SM Makati', 1, 1
    )
";

if ($conn->query($insert_sql)) {
    $address_id = $conn->insert_id;

    echo json_encode([
        'success' => true,
        'message' => 'Delivery address created successfully',
        'address_id' => $address_id
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'Failed to create delivery address: ' . $conn->error]
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>
