<?php
// 단계별 실행 테스트
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Step by Step Test</title>
</head>
<body>
    <h1>PHP Step by Step Test</h1>
    
    <?php
    echo "<p>Step 1: PHP is working ✓</p>";
    
    try {
        echo "<p>Step 2: Trying to include config...</p>";
        
        $config_path = __DIR__ . '/config/db_config.php';
        echo "<p>Config path: $config_path</p>";
        
        if (file_exists($config_path)) {
            echo "<p>Config file exists ✓</p>";
            
            require_once $config_path;
            echo "<p>Step 3: Config loaded ✓</p>";
            
            if (defined('DB_HOST')) {
                echo "<p>Step 4: DB constants defined ✓</p>";
                echo "<p>DB_HOST: " . DB_HOST . "</p>";
                echo "<p>DB_NAME: " . DB_NAME . "</p>";
                
                try {
                    echo "<p>Step 5: Attempting database connection...</p>";
                    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
                    echo "<p>Step 6: Database connected ✓</p>";
                    
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products LIMIT 1");
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo "<p>Step 7: Query executed ✓ - Product count: " . $result['count'] . "</p>";
                    
                    echo "<h2 style='color: green;'>ALL TESTS PASSED!</h2>";
                    
                } catch (Exception $e) {
                    echo "<p style='color: red;'>Database Error: " . $e->getMessage() . "</p>";
                }
                
            } else {
                echo "<p style='color: red;'>Step 4: DB constants not defined ✗</p>";
            }
            
        } else {
            echo "<p style='color: red;'>Config file not found ✗</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    }
    ?>
</body>
</html>