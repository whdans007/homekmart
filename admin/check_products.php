<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/partials/header.php';

// 권한 확인
if (!has_permission('purchase_management')) {
    echo "권한 없음";
    exit;
}

try {
    $conn = get_db_connection();
    
    // products 테이블 구조 확인
    echo "<h2>Products 테이블 구조:</h2>";
    $result = $conn->query("DESCRIBE products");
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-bottom: 20px;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($value ?? '') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    // products 데이터 확인 (처음 5개만)
    echo "<h2>Products 데이터 (처음 5개):</h2>";
    $result = $conn->query("SELECT id, sku, name_en, name_ko, created_at FROM products ORDER BY id LIMIT 5");
    
    if ($result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-bottom: 20px;'>";
        echo "<tr><th>ID</th><th>SKU</th><th>Name EN</th><th>Name KO</th><th>Created At</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['sku'] ?? '') . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['name_en'] ?? '') . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['name_ko'] ?? '') . "</td>";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['created_at'] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>상품 데이터가 없습니다.</p>";
    }
    
    // 전체 상품 수 확인
    $result = $conn->query("SELECT COUNT(*) as total FROM products");
    $row = $result->fetch_assoc();
    echo "<p><strong>전체 상품 수:</strong> " . $row['total'] . "</p>";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<a href="test_product_name_update.php" class="btn btn-primary" style="display: inline-block; margin-top: 20px; padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;">테스트 페이지로 이동</a>

<?php require_once __DIR__ . '/partials/footer.php'; ?>