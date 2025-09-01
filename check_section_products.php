<?php
require_once __DIR__ . '/config/db_config.php';

$conn = get_db_connection();
$store_id = 1;
$section_id = 1;

echo "<h2>상품 조회 디버깅 (섹션 ID: $section_id)</h2>";

// 1. getSectionProducts와 동일한 쿼리 테스트
echo "<h3>1. getSectionProducts 함수와 동일한 쿼리</h3>";
$query = "
    SELECT p.id, p.name_kr, i.selling_price, i.quantity, pd.display_order
    FROM product_displays pd
    INNER JOIN products p ON pd.product_id = p.id
    LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = $store_id
    WHERE pd.section_id = $section_id AND pd.is_active = 1
    ORDER BY pd.display_order ASC, p.name_kr ASC
";

echo "<p>실행 쿼리:</p>";
echo "<pre>" . htmlspecialchars($query) . "</pre>";

$result = $conn->query($query);
if ($result) {
    echo "<p><strong>결과: " . $result->num_rows . "개 상품</strong></p>";
    
    if ($result->num_rows > 0) {
        echo "<table border='1'>";
        echo "<tr><th>Product ID</th><th>Name</th><th>Price</th><th>Quantity</th><th>Display Order</th></tr>";
        while ($product = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $product['id'] . "</td>";
            echo "<td>" . htmlspecialchars($product['name_kr'] ?: 'NULL') . "</td>";
            echo "<td>" . ($product['selling_price'] ?: 'NULL') . "</td>";
            echo "<td>" . ($product['quantity'] ?: 'NULL') . "</td>";
            echo "<td>" . $product['display_order'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>결과가 없습니다. 개별 테이블을 확인합니다...</p>";
        
        // 2. product_displays만 확인
        echo "<h3>2. product_displays 테이블 확인</h3>";
        $pd_result = $conn->query("SELECT * FROM product_displays WHERE section_id = $section_id AND is_active = 1 LIMIT 5");
        if ($pd_result && $pd_result->num_rows > 0) {
            echo "<p>" . $pd_result->num_rows . "개의 product_displays 발견</p>";
            echo "<table border='1'><tr><th>ID</th><th>Product ID</th><th>Section ID</th><th>Active</th></tr>";
            while ($pd = $pd_result->fetch_assoc()) {
                echo "<tr><td>" . $pd['id'] . "</td><td>" . $pd['product_id'] . "</td><td>" . $pd['section_id'] . "</td><td>" . $pd['is_active'] . "</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p>product_displays에서 해당 섹션 데이터를 찾을 수 없습니다.</p>";
        }
        
        // 3. products 테이블 일반 확인
        echo "<h3>3. products 테이블 일반 확인</h3>";
        $p_result = $conn->query("SELECT COUNT(*) as count FROM products WHERE is_active = 1");
        $p_count = $p_result ? $p_result->fetch_assoc()['count'] : 0;
        echo "<p>활성 상품 수: $p_count 개</p>";
    }
} else {
    echo "<p style='color: red;'>쿼리 실행 오류: " . $conn->error . "</p>";
}

$conn->close();
?>