<?php
require_once __DIR__ . '/config/db_config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check users table structure
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Users table structure:\n";
    foreach ($columns as $column) {
        echo "- " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
    // Check if phone column exists
    $has_phone = false;
    $has_permissions = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'phone') {
            $has_phone = true;
        }
        if ($column['Field'] === 'permissions') {
            $has_permissions = true;
        }
    }
    
    echo "\nPhone column exists: " . ($has_phone ? "Yes" : "No") . "\n";
    echo "Permissions column exists: " . ($has_permissions ? "Yes" : "No") . "\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>