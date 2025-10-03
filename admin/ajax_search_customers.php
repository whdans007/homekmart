<?php
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 고객 관리 권한 확인
if (!has_permission('customer_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// JSON 헤더 설정
header('Content-Type: application/json; charset=utf-8');

$search_term = $_GET['q'] ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$limit = min($limit, 50); // 최대 50개로 제한

if (empty($search_term)) {
    echo json_encode(['success' => false, 'customers' => [], 'message' => 'Search term is required']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // WHERE 절 구성
    $where_clause = " WHERE (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
    $params = ["%$search_term%", "%$search_term%", "%$search_term%", "%$search_term%"];

    // 점포별 필터링 (admin은 자기 점포만 조회)
    if ($_SESSION['role'] !== 'super_admin' && !empty($_SESSION['store_id'])) {
        $where_clause .= " AND (c.preferred_store_id = ? OR c.preferred_store_id IS NULL)";
        $params[] = $_SESSION['store_id'];
    }

    // 고객 검색
    $sql = "
        SELECT
            c.id,
            c.name,
            c.email,
            c.phone,
            c.address,
            c.status,
            s.name as store_name
        FROM customers c
        LEFT JOIN stores s ON c.preferred_store_id = s.id
        " . $where_clause . "
        ORDER BY c.name ASC
        LIMIT ?
    ";

    $stmt = $pdo->prepare($sql);

    $current_param = 1;
    foreach ($params as $param) {
        $stmt->bindValue($current_param++, $param, PDO::PARAM_STR);
    }
    $stmt->bindValue($current_param, $limit, PDO::PARAM_INT);

    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 주소 간략화 (너무 길면 잘라내기)
    foreach ($customers as &$customer) {
        if (!empty($customer['address']) && mb_strlen($customer['address']) > 50) {
            $customer['address'] = mb_substr($customer['address'], 0, 50) . '...';
        }
    }

    echo json_encode([
        'success' => true,
        'customers' => $customers,
        'count' => count($customers)
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
