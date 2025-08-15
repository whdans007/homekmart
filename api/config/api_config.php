<?php
/**
 * 배달 앱 API 기본 설정
 * 필리핀 배달 서비스를 위한 API 공통 설정
 */

// CORS 설정 (React Native 앱에서 접근 허용)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// OPTIONS 요청 처리 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 기본 경로 설정
define('API_BASE_PATH', dirname(__DIR__));
define('ROOT_PATH', dirname(API_BASE_PATH));

// 필요한 라이브러리 포함
require_once ROOT_PATH . '/config/db_config.php';
require_once ROOT_PATH . '/lib/permission_helper.php';
require_once ROOT_PATH . '/lib/session_helper.php';
require_once ROOT_PATH . '/lib/lang_helper.php';

/**
 * API 응답 헬퍼 함수들
 */

/**
 * 성공 응답 반환
 */
function api_success($data = null, $message = 'Success', $status_code = 200) {
    http_response_code($status_code);
    $response = [
        'success' => true,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('c')
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * 에러 응답 반환
 */
function api_error($message = 'Error', $status_code = 400, $error_code = null) {
    http_response_code($status_code);
    $response = [
        'success' => false,
        'message' => $message,
        'error_code' => $error_code,
        'timestamp' => date('c')
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * 인증 필요 에러
 */
function api_unauthorized($message = 'Authentication required') {
    api_error($message, 401, 'UNAUTHORIZED');
}

/**
 * 권한 부족 에러
 */
function api_forbidden($message = 'Insufficient permissions') {
    api_error($message, 403, 'FORBIDDEN');
}

/**
 * 데이터 없음 에러
 */
function api_not_found($message = 'Resource not found') {
    api_error($message, 404, 'NOT_FOUND');
}

/**
 * 입력 데이터 검증 에러
 */
function api_validation_error($message = 'Validation failed', $errors = []) {
    http_response_code(422);
    $response = [
        'success' => false,
        'message' => $message,
        'error_code' => 'VALIDATION_ERROR',
        'errors' => $errors,
        'timestamp' => date('c')
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * JSON 입력 데이터 파싱
 */
function get_json_input() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('Invalid JSON format', 400, 'INVALID_JSON');
    }
    
    return $data ?? [];
}

/**
 * 입력 데이터 정제 및 검증
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * 필수 필드 검증
 */
function validate_required_fields($data, $required_fields) {
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $missing_fields[] = $field;
        }
    }
    
    if (!empty($missing_fields)) {
        api_validation_error('Missing required fields', [
            'missing_fields' => $missing_fields
        ]);
    }
}

/**
 * JWT 토큰 검증 (향후 구현)
 */
function verify_jwt_token($token = null) {
    // TODO: JWT 토큰 검증 로직 구현
    // 현재는 세션 기반 인증 사용
    return verify_session_auth();
}

/**
 * 세션 기반 인증 검증
 */
function verify_session_auth() {
    session_start();
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }
    
    return [
        'user_id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['role'] ?? 'user',
        'store_id' => $_SESSION['store_id'] ?? null
    ];
}

/**
 * API 인증 미들웨어
 */
function require_auth() {
    $auth = verify_session_auth();
    if (!$auth) {
        api_unauthorized('Please login to access this resource');
    }
    return $auth;
}

/**
 * 권한 검증 미들웨어
 */
function require_permission($permission) {
    $auth = require_auth();
    
    if (!has_permission($permission)) {
        api_forbidden("Permission '{$permission}' required");
    }
    
    return $auth;
}

/**
 * 배달 앱 설정값 조회
 */
function get_delivery_setting($key, $default = null) {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT setting_value, setting_type FROM delivery_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return $default;
        }
        
        // 타입에 따른 값 변환
        switch ($result['setting_type']) {
            case 'number':
                return is_numeric($result['setting_value']) ? (float)$result['setting_value'] : $default;
            case 'boolean':
                return filter_var($result['setting_value'], FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($result['setting_value'], true) ?? $default;
            default:
                return $result['setting_value'];
        }
    } catch (Exception $e) {
        error_log("Error getting delivery setting '{$key}': " . $e->getMessage());
        return $default;
    }
}

/**
 * 배달 앱 설정값 저장
 */
function set_delivery_setting($key, $value, $type = 'string') {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 타입에 따른 값 변환
        switch ($type) {
            case 'boolean':
                $value = $value ? 'true' : 'false';
                break;
            case 'json':
                $value = json_encode($value);
                break;
            default:
                $value = (string)$value;
                break;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO delivery_settings (setting_key, setting_value, setting_type) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type)
        ");
        
        return $stmt->execute([$key, $value, $type]);
    } catch (Exception $e) {
        error_log("Error setting delivery setting '{$key}': " . $e->getMessage());
        return false;
    }
}

/**
 * 페이지네이션 헬퍼
 */
function paginate($total_count, $page = 1, $per_page = 20) {
    $page = max(1, (int)$page);
    $per_page = max(1, min(100, (int)$per_page)); // 최대 100개로 제한
    
    $total_pages = ceil($total_count / $per_page);
    $offset = ($page - 1) * $per_page;
    
    return [
        'page' => $page,
        'per_page' => $per_page,
        'total_count' => $total_count,
        'total_pages' => $total_pages,
        'offset' => $offset,
        'has_next' => $page < $total_pages,
        'has_prev' => $page > 1
    ];
}

/**
 * 필리핀 통화 포맷팅
 */
function format_php_currency($amount) {
    return '₱' . number_format($amount, 2);
}

/**
 * 거리 계산 (Haversine formula)
 */
function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // km
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earth_radius * $c;
}

/**
 * 에러 로깅
 */
function log_api_error($message, $context = []) {
    $log_message = "[API Error] " . $message;
    if (!empty($context)) {
        $log_message .= " | Context: " . json_encode($context);
    }
    error_log($log_message);
}

/**
 * 디버그 모드 설정
 */
define('API_DEBUG_MODE', false); // 프로덕션에서는 false로 설정

/**
 * 디버그 로깅
 */
function debug_log($message, $data = null) {
    if (API_DEBUG_MODE) {
        $log_message = "[API Debug] " . $message;
        if ($data !== null) {
            $log_message .= " | Data: " . json_encode($data);
        }
        error_log($log_message);
    }
}

// API 시작 로깅
debug_log("API Request", [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
]);
?>