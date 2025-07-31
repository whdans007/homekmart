<?php
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h2>Transaction Type 컬럼 상태 확인</h2>";
    
    // 1. 테이블 구조 확인
    echo "<h3>1. inventory_transactions 테이블 구조:</h3>";
    $result = $conn->query("DESCRIBE inventory_transactions");
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    // 2. 현재 데이터 확인
    echo "<h3>2. 현재 transaction_type 데이터:</h3>";
    $result = $conn->query("SELECT transaction_type, COUNT(*) as count FROM inventory_transactions GROUP BY transaction_type");
    if ($result && $result->num_rows > 0) {
        echo "<table border='1'>";
        echo "<tr><th>Transaction Type</th><th>Count</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr><td>" . htmlspecialchars($row['transaction_type']) . "</td><td>" . $row['count'] . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "데이터가 없습니다.";
    }
    
    // 3. 테스트 삽입 시도
    echo "<h3>3. 테스트 삽입:</h3>";
    
    // 먼저 inventory가 있는지 확인
    $inv_result = $conn->query("SELECT id FROM inventory LIMIT 1");
    if ($inv_result && $inv_result->num_rows > 0) {
        $inv_row = $inv_result->fetch_assoc();
        $inventory_id = $inv_row['id'];
        
        // 사용자 ID 확인
        $user_result = $conn->query("SELECT id FROM users LIMIT 1");
        if ($user_result && $user_result->num_rows > 0) {
            $user_row = $user_result->fetch_assoc();
            $user_id = $user_row['id'];
            
            // 각 값으로 테스트 삽입
            $test_values = ['IN', 'OUT', 'SALE', 'RETURN', 'ADJUST', 'INIT'];
            
            foreach ($test_values as $test_value) {
                try {
                    $stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, ?, 1, 'TEST')");
                    $stmt->bind_param("iis", $inventory_id, $user_id, $test_value);
                    if ($stmt->execute()) {
                        echo "✓ '{$test_value}' 삽입 성공<br>";
                        // 테스트 데이터 삭제
                        $conn->query("DELETE FROM inventory_transactions WHERE remarks = 'TEST' ORDER BY id DESC LIMIT 1");
                    } else {
                        echo "✗ '{$test_value}' 삽입 실패: " . $stmt->error . "<br>";
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    echo "✗ '{$test_value}' 삽입 오류: " . $e->getMessage() . "<br>";
                }
            }
        } else {
            echo "사용자가 없습니다.";
        }
    } else {
        echo "inventory 데이터가 없습니다.";
    }
    
    // 4. ENUM 값 강제 업데이트 시도
    echo "<h3>4. ENUM 컬럼 강제 업데이트:</h3>";
    try {
        $alter_sql = "ALTER TABLE inventory_transactions MODIFY COLUMN transaction_type ENUM('IN', 'OUT', 'SALE', 'RETURN', 'ADJUST', 'INIT') NOT NULL";
        if ($conn->query($alter_sql)) {
            echo "✓ 컬럼 업데이트 성공<br>";
        } else {
            echo "✗ 컬럼 업데이트 실패: " . $conn->error . "<br>";
        }
    } catch (Exception $e) {
        echo "✗ 컬럼 업데이트 오류: " . $e->getMessage() . "<br>";
    }
    
    // 5. 다시 테이블 구조 확인
    echo "<h3>5. 업데이트 후 테이블 구조:</h3>";
    $result = $conn->query("DESCRIBE inventory_transactions");
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
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