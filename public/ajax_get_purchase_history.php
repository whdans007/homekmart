<?php
// 오류 출력 설정
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 출력 버퍼링 시작
ob_start();

try {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/margin_helper.php';
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Include file error: ' . $e->getMessage()]);
    exit;
}

// 세션 시작 (세션이 시작되지 않은 경우)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// 권한 확인
if (!function_exists('is_logged_in')) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Session helper function not found']);
    exit;
}

if (!is_logged_in()) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '권한이 없습니다. 현재 권한: ' . ($_SESSION['role'] ?? 'none')]);
    exit;
}

$product_id = $_GET['product_id'] ?? 0;
$store_id = $_GET['store_id'] ?? null;

if (empty($product_id) || !is_numeric($product_id)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '올바른 상품 ID가 필요합니다.']);
    exit;
}

// 상품 존재 여부 확인
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $product_check = $pdo->prepare("SELECT id FROM products WHERE id = ?");
    $product_check->execute([$product_id]);
    if (!$product_check->fetch()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => '존재하지 않는 상품입니다.']);
        exit;
    }
} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '데이터베이스 연결 오류: ' . $e->getMessage()]);
    exit;
}

// 먼저 테이블 존재 여부 확인
try {
    $table_check = $pdo->prepare("SHOW TABLES LIKE 'purchase_items'");
    $table_check->execute();
    if (!$table_check->fetch()) {
        ob_clean();
        echo json_encode([
            'success' => true, 
            'data' => [],
            'count' => 0,
            'message' => '매입 관리 기능이 설정되지 않았습니다.'
        ]);
        exit;
    }
} catch (PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '테이블 확인 오류: ' . $e->getMessage()]);
    exit;
}

// 최근 매입 이력 5건 조회 (점포별 필터링 포함)
try {
    // purchases 테이블에 store_id 컬럼이 있는지 확인
    $column_check = $pdo->prepare("SHOW COLUMNS FROM purchases LIKE 'store_id'");
    $column_check->execute();
    $has_store_id = $column_check->fetch();
    
    $base_query = "
        SELECT 
            p.purchase_id,
            p.purchase_date,
            s.name as supplier_name,
            pi.unit_price,
            pi.quantity,
            pi.purchase_type,
            COALESCE(pr.pieces_per_box, 1) as pieces_per_box,
            (CASE 
                WHEN pi.purchase_type = 'box' THEN pi.unit_price / COALESCE(pr.pieces_per_box, 1)
                ELSE pi.unit_price
            END) as unit_cost_per_piece" .
            ($has_store_id ? ", st.name as store_name" : "") . "
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.purchase_id
        JOIN suppliers s ON p.supplier_id = s.id
        JOIN products pr ON pi.product_id = pr.id" .
        ($has_store_id ? " LEFT JOIN stores st ON p.store_id = st.id" : "") . "
        WHERE pi.product_id = ? 
        AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')";
    
    // 점포별 필터링 추가 (store_id 컬럼이 있고 요청된 경우)
    $params = [$product_id];
    if ($has_store_id && $store_id && is_numeric($store_id)) {
        $base_query .= " AND p.store_id = ?";
        $params[] = $store_id;
    }
    
    $base_query .= " ORDER BY p.purchase_date DESC, p.purchase_id DESC LIMIT 5";
    
    $stmt = $pdo->prepare($base_query);
    
    $stmt->execute($params);
    $purchase_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 상품별 마진율 조회
    $margin_rate = get_margin_rate_by_product($product_id, $pdo);
    
    // 데이터 포맷팅
    foreach ($purchase_history as &$item) {
        $item['purchase_date_formatted'] = date('Y-m-d', strtotime($item['purchase_date']));
        $item['unit_price_formatted'] = number_format($item['unit_price']);
        $item['unit_cost_per_piece_formatted'] = number_format($item['unit_cost_per_piece'], 2);
        
        // 마진 관리에서 설정된 마진율 사용 (기본값: 30%)
        $item['suggested_selling_price'] = calculate_suggested_price($item['unit_cost_per_piece'], $margin_rate);
        $item['margin_rate'] = $margin_rate; // 프론트엔드에서 사용할 마진율 정보
    }

    ob_clean();
    echo json_encode([
        'success' => true, 
        'data' => $purchase_history,
        'count' => count($purchase_history)
    ]);

} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}

// 출력 버퍼 정리
ob_end_flush();
?>