<?php
require_once '../config/db_config.php';

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Check if the column already exists
    $result = $conn->query("SHOW COLUMNS FROM `suppliers` LIKE 'memo'");
    if ($result->num_rows > 0) {
        echo "Column 'memo' already exists in 'suppliers' table.";
    } else {
        $sql = "ALTER TABLE suppliers ADD memo TEXT";
        if ($conn->query($sql) === TRUE) {
            echo "Column 'memo' added successfully to 'suppliers' table.";
        } else {
            echo "Error adding column: " . $conn->error;
        }
    }

    $conn->close();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>