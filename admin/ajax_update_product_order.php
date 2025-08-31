<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';
    
    // 로그인 체크 및 권한 확인
    if (!is_logged_in()) {
        echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
        exit();
    }
    
    if (!has_permission('admin_access')) {
        echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
        exit();
    }
    
    // JSON 데이터 받기
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['section_id']) || !isset($data['products'])) {
        echo json_encode(['success' => false, 'message' => '필수 데이터가 누락되었습니다.']);
        exit();
    }
    
    $section_id = (int)$data['section_id'];
    $products = $data['products'];
    
    if (empty($products)) {
        echo json_encode(['success' => false, 'message' => '상품 데이터가 없습니다.']);
        exit();
    }
    
    $conn = get_db_connection();
    
    // 현재 사용자의 점포 정보 가져오기
    $current_store_id = null;
    if (!empty($_SESSION['user_id'])) {
        $user_stmt = $conn->prepare("SELECT s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $current_store_id = $user_row['store_id'];
            
            // super_admin이고 store_id가 없는 경우 기본 점포 설정
            if ($_SESSION['role'] === 'super_admin' && empty($current_store_id)) {
                $current_store_id = 1;
            }
        }
        $user_stmt->close();
    }
    
    if (!$current_store_id) {
        echo json_encode(['success' => false, 'message' => '점포 정보를 찾을 수 없습니다.']);
        exit();
    }
    
    // 트랜잭션 시작
    $conn->autocommit(false);
    
    try {
        // 각 상품의 순서 업데이트
        $update_stmt = $conn->prepare("UPDATE product_displays SET display_order = ? WHERE id = ? AND section_id = ? AND store_id = ?");
        
        foreach ($products as $product) {
            $product_id = (int)$product['id'];
            $new_order = (int)$product['order'];
            
            $update_stmt->bind_param("iiii", $new_order, $product_id, $section_id, $current_store_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("상품 ID {$product_id} 순서 업데이트 실패");
            }
        }
        
        $update_stmt->close();
        
        // 트랜잭션 커밋
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => '상품 순서가 성공적으로 업데이트되었습니다.']);
        
    } catch (Exception $e) {
        // 트랜잭션 롤백
        $conn->rollback();
        throw $e;
    }
    
    $conn->close();
    
} catch (Exception $e) {
    error_log("Product order update error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '순서 업데이트 중 오류가 발생했습니다: ' . $e->getMessage()]);
}
?>