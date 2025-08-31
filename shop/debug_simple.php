<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/../config/db_config.php';
    $conn = get_db_connection();
    $selected_store_id = 1;

    echo "<h2>간단 디버깅</h2>";

    // 1. 섹션 수 확인
    $result = $conn->query("SELECT COUNT(*) as cnt FROM display_sections WHERE is_active = 1");
    $count = $result->fetch_assoc();
    echo "활성 섹션 수: " . $count['cnt'] . "<br>";

    // 2. 진열 상품 수 확인
    $result = $conn->query("SELECT COUNT(*) as cnt FROM product_displays WHERE store_id = $selected_store_id AND is_active = 1");
    $count = $result->fetch_assoc();
    echo "점포 $selected_store_id 진열 상품 수: " . $count['cnt'] . "<br>";

    // 3. categories 테이블 컬럼 확인
    $result = $conn->query("SHOW COLUMNS FROM categories");
    echo "<h3>Categories 테이블 컬럼:</h3>";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . "<br>";
    }

    $conn->close();

} catch (Exception $e) {
    echo "오류: " . $e->getMessage();
}
?>