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
$limit = min(100, (int)($_GET['limit'] ?? $_POST['limit'] ?? 10));
$show_all = $_GET['show_all'] ?? $_POST['show_all'] ?? '';

// 점포 필터링 (super_admin이 아닌 경우 자신의 점포 거래처만 조회)
$store_id = $_SESSION['role'] === 'super_admin' ? null : ($_SESSION['store_id'] ?? null);


// 전체 목록 요청이거나 검색어가 있는 경우만 처리
if (empty($search_term) && !$show_all) {
    echo json_encode(['success' => false, 'message' => '검색어를 입력해주세요.']);
    exit;
}

if (!$show_all && strlen(trim($search_term)) < 1) {
    echo json_encode(['success' => false, 'message' => '검색어는 최소 1자 이상 입력해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 먼저 전체 거래처 수 확인 (점포 필터 적용)
    $count_sql = "SELECT COUNT(*) FROM wholesale_customers WHERE is_active = 1" . ($store_id ? " AND store_id = ?" : "");
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($store_id ? [$store_id] : []);
    $total_customers = $count_stmt->fetchColumn();

    // 전체 목록 요청인지 검색인지에 따라 쿼리 구성
    if ($show_all && empty($search_term)) {
        // 전체 목록 요청
        $sql = "
            SELECT id, name, phone, address
            FROM wholesale_customers
            WHERE is_active = 1
            " . ($store_id ? "AND store_id = ?" : "") . "
            ORDER BY name ASC
            LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($store_id ? [$store_id] : []);
    } else {
        // 검색 요청
        $sql = "
            SELECT id, name, phone, address
            FROM wholesale_customers
            WHERE is_active = 1
            " . ($store_id ? "AND store_id = ?" : "") . "
            AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)
            ORDER BY name ASC
            LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $search_pattern = "%{$search_term}%";
        $params = $store_id ? [$store_id] : [];
        $params = array_merge($params, [$search_pattern, $search_pattern, $search_pattern]);

        $stmt->execute($params);
    }
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // 검색 결과가 없으면 샘플 데이터 확인
    if (empty($customers)) {
        $sample_sql = "SELECT id, name, phone, address FROM wholesale_customers WHERE is_active = 1" . ($store_id ? " AND store_id = ?" : "") . " LIMIT 3";
        $sample_stmt = $pdo->prepare($sample_sql);
        $sample_stmt->execute($store_id ? [$store_id] : []);
        $sample_customers = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'customers' => $customers,
        'total_available' => $total_customers,
        'search_term' => $search_term
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => '데이터베이스 오류: ' . $e->getMessage()
    ]);
}