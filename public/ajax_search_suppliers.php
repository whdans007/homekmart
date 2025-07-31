<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// 세션 검증
if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo json_encode(['error' => '접근 권한이 없습니다.']);
    exit;
}

// 검색어 받기
$term = trim($_GET['term'] ?? '');

if (empty($term) || strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $conn = get_db_connection();
    
    // 거래처명으로 검색 (부분 일치)
    $search_term = "%{$term}%";
    $stmt = $conn->prepare("
        SELECT id, name, phone, memo 
        FROM suppliers 
        WHERE name LIKE ? 
        ORDER BY 
            CASE WHEN name = ? THEN 1 ELSE 2 END,
            name ASC
        LIMIT 10
    ");
    
    $stmt->bind_param("ss", $search_term, $term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $suppliers = [];
    while ($row = $result->fetch_assoc()) {
        $suppliers[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'phone' => $row['phone'] ?? '',
            'memo' => $row['memo'] ?? '',
            'exact_match' => $row['name'] === $term
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode($suppliers);
    
} catch (Exception $e) {
    echo json_encode(['error' => '검색 중 오류가 발생했습니다: ' . $e->getMessage()]);
}
?>