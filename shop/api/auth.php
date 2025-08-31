<?php
/**
 * 사용자 인증 API (독립 실행형)
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
$action = $_GET['action'] ?? '';

// JSON 데이터 파싱
$data = [];
if (in_array($request_method, ['POST', 'PUT'])) {
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

    switch ($action) {
        case 'login':
            if ($request_method === 'POST') {
                login($conn, $data);
            } else {
                sendError('POST 메소드만 지원합니다.', 405);
            }
            break;
            
        case 'register':
            if ($request_method === 'POST') {
                register($conn, $data);
            } else {
                sendError('POST 메소드만 지원합니다.', 405);
            }
            break;
            
        case 'logout':
            logout();
            break;
            
        case 'profile':
            if ($request_method === 'GET') {
                getProfile($conn);
            } else {
                sendError('GET 메소드만 지원합니다.', 405);
            }
            break;
            
        case 'check':
            checkLogin($conn);
            break;
            
        default:
            sendError('지원하지 않는 액션입니다.', 400);
    }
} catch (Exception $e) {
    error_log('Auth API Error: ' . $e->getMessage());
    sendError('서버 오류가 발생했습니다.', 500);
}

/**
 * 로그인 처리
 */
function login($conn, $data) {
    // 입력 검증
    if (!isset($data['email']) || !isset($data['password'])) {
        sendError('이메일과 비밀번호가 필요합니다.', 400);
    }
    
    $email = trim($data['email']);
    $password = $data['password'];
    
    if (empty($email) || empty($password)) {
        sendError('이메일과 비밀번호를 입력해주세요.', 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError('올바른 이메일 형식이 아닙니다.', 400);
    }
    
    try {
        // 사용자 조회 (shop_access 권한이 있는 사용자만)
        $sql = "SELECT 
                    u.id,
                    u.username,
                    u.email,
                    u.name,
                    u.phone,
                    u.role,
                    u.password,
                    u.permissions,
                    u.store_id,
                    s.name as store_name,
                    u.status
                FROM users u
                LEFT JOIN stores s ON u.store_id = s.id
                WHERE u.email = ? AND u.status = 'active'";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (!$user) {
            sendError('이메일 또는 비밀번호가 잘못되었습니다.', 401);
        }
        
        // 비밀번호 확인
        if (!password_verify($password, $user['password'])) {
            sendError('이메일 또는 비밀번호가 잘못되었습니다.', 401);
        }
        
        // shop_access 권한 확인
        $permissions = json_decode($user['permissions'], true) ?? [];
        if (!in_array($user['role'], ['super_admin', 'admin']) && !isset($permissions['shop_access'])) {
            sendError('쇼핑몰 접근 권한이 없습니다.', 403);
        }
        
        // 세션에 사용자 정보 저장
        $_SESSION['customer_id'] = $user['id'];
        $_SESSION['customer_email'] = $user['email'];
        $_SESSION['customer_name'] = $user['name'];
        $_SESSION['customer_role'] = $user['role'];
        $_SESSION['store_id'] = $user['store_id'];
        
        // 응답 데이터 구성 (비밀번호 제외)
        $response_data = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'name' => $user['name'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'store_id' => $user['store_id'] ? (int)$user['store_id'] : null,
            'store_name' => $user['store_name'],
            'permissions' => $permissions,
            'token' => session_id() // 세션 ID를 토큰으로 사용
        ];
        
        echo json_encode([
            'success' => true,
            'message' => '로그인되었습니다.',
            'user' => $response_data,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        error_log('Login Error: ' . $e->getMessage());
        sendError('로그인 처리 중 오류가 발생했습니다.');
    }
}

/**
 * 회원가입 처리
 */
function register($conn, $data) {
    // 입력 검증
    $required_fields = ['name', 'email', 'password', 'password_confirm'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            sendError("필수 항목이 누락되었습니다: {$field}", 400);
        }
    }
    
    $name = trim($data['name']);
    $email = trim($data['email']);
    $password = $data['password'];
    $password_confirm = $data['password_confirm'];
    $phone = isset($data['phone']) ? trim($data['phone']) : '';
    
    // 유효성 검증
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError('올바른 이메일 형식이 아닙니다.', 400);
    }
    
    if (strlen($password) < 6) {
        sendError('비밀번호는 최소 6자 이상이어야 합니다.', 400);
    }
    
    if ($password !== $password_confirm) {
        sendError('비밀번호가 일치하지 않습니다.', 400);
    }
    
    try {
        // 이메일 중복 확인
        $check_sql = "SELECT id FROM users WHERE email = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('s', $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $check_stmt->close();
            sendError('이미 사용 중인 이메일입니다.', 409);
        }
        $check_stmt->close();
        
        // 비밀번호 해시화
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // 기본 쇼핑 권한 설정
        $default_permissions = json_encode(['shop_access' => true]);
        
        // 사용자 등록
        $insert_sql = "INSERT INTO users (name, email, password, phone, role, permissions, status, created_at) 
                       VALUES (?, ?, ?, ?, 'user', ?, 'active', NOW())";
        
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param('sssss', $name, $email, $hashed_password, $phone, $default_permissions);
        
        if ($stmt->execute()) {
            $user_id = $conn->insert_id;
            $stmt->close();
            
            // 자동 로그인
            $_SESSION['customer_id'] = $user_id;
            $_SESSION['customer_email'] = $email;
            $_SESSION['customer_name'] = $name;
            $_SESSION['customer_role'] = 'user';
            
            echo json_encode([
                'success' => true,
                'message' => '회원가입이 완료되었습니다.',
                'user' => [
                    'id' => $user_id,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => 'user',
                    'token' => session_id()
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            throw new Exception('사용자 등록 실패');
        }
        
    } catch (Exception $e) {
        error_log('Register Error: ' . $e->getMessage());
        sendError('회원가입 처리 중 오류가 발생했습니다.');
    }
}

/**
 * 로그아웃 처리
 */
function logout() {
    // 세션 데이터 삭제
    session_unset();
    session_destroy();
    
    // 새 세션 시작 (장바구니 유지를 위해)
    session_start();
    
    echo json_encode([
        'success' => true,
        'message' => '로그아웃되었습니다.',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * 사용자 프로필 조회
 */
function getProfile($conn) {
    if (!isset($_SESSION['customer_id'])) {
        sendError('로그인이 필요합니다.', 401);
    }
    
    try {
        $user_id = (int)$_SESSION['customer_id'];
        
        $sql = "SELECT 
                    u.id,
                    u.username,
                    u.email,
                    u.name,
                    u.phone,
                    u.role,
                    u.store_id,
                    s.name as store_name,
                    u.permissions
                FROM users u
                LEFT JOIN stores s ON u.store_id = s.id
                WHERE u.id = ? AND u.status = 'active'";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (!$user) {
            sendError('사용자를 찾을 수 없습니다.', 404);
        }
        
        $permissions = json_decode($user['permissions'], true) ?? [];
        
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'name' => $user['name'],
                'phone' => $user['phone'],
                'role' => $user['role'],
                'store_id' => $user['store_id'] ? (int)$user['store_id'] : null,
                'store_name' => $user['store_name'],
                'permissions' => $permissions,
                'token' => session_id()
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        error_log('Profile Error: ' . $e->getMessage());
        sendError('프로필 조회 중 오류가 발생했습니다.');
    }
}

/**
 * 로그인 상태 확인
 */
function checkLogin($conn) {
    if (isset($_SESSION['customer_id'])) {
        // 유효한 세션이면 프로필 정보 반환
        getProfile($conn);
    } else {
        echo json_encode([
            'success' => true,
            'logged_in' => false,
            'message' => '로그인되지 않았습니다.',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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