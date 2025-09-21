<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// JSON 응답 헤더 설정
header('Content-Type: application/json');

// 세션 확인
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// 권한 확인 (매입관리 권한 필요)
if (!has_permission('purchase_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '가격변경 이력 삭제 권한이 없습니다.']);
    exit;
}

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST 요청만 허용됩니다.']);
    exit;
}

// JSON 데이터 파싱
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['ids']) || !is_array($input['ids'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '유효하지 않은 요청 데이터입니다.']);
    exit;
}

$ids = $input['ids'];

// ID 배열이 비어있는지 확인
if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => '삭제할 항목이 선택되지 않았습니다.']);
    exit;
}

// ID 유효성 검사 (숫자인지 확인)
foreach ($ids as $id) {
    if (!is_numeric($id) || intval($id) <= 0) {
        echo json_encode(['success' => false, 'message' => '유효하지 않은 ID가 포함되어 있습니다.']);
        exit;
    }
}

try {
    $pdo = null;
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 가격변경 이력 테이블 존재 확인
    $table_check = $pdo->prepare("SHOW TABLES LIKE 'price_change_history'");
    $table_check->execute();

    if (!$table_check->fetch()) {
        echo json_encode(['success' => false, 'message' => '가격변경 이력 테이블이 존재하지 않습니다.']);
        exit;
    }

    // 트랜잭션 시작
    $pdo->beginTransaction();

    // 권한별 삭제 조건 설정
    $where_conditions = [];
    $params = [];

    // ID 조건 추가
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where_conditions[] = "id IN ($placeholders)";
    $params = array_merge($params, $ids);

    // super_admin이 아닌 경우 점포별 제한
    if ($_SESSION['role'] !== 'super_admin' && !empty($_SESSION['store_id'])) {
        $where_conditions[] = "(store_id = ? OR store_id IS NULL)";
        $params[] = $_SESSION['store_id'];
    }

    $where_clause = implode(' AND ', $where_conditions);

    // 삭제 전 실제 존재하는 레코드 수 확인
    $count_sql = "SELECT COUNT(*) FROM price_change_history WHERE $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $available_count = $count_stmt->fetchColumn();

    if ($available_count == 0) {
        $pdo->rollback();
        echo json_encode(['success' => false, 'message' => '삭제할 수 있는 항목이 없습니다.']);
        exit;
    }

    // 실제 삭제 실행
    $delete_sql = "DELETE FROM price_change_history WHERE $where_clause";
    $delete_stmt = $pdo->prepare($delete_sql);
    $delete_stmt->execute($params);

    $deleted_count = $delete_stmt->rowCount();

    // 트랜잭션 커밋
    $pdo->commit();

    // 성공 응답
    echo json_encode([
        'success' => true,
        'deleted_count' => $deleted_count,
        'message' => "{$deleted_count}개 항목이 성공적으로 삭제되었습니다."
    ]);

} catch (PDOException $e) {
    // 트랜잭션 롤백
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollback();
    }

    // 에러 로그 기록
    error_log("Price history delete error: " . $e->getMessage());

    // 사용자에게는 일반적인 에러 메시지 반환
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '데이터베이스 오류가 발생했습니다.'
    ]);

} catch (Exception $e) {
    // 트랜잭션 롤백
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollback();
    }

    // 에러 로그 기록
    error_log("General delete error: " . $e->getMessage());

    // 사용자에게는 일반적인 에러 메시지 반환
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '삭제 처리 중 오류가 발생했습니다.'
    ]);
}
?>