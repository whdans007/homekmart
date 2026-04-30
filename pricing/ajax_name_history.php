<?php
require_once __DIR__ . '/../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) {
    echo json_encode(['success' => false, 'message' => '상품 ID가 필요합니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $tableExists = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='product_name_history'"
    )->fetchColumn();

    if (!$tableExists) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT id, language, old_name, new_name, changed_at
         FROM product_name_history
         WHERE product_id = ?
         ORDER BY changed_at DESC
         LIMIT 100"
    );
    $stmt->execute([$product_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('pricing/ajax_name_history.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
}
