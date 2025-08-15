<?php
/**
 * 배달 주소 관리 API (필리핀 주소 체계)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/config/db_config.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    switch ($method) {
        case 'GET':
            // 배달 주소 목록 조회
            $user_id = (int)($_GET['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('User ID is required');
            }
            
            $sql = "
                SELECT 
                    id, recipient_name, phone_number, 
                    street_address, barangay, city, province,
                    postal_code, landmark, delivery_instructions,
                    latitude, longitude, is_default,
                    created_at, updated_at
                FROM delivery_addresses 
                WHERE user_id = ? 
                ORDER BY is_default DESC, created_at DESC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($addresses as &$address) {
                $address['id'] = (int)$address['id'];
                $address['is_default'] = (bool)$address['is_default'];
                $address['latitude'] = $address['latitude'] ? (float)$address['latitude'] : null;
                $address['longitude'] = $address['longitude'] ? (float)$address['longitude'] : null;
                
                // 전체 주소 문자열 생성
                $full_parts = array_filter([
                    $address['street_address'],
                    $address['barangay'] ? "Brgy. " . $address['barangay'] : null,
                    $address['city'],
                    $address['province'],
                    $address['postal_code']
                ]);
                $address['full_address'] = implode(', ', $full_parts);
                
                if ($address['landmark']) {
                    $address['full_address'] .= ' (Near: ' . $address['landmark'] . ')';
                }
            }
            
            $response = [
                'success' => true,
                'data' => [
                    'addresses' => $addresses,
                    'count' => count($addresses)
                ],
                'message' => 'Addresses retrieved successfully'
            ];
            break;
            
        case 'POST':
            // 새 배달 주소 추가
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            $user_id = (int)($input['user_id'] ?? 0);
            $recipient_name = trim($input['recipient_name'] ?? '');
            $phone_number = trim($input['phone_number'] ?? '');
            $street_address = trim($input['street_address'] ?? '');
            $barangay = trim($input['barangay'] ?? '');
            $city = trim($input['city'] ?? '');
            $province = trim($input['province'] ?? '');
            $postal_code = trim($input['postal_code'] ?? '');
            $landmark = trim($input['landmark'] ?? '');
            $delivery_instructions = trim($input['delivery_instructions'] ?? '');
            $latitude = isset($input['latitude']) ? (float)$input['latitude'] : null;
            $longitude = isset($input['longitude']) ? (float)$input['longitude'] : null;
            $is_default = (bool)($input['is_default'] ?? false);
            
            // 필수 필드 검증
            if (!$user_id || !$recipient_name || !$phone_number || !$street_address || !$city) {
                throw new Exception('Required fields: user_id, recipient_name, phone_number, street_address, city');
            }
            
            // 필리핀 전화번호 형식 검증 (간단한 버전)
            if (!preg_match('/^(\+63|0)?[0-9]{10}$/', str_replace([' ', '-', '(', ')'], '', $phone_number))) {
                throw new Exception('Invalid Philippine phone number format');
            }
            
            $pdo->beginTransaction();
            
            try {
                // 기본 주소로 설정하는 경우 다른 주소들의 기본 설정 해제
                if ($is_default) {
                    $reset_stmt = $pdo->prepare("UPDATE delivery_addresses SET is_default = 0 WHERE user_id = ?");
                    $reset_stmt->execute([$user_id]);
                }
                
                $sql = "
                    INSERT INTO delivery_addresses (
                        user_id, recipient_name, phone_number, street_address,
                        barangay, city, province, postal_code, landmark,
                        delivery_instructions, latitude, longitude, is_default,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $user_id, $recipient_name, $phone_number, $street_address,
                    $barangay, $city, $province, $postal_code, $landmark,
                    $delivery_instructions, $latitude, $longitude, $is_default
                ]);
                
                $address_id = $pdo->lastInsertId();
                
                $pdo->commit();
                
                // 배달 가능 지역 확인
                $delivery_available = true; // 실제로는 지역 확인 로직 필요
                $estimated_fee = 50.00; // 기본 배달비
                
                $response = [
                    'success' => true,
                    'data' => [
                        'address_id' => $address_id,
                        'delivery_available' => $delivery_available,
                        'estimated_delivery_fee' => $estimated_fee,
                        'formatted_fee' => '₱' . number_format($estimated_fee, 2),
                        'estimated_delivery_time' => '1-2 hours'
                    ],
                    'message' => 'Address added successfully'
                ];
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        case 'PUT':
            // 배달 주소 수정
            $input = json_decode(file_get_contents('php://input'), true);
            $address_id = (int)($_GET['id'] ?? 0);
            
            if (!$input || !$address_id) {
                throw new Exception('Address ID and valid JSON input required');
            }
            
            $user_id = (int)($input['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('User ID is required');
            }
            
            // 주소 소유권 확인
            $check_stmt = $pdo->prepare("SELECT id FROM delivery_addresses WHERE id = ? AND user_id = ?");
            $check_stmt->execute([$address_id, $user_id]);
            
            if (!$check_stmt->fetch()) {
                throw new Exception('Address not found or access denied');
            }
            
            // 업데이트할 필드들
            $updates = [];
            $params = [];
            
            $updatable_fields = [
                'recipient_name', 'phone_number', 'street_address', 'barangay',
                'city', 'province', 'postal_code', 'landmark', 'delivery_instructions',
                'latitude', 'longitude', 'is_default'
            ];
            
            foreach ($updatable_fields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($updates)) {
                throw new Exception('No fields to update');
            }
            
            $pdo->beginTransaction();
            
            try {
                // 기본 주소로 변경하는 경우
                if (isset($input['is_default']) && $input['is_default']) {
                    $reset_stmt = $pdo->prepare("UPDATE delivery_addresses SET is_default = 0 WHERE user_id = ?");
                    $reset_stmt->execute([$user_id]);
                }
                
                $updates[] = "updated_at = NOW()";
                $params[] = $address_id;
                
                $sql = "UPDATE delivery_addresses SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                $pdo->commit();
                
                $response = [
                    'success' => true,
                    'data' => ['address_id' => $address_id],
                    'message' => 'Address updated successfully'
                ];
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        case 'DELETE':
            // 배달 주소 삭제
            $address_id = (int)($_GET['id'] ?? 0);
            $user_id = (int)($_GET['user_id'] ?? 0);
            
            if (!$address_id || !$user_id) {
                throw new Exception('Address ID and User ID are required');
            }
            
            // 사용 중인 주문이 있는지 확인
            $order_check = $pdo->prepare("SELECT COUNT(*) as count FROM delivery_orders WHERE delivery_address_id = ?");
            $order_check->execute([$address_id]);
            $order_count = $order_check->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($order_count > 0) {
                throw new Exception('Cannot delete address that has been used in orders');
            }
            
            $stmt = $pdo->prepare("DELETE FROM delivery_addresses WHERE id = ? AND user_id = ?");
            $result = $stmt->execute([$address_id, $user_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Address not found or access denied');
            }
            
            $response = [
                'success' => true,
                'data' => ['address_id' => $address_id],
                'message' => 'Address deleted successfully'
            ];
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    http_response_code($method === 'POST' ? 400 : ($method === 'PUT' ? 400 : ($method === 'DELETE' ? 400 : 500)));
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>