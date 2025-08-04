<?php
/**
 * purchase_items 테이블에 할인 관련 컬럼 추가
 * discount_rate: 할인율 (DECIMAL 5,2 - 최대 999.99%)
 * discounted_total: 할인후 총액 (DECIMAL 10,2)
 */

require_once __DIR__ . '/config/db_config.php';

try {
    $conn = get_db_connection();
    
    // discount_rate 컬럼 체크 및 추가
    $check_discount_rate = $conn->query("SHOW COLUMNS FROM purchase_items LIKE 'discount_rate'");
    if ($check_discount_rate->num_rows == 0) {
        $sql_discount_rate = "ALTER TABLE purchase_items ADD COLUMN discount_rate DECIMAL(5,2) DEFAULT 0.00 COMMENT '할인율 (%)' AFTER unit_price";
        if ($conn->query($sql_discount_rate)) {
            echo "✓ discount_rate 컬럼이 성공적으로 추가되었습니다.\n";
        } else {
            throw new Exception("discount_rate 컬럼 추가 실패: " . $conn->error);
        }
    } else {
        echo "ℹ discount_rate 컬럼이 이미 존재합니다.\n";
    }
    
    // discounted_total 컬럼 체크 및 추가
    $check_discounted_total = $conn->query("SHOW COLUMNS FROM purchase_items LIKE 'discounted_total'");
    if ($check_discounted_total->num_rows == 0) {
        $sql_discounted_total = "ALTER TABLE purchase_items ADD COLUMN discounted_total DECIMAL(10,2) DEFAULT NULL COMMENT '할인후 총액' AFTER discount_rate";
        if ($conn->query($sql_discounted_total)) {
            echo "✓ discounted_total 컬럼이 성공적으로 추가되었습니다.\n";
        } else {
            throw new Exception("discounted_total 컬럼 추가 실패: " . $conn->error);
        }
    } else {
        echo "ℹ discounted_total 컬럼이 이미 존재합니다.\n";
    }
    
    // 기존 데이터에 대해 discounted_total 초기값 설정 (할인이 없으면 기존 총액과 동일)
    $update_existing = "UPDATE purchase_items 
                       SET discounted_total = (quantity * unit_price) 
                       WHERE discounted_total IS NULL";
    if ($conn->query($update_existing)) {
        $affected_rows = $conn->affected_rows;
        echo "✓ 기존 {$affected_rows}개 레코드의 discounted_total이 초기화되었습니다.\n";
    }
    
    echo "\n=== 데이터베이스 스키마 업데이트 완료 ===\n";
    echo "purchase_items 테이블에 할인 관련 컬럼이 추가되었습니다:\n";
    echo "- discount_rate: 할인율 (DECIMAL 5,2)\n";
    echo "- discounted_total: 할인후 총액 (DECIMAL 10,2)\n";
    
} catch (Exception $e) {
    echo "❌ 오류 발생: " . $e->getMessage() . "\n";
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>