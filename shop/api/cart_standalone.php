<?php
/**
 * 장바구니 관련 API (독립 실행형)
 * Updated: 2025-08-31
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// OPTIONS 요청 처리 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../../config/db_config.php';

// 세션 시작
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 요청 메서드와 경로 파싱
$request_method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];
$path_parts = explode('/', trim(str_replace('/homekmart/shop/api/cart_standalone.php', '', $path), '/'));

// JSON 데이터 파싱
$data = [];
if (in_array($request_method, ['POST', 'PUT', 'DELETE'])) {
    $json = file_get_contents('php://input');
    if ($json) {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendError('잘못된 JSON 형식입니다.', 400);
        }
    }
}

try {
    $conn = get_db_connection();
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }

    switch ($request_method) {
        case 'GET':
            getCart($conn);
            break;
            
        case 'POST':
            if (isset($_GET['action']) && $_GET['action'] === 'add') {
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
} catch (Exception $e) {
    error_log('Cart API Error: ' . $e->getMessage());
    sendError('서버 오류가 발생했습니다.', 500);
}

/**
 * 장바구니 조회
 */
function getCart($conn) {
    try {
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
                    WHERE p.id IN ({$placeholders}) AND (p.status = 'active' OR p.status IS NULL)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(str_repeat('i', count($product_ids)), ...$product_ids);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($product = $result->fetch_assoc()) {
                $product_id = $product['product_id'];
                $cart_items[] = [
                    'id' => $product_id,
                    'product_id' => (int)$product_id,
                    'quantity' => (int)$session_cart[$product_id],
                    'name_kr' => $product['name_kr'],
                    'name_en' => $product['name_en'],
                    'image_path' => $product['image_path'],
                    'price' => (float)$product['selling_price'],
                    'selling_price' => (float)$product['selling_price']
                ];
            }
            $stmt->close();
        }
        
        // 데이터 포맷팅
        $total = 0;
        foreach ($cart_items as &$item) {
            $item['subtotal'] = $item['price'] * $item['quantity'];
            $total += $item['subtotal'];
            
            // 이미지 URL 생성
            if ($item['image_path']) {
                $item['image'] = '/homekmart/admin/uploads/' . $item['image_path'];
            } else {
                $item['image'] = null;
            }
        }
        
        echo json_encode([
            'success' => true,
            'cart' => $cart_items,
            'total' => $total,
            'count' => array_sum($session_cart),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        error_log('Cart Get API Error: ' . $e->getMessage());
        sendError('장바구니 조회 중 오류가 발생했습니다.');
    }
}

/**
 * 장바구니에 상품 추가
 */
function addToCart($conn, $data) {
    if (!isset($data['product_id'])) {
        sendError('상품 ID가 필요합니다.', 400);
    }
    
    $product_id = (int)$data['product_id'];
    $quantity = isset($data['quantity']) ? max(1, (int)$data['quantity']) : 1;
    
    // 상품 존재 확인
    $check_sql = "SELECT id FROM products WHERE id = ? AND (status = 'active' OR status IS NULL)";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('i', $product_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        $check_stmt->close();
        sendError('상품을 찾을 수 없습니다.', 404);
    }
    $check_stmt->close();
    
    try {
        // 비회원 세션 장바구니 처리
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id] += $quantity;
        } else {
            $_SESSION['cart'][$product_id] = $quantity;
        }
        
        echo json_encode([
            'success' => true,
            'message' => '장바구니에 추가되었습니다.',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        error_log('Cart Add API Error: ' . $e->getMessage());
        sendError('장바구니 추가 중 오류가 발생했습니다.');
    }
}

/**
 * 장바구니 수량 업데이트
 */
function updateCart($conn, $data) {
    if (!isset($data['product_id']) || !isset($data['quantity'])) {
        sendError('상품 ID와 수량이 필요합니다.', 400);
    }
    
    $product_id = (int)$data['product_id'];
    $quantity = max(0, (int)$data['quantity']);
    
    try {
        // 비회원 세션 장바구니 처리
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        if ($quantity > 0) {
            $_SESSION['cart'][$product_id] = $quantity;
        } else {
            unset($_SESSION['cart'][$product_id]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => '장바구니가 업데이트되었습니다.',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        error_log('Cart Update API Error: ' . $e->getMessage());
        sendError('장바구니 업데이트 중 오류가 발생했습니다.');
    }
}

/**
 * 장바구니에서 상품 제거
 */
function removeFromCart($conn, $data) {
    if (!isset($data['product_id'])) {
        sendError('상품 ID가 필요합니다.', 400);
    }
    
    $product_id = (int)$data['product_id'];
    
    try {
        unset($_SESSION['cart'][$product_id]);
        
        echo json_encode([
            'success' => true,
            'message' => '장바구니에서 제거되었습니다.',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        error_log('Cart Remove API Error: ' . $e->getMessage());
        sendError('장바구니 제거 중 오류가 발생했습니다.');
    }
}

/**
 * 에러 응답 전송
 */
function sendError($message, $code = 500, $details = null) {
    http_response_code($code);
    $response = [
        'success' => false,
        'error' => true,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($details) {
        $response['details'] = $details;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>