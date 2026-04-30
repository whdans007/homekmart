<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

if (!has_permission('product_management') && !has_permission('shop_access')) {
    echo json_encode(['success' => false, 'error' => '권한이 없습니다.']);
    exit;
}

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : (isset($_SESSION['store_id']) ? $_SESSION['store_id'] : 0);

if (!$product_id || !$store_id) {
    echo json_encode(['success' => false, 'error' => '필수 파라미터 누락']);
    exit;
}

try {
    $conn = get_db_connection();

    $stmt = $conn->prepare("
        SELECT id, expiration_date, quantity, updated_at 
        FROM inventory_expirations 
        WHERE product_id = ? AND store_id = ? 
        ORDER BY expiration_date ASC
    ");
    $stmt->bind_param("ii", $product_id, $store_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $lots = [];
    while ($row = $result->fetch_assoc()) {
        $lots[] = $row;
    }
    
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'data' => $lots]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
