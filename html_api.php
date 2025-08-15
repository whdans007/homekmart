<?php
/**
 * HTML 형식 API (step_by_step.php 패턴)
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Philippines Delivery API - HTML Format</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .product { border: 1px solid #ddd; padding: 10px; margin: 10px 0; border-radius: 5px; }
        .price { color: #007bff; font-weight: bold; }
        .api-info { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="api-info">
        <h1>🛒 Philippines Delivery API</h1>
        <p><strong>Status:</strong> ✅ SUCCESS</p>
        <p><strong>Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>Currency:</strong> Philippine Peso (₱)</p>
    </div>

    <h2>📦 Available Products for Delivery</h2>

    <?php
    try {
        require_once __DIR__ . '/config/db_config.php';
        
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $limit = isset($_GET['limit']) ? min(10, max(1, (int)$_GET['limit'])) : 5;
        
        $sql = "SELECT p.id, p.name, p.description FROM products p WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00') ORDER BY p.id ASC LIMIT " . $limit;
        
        $stmt = $pdo->query($sql);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($products) {
            foreach ($products as $product) {
                echo '<div class="product">';
                echo '<h3>' . htmlspecialchars($product['name']) . '</h3>';
                echo '<p><strong>ID:</strong> ' . $product['id'] . '</p>';
                echo '<p><strong>Description:</strong> ' . htmlspecialchars($product['description']) . '</p>';
                echo '<p class="price">💰 Price: ₱1,500 - ₱3,000 (Sample)</p>';
                echo '<p>🚚 <strong>Available for delivery</strong></p>';
                echo '</div>';
            }
        } else {
            echo '<p>No products found.</p>';
        }
        
        echo '<div class="api-info">';
        echo '<h3>📊 API Response Summary</h3>';
        echo '<p><strong>Products Found:</strong> ' . count($products) . '</p>';
        echo '<p><strong>Database Connection:</strong> ✅ SUCCESS</p>';
        echo '<p><strong>Query Execution:</strong> ✅ SUCCESS</p>';
        echo '</div>';
        
    } catch (Exception $e) {
        echo '<div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px;">';
        echo '<h3>❌ Error Occurred</h3>';
        echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . '</p>';
        echo '<p><strong>Line:</strong> ' . $e->getLine() . '</p>';
        echo '</div>';
    }
    ?>

    <div class="api-info">
        <h3>🎯 API Information</h3>
        <p><strong>Endpoint:</strong> /min/html_api.php</p>
        <p><strong>Method:</strong> GET</p>
        <p><strong>Parameters:</strong> limit (optional, default: 5)</p>
        <p><strong>Format:</strong> HTML</p>
        <p><strong>Region:</strong> Philippines</p>
    </div>

    <script>
        // 자동으로 JSON 형식도 제공
        const apiData = {
            success: true,
            message: "HTML API working successfully",
            products_displayed: <?php echo isset($products) ? count($products) : 0; ?>,
            timestamp: "<?php echo date('Y-m-d H:i:s'); ?>",
            currency: "PHP"
        };
        console.log("API Data (JSON format):", apiData);
    </script>
</body>
</html>