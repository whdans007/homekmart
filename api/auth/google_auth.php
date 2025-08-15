<?php
/**
 * 구글 OAuth 로그인 API
 * Google Sign-In에서 받은 토큰을 검증하고 사용자 인증
 */

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
}

// JSON 입력 데이터 파싱
$input = get_json_input();
$input = sanitize_input($input);

// 필수 필드 검증
validate_required_fields($input, ['google_token']);

$google_token = $input['google_token'];

try {
    // Google API로 토큰 검증
    $google_user_info = verify_google_token($google_token);
    
    if (!$google_user_info) {
        api_error('Invalid Google token', 401, 'INVALID_GOOGLE_TOKEN');
    }
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 구글 ID로 기존 사용자 확인
    $stmt = $pdo->prepare("
        SELECT 
            u.id, u.username, u.email, u.full_name, u.role, 
            u.store_id, u.is_active, u.google_id, u.auth_provider,
            u.profile_image_url, u.preferred_language,
            s.name as store_name
        FROM users u
        LEFT JOIN stores s ON u.store_id = s.id
        WHERE u.google_id = ? AND u.is_active = 1
    ");
    
    $stmt->execute([$google_user_info['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // 기존 사용자 - 프로필 정보 업데이트
        $update_stmt = $pdo->prepare("
            UPDATE users 
            SET full_name = ?, profile_image_url = ?, last_login = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([
            $google_user_info['name'],
            $google_user_info['picture'],
            $user['id']
        ]);
        
        // 업데이트된 정보 반영
        $user['full_name'] = $google_user_info['name'];
        $user['profile_image_url'] = $google_user_info['picture'];
        
    } else {
        // 새 사용자 - 계정 생성
        $new_user_data = create_google_user($google_user_info, $pdo);
        if (!$new_user_data) {
            api_error('Failed to create user account', 500, 'USER_CREATION_FAILED');
        }
        $user = $new_user_data;
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
    $_SESSION['auth_provider'] = 'google';
    $_SESSION['login_time'] = time();
    
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
            'store_name' => $user['store_name'] ?? null,
            'profile_image_url' => $user['profile_image_url'],
            'preferred_language' => $user['preferred_language'] ?? 'en',
            'auth_provider' => 'google',
            'is_new_user' => !isset($user['existing_user'])
        ],
        'permissions' => $permissions,
        'session' => [
            'session_id' => session_id(),
            'expires_in' => 24 * 60 * 60, // 24시간
            'login_time' => $_SESSION['login_time']
        ],
        'delivery_access' => true
    ];
    
    // 로그인 정보 로깅
    debug_log("Google login successful", [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'google_id' => $google_user_info['id'],
        'is_new_user' => !isset($user['existing_user']),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    $message = isset($user['existing_user']) ? 'Login successful' : 'Account created and login successful';
    api_success($response_data, $message);
    
} catch (Exception $e) {
    log_api_error("Google authentication error", [
        'error' => $e->getMessage()
    ]);
    api_error('Google authentication failed', 500, 'GOOGLE_AUTH_ERROR');
}

/**
 * Google 토큰 검증
 */
function verify_google_token($token) {
    // Google API를 통한 토큰 검증
    $google_api_url = "https://www.googleapis.com/oauth2/v3/tokeninfo?id_token=" . urlencode($token);
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'method' => 'GET'
        ]
    ]);
    
    $response = @file_get_contents($google_api_url, false, $context);
    
    if ($response === false) {
        return false;
    }
    
    $token_info = json_decode($response, true);
    
    // 토큰 유효성 확인
    if (!isset($token_info['sub']) || !isset($token_info['email'])) {
        return false;
    }
    
    // 필요한 사용자 정보 반환
    return [
        'id' => $token_info['sub'],
        'email' => $token_info['email'],
        'name' => $token_info['name'] ?? $token_info['email'],
        'picture' => $token_info['picture'] ?? null,
        'email_verified' => $token_info['email_verified'] ?? false
    ];
}

/**
 * Google 사용자 계정 생성
 */
function create_google_user($google_info, $pdo) {
    try {
        // 이메일로 기존 계정 확인 (다른 인증 방식으로 가입된 경우)
        $stmt = $pdo->prepare("SELECT id, auth_provider FROM users WHERE email = ?");
        $stmt->execute([$google_info['email']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing && $existing['auth_provider'] !== 'google') {
            // 기존 이메일 계정을 구글 계정으로 업그레이드
            $update_stmt = $pdo->prepare("
                UPDATE users 
                SET google_id = ?, auth_provider = 'google', profile_image_url = ?, last_login = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([
                $google_info['id'],
                $google_info['picture'],
                $existing['id']
            ]);
            
            // 업데이트된 사용자 정보 조회
            $user_stmt = $pdo->prepare("
                SELECT 
                    u.id, u.username, u.email, u.full_name, u.role, 
                    u.store_id, u.is_active, u.google_id, u.auth_provider,
                    u.profile_image_url, u.preferred_language,
                    s.name as store_name
                FROM users u
                LEFT JOIN stores s ON u.store_id = s.id
                WHERE u.id = ?
            ");
            $user_stmt->execute([$existing['id']]);
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            $user['existing_user'] = true;
            
            return $user;
        }
        
        // 새 사용자 생성
        $username = generate_username_from_email($google_info['email']);
        
        $insert_stmt = $pdo->prepare("
            INSERT INTO users (
                username, email, full_name, google_id, auth_provider,
                profile_image_url, role, is_active, preferred_language,
                created_at
            ) VALUES (?, ?, ?, ?, 'google', ?, 'user', 1, 'en', NOW())
        ");
        
        $insert_stmt->execute([
            $username,
            $google_info['email'],
            $google_info['name'],
            $google_info['id'],
            $google_info['picture']
        ]);
        
        $new_user_id = $pdo->lastInsertId();
        
        // 새 사용자 정보 조회
        $user_stmt = $pdo->prepare("
            SELECT 
                u.id, u.username, u.email, u.full_name, u.role, 
                u.store_id, u.is_active, u.google_id, u.auth_provider,
                u.profile_image_url, u.preferred_language,
                s.name as store_name
            FROM users u
            LEFT JOIN stores s ON u.store_id = s.id
            WHERE u.id = ?
        ");
        $user_stmt->execute([$new_user_id]);
        
        return $user_stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        log_api_error("Error creating Google user", [
            'google_info' => $google_info,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * 이메일에서 사용자명 생성
 */
function generate_username_from_email($email) {
    $username = explode('@', $email)[0];
    $username = preg_replace('/[^a-zA-Z0-9_]/', '_', $username);
    $username = substr($username, 0, 50);
    
    return $username;
}
?>