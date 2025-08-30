<?php
/**
 * HOME K MART Shop API Router
 * 모든 API 요청을 처리하는 중앙 라우터
 */

// CORS 헤더 설정
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// OPTIONS 요청 처리 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 에러 처리 설정
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 공통 함수
function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

function sendError($message, $status = 400, $details = null) {
    $response = ['error' => true, 'message' => $message];
    if ($details) {
        $response['details'] = $details;
    }
    sendResponse($response, $status);
}

// 입력 검증 함수
function validateInput($data, $required_fields) {
    $errors = [];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $errors[] = "{$field}는 필수 입력 항목입니다.";
        }
    }
    return $errors;
}

try {
    // 데이터베이스 연결
    require_once '../../config/db_config.php';
    $conn = get_db_connection();
    
    // 요청 URI 파싱
    $request_uri = $_SERVER['REQUEST_URI'];
    $request_method = $_SERVER['REQUEST_METHOD'];
    
    // API 경로 추출
    $base_path = '/homekmart/shop/api/';
    $path = str_replace($base_path, '', $request_uri);
    $path = parse_url($path, PHP_URL_PATH);
    $path_parts = explode('/', trim($path, '/'));
    
    // 입력 데이터 처리
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $data = array_merge($_GET, $_POST, $input);
    
    // 라우팅
    $endpoint = $path_parts[0] ?? '';
    
    switch ($endpoint) {
        case 'products':
            include 'products.php';
            break;
            
        case 'categories':
            include 'categories.php';
            break;
            
        case 'cart':
            include 'cart.php';
            break;
            
        case 'auth':
            include 'auth.php';
            break;
            
        case 'orders':
            include 'orders.php';
            break;
            
        case 'customer':
            include 'customer.php';
            break;
            
        case 'search':
            include 'search.php';
            break;
            
        case '':
            // API 정보 제공
            sendResponse([
                'name' => 'HOME K MART Shop API',
                'version' => '1.0.0',
                'endpoints' => [
                    'GET /products' => '상품 목록 조회',
                    'GET /products/{id}' => '상품 상세 조회',
                    'GET /categories' => '카테고리 목록 조회',
                    'POST /auth/login' => '로그인',
                    'POST /auth/register' => '회원가입',
                    'GET /cart' => '장바구니 조회',
                    'POST /cart/add' => '장바구니 추가',
                    'POST /orders' => '주문 생성',
                    'GET /search' => '상품 검색'
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            sendError('지원하지 않는 API 엔드포인트입니다.', 404);
    }
    
} catch (Exception $e) {
    error_log('API Error: ' . $e->getMessage());
    sendError('서버 오류가 발생했습니다.', 500, $e->getMessage());
}