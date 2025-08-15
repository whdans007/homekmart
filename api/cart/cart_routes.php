<?php
/**
 * 장바구니 API 라우트
 */

$cart_path = $path_parts[1] ?? '';

switch ($method) {
    case 'GET':
        switch ($cart_path) {
            case '':
                // GET /api/cart - 장바구니 조회
                require_once __DIR__ . '/get_cart.php';
                break;
                
            case 'count':
                // GET /api/cart/count - 장바구니 아이템 수
                require_once __DIR__ . '/cart_count.php';
                break;
                
            default:
                api_not_found('Cart endpoint not found');
        }
        break;
        
    case 'POST':
        switch ($cart_path) {
            case '':
                // POST /api/cart - 장바구니에 아이템 추가
                require_once __DIR__ . '/add_to_cart.php';
                break;
                
            case 'clear':
                // POST /api/cart/clear - 장바구니 비우기
                require_once __DIR__ . '/clear_cart.php';
                break;
                
            default:
                api_not_found('Cart endpoint not found');
        }
        break;
        
    case 'PUT':
        // PUT /api/cart/{id} - 장바구니 아이템 수량 변경
        if (is_numeric($cart_path)) {
            $_PUT['cart_id'] = $cart_path;
            require_once __DIR__ . '/update_cart.php';
        } else {
            api_not_found('Cart item not found');
        }
        break;
        
    case 'DELETE':
        // DELETE /api/cart/{id} - 장바구니 아이템 삭제
        if (is_numeric($cart_path)) {
            $_DELETE['cart_id'] = $cart_path;
            require_once __DIR__ . '/remove_from_cart.php';
        } else {
            api_not_found('Cart item not found');
        }
        break;
        
    default:
        api_error('Method not allowed', 405);
}
?>