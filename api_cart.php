<?php
/**
 * 장바구니 관리 API
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
            // 장바구니 조회
            $user_id = (int)($_GET['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('User ID is required');
            }
            
            $sql = "
                SELECT 
                    c.id, c.product_id, c.quantity, c.store_id,
                    p.name as product_name, p.description, p.image_url, p.barcode,
                    i.selling_price, i.quantity as stock,
                    cat.name as category_name,
                    b.name_ko as brand_name,
                    s.name as store_name,
                    c.created_at
                FROM shopping_cart c
                JOIN products p ON c.product_id = p.id
                JOIN inventory i ON p.id = i.product_id AND c.store_id = i.store_id
                LEFT JOIN categories cat ON p.category_id = cat.id
                LEFT JOIN brands b ON p.brand_id = b.id
                LEFT JOIN stores s ON c.store_id = s.id
                WHERE c.user_id = ? 
                  AND i.quantity > 0
                  AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
                ORDER BY c.created_at DESC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id]);
            $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $total_amount = 0;
            $total_items = 0;
            $stores = [];
            
            foreach ($cart_items as &$item) {
                $item['id'] = (int)$item['id'];
                $item['product_id'] = (int)$item['product_id'];
                $item['quantity'] = (int)$item['quantity'];
                $item['store_id'] = (int)$item['store_id'];
                $item['selling_price'] = (float)$item['selling_price'];
                $item['stock'] = (int)$item['stock'];
                
                // 재고 확인 및 수량 조정
                if ($item['quantity'] > $item['stock']) {
                    $item['quantity'] = $item['stock'];
                    // 실제 장바구니 수량도 업데이트
                    $update_stmt = $pdo->prepare("UPDATE shopping_cart SET quantity = ? WHERE id = ?");
                    $update_stmt->execute([$item['stock'], $item['id']]);
                }
                
                $item['total_price'] = $item['selling_price'] * $item['quantity'];
                $item['formatted_price'] = '₱' . number_format($item['selling_price'], 2);
                $item['formatted_total'] = '₱' . number_format($item['total_price'], 2);
                $item['available'] = $item['stock'] >= $item['quantity'];
                
                $total_amount += $item['total_price'];
                $total_items += $item['quantity'];
                
                // 점포별 그룹화
                if (!isset($stores[$item['store_id']])) {
                    $stores[$item['store_id']] = [
                        'store_id' => $item['store_id'],
                        'store_name' => $item['store_name'],
                        'items' => [],
                        'subtotal' => 0,
                        'item_count' => 0
                    ];
                }
                
                $stores[$item['store_id']]['items'][] = $item;
                $stores[$item['store_id']]['subtotal'] += $item['total_price'];
                $stores[$item['store_id']]['item_count'] += $item['quantity'];
            }
            
            // 점포별 배달비 계산 (점포당 50페소)
            $delivery_fee = count($stores) * 50.00;
            $grand_total = $total_amount + $delivery_fee;
            
            foreach ($stores as &$store) {
                $store['formatted_subtotal'] = '₱' . number_format($store['subtotal'], 2);
                $store['delivery_fee'] = 50.00;
                $store['formatted_delivery_fee'] = '₱50.00';
            }
            
            $response = [
                'success' => true,
                'data' => [
                    'cart_items' => $cart_items,
                    'stores' => array_values($stores),
                    'summary' => [
                        'total_items' => $total_items,
                        'total_amount' => $total_amount,
                        'delivery_fee' => $delivery_fee,
                        'grand_total' => $grand_total,
                        'formatted_total' => '₱' . number_format($total_amount, 2),
                        'formatted_delivery_fee' => '₱' . number_format($delivery_fee, 2),
                        'formatted_grand_total' => '₱' . number_format($grand_total, 2),
                        'store_count' => count($stores)
                    ],
                    'currency' => 'PHP'
                ],
                'message' => 'Cart retrieved successfully'
            ];
            break;
            
        case 'POST':
            // 장바구니에 상품 추가
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            $user_id = (int)($input['user_id'] ?? 0);
            $product_id = (int)($input['product_id'] ?? 0);
            $quantity = max(1, (int)($input['quantity'] ?? 1));
            $store_id = (int)($input['store_id'] ?? 1);
            
            if (!$user_id || !$product_id) {
                throw new Exception('User ID and Product ID are required');
            }
            
            // 상품 및 재고 확인
            $product_stmt = $pdo->prepare("
                SELECT p.id, p.name, i.selling_price, i.quantity as stock
                FROM products p
                JOIN inventory i ON p.id = i.product_id
                WHERE p.id = ? AND i.store_id = ?
                  AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            ");
            $product_stmt->execute([$product_id, $store_id]);
            $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception('Product not found or not available');
            }
            
            if ($quantity > $product['stock']) {
                throw new Exception("Requested quantity ($quantity) exceeds available stock ({$product['stock']})");
            }
            
            // 기존 장바구니 항목 확인
            $existing_stmt = $pdo->prepare("
                SELECT id, quantity FROM shopping_cart 
                WHERE user_id = ? AND product_id = ? AND store_id = ?
            ");
            $existing_stmt->execute([$user_id, $product_id, $store_id]);
            $existing_item = $existing_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_item) {
                // 기존 항목 업데이트
                $new_quantity = $existing_item['quantity'] + $quantity;
                
                if ($new_quantity > $product['stock']) {
                    throw new Exception("Total quantity ($new_quantity) would exceed available stock ({$product['stock']})");
                }
                
                $update_stmt = $pdo->prepare("
                    UPDATE shopping_cart 
                    SET quantity = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $update_stmt->execute([$new_quantity, $existing_item['id']]);
                
                $cart_item_id = $existing_item['id'];
                $final_quantity = $new_quantity;
            } else {
                // 새 항목 추가
                $insert_stmt = $pdo->prepare("
                    INSERT INTO shopping_cart (user_id, product_id, quantity, store_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                $insert_stmt->execute([$user_id, $product_id, $quantity, $store_id]);
                
                $cart_item_id = $pdo->lastInsertId();
                $final_quantity = $quantity;
            }
            
            $total_price = $product['selling_price'] * $final_quantity;
            
            $response = [
                'success' => true,
                'data' => [
                    'cart_item_id' => $cart_item_id,
                    'product_id' => $product_id,
                    'product_name' => $product['name'],
                    'quantity' => $final_quantity,
                    'unit_price' => (float)$product['selling_price'],
                    'total_price' => $total_price,
                    'formatted_total' => '₱' . number_format($total_price, 2),
                    'stock_remaining' => $product['stock'] - $final_quantity
                ],
                'message' => $existing_item ? 'Cart item updated' : 'Item added to cart'
            ];
            break;
            
        case 'PUT':
            // 장바구니 수량 수정
            $input = json_decode(file_get_contents('php://input'), true);
            $cart_item_id = (int)($_GET['id'] ?? 0);
            
            if (!$input || !$cart_item_id) {
                throw new Exception('Cart item ID and valid JSON input required');
            }
            
            $user_id = (int)($input['user_id'] ?? 0);
            $quantity = max(1, (int)($input['quantity'] ?? 1));
            
            if (!$user_id) {
                throw new Exception('User ID is required');
            }
            
            // 장바구니 항목 확인
            $cart_stmt = $pdo->prepare("
                SELECT c.id, c.product_id, c.store_id, c.quantity,
                       i.quantity as stock, i.selling_price
                FROM shopping_cart c
                JOIN inventory i ON c.product_id = i.product_id AND c.store_id = i.store_id
                WHERE c.id = ? AND c.user_id = ?
            ");
            $cart_stmt->execute([$cart_item_id, $user_id]);
            $cart_item = $cart_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cart_item) {
                throw new Exception('Cart item not found or access denied');
            }
            
            if ($quantity > $cart_item['stock']) {
                throw new Exception("Requested quantity ($quantity) exceeds available stock ({$cart_item['stock']})");
            }
            
            $update_stmt = $pdo->prepare("
                UPDATE shopping_cart 
                SET quantity = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_stmt->execute([$quantity, $cart_item_id]);
            
            $total_price = $cart_item['selling_price'] * $quantity;
            
            $response = [
                'success' => true,
                'data' => [
                    'cart_item_id' => $cart_item_id,
                    'quantity' => $quantity,
                    'unit_price' => (float)$cart_item['selling_price'],
                    'total_price' => $total_price,
                    'formatted_total' => '₱' . number_format($total_price, 2)
                ],
                'message' => 'Cart item updated successfully'
            ];
            break;
            
        case 'DELETE':
            // 장바구니에서 상품 제거
            $cart_item_id = (int)($_GET['id'] ?? 0);
            $user_id = (int)($_GET['user_id'] ?? 0);
            
            if (!$cart_item_id || !$user_id) {
                throw new Exception('Cart item ID and User ID are required');
            }
            
            $stmt = $pdo->prepare("DELETE FROM shopping_cart WHERE id = ? AND user_id = ?");
            $result = $stmt->execute([$cart_item_id, $user_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Cart item not found or access denied');
            }
            
            $response = [
                'success' => true,
                'data' => ['cart_item_id' => $cart_item_id],
                'message' => 'Item removed from cart'
            ];
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code($method === 'POST' ? 400 : ($method === 'PUT' ? 400 : ($method === 'DELETE' ? 400 : 500)));
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>