<?php
require_once __DIR__ . '/../config/db_config.php';

echo '<pre>';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("DESCRIBE suppliers");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    print_r($columns);

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

echo '</pre>';
?>