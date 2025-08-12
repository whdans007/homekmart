<?php
require_once __DIR__ . '/../config/db_config.php';

echo "<!DOCTYPE html>
<html lang='ko'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>테이블 구조 확인</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>테이블 구조 확인</h1>";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p class='success'>✓ 데이터베이스 연결 성공</p>";
    
    // 관련 테이블들 확인
    $tables = ['stores', 'users', 'products', 'inventory'];
    
    foreach ($tables as $table) {
        echo "<h2>테이블: {$table}</h2>";
        
        // 테이블 존재 확인
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if ($stmt->fetch()) {
            echo "<p class='success'>✓ 테이블 '{$table}' 존재</p>";
            
            // 테이블 구조 확인
            $columns = $pdo->query("DESCRIBE {$table}")->fetchAll(PDO::FETCH_ASSOC);
            echo "<table>";
            echo "<tr><th>컬럼</th><th>타입</th><th>Null</th><th>Key</th><th>Default</th></tr>";
            foreach ($columns as $column) {
                echo "<tr>";
                echo "<td>{$column['Field']}</td>";
                echo "<td>{$column['Type']}</td>";
                echo "<td>{$column['Null']}</td>";
                echo "<td>{$column['Key']}</td>";
                echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>✗ 테이블 '{$table}' 존재하지 않음</p>";
        }
    }
    
    // 외래키 제약조건 확인
    echo "<h2>외래키 제약조건 확인</h2>";
    $fk_query = "
        SELECT 
            TABLE_NAME,
            COLUMN_NAME,
            CONSTRAINT_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM 
            INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE 
            REFERENCED_TABLE_SCHEMA = '" . DB_NAME . "'
            AND TABLE_NAME IN ('stores', 'users', 'products', 'inventory')
    ";
    
    $fk_result = $pdo->query($fk_query);
    $foreign_keys = $fk_result->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($foreign_keys)) {
        echo "<table>";
        echo "<tr><th>테이블</th><th>컬럼</th><th>참조 테이블</th><th>참조 컬럼</th></tr>";
        foreach ($foreign_keys as $fk) {
            echo "<tr>";
            echo "<td>{$fk['TABLE_NAME']}</td>";
            echo "<td>{$fk['COLUMN_NAME']}</td>";
            echo "<td>{$fk['REFERENCED_TABLE_NAME']}</td>";
            echo "<td>{$fk['REFERENCED_COLUMN_NAME']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>외래키 제약조건이 없습니다.</p>";
    }
    
} catch (PDOException $e) {
    echo "<p class='error'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";
?>