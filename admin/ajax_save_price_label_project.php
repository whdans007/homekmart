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

try {
    $conn = get_db_connection();
    $conn->autocommit(false); // 트랜잭션 시작
    
    // 현재 사용자와 점포 정보
    $current_user_id = $_SESSION['user_id'] ?? 0;
    $current_store_id = $_SESSION['store_id'] ?? 0;
    
    // 입력 데이터 검증
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $items = json_decode($_POST['items'] ?? '[]', true);
    
    if (!is_array($items)) {
        throw new Exception('상품 데이터가 올바르지 않습니다.');
    }
    
    if ($project_id > 0) {
        // 기존 프로젝트 업데이트
        
        // 프로젝트 소유권 확인
        $check_sql = "SELECT id FROM price_label_projects WHERE id = ? AND store_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $project_id, $current_store_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            throw new Exception('프로젝트를 찾을 수 없거나 접근 권한이 없습니다.');
        }
        
        // 프로젝트 정보 업데이트 (updated_at만 갱신)
        $update_sql = "UPDATE price_label_projects SET updated_at = NOW() WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $project_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception('프로젝트 업데이트 중 오류가 발생했습니다.');
        }
        
        // 기존 상품 아이템들 삭제
        $delete_items_sql = "DELETE FROM price_label_project_items WHERE project_id = ?";
        $delete_items_stmt = $conn->prepare($delete_items_sql);
        $delete_items_stmt->bind_param("i", $project_id);
        $delete_items_stmt->execute();
        
        $result_project_id = $project_id;
        
    } else {
        // 새 프로젝트 생성
        
        $insert_sql = "INSERT INTO price_label_projects (store_id, created_by) VALUES (?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("ii", $current_store_id, $current_user_id);
        
        if (!$insert_stmt->execute()) {
            throw new Exception('프로젝트 생성 중 오류가 발생했습니다.');
        }
        
        $result_project_id = $conn->insert_id;
    }
    
    // 상품 아이템들 추가
    if (!empty($items)) {
        $item_sql = "INSERT INTO price_label_project_items (project_id, product_id, quantity, remarks) VALUES (?, ?, ?, ?)";
        $item_stmt = $conn->prepare($item_sql);
        
        foreach ($items as $item) {
            $product_id = (int)($item['product_id'] ?? 0);
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $remarks = trim($item['remarks'] ?? '');
            
            if ($product_id > 0) {
                $item_stmt->bind_param("iiis", $result_project_id, $product_id, $quantity, $remarks);
                if (!$item_stmt->execute()) {
                    throw new Exception('상품 아이템 저장 중 오류가 발생했습니다.');
                }
            }
        }
    }
    
    $conn->commit(); // 트랜잭션 커밋
    
    echo json_encode([
        'success' => true,
        'project_id' => $result_project_id,
        'message' => $project_id > 0 ? '프로젝트가 업데이트되었습니다.' : '새 프로젝트가 생성되었습니다.'
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback(); // 트랜잭션 롤백
    }
    
    error_log("Error in ajax_save_price_label_project.php: " . $e->getMessage());
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