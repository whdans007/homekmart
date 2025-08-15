<?php
/**
 * 기존 이메일/패스워드 로그인 API
 */

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
}

// JSON 입력 데이터 파싱
$input = get_json_input();
$input = sanitize_input($input);

// 필수 필드 검증
validate_required_fields($input, ['email', 'password']);

$email = $input['email'];
$password = $input['password'];
$remember_me = isset($input['remember_me']) ? (bool)$input['remember_me'] : false;

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 사용자 조회 (이메일로)
    $stmt = $pdo->prepare("
        SELECT 
            u.id, u.username, u.email, u.password_hash, u.full_name, u.role, 
            u.store_id, u.is_active, u.google_id, u.auth_provider,
            u.profile_image_url, u.preferred_language,
            s.name as store_name
        FROM users u
        LEFT JOIN stores s ON u.store_id = s.id
        WHERE u.email = ? AND u.is_active = 1
    ");
    
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        api_error('Invalid email or password', 401, 'INVALID_CREDENTIALS');
    }
    
    // 패스워드 검증
    if (!password_verify($password, $user['password_hash'])) {
        api_error('Invalid email or password', 401, 'INVALID_CREDENTIALS');
    }
    
    // 구글 계정인 경우 경고
    if ($user['auth_provider'] === 'google') {
        api_error('This account uses Google login. Please use Google sign-in.', 400, 'GOOGLE_ACCOUNT');
    }
    
    // 세션 시작
    session_start();
    session_regenerate_id(true);
    
    // 세션 정보 설정
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['store_id'] = $user['store_id'];
    $_SESSION['auth_provider'] = 'email';
    $_SESSION['login_time'] = time();
    
    // Remember Me 처리 (향후 JWT 토큰으로 대체 가능)
    if ($remember_me) {
        // 30일간 세션 유지
        ini_set('session.cookie_lifetime', 30 * 24 * 60 * 60);
    }
    
    // 로그인 기록 업데이트
    $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $update_stmt->execute([$user['id']]);
    
    // 사용자 권한 조회
    $permissions = get_user_permissions($user['id']);
    
    // 응답 데이터 구성
    $response_data = [
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'store_id' => $user['store_id'] ? (int)$user['store_id'] : null,
            'store_name' => $user['store_name'],
            'profile_image_url' => $user['profile_image_url'],
            'preferred_language' => $user['preferred_language'] ?? 'en',
            'auth_provider' => $user['auth_provider'] ?? 'email'
        ],
        'permissions' => $permissions,
        'session' => [
            'session_id' => session_id(),
            'expires_in' => $remember_me ? 30 * 24 * 60 * 60 : 24 * 60 * 60, // 초 단위
            'login_time' => $_SESSION['login_time']
        ],
        'delivery_access' => true // 모든 로그인 사용자는 배달 서비스 이용 가능
    ];
    
    // 마지막 로그인 정보 로깅
    debug_log("User login successful", [
        'user_id' => $user['id'],
        'email' => $email,
        'auth_provider' => 'email',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    api_success($response_data, 'Login successful');
    
} catch (PDOException $e) {
    log_api_error("Database error during login", [
        'email' => $email,
        'error' => $e->getMessage()
    ]);
    api_error('Login failed', 500, 'DATABASE_ERROR');
    
} catch (Exception $e) {
    log_api_error("Unexpected error during login", [
        'email' => $email,
        'error' => $e->getMessage()
    ]);
    api_error('Login failed', 500, 'UNEXPECTED_ERROR');
}
?>