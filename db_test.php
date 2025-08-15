<?php
// 데이터베이스 연결 테스트
header('Content-Type: text/plain; charset=utf-8');

echo "=== Database Connection Test ===\n";

try {
    echo "Step 1: Loading config...\n";
    
    require_once __DIR__ . '/config/db_config.php';
    
    echo "Step 2: Config loaded\n";
    echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'UNDEFINED') . "\n";
    echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'UNDEFINED') . "\n";
    echo "DB_USER: " . (defined('DB_USER') ? DB_USER : 'UNDEFINED') . "\n";
    echo "DB_CHARSET: " . (defined('DB_CHARSET') ? DB_CHARSET : 'UNDEFINED') . "\n";
    
    echo "Step 3: Attempting PDO connection...\n";
    
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, 
        DB_USER, 
        DB_PASS
    );
    
    echo "Step 4: PDO connection successful\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Step 5: Query successful - Product count: " . $result['count'] . "\n";
    echo "=== TEST PASSED ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
?>