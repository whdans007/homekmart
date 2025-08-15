<?php
/**
 * 배달 API 라우트
 * 배달 주소, 추적, 지역 관리
 */

$delivery_path = $path_parts[1] ?? '';
$delivery_action = $path_parts[2] ?? '';

switch ($method) {
    case 'GET':
        switch ($delivery_path) {
            case 'addresses':
                // GET /api/delivery/addresses - 배달 주소 목록
                require_once __DIR__ . '/addresses.php';
                break;
                
            case 'zones':
                // GET /api/delivery/zones - 배달 지역 목록
                require_once __DIR__ . '/zones.php';
                break;
                
            case 'track':
                // GET /api/delivery/track/{order_id} - 배달 추적
                if (is_numeric($delivery_action)) {
                    $_GET['order_id'] = $delivery_action;
                    require_once __DIR__ . '/track_delivery.php';
                } else {
                    api_not_found('Order ID required for tracking');
                }
                break;
                
            case 'fee':
                // GET /api/delivery/fee - 배달비 계산
                require_once __DIR__ . '/calculate_fee.php';
                break;
                
            default:
                api_not_found('Delivery endpoint not found');
        }
        break;
        
    case 'POST':
        switch ($delivery_path) {
            case 'addresses':
                // POST /api/delivery/addresses - 배달 주소 추가
                require_once __DIR__ . '/add_address.php';
                break;
                
            case 'validate-address':
                // POST /api/delivery/validate-address - 주소 유효성 검증
                require_once __DIR__ . '/validate_address.php';
                break;
                
            case 'update-tracking':
                // POST /api/delivery/update-tracking - 배달 상태 업데이트
                require_once __DIR__ . '/update_tracking.php';
                break;
                
            default:
                api_not_found('Delivery endpoint not found');
        }
        break;
        
    case 'PUT':
        switch ($delivery_path) {
            case 'addresses':
                // PUT /api/delivery/addresses/{id} - 배달 주소 수정
                if (is_numeric($delivery_action)) {
                    $_PUT['address_id'] = $delivery_action;
                    require_once __DIR__ . '/update_address.php';
                } else {
                    api_not_found('Address ID required');
                }
                break;
                
            default:
                api_not_found('Delivery endpoint not found');
        }
        break;
        
    case 'DELETE':
        switch ($delivery_path) {
            case 'addresses':
                // DELETE /api/delivery/addresses/{id} - 배달 주소 삭제
                if (is_numeric($delivery_action)) {
                    $_DELETE['address_id'] = $delivery_action;
                    require_once __DIR__ . '/delete_address.php';
                } else {
                    api_not_found('Address ID required');
                }
                break;
                
            default:
                api_not_found('Delivery endpoint not found');
        }
        break;
        
    default:
        api_error('Method not allowed', 405);
}
?>