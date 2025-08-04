<?php
/**
 * inventory 테이블에 box_price 컬럼 추가 스크립트
 * 점포별 박스단가 설정을 위한 데이터베이스 구조 변경
 */

require_once __DIR__ . '/config/db_config.php';

echo "inventory 테이블에 box_price 컬럼 추가 중...\n";

try {
    $conn = get_db_connection();
    
    // box_price 컬럼이 이미 존재하는지 확인
    $check_column = $conn->query("SHOW COLUMNS FROM inventory LIKE 'box_price'");
    
    if ($check_column->num_rows == 0) {
        // box_price 컬럼 추가
        $sql = "ALTER TABLE inventory ADD COLUMN box_price DECIMAL(10,2) DEFAULT NULL COMMENT '점포별 박스단가'";
        
        if ($conn->query($sql)) {
            echo "✅ box_price 컬럼이 성공적으로 추가되었습니다.\n";
        } else {
            echo "❌ 컬럼 추가 실패: " . $conn->error . "\n";
        }
    } else {
        echo "ℹ️  box_price 컬럼이 이미 존재합니다.\n";
    }
    
    // 컬럼 구조 확인
    echo "\n현재 inventory 테이블 구조:\n";
    $result = $conn->query("DESCRIBE inventory");
    while ($row = $result->fetch_assoc()) {
        echo "- {$row['Field']}: {$row['Type']} ({$row['Null']}, {$row['Default']})\n";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "❌ 오류 발생: " . $e->getMessage() . "\n";
}

echo "\n완료!\n";
?>