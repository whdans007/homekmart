<?php
// 테이블 구조 확인
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h1>테이블 구조 확인</h1>";
    
    // layout_rows 테이블 구조
    echo "<h2>1. layout_rows 테이블 구조</h2>";
    $result = $conn->query("DESCRIBE layout_rows");
    if ($result) {
        echo "<table border='1'>";
        echo "<tr><th>컬럼명</th><th>타입</th><th>NULL</th><th>키</th><th>기본값</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
        }
        echo "</table>";
    }
    
    // layout_columns 테이블 구조
    echo "<h2>2. layout_columns 테이블 구조</h2>";
    $result = $conn->query("DESCRIBE layout_columns");
    if ($result) {
        echo "<table border='1'>";
        echo "<tr><th>컬럼명</th><th>타입</th><th>NULL</th><th>키</th><th>기본값</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
        }
        echo "</table>";
    }
    
    // display_sections 테이블 구조
    echo "<h2>3. display_sections 테이블 구조</h2>";
    $result = $conn->query("DESCRIBE display_sections");
    if ($result) {
        echo "<table border='1'>";
        echo "<tr><th>컬럼명</th><th>타입</th><th>NULL</th><th>키</th><th>기본값</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td><td>{$row['Default']}</td></tr>";
        }
        echo "</table>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>