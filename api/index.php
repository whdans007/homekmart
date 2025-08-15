<?php
/**
 * 배달 앱 API 메인 라우터
 * RESTful API 엔드포인트 라우팅 처리
 */

require_once __DIR__ . '/config/api_config.php';

// 요청 URI 파싱
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// API 기본 경로 제거
$api_path = str_replace('/min/api', '', $path);
$api_path = trim($api_path, '/');

// 경로를 배열로 분할
$path_parts = explode('/', $api_path);
$endpoint = $path_parts[0] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// API 정보 엔드포인트
if (empty($endpoint) || $endpoint === 'info') {
    api_success([
        'name' => 'HOME K MART Delivery API',
        'version' => '1.0.0',
        'description' => 'Philippines delivery app API with COD payment support',
        'endpoints' => [
            'auth' => [
                'POST /api/auth/login' => 'User login (email/password)',
                'POST /api/auth/google' => 'Google OAuth login',
                'POST /api/auth/logout' => 'User logout',
                'GET /api/auth/profile' => 'Get user profile'
            ],
            'products' => [
                'GET /api/products' => 'Get products list',
                'GET /api/products/{id}' => 'Get product details',
                'GET /api/products/search' => 'Search products'
            ],
            'cart' => [
                'GET /api/cart' => 'Get cart items',
                'POST /api/cart' => 'Add item to cart',
                'PUT /api/cart/{id}' => 'Update cart item',
                'DELETE /api/cart/{id}' => 'Remove cart item'
            ],
            'orders' => [
                'GET /api/orders' => 'Get user orders',
                'POST /api/orders' => 'Create new order',
                'GET /api/orders/{id}' => 'Get order details',
                'PUT /api/orders/{id}/status' => 'Update order status'
            ],
            'delivery' => [
                'GET /api/delivery/addresses' => 'Get delivery addresses',
                'POST /api/delivery/addresses' => 'Add delivery address',
                'GET /api/delivery/zones' => 'Get delivery zones',
                'GET /api/delivery/track/{order_id}' => 'Track delivery'
            ],
            'locations' => [
                'GET /api/locations/zones' => 'Get service zones',
                'POST /api/locations/validate' => 'Validate address'
            ]
        ],
        'supported_currencies' => ['PHP'],
        'supported_languages' => ['en', 'ko'],
        'payment_methods' => ['cod', 'gcash', 'paymaya']
    ], 'API Information');
}

// 엔드포인트별 라우팅
switch ($endpoint) {
    case 'auth':
        require_once __DIR__ . '/auth/auth_routes.php';
        break;
        
    case 'products':
        require_once __DIR__ . '/products/products_routes.php';
        break;
        
    case 'cart':
        require_once __DIR__ . '/cart/cart_routes.php';
        break;
        
    case 'orders':
        require_once __DIR__ . '/orders/orders_routes.php';
        break;
        
    case 'delivery':
        require_once __DIR__ . '/delivery/delivery_routes.php';
        break;
        
    case 'locations':
        require_once __DIR__ . '/locations/locations_routes.php';
        break;
        
    default:
        api_not_found('API endpoint not found');
}
?>