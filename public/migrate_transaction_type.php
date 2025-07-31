<?php
// 데이터베이스 transaction_type 컬럼 마이그레이션 스크립트
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h2>Transaction Type 마이그레이션 시작</h2>";
    
    // 1. 기존 데이터 백업 및 변환
    echo "1. 기존 데이터 확인 중...<br>";
    $result = $conn->query("SELECT DISTINCT transaction_type FROM inventory_transactions");
    $existing_types = [];
    while ($row = $result->fetch_assoc()) {
        $existing_types[] = $row['transaction_type'];
        echo "- 발견된 타입: " . $row['transaction_type'] . "<br>";
    }
    
    // 2. 데이터 변환
    echo "<br>2. 데이터 변환 중...<br>";
    $updates = [
        '입고' => 'IN',
        '판매' => 'SALE', 
        '반품' => 'RETURN',
        '재고조정' => 'ADJUST',
        '최초등록' => 'INIT',
        '출고' => 'OUT'  // 수정/삭제 시 사용되는 출고 타입 추가
    ];
    
    foreach ($updates as $old_type => $new_type) {
        $stmt = $conn->prepare("UPDATE inventory_transactions SET transaction_type = ? WHERE transaction_type = ?");
        $stmt->bind_param("ss", $new_type, $old_type);
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            echo "- '{$old_type}' → '{$new_type}': {$affected}개 행 업데이트<br>";
        }
        $stmt->close();
    }
    
    // 3. ENUM 컬럼 업데이트
    echo "<br>3. 컬럼 스키마 업데이트 중...<br>";
    $alter_sql = "ALTER TABLE inventory_transactions MODIFY COLUMN transaction_type ENUM('IN', 'SALE', 'RETURN', 'ADJUST', 'INIT', 'OUT') NOT NULL";
    
    if ($conn->query($alter_sql)) {
        echo "- transaction_type 컬럼 스키마가 성공적으로 업데이트되었습니다.<br>";
    } else {
        echo "- 오류: " . $conn->error . "<br>";
    }
    
    // 4. 변환 결과 확인
    echo "<br>4. 변환 결과 확인...<br>";
    $result = $conn->query("SELECT transaction_type, COUNT(*) as count FROM inventory_transactions GROUP BY transaction_type");
    while ($row = $result->fetch_assoc()) {
        echo "- {$row['transaction_type']}: {$row['count']}개<br>";
    }
    
    echo "<br><strong>마이그레이션 완료!</strong><br>";
    echo '<a href="edit_purchase.php?id=1">매입 상세보기로 돌아가기</a>';
    
} catch (Exception $e) {
    echo "<strong>오류 발생:</strong> " . $e->getMessage();
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>