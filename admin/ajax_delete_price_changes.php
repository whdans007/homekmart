<?php
session_start();
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// JSON 응답 헤더 설정
header('Content-Type: application/json');

// 권한 확인
if (!has_permission('purchase_management')) {
    echo json_encode([
        'success' => false,
        'message' => '가격 변경 이력 삭제 권한이 없습니다.'
    ]);
    exit;
}

// POST 요청 확인
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => '잘못된 요청입니다.'
    ]);
    exit;
}

// JSON 데이터 파싱
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['ids']) || !is_array($input['ids']) || empty($input['ids'])) {
    echo json_encode([
        'success' => false,
        'message' => '삭제할 항목이 선택되지 않았습니다.'
    ]);
    exit;
}

$ids = $input['ids'];

// ID 유효성 검사 (숫자만 허용)
foreach ($ids as $id) {
    if (!is_numeric($id)) {
        echo json_encode([
            'success' => false,
            'message' => '잘못된 ID 형식입니다.'
        ]);
        exit;
    }
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 트랜잭션 시작
    $pdo->beginTransaction();
    
    // placeholders 생성
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    
    // 점포별 필터링 추가 (super_admin이 아닌 경우)
    if ($_SESSION['role'] !== 'super_admin' && !empty($_SESSION['store_id'])) {
        // 해당 점포의 데이터만 삭제 가능
        $sql = "DELETE FROM price_change_history 
                WHERE id IN ($placeholders) 
                AND (store_id = ? OR store_id IS NULL)";
        
        $params = array_merge($ids, [$_SESSION['store_id']]);
    } else {
        // super_admin은 모든 데이터 삭제 가능
        $sql = "DELETE FROM price_change_history WHERE id IN ($placeholders)";
        $params = $ids;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $affected_rows = $stmt->rowCount();
    
    // 커밋
    $pdo->commit();
    
    // 삭제 로그 기록
    error_log("Price change history deleted: " . json_encode([
        'user_id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'deleted_ids' => $ids,
        'affected_rows' => $affected_rows,
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    
    echo json_encode([
        'success' => true,
        'message' => "{$affected_rows}개의 가격 변경 이력이 삭제되었습니다."
    ]);
    
} catch (PDOException $e) {
    // 롤백
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Price change history deletion error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => '삭제 중 오류가 발생했습니다: ' . $e->getMessage()
    ]);
}
?>