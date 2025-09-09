<?php
// 모든 에러 출력 차단
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 출력 버퍼링 시작
ob_start();

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/partials/session_check.php';

// 버퍼 내용 삭제하고 헤더 설정
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// 상품 관리 권한 확인
if (!has_permission('product_management')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Permission denied'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

if ($project_id <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid project ID'
    ]);
    exit;
}

try {
    $conn = get_db_connection();
    $conn->autocommit(false); // 트랜잭션 시작
    
    // 현재 사용자의 점포 ID 가져오기
    $current_store_id = $_SESSION['store_id'] ?? 0;
    
    // 프로젝트 소유권 확인
    $check_sql = "SELECT id, created_at FROM price_label_projects WHERE id = ? AND store_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $project_id, $current_store_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        throw new Exception('프로젝트를 찾을 수 없거나 접근 권한이 없습니다.');
    }
    
    $project_info = $check_result->fetch_assoc();
    
    // 프로젝트 아이템들 삭제 (외래키로 인한 CASCADE는 설정되어 있지만 명시적으로 삭제)
    $delete_items_sql = "DELETE FROM price_label_project_items WHERE project_id = ?";
    $delete_items_stmt = $conn->prepare($delete_items_sql);
    $delete_items_stmt->bind_param("i", $project_id);
    
    if (!$delete_items_stmt->execute()) {
        throw new Exception('프로젝트 아이템 삭제 중 오류가 발생했습니다.');
    }
    
    // 프로젝트 삭제
    $delete_project_sql = "DELETE FROM price_label_projects WHERE id = ? AND store_id = ?";
    $delete_project_stmt = $conn->prepare($delete_project_sql);
    $delete_project_stmt->bind_param("ii", $project_id, $current_store_id);
    
    if (!$delete_project_stmt->execute()) {
        throw new Exception('프로젝트 삭제 중 오류가 발생했습니다.');
    }
    
    if ($delete_project_stmt->affected_rows === 0) {
        throw new Exception('삭제할 프로젝트를 찾을 수 없습니다.');
    }
    
    $conn->commit(); // 트랜잭션 커밋
    
    echo json_encode([
        'success' => true,
        'message' => '가격표 프로젝트 (' . date('Y-m-d H:i', strtotime($project_info['created_at'])) . ')가 성공적으로 삭제되었습니다.'
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback(); // 트랜잭션 롤백
    }
    
    error_log("Error in ajax_delete_price_label_project.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    
} finally {
    if (isset($conn)) {
        $conn->autocommit(true); // 자동커밋 복원
        $conn->close();
    }
}
?>