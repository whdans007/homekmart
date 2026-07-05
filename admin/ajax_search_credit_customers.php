<?php
/**
 * 외상거래처 검색 (credit_customers)
 * 외상거래 입력 화면에서 거래처 검색/전체목록 용도로 사용.
 */
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
$limit = min(100, (int)($_GET['limit'] ?? $_POST['limit'] ?? 10));
$show_all = $_GET['show_all'] ?? $_POST['show_all'] ?? '';

if (empty($search_term) && !$show_all) {
    echo json_encode(['success' => false, 'message' => '검색어를 입력해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 점포 필터 (super_admin이 아닌 경우 본인 점포만)
    $where = ["is_active = 1"];
    $params = [];

    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        $store_id = $_SESSION['store_id'] ?? null;
        if (empty($store_id)) {
            echo json_encode(['success' => true, 'customers' => []]);
            exit;
        }
        $where[] = "store_id = ?";
        $params[] = $store_id;
    }

    if (!empty($search_term)) {
        $where[] = "(name LIKE ? OR phone LIKE ? OR address LIKE ?)";
        $pattern = "%{$search_term}%";
        $params[] = $pattern;
        $params[] = $pattern;
        $params[] = $pattern;
    }

    $where_clause = implode(" AND ", $where);

    $sql = "
        SELECT id, name, phone, address
        FROM credit_customers
        WHERE {$where_clause}
        ORDER BY name ASC
        LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'customers' => $customers,
        'search_term' => $search_term
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => '데이터베이스 오류: ' . $e->getMessage()
    ]);
}
