<?php
/**
 * API 설정 파일
 * RESTful API를 위한 공통 설정 및 유틸리티
 */

// CORS 헤더 설정 (모바일 앱 접근 허용)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

// OPTIONS 요청 처리 (Preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 데이터베이스 설정
require_once __DIR__ . '/../config/db_config.php';

/**
 * PDO 데이터베이스 연결
 */
function getApiDbConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        apiError(500, 'Database connection failed');
        exit();
    }
}

/**
 * JSON 응답 반환
 */
function apiResponse($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * 에러 응답 반환
 */
function apiError($status_code, $message, $details = null) {
    $response = [
        'success' => false,
        'error' => [
            'message' => $message,
            'code' => $status_code
        ]
    ];

    if ($details !== null) {
        $response['error']['details'] = $details;
    }

    apiResponse($response, $status_code);
}

/**
 * 성공 응답 반환
 */
function apiSuccess($data = null, $message = null) {
    $response = ['success' => true];

    if ($message !== null) {
        $response['message'] = $message;
    }

    if ($data !== null) {
        $response['data'] = $data;
    }

    apiResponse($response, 200);
}

/**
 * JWT 토큰 생성
 */
function generateJWT($user_id, $email, $role) {
    $secret_key = "homekmart_delivery_app_secret_2025"; // 실제 환경에서는 환경변수로 관리
    $issued_at = time();
    $expiration_time = $issued_at + (60 * 60 * 24 * 30); // 30일

    $payload = [
        'iat' => $issued_at,
        'exp' => $expiration_time,
        'user_id' => $user_id,
        'email' => $email,
        'role' => $role
    ];

    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload_encoded = base64_encode(json_encode($payload));
    $signature = hash_hmac('sha256', "$header.$payload_encoded", $secret_key, true);
    $signature_encoded = base64_encode($signature);

    return "$header.$payload_encoded.$signature_encoded";
}

/**
 * JWT 토큰 검증
 */
function verifyJWT($token) {
    $secret_key = "homekmart_delivery_app_secret_2025";

    $token_parts = explode('.', $token);
    if (count($token_parts) !== 3) {
        return false;
    }

    list($header, $payload, $signature) = $token_parts;

    $valid_signature = base64_encode(hash_hmac('sha256', "$header.$payload", $secret_key, true));

    if ($signature !== $valid_signature) {
        return false;
    }

    $payload_data = json_decode(base64_decode($payload), true);

    if ($payload_data['exp'] < time()) {
        return false; // 토큰 만료
    }

    return $payload_data;
}

/**
 * Authorization 헤더에서 토큰 추출
 */
function getBearerToken() {
    $headers = getallheaders();

    if (isset($headers['Authorization'])) {
        $matches = [];
        if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }

    return null;
}

/**
 * 인증 필요 미들웨어
 */
function requireAuth() {
    $token = getBearerToken();

    if (!$token) {
        apiError(401, 'Authentication required');
    }

    $payload = verifyJWT($token);

    if (!$payload) {
        apiError(401, 'Invalid or expired token');
    }

    return $payload;
}

/**
 * 요청 본문 파싱
 */
function getRequestBody() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

/**
 * 필수 파라미터 검증
 */
function validateRequired($data, $required_fields) {
    $errors = [];

    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $errors[] = "Field '$field' is required";
        }
    }

    if (!empty($errors)) {
        apiError(400, 'Validation failed', $errors);
    }

    return true;
}

/**
 * 이메일 검증
 */
function validateEmail($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError(400, 'Invalid email format');
    }
    return true;
}

/**
 * 페이지네이션 파라미터 파싱
 */
function getPaginationParams() {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;

    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * 페이지네이션 응답 생성
 */
function paginatedResponse($data, $total, $page, $limit) {
    $total_pages = ceil($total / $limit);

    return [
        'data' => $data,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_items' => $total,
            'items_per_page' => $limit,
            'has_next' => $page < $total_pages,
            'has_prev' => $page > 1
        ]
    ];
}
?>
