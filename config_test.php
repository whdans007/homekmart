<?php
// 설정 파일 테스트
echo "Step 1: PHP working<br>";

$config_path = __DIR__ . '/config/db_config.php';
echo "Step 2: Config path: $config_path<br>";

if (file_exists($config_path)) {
    echo "Step 3: Config file exists<br>";
    
    try {
        require_once $config_path;
        echo "Step 4: Config file loaded<br>";
        
        if (defined('DB_HOST')) {
            echo "Step 5: DB_HOST defined: " . DB_HOST . "<br>";
        } else {
            echo "Step 5: DB_HOST not defined<br>";
        }
        
    } catch (Exception $e) {
        echo "Step 4 ERROR: " . $e->getMessage() . "<br>";
    }
} else {
    echo "Step 3: Config file NOT exists<br>";
}

echo "Test complete";
?>