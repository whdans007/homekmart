<?php
/**
 * 인증 상태 확인 API
 * 현재 세션의 인증 상태를 빠르게 확인
 */

// GET 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed', 405);
}

// 세션 인증 확인 (require_auth 대신 verify_session_auth 직접 사용)
$auth = verify_session_auth();

if (!$auth) {
    api_success([
        'authenticated' => false,
        'message' => 'Not authenticated'
    ], 'Authentication check completed');
}

try {
    // 기본 응답 데이터
    $response_data = [
        'authenticated' => true,
        'user' => [
            'id' => (int)$auth['user_id'],
            'username' => $auth['username'],
            'full_name' => $auth['full_name'],
            'role' => $auth['role'],
            'store_id' => $auth['store_id'] ? (int)$auth['store_id'] : null
        ],
        'session' => [
            'login_time' => $_SESSION['login_time'] ?? null,
            'session_duration' => isset($_SESSION['login_time']) ? time() - $_SESSION['login_time'] : null,
            'auth_provider' => $_SESSION['auth_provider'] ?? 'email'
        ],
        'delivery_access' => true
    ];
    
    // 상세 정보가 요청된 경우
    if (isset($_GET['detailed']) && $_GET['detailed'] === 'true') {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 사용자 상세 정보 조회
        $stmt = $pdo->prepare("
            SELECT 
                u.email, u.profile_image_url, u.preferred_language,
                u.is_delivery_available, s.name as store_name
            FROM users u
            LEFT JOIN stores s ON u.store_id = s.id
            WHERE u.id = ? AND u.is_active = 1
        ");
        
        $stmt->execute([$auth['user_id']]);
        $user_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user_details) {
            $response_data['user']['email'] = $user_details['email'];
            $response_data['user']['profile_image_url'] = $user_details['profile_image_url'];
            $response_data['user']['preferred_language'] = $user_details['preferred_language'] ?? 'en';
            $response_data['user']['store_name'] = $user_details['store_name'];
            $response_data['delivery_access'] = (bool)$user_details['is_delivery_available'];
        }
        
        // 사용자 권한 조회
        $response_data['permissions'] = get_user_permissions($auth['user_id']);
        
        // 장바구니 아이템 수 조회
        $cart_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM shopping_cart WHERE user_id = ?");
        $cart_stmt->execute([$auth['user_id']]);
        $response_data['cart_count'] = (int)$cart_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // 활성 주문 수 조회
        $order_stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM delivery_orders 
            WHERE user_id = ? AND order_status IN ('pending', 'confirmed', 'preparing', 'out_for_delivery')
        ");
        $order_stmt->execute([$auth['user_id']]);
        $response_data['active_orders_count'] = (int)$order_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    api_success($response_data, 'Authentication check completed');
    
} catch (PDOException $e) {
    // 데이터베이스 에러가 있어도 기본 인증 정보는 반환
    log_api_error("Database error during auth check", [
        'user_id' => $auth['user_id'],
        'error' => $e->getMessage()
    ]);
    
    api_success([
        'authenticated' => true,
        'user' => [
            'id' => (int)$auth['user_id'],
            'username' => $auth['username'],
            'full_name' => $auth['full_name'],
            'role' => $auth['role'],
            'store_id' => $auth['store_id'] ? (int)$auth['store_id'] : null
        ],
        'session' => [
            'login_time' => $_SESSION['login_time'] ?? null,
            'session_duration' => isset($_SESSION['login_time']) ? time() - $_SESSION['login_time'] : null,
            'auth_provider' => $_SESSION['auth_provider'] ?? 'email'
        ],
        'delivery_access' => true,
        'warning' => 'Database unavailable - basic auth info only'
    ], 'Authentication check completed (limited)');
    
} catch (Exception $e) {
    log_api_error("Unexpected error during auth check", [
        'user_id' => $auth['user_id'],
        'error' => $e->getMessage()
    ]);
    
    // 예상치 못한 에러도 기본 인증 정보는 반환
    api_success([
        'authenticated' => true,
        'user' => [
            'id' => (int)$auth['user_id'],
            'username' => $auth['username'],
            'full_name' => $auth['full_name'],
            'role' => $auth['role'],
            'store_id' => $auth['store_id'] ? (int)$auth['store_id'] : null
        ],
        'delivery_access' => true,
        'warning' => 'System error - basic auth info only'
    ], 'Authentication check completed (limited)');
}
?>