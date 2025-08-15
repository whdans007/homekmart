<?php
/**
 * API 디버그 페이지
 * 간단한 정보 확인용
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>API Debug</title></head><body>";
echo "<h1>API Debug Information</h1>";

echo "<h2>Server Information</h2>";
echo "<ul>";
echo "<li>PHP Version: " . phpversion() . "</li>";
echo "<li>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "</li>";
echo "<li>Current Directory: " . __DIR__ . "</li>";
echo "<li>Request URI: " . $_SERVER['REQUEST_URI'] . "</li>";
echo "<li>Request Method: " . $_SERVER['REQUEST_METHOD'] . "</li>";
echo "</ul>";

echo "<h2>File Structure</h2>";
echo "<pre>";
echo "API Directory: " . __DIR__ . "\n";
echo "Files:\n";
$files = scandir(__DIR__);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..') {
        echo "  - $file\n";
        if (is_dir(__DIR__ . '/' . $file)) {
            $subfiles = scandir(__DIR__ . '/' . $file);
            foreach ($subfiles as $subfile) {
                if ($subfile !== '.' && $subfile !== '..') {
                    echo "    - $subfile\n";
                }
            }
        }
    }
}
echo "</pre>";

echo "<h2>Database Connection Test</h2>";
try {
    require_once __DIR__ . '/../config/db_config.php';
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // 테이블 확인
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Tables found: " . count($tables) . "</p>";
    
    // delivery_settings 테이블 확인
    if (in_array('delivery_settings', $tables)) {
        echo "<p style='color: green;'>✓ delivery_settings table exists</p>";
    } else {
        echo "<p style='color: orange;'>⚠ delivery_settings table not found</p>";
    }
    
    // products 테이블 확인
    if (in_array('products', $tables)) {
        $count = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        echo "<p>Products count: $count</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
}

echo "<h2>Test API Endpoints</h2>";
echo "<ul>";
echo "<li><a href='/min/api'>API Info</a></li>";
echo "<li><a href='/min/api/products'>Products List</a></li>";
echo "<li><a href='/min/api/products/categories'>Categories</a></li>";
echo "<li><a href='/min/api/products/brands'>Brands</a></li>";
echo "</ul>";

echo "<h2>Session Information</h2>";
session_start();
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "</body></html>";
?>