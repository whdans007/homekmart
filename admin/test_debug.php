<?php
// 최소한의 디버깅 페이지
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>HOME K MART 시스템 디버깅</h1>";

try {
    echo "<h2>1. 파일 경로 테스트</h2>";
    echo "현재 파일: " . __FILE__ . "<br>";
    echo "디렉토리: " . __DIR__ . "<br>";
    
    echo "<h2>2. config 파일 포함 테스트</h2>";
    $config_file = __DIR__ . '/../config/db_config.php';
    echo "config 파일 경로: " . $config_file . "<br>";
    echo "config 파일 존재: " . (file_exists($config_file) ? "예" : "아니오") . "<br>";
    
    if (file_exists($config_file)) {
        require_once $config_file;
        echo "config 파일 포함 성공<br>";
        
        echo "<h2>3. 데이터베이스 연결 테스트</h2>";
        $conn = get_db_connection();
        echo "데이터베이스 연결 성공<br>";
        
        echo "<h2>4. 테이블 존재 확인</h2>";
        $tables_to_check = ['display_sections', 'product_displays', 'orders', 'order_items', 'stores', 'products'];
        
        foreach ($tables_to_check as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            $exists = $result->num_rows > 0 ? "✅" : "❌";
            echo "$exists $table<br>";
        }
        
        echo "<h2>5. 세션 헬퍼 테스트</h2>";
        $session_file = __DIR__ . '/../lib/session_helper.php';
        if (file_exists($session_file)) {
            require_once $session_file;
            echo "session_helper.php 포함 성공<br>";
        } else {
            echo "❌ session_helper.php 파일이 없습니다<br>";
        }
        
        echo "<h2>6. 권한 헬퍼 테스트</h2>";
        $permission_file = __DIR__ . '/../lib/permission_helper.php';
        if (file_exists($permission_file)) {
            require_once $permission_file;
            echo "permission_helper.php 포함 성공<br>";
        } else {
            echo "❌ permission_helper.php 파일이 없습니다<br>";
        }
        
        $conn->close();
        
    } else {
        echo "❌ config 파일을 찾을 수 없습니다.<br>";
    }
    
} catch (Exception $e) {
    echo "<h2>오류 발생:</h2>";
    echo "<div style='color: red; font-weight: bold;'>";
    echo "메시지: " . $e->getMessage() . "<br>";
    echo "파일: " . $e->getFile() . "<br>";
    echo "라인: " . $e->getLine() . "<br>";
    echo "</div>";
}

echo "<h2>7. 기존 샘플 쇼핑몰 찾기</h2>";
$possible_paths = [
    __DIR__ . '/../shop/',
    __DIR__ . '/../',
    __DIR__ . '/../../shop/',
];

foreach ($possible_paths as $path) {
    echo "경로 확인: $path<br>";
    if (is_dir($path)) {
        echo "✅ 디렉토리 존재<br>";
        $files = scandir($path);
        foreach ($files as $file) {
            if (strpos($file, '.php') !== false) {
                echo "  - $file<br>";
            }
        }
    } else {
        echo "❌ 디렉토리 없음<br>";
    }
}
?>