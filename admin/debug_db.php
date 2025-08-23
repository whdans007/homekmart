<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/partials/header.php';

if (!has_permission('purchase_management')) {
    echo "권한 없음";
    exit;
}

echo "<h2>데이터베이스 디버깅</h2>";

try {
    $conn = get_db_connection();
    echo "<p style='color: green;'>✅ 데이터베이스 연결 성공</p>";
    
    // products 테이블 존재 확인
    $result = $conn->query("SHOW TABLES LIKE 'products'");
    if ($result->num_rows > 0) {
        echo "<p style='color: green;'>✅ products 테이블 존재</p>";
        
        // 테이블 구조 확인
        echo "<h3>Products 테이블 구조:</h3>";
        $result = $conn->query("DESCRIBE products");
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // 샘플 데이터 확인
        echo "<h3>샘플 데이터 (첫 3개):</h3>";
        $result = $conn->query("SELECT id, sku, name_en, name_ko FROM products LIMIT 3");
        
        if ($result->num_rows > 0) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>ID</th><th>SKU</th><th>Name EN</th><th>Name KO</th></tr>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['sku'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['name_en'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['name_ko'] ?? '') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // 첫 번째 상품으로 업데이트 테스트
            echo "<h3>업데이트 테스트:</h3>";
            $test_result = $conn->query("SELECT id, name_en, name_ko FROM products LIMIT 1");
            if ($test_row = $test_result->fetch_assoc()) {
                $test_id = $test_row['id'];
                $current_name = $test_row['name_en'];
                
                echo "<p><strong>테스트 대상 상품:</strong></p>";
                echo "<ul>";
                echo "<li>ID: " . $test_id . "</li>";
                echo "<li>현재 영어명: " . htmlspecialchars($current_name) . "</li>";
                echo "<li>현재 한글명: " . htmlspecialchars($test_row['name_ko']) . "</li>";
                echo "</ul>";
                
                // 실제 업데이트 쿼리 테스트 (dry run)
                $test_name = "테스트_" . date('His');
                $update_sql = "UPDATE products SET name_ko = ? WHERE id = ?";
                $stmt = $conn->prepare($update_sql);
                
                if ($stmt) {
                    echo "<p style='color: green;'>✅ 업데이트 쿼리 준비 성공</p>";
                    echo "<p>준비된 쿼리: " . htmlspecialchars($update_sql) . "</p>";
                    echo "<p>테스트 값: name_ko = '$test_name', id = $test_id</p>";
                } else {
                    echo "<p style='color: red;'>❌ 업데이트 쿼리 준비 실패: " . $conn->error . "</p>";
                }
            }
        } else {
            echo "<p style='color: orange;'>⚠️ 상품 데이터가 없습니다.</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ products 테이블이 없습니다.</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ 오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<h3>세션 정보:</h3>";
echo "<ul>";
echo "<li>로그인 상태: " . (is_logged_in() ? "로그인됨" : "로그인 안됨") . "</li>";
echo "<li>사용자 ID: " . ($_SESSION['user_id'] ?? '없음') . "</li>";
echo "<li>권한: " . (has_permission('purchase_management') ? "있음" : "없음") . "</li>";
echo "</ul>";

?>

<div style="margin-top: 20px;">
    <a href="test_product_name_update.php" style="display: inline-block; padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;">실제 업데이트 테스트</a>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>