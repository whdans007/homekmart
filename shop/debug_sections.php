<?php
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();
$selected_store_id = 1; // 기본 점포

echo "<h2>디버깅 정보</h2>";

// 1. 섹션 조회
echo "<h3>1. 섹션 정보</h3>";
$sections_query = "
    SELECT ds.*, 
           COUNT(pd.id) as product_count
    FROM display_sections ds
    LEFT JOIN product_displays pd ON ds.id = pd.section_id 
        AND pd.store_id = ? 
        AND pd.is_active = 1
    WHERE ds.is_active = 1 AND ds.show_on_main = 1
    GROUP BY ds.id
    ORDER BY ds.display_order ASC
";
$sections_stmt = $conn->prepare($sections_query);
$sections_stmt->bind_param("i", $selected_store_id);
$sections_stmt->execute();
$sections_result = $sections_stmt->get_result();

echo "<table border='1'><tr><th>ID</th><th>이름</th><th>상품수</th><th>활성</th></tr>";
while ($section = $sections_result->fetch_assoc()) {
    echo "<tr><td>{$section['id']}</td><td>{$section['name']}</td><td>{$section['product_count']}</td><td>{$section['is_active']}</td></tr>";
}
echo "</table>";
$sections_stmt->close();

// 2. 진열 상품 조회
echo "<h3>2. 진열 상품 정보</h3>";
$products_query = "SELECT pd.*, p.name_ko as product_name FROM product_displays pd JOIN products p ON pd.product_id = p.id WHERE pd.store_id = ?";
$products_stmt = $conn->prepare($products_query);
$products_stmt->bind_param("i", $selected_store_id);
$products_stmt->execute();
$products_result = $products_stmt->get_result();

echo "<table border='1'><tr><th>ID</th><th>섹션ID</th><th>상품명</th><th>활성</th></tr>";
while ($product = $products_result->fetch_assoc()) {
    echo "<tr><td>{$product['id']}</td><td>{$product['section_id']}</td><td>{$product['product_name']}</td><td>{$product['is_active']}</td></tr>";
}
echo "</table>";
$products_stmt->close();

// 3. 섹션별 상품 조회 테스트 (실제 쇼핑몰 쿼리와 동일)
echo "<h3>3. 쇼핑몰 쿼리 테스트</h3>";
$test_query = "
    SELECT pd.*, p.name_ko as product_name, p.barcode, p.description as product_description,
           p.image_url, b.name_ko as brand_name, c.name_ko as category_name,
           i.selling_price, i.cost_price
    FROM product_displays pd
    JOIN products p ON pd.product_id = p.id
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
    WHERE pd.section_id = 1 AND pd.store_id = ? AND pd.is_active = 1
    ORDER BY pd.display_order ASC, pd.created_at ASC
";
$test_stmt = $conn->prepare($test_query);
$test_stmt->bind_param("ii", $selected_store_id, $selected_store_id);
$test_stmt->execute();
$test_result = $test_stmt->get_result();

echo "<strong>섹션 1 상품:</strong><br>";
if ($test_result->num_rows > 0) {
    while ($row = $test_result->fetch_assoc()) {
        echo "- {$row['product_name']} (브랜드: {$row['brand_name']}, 카테고리: {$row['category_name']}, 가격: {$row['selling_price']})<br>";
    }
} else {
    echo "상품이 없습니다.<br>";
}
$test_stmt->close();

$conn->close();
?>