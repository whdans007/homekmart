<?php
require_once __DIR__ . '/config/db_config.php';

try {
    $conn = get_db_connection();

    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }

    // 먼저 현재 스키마 확인
    echo "=== 현재 스키마 ===\n";
    $result = $conn->query('DESCRIBE store_order_list_items');
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }

    echo "\n=== 마이그레이션 실행 ===\n";

    // 1. order_status 컬럼이 이미 있는지 확인
    $check_result = $conn->query("SHOW COLUMNS FROM store_order_list_items LIKE 'order_status'");
    $order_status_exists = $check_result->num_rows > 0;

    if (!$order_status_exists) {
        echo "order_status 컬럼 추가...\n";
        $conn->query("ALTER TABLE store_order_list_items ADD COLUMN order_status ENUM('주문', '비주문') DEFAULT '주문' AFTER quantity");
        echo "✓ order_status 컬럼 추가됨\n";
    } else {
        echo "✓ order_status 컬럼이 이미 존재함\n";
    }

    // 2. remarks 컬럼 삭제
    $check_result = $conn->query("SHOW COLUMNS FROM store_order_list_items LIKE 'remarks'");
    $remarks_exists = $check_result->num_rows > 0;

    if ($remarks_exists) {
        echo "remarks 컬럼 삭제...\n";
        $conn->query("ALTER TABLE store_order_list_items DROP COLUMN remarks");
        echo "✓ remarks 컬럼 삭제됨\n";
    } else {
        echo "✓ remarks 컬럼이 이미 삭제됨\n";
    }

    // 3. order_status 인덱스 추가
    $index_result = $conn->query("SHOW INDEX FROM store_order_list_items WHERE Column_name='order_status'");
    if ($index_result->num_rows === 0) {
        echo "order_status 인덱스 추가...\n";
        $conn->query("ALTER TABLE store_order_list_items ADD INDEX idx_order_status (order_status)");
        echo "✓ order_status 인덱스 추가됨\n";
    } else {
        echo "✓ order_status 인덱스가 이미 존재함\n";
    }

    echo "\n=== 마이그레이션 완료! ===\n";
    echo "\n=== 최종 스키마 ===\n";
    $result = $conn->query('DESCRIBE store_order_list_items');
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }

    $conn->close();
    echo "\n✓ 성공적으로 완료되었습니다.\n";

} catch (Exception $e) {
    echo "오류: " . $e->getMessage() . "\n";
    exit(1);
}
