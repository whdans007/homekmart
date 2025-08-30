<?php
/**
 * 점포 선택 API
 * 고객의 점포 선택을 세션에 저장하고, 로그인한 경우 preferred_store_id 업데이트
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// OPTIONS 요청 처리 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

try {
    require_once '../../config/db_config.php';
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    $request_method = $_SERVER['REQUEST_METHOD'];
    
    if ($request_method === 'GET') {
        // 현재 선택된 점포 반환
        $selected_store_id = $_SESSION['selected_store_id'] ?? 1; // 기본값: 클락힐스점
        
        // 점포 정보 조회
        $sql = "SELECT id, name, address, phone, is_active FROM stores WHERE id = ? AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $selected_store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($store = $result->fetch_assoc()) {
            $response = [
                'success' => true,
                'selected_store' => [
                    'id' => (int)$store['id'],
                    'name' => $store['name'],
                    'address' => $store['address'],
                    'phone' => $store['phone'],
                    'is_active' => (bool)$store['is_active']
                ]
            ];
        } else {
            // 기본 점포 설정
            $_SESSION['selected_store_id'] = 1;
            $response = [
                'success' => true,
                'selected_store' => [
                    'id' => 1,
                    'name' => 'CLARK HILLS',
                    'address' => '',
                    'phone' => '',
                    'is_active' => true
                ]
            ];
        }
        
    } elseif ($request_method === 'POST') {
        // 점포 선택 처리
        $input = json_decode(file_get_contents('php://input'), true);
        $store_id = $input['store_id'] ?? null;
        $customer_id = $_SESSION['customer_id'] ?? null;
        
        if (!$store_id) {
            throw new Exception("점포 ID가 필요합니다.");
        }
        
        // 점포 존재 및 활성화 상태 확인
        $sql = "SELECT id, name, is_active FROM stores WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if (!$store = $result->fetch_assoc()) {
            throw new Exception("존재하지 않는 점포입니다.");
        }
        
        if (!$store['is_active']) {
            throw new Exception("현재 운영하지 않는 점포입니다.");
        }
        
        // 세션에 선택된 점포 저장
        $_SESSION['selected_store_id'] = (int)$store_id;
        
        // 로그인한 고객인 경우 preferred_store_id 업데이트
        if ($customer_id) {
            $update_sql = "UPDATE customers SET preferred_store_id = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $store_id, $customer_id);
            $update_stmt->execute();
        }
        
        // shop_sessions 테이블에도 저장 (세션 기록)
        $session_id = session_id();
        $upsert_sql = "INSERT INTO shop_sessions (session_id, selected_store_id, customer_id, last_activity) 
                       VALUES (?, ?, ?, NOW()) 
                       ON DUPLICATE KEY UPDATE 
                       selected_store_id = VALUES(selected_store_id),
                       customer_id = VALUES(customer_id),
                       last_activity = NOW()";
        $upsert_stmt = $conn->prepare($upsert_sql);
        $upsert_stmt->bind_param("sii", $session_id, $store_id, $customer_id);
        $upsert_stmt->execute();
        
        $response = [
            'success' => true,
            'message' => "{$store['name']}점이 선택되었습니다.",
            'selected_store' => [
                'id' => (int)$store['id'],
                'name' => $store['name']
            ]
        ];
    } else {
        throw new Exception("지원하지 않는 HTTP 메소드입니다.");
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>