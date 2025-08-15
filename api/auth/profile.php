<?php
/**
 * 사용자 프로필 조회 API
 */

// GET 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed', 405);
}

// 인증 필요
$auth = require_auth();

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 사용자 상세 정보 조회
    $stmt = $pdo->prepare("
        SELECT 
            u.id, u.username, u.email, u.full_name, u.role, 
            u.store_id, u.google_id, u.auth_provider, u.profile_image_url, 
            u.preferred_language, u.phone, u.is_delivery_available,
            u.default_delivery_address_id, u.created_at, u.last_login,
            s.name as store_name,
            s.address as store_address
        FROM users u
        LEFT JOIN stores s ON u.store_id = s.id
        WHERE u.id = ? AND u.is_active = 1
    ");
    
    $stmt->execute([$auth['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        api_error('User not found', 404, 'USER_NOT_FOUND');
    }
    
    // 기본 배달 주소 조회
    $default_address = null;
    if ($user['default_delivery_address_id']) {
        $addr_stmt = $pdo->prepare("
            SELECT id, address_name, detailed_address, city, province, 
                   landmark, latitude, longitude
            FROM delivery_addresses 
            WHERE id = ? AND user_id = ? AND is_active = 1
        ");
        $addr_stmt->execute([$user['default_delivery_address_id'], $auth['user_id']]);
        $default_address = $addr_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 배달 주소 개수 조회
    $addr_count_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM delivery_addresses 
        WHERE user_id = ? AND is_active = 1
    ");
    $addr_count_stmt->execute([$auth['user_id']]);
    $address_count = $addr_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 주문 통계 조회
    $order_stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_orders,
            COUNT(CASE WHEN order_status = 'delivered' THEN 1 END) as completed_orders,
            COUNT(CASE WHEN order_status IN ('pending', 'confirmed', 'preparing', 'out_for_delivery') THEN 1 END) as active_orders,
            COALESCE(SUM(CASE WHEN order_status = 'delivered' THEN total_amount END), 0) as total_spent
        FROM delivery_orders 
        WHERE user_id = ?
    ");
    $order_stats_stmt->execute([$auth['user_id']]);
    $order_stats = $order_stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // 장바구니 아이템 수 조회
    $cart_count_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM shopping_cart 
        WHERE user_id = ?
    ");
    $cart_count_stmt->execute([$auth['user_id']]);
    $cart_count = $cart_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 위시리스트 아이템 수 조회
    $wishlist_count_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM wishlists 
        WHERE user_id = ?
    ");
    $wishlist_count_stmt->execute([$auth['user_id']]);
    $wishlist_count = $wishlist_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 사용자 권한 조회
    $permissions = get_user_permissions($auth['user_id']);
    
    // 응답 데이터 구성
    $profile_data = [
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'phone' => $user['phone'],
            'profile_image_url' => $user['profile_image_url'],
            'preferred_language' => $user['preferred_language'] ?? 'en',
            'auth_provider' => $user['auth_provider'] ?? 'email',
            'is_delivery_available' => (bool)$user['is_delivery_available'],
            'created_at' => $user['created_at'],
            'last_login' => $user['last_login']
        ],
        'store' => $user['store_id'] ? [
            'id' => (int)$user['store_id'],
            'name' => $user['store_name'],
            'address' => $user['store_address']
        ] : null,
        'delivery' => [
            'default_address' => $default_address ? [
                'id' => (int)$default_address['id'],
                'name' => $default_address['address_name'],
                'address' => $default_address['detailed_address'],
                'city' => $default_address['city'],
                'province' => $default_address['province'],
                'landmark' => $default_address['landmark'],
                'coordinates' => [
                    'latitude' => $default_address['latitude'] ? (float)$default_address['latitude'] : null,
                    'longitude' => $default_address['longitude'] ? (float)$default_address['longitude'] : null
                ]
            ] : null,
            'address_count' => (int)$address_count
        ],
        'statistics' => [
            'orders' => [
                'total' => (int)$order_stats['total_orders'],
                'completed' => (int)$order_stats['completed_orders'],
                'active' => (int)$order_stats['active_orders'],
                'total_spent' => (float)$order_stats['total_spent']
            ],
            'cart_items' => (int)$cart_count,
            'wishlist_items' => (int)$wishlist_count
        ],
        'permissions' => $permissions,
        'session' => [
            'login_time' => $_SESSION['login_time'] ?? null,
            'session_duration' => isset($_SESSION['login_time']) ? time() - $_SESSION['login_time'] : null
        ]
    ];
    
    api_success($profile_data, 'Profile retrieved successfully');
    
} catch (PDOException $e) {
    log_api_error("Database error retrieving profile", [
        'user_id' => $auth['user_id'],
        'error' => $e->getMessage()
    ]);
    api_error('Failed to retrieve profile', 500, 'DATABASE_ERROR');
    
} catch (Exception $e) {
    log_api_error("Unexpected error retrieving profile", [
        'user_id' => $auth['user_id'],
        'error' => $e->getMessage()
    ]);
    api_error('Failed to retrieve profile', 500, 'UNEXPECTED_ERROR');
}
?>