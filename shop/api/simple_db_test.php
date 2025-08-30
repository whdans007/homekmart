<?php
/**
 * 간단한 데이터베이스 연결 테스트
 */

// 오류 표시 활성화
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    echo "1. 설정 파일 로드 시도...\n";
    require_once '../../config/db_config.php';
    echo "2. 설정 파일 로드 성공\n";
    
    echo "3. 데이터베이스 연결 시도...\n";
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    echo "4. 데이터베이스 연결 성공\n";
    
    echo "5. 카테고리 테이블 조회 시도...\n";
    $sql = "SELECT id, name FROM categories LIMIT 3";
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("쿼리 실행 실패: " . $conn->error);
    }
    
    echo "6. 쿼리 실행 성공, 데이터 조회...\n";
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    
    $response = [
        'success' => true,
        'message' => '데이터베이스 연결 및 조회 성공',
        'categories' => $categories,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $response = [
        'error' => true,
        'message' => '오류 발생: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>