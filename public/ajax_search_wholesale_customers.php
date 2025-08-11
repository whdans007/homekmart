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

// 디버깅을 위한 로깅
error_log("거래처 검색 요청 - 검색어: '$search_term', limit: $limit");

if (empty($search_term)) {
    error_log("거래처 검색 실패 - 검색어 없음");
    echo json_encode(['success' => false, 'message' => '검색어를 입력해주세요.']);
    exit;
}

if (strlen(trim($search_term)) < 1) {
    error_log("거래처 검색 실패 - 검색어 너무 짧음");
    echo json_encode(['success' => false, 'message' => '검색어는 최소 1자 이상 입력해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 먼저 전체 거래처 수 확인
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM wholesale_customers WHERE is_active = 1");
    $total_customers = $count_stmt->fetchColumn();
    error_log("전체 활성 거래처 수: $total_customers");
    
    // LIMIT을 직접 쿼리에 포함 (MariaDB 호환성)
    $sql = "
        SELECT id, name, phone, address
        FROM wholesale_customers 
        WHERE is_active = 1 
        AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)
        ORDER BY name ASC
        LIMIT " . (int)$limit;
    
    $stmt = $pdo->prepare($sql);
    $search_pattern = "%{$search_term}%";
    error_log("검색 패턴: '$search_pattern', SQL: $sql");
    
    $stmt->execute([$search_pattern, $search_pattern, $search_pattern]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("검색 결과 수: " . count($customers));
    
    // 검색 결과가 없으면 샘플 데이터 확인
    if (empty($customers)) {
        $sample_stmt = $pdo->query("SELECT id, name, phone, address FROM wholesale_customers WHERE is_active = 1 LIMIT 3");
        $sample_customers = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("샘플 거래처 데이터: " . json_encode($sample_customers));
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