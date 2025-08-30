<?php
/**
 * 장바구니 관련 API
 */

// 이미 index.php에서 포함되어 실행됨
if (!isset($conn)) {
    sendError('Database connection not available', 500);
}

// 세션 시작 (비회원 장바구니용)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

switch ($request_method) {
    case 'GET':
        getCart($conn);
        break;
        
    case 'POST':
        if (isset($path_parts[1]) && $path_parts[1] === 'add') {
            addToCart($conn, $data);
        } else {
            sendError('지원하지 않는 요청입니다.', 400);
        }
        break;
        
    case 'PUT':
        updateCart($conn, $data);
        break;
        
    case 'DELETE':
        removeFromCart($conn, $data);
        break;
        
    default:
        sendError('지원하지 않는 메소드입니다.', 405);
}

/**
 * 장바구니 조회
 */
function getCart($conn) {
    try {
        $customer_id = getCustomerId();
        
        if ($customer_id) {
            // 회원 장바구니 조회
            $sql = "SELECT 
                        sc.id,
                        sc.product_id,
                        sc.quantity,
                        p.name_kr,
                        p.name_en,
                        p.image_path,
                        i.selling_price
                    FROM shopping_cart sc
                    JOIN products p ON sc.product_id = p.id
                    LEFT JOIN inventory i ON p.id = i.product_id
                    WHERE sc.customer_id = ? AND p.status = 'active'
                    ORDER BY sc.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$customer_id]);
            $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // 비회원 세션 장바구니 조회
            $session_cart = $_SESSION['cart'] ?? [];
            $cart_items = [];
            
            if (!empty($session_cart)) {
                $product_ids = array_keys($session_cart);
                $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
                
                $sql = "SELECT 
                            p.id as product_id,
                            p.name_kr,
                            p.name_en,
                            p.image_path,
                            i.selling_price
                        FROM products p
                        LEFT JOIN inventory i ON p.id = i.product_id
                        WHERE p.id IN ({$placeholders}) AND p.status = 'active'";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($product_ids);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($products as $product) {
                    $product_id = $product['product_id'];
                    $cart_items[] = [
                        'id' => $product_id,
                        'product_id' => (int)$product_id,
                        'quantity' => (int)$session_cart[$product_id],
                        'name_kr' => $product['name_kr'],
                        'name_en' => $product['name_en'],
                        'image_path' => $product['image_path'],
                        'selling_price' => (float)$product['selling_price']
                    ];
                }
            }
        }
        
        // 데이터 포맷팅
        $total = 0;
        foreach ($cart_items as &$item) {
            $item['product_id'] = (int)$item['product_id'];
            $item['quantity'] = (int)$item['quantity'];
            $item['price'] = (float)$item['selling_price'];
            $item['subtotal'] = $item['price'] * $item['quantity'];
            $total += $item['subtotal'];
            
            // 이미지 URL 생성
            if ($item['image_path']) {
                $item['image'] = '/homekmart/admin/uploads/' . $item['image_path'];
            } else {
                $item['image'] = null;
            }
            
            unset($item['selling_price']);
        }
        
        sendResponse([
            'cart' => $cart_items,
            'total' => $total,
            'count' => count($cart_items)
        ]);
        
    } catch (Exception $e) {
        error_log('Cart Get API Error: ' . $e->getMessage());
        sendError('장바구니 조회 중 오류가 발생했습니다.');
    }
}

/**
 * 장바구니에 상품 추가
 */
function addToCart($conn, $data) {
    $errors = validateInput($data, ['product_id']);
    if (!empty($errors)) {
        sendError('입력 오류', 400, $errors);
    }
    
    $product_id = (int)$data['product_id'];
    $quantity = isset($data['quantity']) ? max(1, (int)$data['quantity']) : 1;
    
    // 상품 존재 확인
    if (!productExists($conn, $product_id)) {
        sendError('상품을 찾을 수 없습니다.', 404);
    }
    
    try {
        $customer_id = getCustomerId();
        
        if ($customer_id) {
            // 회원 장바구니 처리
            // 기존 장바구니 항목 확인
            $check_sql = "SELECT id, quantity FROM shopping_cart WHERE customer_id = ? AND product_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->execute([$customer_id, $product_id]);
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // 수량 업데이트
                $new_quantity = $existing['quantity'] + $quantity;
                $update_sql = "UPDATE shopping_cart SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->execute([$new_quantity, $existing['id']]);
            } else {
                // 새 항목 추가
                $insert_sql = "INSERT INTO shopping_cart (customer_id, product_id, quantity, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->execute([$customer_id, $product_id, $quantity]);
            }
        } else {
            // 비회원 세션 장바구니 처리
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
            
            if (isset($_SESSION['cart'][$product_id])) {
                $_SESSION['cart'][$product_id] += $quantity;
            } else {
                $_SESSION['cart'][$product_id] = $quantity;
            }
        }
        
        sendResponse(['message' => '장바구니에 추가되었습니다.']);
        
    } catch (Exception $e) {
        error_log('Cart Add API Error: ' . $e->getMessage());
        sendError('장바구니 추가 중 오류가 발생했습니다.');
    }
}

/**
 * 장바구니 수량 업데이트
 */
function updateCart($conn, $data) {
    $errors = validateInput($data, ['product_id', 'quantity']);
    if (!empty($errors)) {
        sendError('입력 오류', 400, $errors);
    }
    
    $product_id = (int)$data['product_id'];
    $quantity = max(0, (int)$data['quantity']);
    
    try {
        $customer_id = getCustomerId();
        
        if ($customer_id) {
            if ($quantity > 0) {
                // 수량 업데이트
                $sql = "UPDATE shopping_cart SET quantity = ? WHERE customer_id = ? AND product_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$quantity, $customer_id, $product_id]);
            } else {
                // 수량이 0이면 삭제
                $sql = "DELETE FROM shopping_cart WHERE customer_id = ? AND product_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$customer_id, $product_id]);
            }
        } else {
            // 비회원 세션 장바구니 처리
            if ($quantity > 0) {
                $_SESSION['cart'][$product_id] = $quantity;
            } else {
                unset($_SESSION['cart'][$product_id]);
            }
        }
        
        sendResponse(['message' => '장바구니가 업데이트되었습니다.']);
        
    } catch (Exception $e) {
        error_log('Cart Update API Error: ' . $e->getMessage());
        sendError('장바구니 업데이트 중 오류가 발생했습니다.');
    }
}

/**
 * 장바구니에서 상품 제거
 */
function removeFromCart($conn, $data) {
    $errors = validateInput($data, ['product_id']);
    if (!empty($errors)) {
        sendError('입력 오류', 400, $errors);
    }
    
    $product_id = (int)$data['product_id'];
    
    try {
        $customer_id = getCustomerId();
        
        if ($customer_id) {
            $sql = "DELETE FROM shopping_cart WHERE customer_id = ? AND product_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$customer_id, $product_id]);
        } else {
            unset($_SESSION['cart'][$product_id]);
        }
        
        sendResponse(['message' => '장바구니에서 제거되었습니다.']);
        
    } catch (Exception $e) {
        error_log('Cart Remove API Error: ' . $e->getMessage());
        sendError('장바구니 제거 중 오류가 발생했습니다.');
    }
}

/**
 * 현재 로그인된 고객 ID 반환 (세션 기반)
 */
function getCustomerId() {
    return isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : null;
}

/**
 * 상품 존재 여부 확인
 */
function productExists($conn, $product_id) {
    $sql = "SELECT id FROM products WHERE id = ? AND status = 'active'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$product_id]);
    return $stmt->fetchColumn() !== false;
}