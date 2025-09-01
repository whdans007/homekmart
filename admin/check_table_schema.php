<?php
// 테이블 스키마 확인 스크립트
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h2>layout_columns 테이블 스키마 확인</h2>";
    
    $result = $conn->query("DESCRIBE layout_columns");
    
    if ($result) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>컬럼명</th><th>타입</th><th>NULL</th><th>키</th><th>기본값</th><th>Extra</th></tr>";
        
        $current_fields = [];
        while ($row = $result->fetch_assoc()) {
            $current_fields[] = $row['Field'];
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
            echo "<td>{$row['Extra']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // 필요한 필드들 확인
        $required_fields = [
            'padding_x' => 'int(11) DEFAULT 15',
            'padding_y' => 'int(11) DEFAULT 15',
            'border_radius' => 'int(11) DEFAULT 0',
            'min_height' => 'int(11) DEFAULT NULL',
            'background_color' => 'varchar(7) DEFAULT NULL'
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field => $definition) {
            if (!in_array($field, $current_fields)) {
                $missing_fields[$field] = $definition;
            }
        }
        
        echo "<h3>누락된 필드들</h3>";
        if (empty($missing_fields)) {
            echo "<p style='color: green;'>✅ 모든 필수 필드가 존재합니다.</p>";
        } else {
            echo "<p style='color: red;'>❌ 다음 필드들이 누락되었습니다:</p>";
            echo "<ul>";
            foreach ($missing_fields as $field => $definition) {
                echo "<li><strong>{$field}</strong>: {$definition}</li>";
            }
            echo "</ul>";
            
            echo "<h4>실행할 SQL</h4>";
            echo "<pre style='background: #f8f9fa; padding: 10px; border: 1px solid #ddd;'>";
            foreach ($missing_fields as $field => $definition) {
                echo "ALTER TABLE layout_columns ADD COLUMN {$field} {$definition};\n";
            }
            echo "</pre>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ 테이블을 조회할 수 없습니다.</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . $e->getMessage() . "</p>";
}
?>