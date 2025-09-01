<?php
require_once __DIR__ . '/config/db_config.php';

$conn = get_db_connection();
$store_id = 1; // CLARK HILLS
$section_id = 1; // 베너 섹션

echo "<h1>상품 조회 디버깅</h1>";

// 1. 기본 정보 확인
echo "<h2>1. 기본 정보</h2>";
echo "<p>Store ID: $store_id</p>";
echo "<p>Section ID: $section_id</p>";

// 2. product_displays 확인
echo "<h2>2. Product Displays (섹션별)</h2>";
$pd_query = "SELECT * FROM product_displays WHERE section_id = $section_id AND is_active = 1";
$pd_result = $conn->query($pd_query);
echo "<p>쿼리: $pd_query</p>";
echo "<p>결과 수: " . ($pd_result ? $pd_result->num_rows : 0) . "</p>";

if ($pd_result && $pd_result->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Product ID</th><th>Section ID</th><th>Order</th><th>Active</th></tr>";
    while ($pd = $pd_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $pd['id'] . "</td>";
        echo "<td>" . $pd['product_id'] . "</td>";
        echo "<td>" . $pd['section_id'] . "</td>";
        echo "<td>" . $pd['display_order'] . "</td>";
        echo "<td>" . $pd['is_active'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 3. 조인 쿼리 테스트
    echo "<h2>3. 완전한 조인 쿼리 테스트</h2>";
    $full_query = "
        SELECT p.id, p.name_kr, i.selling_price, i.quantity, pd.display_order
        FROM product_displays pd
        INNER JOIN products p ON pd.product_id = p.id
        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = $store_id
        WHERE pd.section_id = $section_id AND pd.is_active = 1
        ORDER BY pd.display_order ASC
    ";
    
    echo "<p>조인 쿼리:</p><pre>" . htmlspecialchars($full_query) . "</pre>";
    
    $full_result = $conn->query($full_query);
    echo "<p>조인 결과 수: " . ($full_result ? $full_result->num_rows : 0) . "</p>";
    
    if ($full_result && $full_result->num_rows > 0) {
        echo "<table border='1'><tr><th>Product ID</th><th>Name KR</th><th>Price</th><th>Quantity</th><th>Order</th></tr>";
        while ($product = $full_result->fetch_assoc()) {
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
        echo "<p style='color: red;'>조인 쿼리에서 결과가 없습니다!</p>";
        
        // 4. 개별 테이블 확인
        echo "<h2>4. 개별 테이블 확인</h2>";
        
        $pd_result->data_seek(0);
        $first_pd = $pd_result->fetch_assoc();
        $product_id = $first_pd['product_id'];
        
        echo "<p>첫 번째 product_id: $product_id</p>";
        
        // Products 테이블 확인
        $p_check = $conn->query("SELECT id, name_kr FROM products WHERE id = $product_id");
        echo "<p>Products 테이블 확인: " . ($p_check ? $p_check->num_rows : 0) . "개 결과</p>";
        if ($p_check && $p_check->num_rows > 0) {
            $p_data = $p_check->fetch_assoc();
            echo "<p>상품명: " . htmlspecialchars($p_data['name_kr'] ?: 'NULL') . "</p>";
        }
        
        // Inventory 테이블 확인
        $i_check = $conn->query("SELECT * FROM inventory WHERE product_id = $product_id AND store_id = $store_id");
        echo "<p>Inventory 테이블 확인: " . ($i_check ? $i_check->num_rows : 0) . "개 결과</p>";
        if ($i_check && $i_check->num_rows > 0) {
            $i_data = $i_check->fetch_assoc();
            echo "<p>판매가: " . $i_data['selling_price'] . ", 수량: " . $i_data['quantity'] . "</p>";
        }
    }
} else {
    echo "<p style='color: red;'>해당 섹션에 product_displays 데이터가 없습니다!</p>";
}

$conn->close();
?>