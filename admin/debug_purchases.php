<?php
require_once __DIR__ . '/../config/db_config.php';

echo "<h1>Purchase 데이터 디버그</h1>";

$conn = get_db_connection();

// 1. purchases 테이블 존재 확인
$tables_query = "SHOW TABLES LIKE 'purchases'";
$tables_result = $conn->query($tables_query);
echo "<h2>1. purchases 테이블 존재 여부</h2>";
echo "<p>테이블 존재: " . ($tables_result->num_rows > 0 ? "✅ 예" : "❌ 아니오") . "</p>";

// 2. purchases 테이블 구조 확인
echo "<h2>2. purchases 테이블 구조</h2>";
$structure_query = "DESCRIBE purchases";
$structure_result = $conn->query($structure_query);
if ($structure_result) {
    echo "<table border='1'>";
    echo "<tr><th>필드</th><th>타입</th><th>Null</th><th>키</th><th>기본값</th><th>Extra</th></tr>";
    while ($row = $structure_result->fetch_assoc()) {
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
}

// 3. 전체 purchases 데이터 개수
echo "<h2>3. 전체 데이터 개수</h2>";
$count_query = "SELECT COUNT(*) as total FROM purchases";
$count_result = $conn->query($count_query);
if ($count_result) {
    $count_row = $count_result->fetch_assoc();
    echo "<p>전체 purchases: " . $count_row['total'] . "개</p>";
}

// 4. deleted_at이 NULL인 데이터 개수
$active_count_query = "SELECT COUNT(*) as total FROM purchases WHERE deleted_at IS NULL";
$active_count_result = $conn->query($active_count_query);
if ($active_count_result) {
    $active_count_row = $active_count_result->fetch_assoc();
    echo "<p>활성 purchases (deleted_at IS NULL): " . $active_count_row['total'] . "개</p>";
}

// 5. suppliers 테이블 확인
echo "<h2>4. suppliers 테이블 확인</h2>";
$suppliers_count_query = "SELECT COUNT(*) as total FROM suppliers";
$suppliers_count_result = $conn->query($suppliers_count_query);
if ($suppliers_count_result) {
    $suppliers_count_row = $suppliers_count_result->fetch_assoc();
    echo "<p>전체 suppliers: " . $suppliers_count_row['total'] . "개</p>";
}

// 6. JOIN 쿼리 테스트 (간단한 버전)
echo "<h2>5. 간단한 JOIN 쿼리 테스트</h2>";
$simple_query = "SELECT p.purchase_id, p.purchase_date, s.name as supplier_name 
                 FROM purchases p 
                 JOIN suppliers s ON p.supplier_id = s.id 
                 WHERE p.deleted_at IS NULL 
                 LIMIT 5";
$simple_result = $conn->query($simple_query);
if ($simple_result) {
    echo "<p>JOIN 결과: " . $simple_result->num_rows . "개</p>";
    if ($simple_result->num_rows > 0) {
        echo "<table border='1'>";
        echo "<tr><th>Purchase ID</th><th>Purchase Date</th><th>Supplier Name</th></tr>";
        while ($row = $simple_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['purchase_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['purchase_date']) . "</td>";
            echo "<td>" . htmlspecialchars($row['supplier_name']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} else {
    echo "<p>쿼리 오류: " . $conn->error . "</p>";
}

$conn->close();
?>