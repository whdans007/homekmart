<?php
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h2>Transaction Type 컬럼 완전 수정</h2>";
    
    // 1. 현재 상태 확인
    echo "<h3>1. 현재 컬럼 상태:</h3>";
    $result = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE 'transaction_type'");
    $column_info = $result->fetch_assoc();
    echo "현재 타입: " . htmlspecialchars($column_info['Type']) . "<br>";
    
    // 2. 기존 데이터 확인
    echo "<h3>2. 기존 데이터:</h3>";
    $result = $conn->query("SELECT DISTINCT transaction_type FROM inventory_transactions");
    while ($row = $result->fetch_assoc()) {
        echo "- " . htmlspecialchars($row['transaction_type']) . "<br>";
    }
    
    // 3. 컬럼을 VARCHAR로 변경
    echo "<h3>3. 컬럼을 VARCHAR로 변경:</h3>";
    $alter_sql = "ALTER TABLE inventory_transactions MODIFY COLUMN transaction_type VARCHAR(20) NOT NULL";
    if ($conn->query($alter_sql)) {
        echo "✓ 컬럼이 VARCHAR(20)으로 변경되었습니다.<br>";
    } else {
        echo "✗ 컬럼 변경 실패: " . $conn->error . "<br>";
        throw new Exception("컬럼 변경 실패");
    }
    
    // 4. 기존 한글 데이터를 영문으로 변환
    echo "<h3>4. 데이터 변환:</h3>";
    $updates = [
        '입고' => 'IN',
        '판매' => 'SALE', 
        '반품' => 'RETURN',
        '재고조정' => 'ADJUST',
        '최초등록' => 'INIT',
        '출고' => 'OUT'
    ];
    
    foreach ($updates as $old_type => $new_type) {
        $stmt = $conn->prepare("UPDATE inventory_transactions SET transaction_type = ? WHERE transaction_type = ?");
        $stmt->bind_param("ss", $new_type, $old_type);
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            if ($affected > 0) {
                echo "- '{$old_type}' → '{$new_type}': {$affected}개 행 업데이트<br>";
            }
        }
        $stmt->close();
    }
    
    // 5. 테스트 삽입
    echo "<h3>5. 테스트 삽입:</h3>";
    $test_values = ['IN', 'OUT', 'SALE', 'RETURN', 'ADJUST', 'INIT'];
    
    // inventory와 user 확인
    $inv_result = $conn->query("SELECT id FROM inventory LIMIT 1");
    $user_result = $conn->query("SELECT id FROM users LIMIT 1");
    
    if ($inv_result->num_rows > 0 && $user_result->num_rows > 0) {
        $inventory_id = $inv_result->fetch_assoc()['id'];
        $user_id = $user_result->fetch_assoc()['id'];
        
        foreach ($test_values as $test_value) {
            try {
                $stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, ?, 1, 'TEST - DELETE ME')");
                $stmt->bind_param("iis", $inventory_id, $user_id, $test_value);
                if ($stmt->execute()) {
                    echo "✓ '{$test_value}' 삽입 성공<br>";
                    // 테스트 데이터 즉시 삭제
                    $delete_id = $stmt->insert_id;
                    $conn->query("DELETE FROM inventory_transactions WHERE id = $delete_id");
                } else {
                    echo "✗ '{$test_value}' 삽입 실패: " . $stmt->error . "<br>";
                }
                $stmt->close();
            } catch (Exception $e) {
                echo "✗ '{$test_value}' 오류: " . $e->getMessage() . "<br>";
            }
        }
    }
    
    // 6. 최종 상태 확인
    echo "<h3>6. 최종 상태:</h3>";
    $result = $conn->query("SHOW COLUMNS FROM inventory_transactions LIKE 'transaction_type'");
    $column_info = $result->fetch_assoc();
    echo "최종 타입: " . htmlspecialchars($column_info['Type']) . "<br>";
    
    $result = $conn->query("SELECT transaction_type, COUNT(*) as count FROM inventory_transactions GROUP BY transaction_type");
    echo "<table border='1'>";
    echo "<tr><th>Transaction Type</th><th>Count</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>" . htmlspecialchars($row['transaction_type']) . "</td><td>" . $row['count'] . "</td></tr>";
    }
    echo "</table>";
    
    echo "<br><h3>🎉 수정 완료!</h3>";
    echo "이제 매입 상세보기에서 수정/삭제가 정상적으로 작동할 것입니다.<br>";
    echo '<a href="edit_purchase.php?id=1">매입 상세보기 테스트</a>';
    
} catch (Exception $e) {
    echo "<strong>오류:</strong> " . $e->getMessage();
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<style>
table { border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
</style>