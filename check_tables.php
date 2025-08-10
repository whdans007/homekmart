<?php
require_once __DIR__ . '/config/db_config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== products 테이블 구조 ===\n";
    $stmt = $pdo->query('DESCRIBE products');
    while ($row = $stmt->fetch()) {
        echo $row['Field'] . ' - ' . $row['Type'] . ' - ' . $row['Key'] . "\n";
    }
    
    echo "\n=== stores 테이블 구조 ===\n";
    $stmt = $pdo->query('DESCRIBE stores');
    while ($row = $stmt->fetch()) {
        echo $row['Field'] . ' - ' . $row['Type'] . ' - ' . $row['Key'] . "\n";
    }
    
    echo "\n=== 테이블 존재 확인 ===\n";
    $tables = ['products', 'stores', 'wholesale_customers'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0 ? 'EXISTS' : 'NOT EXISTS';
        echo "$table: $exists\n";
    }
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>