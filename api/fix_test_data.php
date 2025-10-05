<?php
/**
 * 테스트 데이터 수정
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

$results = [];

// 1. User 13의 store_id를 1로 설정
$update_user = $conn->query("UPDATE users SET store_id = 1 WHERE id = 13");
$results['update_user'] = $update_user ? 'success' : 'failed: ' . $conn->error;

// 2. 배달 주소의 user_id 확인 및 수정
$check_address = $conn->query("SELECT id, user_id FROM delivery_addresses WHERE id = 1");
if ($check_address && $check_address->num_rows > 0) {
    $addr = $check_address->fetch_assoc();
    if ($addr['user_id'] != 13) {
        $update_address = $conn->query("UPDATE delivery_addresses SET user_id = 13 WHERE id = 1");
        $results['update_address'] = $update_address ? 'updated to user 13' : 'failed: ' . $conn->error;
    } else {
        $results['update_address'] = 'already correct';
    }
} else {
    $results['update_address'] = 'address not found';
}

// 3. 검증
$verify_user = $conn->query("SELECT id, username, store_id FROM users WHERE id = 13");
$verify_address = $conn->query("SELECT id, user_id, address_name FROM delivery_addresses WHERE id = 1");

$results['verification'] = [
    'user' => $verify_user ? $verify_user->fetch_assoc() : null,
    'address' => $verify_address ? $verify_address->fetch_assoc() : null
];

echo json_encode([
    'success' => true,
    'results' => $results
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$conn->close();
?>
