<?php
// products 테이블 구조 확인용
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><title>Products 테이블 구조 확인</title></head><body>";
echo "<h1>Products 테이블 구조 확인</h1>";

try {
    require_once __DIR__ . '/../config/db_config.php';
    $conn = get_db_connection();
    
    echo "<h2>1. Products 테이블 존재 여부</h2>";
    $tables_result = $conn->query("SHOW TABLES LIKE 'products'");
    if ($tables_result->num_rows > 0) {
        echo "<p>✅ products 테이블 존재</p>";
        
        echo "<h2>2. Products 테이블 구조</h2>";
        $desc_result = $conn->query("DESCRIBE products");
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        while ($row = $desc_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Field'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . $row['Null'] . "</td>";
            echo "<td>" . $row['Key'] . "</td>";
            echo "<td>" . $row['Default'] . "</td>";
            echo "<td>" . $row['Extra'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<h2>3. Brands 테이블 구조</h2>";
        $brands_result = $conn->query("DESCRIBE brands");
        if ($brands_result) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
            while ($row = $brands_result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['Field'] . "</td>";
                echo "<td>" . $row['Type'] . "</td>";
                echo "<td>" . $row['Null'] . "</td>";
                echo "<td>" . $row['Key'] . "</td>";
                echo "<td>" . $row['Default'] . "</td>";
                echo "<td>" . $row['Extra'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>❌ brands 테이블이 없습니다</p>";
        }
        
        echo "<h2>4. Categories 테이블 구조</h2>";
        $categories_result = $conn->query("DESCRIBE categories");
        if ($categories_result) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
            while ($row = $categories_result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['Field'] . "</td>";
                echo "<td>" . $row['Type'] . "</td>";
                echo "<td>" . $row['Null'] . "</td>";
                echo "<td>" . $row['Key'] . "</td>";
                echo "<td>" . $row['Default'] . "</td>";
                echo "<td>" . $row['Extra'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>❌ categories 테이블이 없습니다</p>";
        }
        
        echo "<h2>5. 샘플 데이터</h2>";
        $data_result = $conn->query("SELECT * FROM products LIMIT 3");
        if ($data_result && $data_result->num_rows > 0) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            
            // 헤더
            $first_row = $data_result->fetch_assoc();
            echo "<tr>";
            foreach ($first_row as $key => $value) {
                echo "<th>" . htmlspecialchars($key) . "</th>";
            }
            echo "</tr>";
            
            // 첫 번째 행
            echo "<tr>";
            foreach ($first_row as $key => $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
            
            // 나머지 행들
            while ($row = $data_result->fetch_assoc()) {
                echo "<tr>";
                foreach ($row as $key => $value) {
                    echo "<td>" . htmlspecialchars($value) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>데이터가 없습니다.</p>";
        }
        
    } else {
        echo "<p>❌ products 테이블이 존재하지 않습니다.</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p>❌ 오류 발생: " . $e->getMessage() . "</p>";
}

echo "</body></html>";
?>