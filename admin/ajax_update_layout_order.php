<?php
// AJAX 레이아웃 순서 업데이트
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 헤더 설정
header('Content-Type: application/json');

try {
    // 로그인 및 권한 체크
    if (!is_logged_in()) {
        throw new Exception('로그인이 필요합니다.');
    }
    
    if (!has_permission('admin_access')) {
        throw new Exception('관리자 권한이 필요합니다.');
    }
    
    // POST 데이터 확인
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !is_array($data)) {
        throw new Exception('잘못된 데이터 형식입니다.');
    }
    
    $conn = get_db_connection();
    
    // 트랜잭션 시작
    $conn->autocommit(false);
    
    try {
        // 각 행의 순서 업데이트
        $stmt = $conn->prepare("UPDATE layout_rows SET row_order = ? WHERE id = ?");
        $updated_count = 0;
        
        foreach ($data as $row) {
            if (!isset($row['id']) || !isset($row['order'])) {
                continue;
            }
            
            $rowId = (int)$row['id'];
            $rowOrder = (int)$row['order'];
            
            $stmt->bind_param("ii", $rowOrder, $rowId);
            
            if (!$stmt->execute()) {
                throw new Exception('행 순서 업데이트에 실패했습니다: ' . $stmt->error);
            }
            
            $updated_count++;
        }
        
        $stmt->close();
        
        // 커밋
        $conn->commit();
        $conn->autocommit(true);
        
        echo json_encode([
            'success' => true,
            'message' => '레이아웃 행 순서가 성공적으로 업데이트되었습니다.',
            'updated_count' => $updated_count
        ]);
        
    } catch (Exception $e) {
        // 롤백
        $conn->rollback();
        $conn->autocommit(true);
        throw $e;
    }
    
    $conn->close();
    
} catch (Exception $e) {
    error_log("AJAX 레이아웃 순서 업데이트 오류: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>