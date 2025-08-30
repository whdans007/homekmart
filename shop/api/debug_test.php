<?php
/**
 * 디버그 테스트 파일 - 데이터베이스 연결 및 경로 확인
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>디버그 테스트</h2>";

// 현재 경로 확인
echo "<h3>1. 현재 경로 정보</h3>";
echo "현재 디렉토리: " . __DIR__ . "<br>";
echo "현재 파일: " . __FILE__ . "<br>";

// 설정 파일 경로 확인
echo "<h3>2. 설정 파일 경로 확인</h3>";
$config_path = '../../config/db_config.php';
echo "설정 파일 경로: " . realpath($config_path) . "<br>";
echo "파일 존재 여부: " . (file_exists($config_path) ? "존재" : "존재하지 않음") . "<br>";

if (file_exists($config_path)) {
    echo "<h3>3. 데이터베이스 연결 테스트</h3>";
    try {
        require_once $config_path;
        echo "설정 파일 로드 성공<br>";
        
        $conn = get_db_connection();
        if ($conn) {
            echo "데이터베이스 연결 성공<br>";
            
            // 테이블 존재 확인
            $tables_to_check = ['categories', 'products', 'inventory'];
            foreach ($tables_to_check as $table) {
                $result = $conn->query("SHOW TABLES LIKE '$table'");
                echo "테이블 '$table': " . ($result->num_rows > 0 ? "존재" : "존재하지 않음") . "<br>";
            }
            
            // 카테고리 데이터 확인
            echo "<h4>카테고리 데이터:</h4>";
            $result = $conn->query("SELECT * FROM categories LIMIT 5");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    echo "ID: " . $row['id'] . ", 이름: " . ($row['name'] ?? $row['name_kr'] ?? '이름없음') . "<br>";
                }
            } else {
                echo "카테고리 조회 오류: " . $conn->error . "<br>";
            }
            
        } else {
            echo "데이터베이스 연결 실패<br>";
        }
        
    } catch (Exception $e) {
        echo "오류 발생: " . $e->getMessage() . "<br>";
    }
} else {
    echo "<h3>설정 파일을 찾을 수 없습니다</h3>";
    echo "다른 경로들 확인:<br>";
    $other_paths = [
        '../../config/db_config.php',
        '../config/db_config.php',
        '/homekmart/config/db_config.php'
    ];
    
    foreach ($other_paths as $path) {
        echo "경로: $path - " . (file_exists($path) ? "존재" : "존재하지 않음") . "<br>";
    }
}
?>