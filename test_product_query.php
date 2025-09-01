<?php
require_once __DIR__ . '/config/db_config.php';

$conn = get_db_connection();
$store_id = 1;
$section_id = 1; // 베너 섹션

echo "<h2>상품 조회 쿼리 테스트</h2>";

// product_displays 테이블 구조 확인
echo "<h3>1. product_displays 테이블 구조</h3>";
$structure = $conn->query("DESCRIBE product_displays");
if ($structure) {
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    while ($col = $structure->fetch_assoc()) {
        echo "<tr><td>" . $col['Field'] . "</td><td>" . $col['Type'] . "</td><td>" . $col['Null'] . "</td><td>" . $col['Key'] . "</td></tr>";
    }
    echo "</table>";
}

// 현재 사용하고 있는 쿼리 테스트 (store_id 포함)
echo "<h3>2. 현재 쿼리 (store_id 조건 포함) - 베너 섹션</h3>";
$current_query = "
    SELECT p.id, p.name_kr, p.name_en, p.description, 
           i.selling_price, i.cost_price, i.quantity,
           pd.display_order, pd.badge_text
    FROM product_displays pd
    INNER JOIN products p ON pd.product_id = p.id
    INNER JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
    WHERE pd.section_id = ? 
      AND pd.store_id = ?
      AND pd.is_active = 1
    ORDER BY pd.display_order ASC
";

$stmt1 = $conn->prepare($current_query);
$stmt1->bind_param("iii", $store_id, $section_id, $store_id);
$stmt1->execute();
$result1 = $stmt1->get_result();

echo "<p>결과 수: " . $result1->num_rows . "개</p>";
if ($result1->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Price</th><th>Order</th></tr>";
    while ($row = $result1->fetch_assoc()) {
        echo "<tr><td>" . $row['id'] . "</td><td>" . $row['name_kr'] . "</td><td>" . $row['selling_price'] . "</td><td>" . $row['display_order'] . "</td></tr>";
    }
    echo "</table>";
}
$stmt1->close();

// store_id 조건 없이 테스트
echo "<h3>3. 수정된 쿼리 (store_id 조건 제거) - 베너 섹션</h3>";
$fixed_query = "
    SELECT p.id, p.name_kr, p.name_en, p.description, 
           i.selling_price, i.cost_price, i.quantity,
           pd.display_order, pd.badge_text
    FROM product_displays pd
    INNER JOIN products p ON pd.product_id = p.id
    INNER JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
    WHERE pd.section_id = ? 
      AND pd.is_active = 1
    ORDER BY pd.display_order ASC
";

$stmt2 = $conn->prepare($fixed_query);
$stmt2->bind_param("ii", $store_id, $section_id);
$stmt2->execute();
$result2 = $stmt2->get_result();

echo "<p>결과 수: " . $result2->num_rows . "개</p>";
if ($result2->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Price</th><th>Order</th></tr>";
    while ($row = $result2->fetch_assoc()) {
        echo "<tr><td>" . $row['id'] . "</td><td>" . $row['name_kr'] . "</td><td>" . $row['selling_price'] . "</td><td>" . $row['display_order'] . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>결과 없음</p>";
}
$stmt2->close();

$conn->close();
?>