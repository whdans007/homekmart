<?php
/**
 * 필리핀 배달 주문 API
 * COD 결제 중심
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
            // 주문 목록 조회
            $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
            $status = isset($_GET['status']) ? $_GET['status'] : '';
            $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;
            
            $sql = "SELECT * FROM delivery_orders WHERE 1=1";
            $params = [];
            
            if ($user_id > 0) {
                $sql .= " AND user_id = ?";
                $params[] = $user_id;
            }
            
            if (!empty($status)) {
                $sql .= " AND status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT " . $limit;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 주문 데이터 가공
            $processed_orders = [];
            foreach ($orders as $order) {
                $processed_orders[] = [
                    'id' => (int)$order['id'],
                    'user_id' => (int)$order['user_id'],
                    'order_number' => $order['order_number'],
                    'status' => $order['status'],
                    'total_amount' => (float)$order['total_amount'],
                    'payment_method' => $order['payment_method'],
                    'delivery_fee' => (float)$order['delivery_fee'],
                    'currency' => 'PHP',
                    'formatted_total' => '₱' . number_format($order['total_amount'], 2),
                    'created_at' => $order['created_at'],
                    'delivery_address_id' => (int)$order['delivery_address_id']
                ];
            }
            
            $response = [
                'success' => true,
                'message' => 'Orders retrieved successfully',
                'data' => [
                    'orders' => $processed_orders,
                    'count' => count($processed_orders),
                    'currency' => 'PHP'
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'POST':
            // 새 주문 생성
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            $required_fields = ['user_id', 'delivery_address_id', 'items'];
            foreach ($required_fields as $field) {
                if (!isset($input[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            $pdo->beginTransaction();
            
            // 주문 번호 생성
            $order_number = 'ORD' . date('Ymd') . sprintf('%06d', rand(1, 999999));
            
            // 주문 총액 계산 (간단한 예시)
            $total_amount = 0;
            foreach ($input['items'] as $item) {
                $total_amount += ($item['quantity'] * 100); // 임시 가격
            }
            
            $delivery_fee = 50.00; // 고정 배달비
            $total_amount += $delivery_fee;
            
            // 주문 삽입
            $stmt = $pdo->prepare("
                INSERT INTO delivery_orders 
                (user_id, order_number, status, total_amount, payment_method, delivery_fee, delivery_address_id, notes, created_at) 
                VALUES (?, ?, 'pending', ?, 'cod', ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $input['user_id'],
                $order_number,
                $total_amount,
                $delivery_fee,
                $input['delivery_address_id'],
                $input['notes'] ?? ''
            ]);
            
            $order_id = $pdo->lastInsertId();
            
            // 주문 아이템 삽입
            foreach ($input['items'] as $item) {
                $stmt = $pdo->prepare("
                    INSERT INTO delivery_order_items 
                    (order_id, product_id, quantity, unit_price, total_price, store_id) 
                    VALUES (?, ?, ?, 100, ?, ?)
                ");
                
                $item_total = $item['quantity'] * 100;
                $stmt->execute([
                    $order_id,
                    $item['product_id'],
                    $item['quantity'],
                    $item_total,
                    $item['store_id'] ?? 1
                ]);
            }
            
            $pdo->commit();
            
            $response = [
                'success' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'order_id' => $order_id,
                    'order_number' => $order_number,
                    'total_amount' => $total_amount,
                    'currency' => 'PHP',
                    'formatted_total' => '₱' . number_format($total_amount, 2),
                    'payment_method' => 'cod',
                    'status' => 'pending'
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