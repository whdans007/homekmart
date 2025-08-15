<?php
/**
 * 필리핀 배달 주소 API
 * 바랑가이, 랜드마크 지원
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$request_method = $_SERVER['REQUEST_METHOD'];

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    switch ($request_method) {
        case 'GET':
            // 주소 목록 조회
            $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
            
            if ($user_id <= 0) {
                throw new Exception('Valid user_id is required');
            }
            
            $stmt = $pdo->prepare("
                SELECT * FROM delivery_addresses 
                WHERE user_id = ? 
                ORDER BY is_default DESC, id DESC
            ");
            $stmt->execute([$user_id]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 주소 데이터 가공
            $processed_addresses = [];
            foreach ($addresses as $address) {
                $processed_addresses[] = [
                    'id' => (int)$address['id'],
                    'user_id' => (int)$address['user_id'],
                    'recipient_name' => $address['recipient_name'],
                    'phone_number' => $address['phone_number'],
                    'street_address' => $address['street_address'],
                    'barangay' => $address['barangay'],
                    'city' => $address['city'],
                    'province' => $address['province'],
                    'postal_code' => $address['postal_code'],
                    'landmark' => $address['landmark'],
                    'delivery_instructions' => $address['delivery_instructions'],
                    'is_default' => (bool)$address['is_default'],
                    'full_address' => $address['street_address'] . ', ' . 
                                   $address['barangay'] . ', ' . 
                                   $address['city'] . ', ' . 
                                   $address['province'] . ' ' . 
                                   $address['postal_code'],
                    'created_at' => $address['created_at']
                ];
            }
            
            $response = [
                'success' => true,
                'message' => 'Addresses retrieved successfully',
                'data' => [
                    'addresses' => $processed_addresses,
                    'count' => count($processed_addresses)
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'POST':
            // 새 주소 추가
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            $required_fields = ['user_id', 'recipient_name', 'phone_number', 'street_address', 'barangay', 'city', 'province'];
            foreach ($required_fields as $field) {
                if (!isset($input[$field]) || empty($input[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            $pdo->beginTransaction();
            
            // 기본 주소로 설정하는 경우, 다른 주소들의 기본 설정 해제
            if (isset($input['is_default']) && $input['is_default']) {
                $stmt = $pdo->prepare("UPDATE delivery_addresses SET is_default = 0 WHERE user_id = ?");
                $stmt->execute([$input['user_id']]);
            }
            
            // 새 주소 삽입
            $stmt = $pdo->prepare("
                INSERT INTO delivery_addresses 
                (user_id, recipient_name, phone_number, street_address, barangay, city, province, postal_code, landmark, delivery_instructions, is_default, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $input['user_id'],
                $input['recipient_name'],
                $input['phone_number'],
                $input['street_address'],
                $input['barangay'],
                $input['city'],
                $input['province'],
                $input['postal_code'] ?? '',
                $input['landmark'] ?? '',
                $input['delivery_instructions'] ?? '',
                isset($input['is_default']) ? (int)$input['is_default'] : 0
            ]);
            
            $address_id = $pdo->lastInsertId();
            $pdo->commit();
            
            $response = [
                'success' => true,
                'message' => 'Address added successfully',
                'data' => [
                    'address_id' => $address_id,
                    'full_address' => $input['street_address'] . ', ' . 
                                   $input['barangay'] . ', ' . 
                                   $input['city'] . ', ' . 
                                   $input['province']
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'PUT':
            // 주소 수정
            $input = json_decode(file_get_contents('php://input'), true);
            $address_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if ($address_id <= 0) {
                throw new Exception('Valid address ID is required');
            }
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            $pdo->beginTransaction();
            
            // 기본 주소로 설정하는 경우
            if (isset($input['is_default']) && $input['is_default']) {
                $stmt = $pdo->prepare("UPDATE delivery_addresses SET is_default = 0 WHERE user_id = (SELECT user_id FROM delivery_addresses WHERE id = ?)");
                $stmt->execute([$address_id]);
            }
            
            // 수정 가능한 필드들
            $update_fields = [];
            $params = [];
            
            $allowed_fields = ['recipient_name', 'phone_number', 'street_address', 'barangay', 'city', 'province', 'postal_code', 'landmark', 'delivery_instructions', 'is_default'];
            
            foreach ($allowed_fields as $field) {
                if (isset($input[$field])) {
                    $update_fields[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($update_fields)) {
                throw new Exception('No valid fields to update');
            }
            
            $params[] = $address_id;
            
            $stmt = $pdo->prepare("UPDATE delivery_addresses SET " . implode(', ', $update_fields) . " WHERE id = ?");
            $stmt->execute($params);
            
            $pdo->commit();
            
            $response = [
                'success' => true,
                'message' => 'Address updated successfully',
                'data' => [
                    'address_id' => $address_id
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'DELETE':
            // 주소 삭제
            $address_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if ($address_id <= 0) {
                throw new Exception('Valid address ID is required');
            }
            
            $stmt = $pdo->prepare("DELETE FROM delivery_addresses WHERE id = ?");
            $stmt->execute([$address_id]);
            
            $response = [
                'success' => true,
                'message' => 'Address deleted successfully',
                'data' => [
                    'address_id' => $address_id
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>