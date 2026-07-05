<?php
// File: logistics/check_db_schema.php
// Purpose: Check actual database schema for inbound tables

require_once __DIR__ . '/config/db.php';

// 웹 접근 시 CENTER(물류센터) 소속/슈퍼관리자만 허용 (CLI 실행은 예외)
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/lib/auth.php';
    lc_require_staff();
}

echo "=== Database Schema Check ===\n\n";

try {
    $conn = get_lc_db();

    // Check tables
    $tables = ['lc_inbound', 'lc_inbound_batches', 'lc_suppliers', 'products'];

    foreach ($tables as $table) {
        echo "Table: $table\n";
        echo "---\n";

        $result = $conn->query("DESCRIBE $table");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                echo sprintf("  %-20s | %-15s | %s\n",
                    $row['Field'],
                    $row['Type'],
                    $row['Null'] . ' | ' . $row['Key']
                );
            }
        } else {
            echo "  ⚠️ Table does not exist or error: " . $conn->error . "\n";
        }
        echo "\n";
    }

    // Check lc_inbound columns
    echo "lc_inbound sample data:\n";
    echo "---\n";
    $result = $conn->query("SELECT * FROM lc_inbound LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "No data found\n";
    }

    $conn->close();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>
