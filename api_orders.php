<?php
/**
 * 주문 관리 API (COD 중심)
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
            // 주문 목록 조회
            $user_id = (int)($_GET['user_id'] ?? 0);
            $status = $_GET['status'] ?? '';
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            
            if (!$user_id) {
                throw new Exception('User ID is required');
            }
            
            $where_conditions = ['o.user_id = ?'];
            $params = [$user_id];
            
            if ($status) {
                $where_conditions[] = 'o.status = ?';
                $params[] = $status;
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            $sql = "
                SELECT 
                    o.id, o.order_number, o.status, o.payment_method,
                    o.total_amount, o.delivery_fee, o.payment_status,
                    o.created_at, o.estimated_delivery_time,
                    da.recipient_name, da.phone_number, da.full_address,
                    da.barangay, da.landmark,
                    COUNT(oi.id) as item_count
                FROM delivery_orders o
                LEFT JOIN delivery_addresses da ON o.delivery_address_id = da.id
                LEFT JOIN delivery_order_items oi ON o.id = oi.order_id
                WHERE $where_clause
                GROUP BY o.id
                ORDER BY o.created_at DESC
                LIMIT $limit OFFSET $offset
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($orders as &$order) {
                $order['id'] = (int)$order['id'];
                $order['total_amount'] = (float)$order['total_amount'];
                $order['delivery_fee'] = (float)$order['delivery_fee'];
                $order['item_count'] = (int)$order['item_count'];
                $order['formatted_total'] = '₱' . number_format($order['total_amount'], 2);
                $order['formatted_delivery_fee'] = '₱' . number_format($order['delivery_fee'], 2);
                $order['is_cod'] = $order['payment_method'] === 'cod';
            }
            
            $response = [
                'success' => true,
                'data' => [
                    'orders' => $orders,
                    'count' => count($orders),
                    'limit' => $limit,
                    'offset' => $offset
                ],
                'message' => 'Orders retrieved successfully'
            ];
            break;
            
        case 'POST':
            // 새 주문 생성
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            $user_id = (int)($input['user_id'] ?? 0);
            $delivery_address_id = (int)($input['delivery_address_id'] ?? 0);
            $items = $input['items'] ?? [];
            $payment_method = $input['payment_method'] ?? 'cod';
            $notes = $input['notes'] ?? '';
            
            if (!$user_id || !$delivery_address_id || empty($items)) {
                throw new Exception('User ID, delivery address, and items are required');
            }
            
            // 배달 주소 확인
            $addr_stmt = $pdo->prepare("SELECT * FROM delivery_addresses WHERE id = ? AND user_id = ?");
            $addr_stmt->execute([$delivery_address_id, $user_id]);
            $delivery_address = $addr_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$delivery_address) {
                throw new Exception('Invalid delivery address');
            }
            
            $pdo->beginTransaction();
            
            try {
                // 주문 번호 생성
                $order_number = 'ORD' . date('Ymd') . sprintf('%06d', mt_rand(1, 999999));
                
                // 상품 가격 계산
                $total_amount = 0;
                $delivery_fee = 50.00; // 기본 배달비 (PHP)
                $valid_items = [];
                
                foreach ($items as $item) {
                    $product_id = (int)($item['product_id'] ?? 0);
                    $quantity = max(1, (int)($item['quantity'] ?? 1));
                    $store_id = (int)($item['store_id'] ?? 1);
                    
                    // 상품 및 재고 확인
                    $product_stmt = $pdo->prepare("
                        SELECT p.id, p.name, i.selling_price, i.quantity as stock
                        FROM products p
                        JOIN inventory i ON p.id = i.product_id
                        WHERE p.id = ? AND i.store_id = ? AND i.quantity >= ?
                        AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
                    ");
                    $product_stmt->execute([$product_id, $store_id, $quantity]);
                    $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$product) {
                        throw new Exception("Product ID $product_id not available or insufficient stock");
                    }
                    
                    $item_total = $product['selling_price'] * $quantity;
                    $total_amount += $item_total;
                    
                    $valid_items[] = [
                        'product_id' => $product_id,
                        'product_name' => $product['name'],
                        'quantity' => $quantity,
                        'unit_price' => (float)$product['selling_price'],
                        'total_price' => $item_total,
                        'store_id' => $store_id
                    ];
                }
                
                // 주문 생성
                $order_stmt = $pdo->prepare("
                    INSERT INTO delivery_orders (
                        user_id, order_number, delivery_address_id, 
                        total_amount, delivery_fee, payment_method, 
                        payment_status, status, notes, 
                        estimated_delivery_time, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'confirmed', ?, 
                             DATE_ADD(NOW(), INTERVAL 2 HOUR), NOW())
                ");
                
                $order_stmt->execute([
                    $user_id, $order_number, $delivery_address_id,
                    $total_amount, $delivery_fee, $payment_method, $notes
                ]);
                
                $order_id = $pdo->lastInsertId();
                
                // 주문 상품 추가 및 재고 업데이트
                foreach ($valid_items as $item) {
                    // 주문 상품 추가
                    $item_stmt = $pdo->prepare("
                        INSERT INTO delivery_order_items (
                            order_id, product_id, quantity, unit_price, total_price
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    $item_stmt->execute([
                        $order_id, $item['product_id'], $item['quantity'],
                        $item['unit_price'], $item['total_price']
                    ]);
                    
                    // 재고 업데이트
                    $stock_stmt = $pdo->prepare("
                        UPDATE inventory 
                        SET quantity = quantity - ? 
                        WHERE product_id = ? AND store_id = ?
                    ");
                    $stock_stmt->execute([
                        $item['quantity'], $item['product_id'], $item['store_id']
                    ]);
                }
                
                // 배송 추적 생성
                $tracking_stmt = $pdo->prepare("
                    INSERT INTO delivery_tracking (
                        order_id, status, location, notes, created_at
                    ) VALUES (?, 'confirmed', '매장', '주문이 확인되었습니다.', NOW())
                ");
                $tracking_stmt->execute([$order_id]);
                
                $pdo->commit();
                
                $response = [
                    'success' => true,
                    'data' => [
                        'order_id' => $order_id,
                        'order_number' => $order_number,
                        'total_amount' => $total_amount,
                        'delivery_fee' => $delivery_fee,
                        'formatted_total' => '₱' . number_format($total_amount + $delivery_fee, 2),
                        'payment_method' => $payment_method,
                        'status' => 'confirmed',
                        'estimated_delivery' => '2 hours',
                        'items' => $valid_items
                    ],
                    'message' => 'Order created successfully'
                ];
                
            } catch (Exception $e) {
                $pdo->rollback();
                throw $e;
            }
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    http_response_code($method === 'POST' ? 400 : 500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>