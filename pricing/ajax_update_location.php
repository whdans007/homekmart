<?php
// 상품 위치 정보 저장 (점포별)
require_once __DIR__ . '/../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청']);
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$store_id   = (int)($_POST['store_id'] ?? 0);
$location   = trim($_POST['location'] ?? '');

if (!$product_id || !$store_id) {
    echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        store_id INT NOT NULL,
        location VARCHAR(100) NOT NULL DEFAULT '',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_product_store (product_id, store_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($location === '') {
        $pdo->prepare("DELETE FROM product_locations WHERE product_id = ? AND store_id = ?")
            ->execute([$product_id, $store_id]);
    } else {
        $pdo->prepare("INSERT INTO product_locations (product_id, store_id, location, updated_at)
                       VALUES (?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE location = VALUES(location), updated_at = NOW()")
            ->execute([$product_id, $store_id, $location]);
    }

    echo json_encode(['success' => true, 'location' => $location]);
} catch (Throwable $e) {
    error_log('pricing/ajax_update_location.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '오류가 발생했습니다.']);
}
