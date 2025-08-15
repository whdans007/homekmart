<?php
// 에러 표시 강제 활성화
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

ob_start();

try {
    // 설정 파일에서 DB 정보 가져오기
    require_once __DIR__ . '/config/db_config.php';
    
    $step1 = "Config loaded: HOST=" . DB_HOST . ", NAME=" . DB_NAME . ", USER=" . DB_USER;
    
    // PDO 연결 시도
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, 
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    $step2 = "Database connected successfully";
    
    // 매우 간단한 쿼리
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    
    $step3 = "Query executed, result: " . $result['test'];
    
    // 상품 테이블 확인
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products LIMIT 1");
    $count = $stmt->fetch()['count'];
    
    $step4 = "Products table accessible, count: " . $count;
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'steps' => [
            'step1' => $step1,
            'step2' => $step2, 
            'step3' => $step3,
            'step4' => $step4
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error_type' => 'PDO Exception',
        'error_message' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'steps_completed' => isset($step1) ? ['step1' => $step1] : [],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error_type' => 'General Exception',
        'error_message' => $e->getMessage(),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}

ob_end_flush();
?>