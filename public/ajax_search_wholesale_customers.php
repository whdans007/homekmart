<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if (!has_permission('wholesale_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

$search_term = $_GET['q'] ?? $_POST['q'] ?? '';
$limit = min(20, (int)($_GET['limit'] ?? $_POST['limit'] ?? 10));

if (empty($search_term)) {
    echo json_encode(['success' => false, 'message' => '검색어를 입력해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "
        SELECT id, name, phone, address
        FROM wholesale_customers 
        WHERE is_active = 1 
        AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)
        ORDER BY name ASC
        LIMIT ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $search_pattern = "%{$search_term}%";
    $stmt->execute([$search_pattern, $search_pattern, $search_pattern, $limit]);
    
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'customers' => $customers
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => '데이터베이스 오류: ' . $e->getMessage()
    ]);
}