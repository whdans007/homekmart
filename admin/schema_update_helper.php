<?php
require_once __DIR__ . '/../config/db_config.php';

echo "<h1>도매 상품 스키마 업데이트</h1>";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>현재 wholesale_products 테이블 구조 확인</h2>";
    
    // 현재 테이블 구조 확인
    $stmt = $pdo->query("DESCRIBE wholesale_products");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    $existing_columns = [];
    foreach ($columns as $column) {
        $existing_columns[] = $column['Field'];
        echo "<tr>";
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 필요한 새 컬럼들 확인
    $required_columns = [
        'wholesale_name_ko',
        'wholesale_name_en', 
        'wholesale_skus',
        'wholesale_description',
        'updated_at'
    ];
    
    $missing_columns = array_diff($required_columns, $existing_columns);
    
    if (empty($missing_columns)) {
        echo "<h2 style='color: green;'>✅ 모든 필요한 컬럼이 이미 존재합니다!</h2>";
    } else {
        echo "<h2 style='color: red;'>❌ 다음 컬럼들이 누락되었습니다:</h2>";
        echo "<ul>";
        foreach ($missing_columns as $column) {
            echo "<li>" . htmlspecialchars($column) . "</li>";
        }
        echo "</ul>";
        
        echo "<h2>스키마 업데이트 실행</h2>";
        
        // 컬럼들을 하나씩 추가
        $alter_queries = [
            'wholesale_name_ko' => "ALTER TABLE wholesale_products ADD COLUMN wholesale_name_ko VARCHAR(255) NULL AFTER product_id",
            'wholesale_name_en' => "ALTER TABLE wholesale_products ADD COLUMN wholesale_name_en VARCHAR(255) NULL AFTER wholesale_name_ko",
            'wholesale_skus' => "ALTER TABLE wholesale_products ADD COLUMN wholesale_skus JSON NULL AFTER wholesale_name_en",
            'wholesale_description' => "ALTER TABLE wholesale_products ADD COLUMN wholesale_description TEXT NULL AFTER wholesale_skus",
            'updated_at' => "ALTER TABLE wholesale_products ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
        ];
        
        foreach ($missing_columns as $column) {
            if (isset($alter_queries[$column])) {
                try {
                    $pdo->exec($alter_queries[$column]);
                    echo "<p style='color: green;'>✅ " . htmlspecialchars($column) . " 컬럼이 추가되었습니다.</p>";
                } catch (PDOException $e) {
                    echo "<p style='color: red;'>❌ " . htmlspecialchars($column) . " 컬럼 추가 실패: " . htmlspecialchars($e->getMessage()) . "</p>";
                }
            }
        }
        
        // 기존 데이터 마이그레이션
        echo "<h2>기존 데이터 마이그레이션</h2>";
        try {
            $migration_query = "
                UPDATE wholesale_products wp
                JOIN products p ON wp.product_id = p.id
                SET 
                    wp.wholesale_name_ko = p.name_ko,
                    wp.wholesale_name_en = p.name_en,
                    wp.wholesale_skus = JSON_ARRAY(p.sku)
                WHERE wp.wholesale_name_ko IS NULL
            ";
            
            $updated_rows = $pdo->exec($migration_query);
            echo "<p style='color: green;'>✅ " . $updated_rows . "개 레코드의 데이터가 마이그레이션되었습니다.</p>";
            
        } catch (PDOException $e) {
            echo "<p style='color: red;'>❌ 데이터 마이그레이션 실패: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    // 최종 테이블 구조 확인
    echo "<h2>업데이트된 테이블 구조</h2>";
    $stmt = $pdo->query("DESCRIBE wholesale_products");
    $updated_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    foreach ($updated_columns as $column) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 샘플 데이터 확인
    echo "<h2>샘플 데이터 확인</h2>";
    $sample_stmt = $pdo->query("SELECT * FROM wholesale_products LIMIT 3");
    $sample_data = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($sample_data)) {
        echo "<table border='1'>";
        echo "<tr>";
        foreach (array_keys($sample_data[0]) as $key) {
            echo "<th>" . htmlspecialchars($key) . "</th>";
        }
        echo "</tr>";
        
        foreach ($sample_data as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>데이터가 없습니다.</p>";
    }
    
    echo "<h2 style='color: green;'>✅ 스키마 업데이트가 완료되었습니다!</h2>";
    echo "<p><strong>다음 단계:</strong></p>";
    echo "<ul>";
    echo "<li>이 페이지를 새로고침하여 최종 확인</li>";
    echo "<li>도매 상품 관리 페이지에서 정상 작동 확인</li>";
    echo "<li>필요시 기존 도매 상품들의 커스터마이징 진행</li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<h2 style='color: red;'>❌ 데이터베이스 오류</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
</style>