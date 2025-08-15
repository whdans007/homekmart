<?php
/**
 * 주문 API 라우트
 * COD 결제 중심 주문 관리
 */

$order_path = $path_parts[1] ?? '';
$order_action = $path_parts[2] ?? '';

switch ($method) {
    case 'GET':
        switch ($order_path) {
            case '':
                // GET /api/orders - 사용자 주문 목록
                require_once __DIR__ . '/list_orders.php';
                break;
                
            case 'summary':
                // GET /api/orders/summary - 주문 요약 통계
                require_once __DIR__ . '/order_summary.php';
                break;
                
            default:
                // GET /api/orders/{id} - 주문 상세
                if (is_numeric($order_path)) {
                    $_GET['order_id'] = $order_path;
                    require_once __DIR__ . '/order_detail.php';
                } else {
                    api_not_found('Order endpoint not found');
                }
        }
        break;
        
    case 'POST':
        switch ($order_path) {
            case '':
                // POST /api/orders - 새 주문 생성
                require_once __DIR__ . '/create_order.php';
                break;
                
            case 'estimate':
                // POST /api/orders/estimate - 주문 예상 금액 계산
                require_once __DIR__ . '/estimate_order.php';
                break;
                
            default:
                api_not_found('Order endpoint not found');
        }
        break;
        
    case 'PUT':
        if (is_numeric($order_path)) {
            $_PUT['order_id'] = $order_path;
            
            switch ($order_action) {
                case 'status':
                    // PUT /api/orders/{id}/status - 주문 상태 변경
                    require_once __DIR__ . '/update_order_status.php';
                    break;
                    
                case 'cancel':
                    // PUT /api/orders/{id}/cancel - 주문 취소
                    require_once __DIR__ . '/cancel_order.php';
                    break;
                    
                default:
                    api_not_found('Order action not found');
            }
        } else {
            api_not_found('Order not found');
        }
        break;
        
    default:
        api_error('Method not allowed', 405);
}
?>