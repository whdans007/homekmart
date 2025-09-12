<?php
session_start();
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 권한 확인
if (!has_permission('purchase_management')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => t('messages.permission_denied')
    ]);
    exit;
}

header('Content-Type: application/json');

try {
    // POST 데이터 검증
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('잘못된 요청 방법입니다.');
    }
    
    if (!isset($_POST['product_ids']) || !isset($_POST['brand_id'])) {
        throw new Exception('필수 데이터가 누락되었습니다.');
    }
    
    $product_ids_json = $_POST['product_ids'];
    $brand_id = intval($_POST['brand_id']);
    $brand_name_en = $_POST['brand_name_en'] ?? '';
    $brand_name_ko = $_POST['brand_name_ko'] ?? '';
    
    // product_ids JSON 파싱
    $product_ids = json_decode($product_ids_json, true);
    if (!is_array($product_ids) || empty($product_ids)) {
        throw new Exception('유효하지 않은 상품 ID 목록입니다.');
    }
    
    // 모든 product_id가 숫자인지 확인
    $product_ids = array_map('intval', $product_ids);
    $product_ids = array_filter($product_ids, function($id) {
        return $id > 0;
    });
    
    if (empty($product_ids)) {
        throw new Exception('유효한 상품 ID가 없습니다.');
    }
    
    // 브랜드 ID 검증
    if ($brand_id <= 0) {
        throw new Exception('유효하지 않은 브랜드입니다.');
    }
    
    // 데이터베이스 연결
    $conn = get_db_connection();
    if (!$conn) {
        throw new Exception('데이터베이스 연결에 실패했습니다.');
    }
    
    // 브랜드가 실제로 존재하는지 확인
    $brand_check_stmt = $conn->prepare("SELECT id, name_en, name_ko FROM brands WHERE id = ?");
    $brand_check_stmt->bind_param("i", $brand_id);
    $brand_check_stmt->execute();
    $brand_result = $brand_check_stmt->get_result();
    
    if ($brand_result->num_rows === 0) {
        throw new Exception('존재하지 않는 브랜드입니다.');
    }
    
    $brand_data = $brand_result->fetch_assoc();
    $brand_check_stmt->close();
    
    // 트랜잭션 시작
    $conn->autocommit(false);
    
    try {
        // 상품들의 브랜드 업데이트
        $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
        $update_sql = "UPDATE products SET brand_id = ? WHERE id IN ($placeholders)";
        
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception('쿼리 준비에 실패했습니다: ' . $conn->error);
        }
        
        // 바인딩 파라미터 타입 문자열 생성
        $types = 'i' . str_repeat('i', count($product_ids)); // brand_id + product_ids
        $params = array_merge([$brand_id], $product_ids);
        
        $update_stmt->bind_param($types, ...$params);
        $update_stmt->execute();
        
        $updated_count = $update_stmt->affected_rows;
        $update_stmt->close();
        
        // 변경 로그 기록 (옵션) - 테이블이 존재하는 경우에만
        $check_log_table = $conn->query("SHOW TABLES LIKE 'product_change_logs'");
        if ($check_log_table && $check_log_table->num_rows > 0) {
            foreach ($product_ids as $product_id) {
                $log_sql = "INSERT INTO product_change_logs (product_id, changed_field, old_value, new_value, changed_by, changed_at) 
                           SELECT ?, 'brand_id', brand_id, ?, ?, NOW() 
                           FROM products WHERE id = ?";
                $log_stmt = $conn->prepare($log_sql);
                if ($log_stmt) {
                    $user_id = $_SESSION['user_id'] ?? 0;
                    $log_stmt->bind_param("iiii", $product_id, $brand_id, $user_id, $product_id);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
            }
        }
        
        // 커밋
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "{$updated_count}개 상품의 브랜드가 성공적으로 변경되었습니다.",
            'updated_count' => $updated_count,
            'brand' => [
                'id' => $brand_id,
                'name_en' => $brand_data['name_en'],
                'name_ko' => $brand_data['name_ko']
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
    $conn->autocommit(true);
    $conn->close();
    
} catch (Exception $e) {
    error_log("Bulk brand update error: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug' => [
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ]
    ]);
}
?>