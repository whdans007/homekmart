<?php
// purchases 테이블에 soft delete 컬럼 추가 스크립트
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h2>purchases 테이블 Soft Delete 컬럼 추가</h2>";
    
    // 1. deleted_at 컬럼 추가
    echo "<h3>1. deleted_at 컬럼 확인 및 추가:</h3>";
    $check_deleted_at = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
    if ($check_deleted_at->num_rows == 0) {
        $add_deleted_at = "ALTER TABLE purchases ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL";
        if ($conn->query($add_deleted_at)) {
            echo "✓ deleted_at 컬럼이 추가되었습니다.<br>";
        } else {
            echo "✗ deleted_at 컬럼 추가 실패: " . $conn->error . "<br>";
        }
    } else {
        echo "✓ deleted_at 컬럼이 이미 존재합니다.<br>";
    }
    
    // 2. deleted_by_user_id 컬럼 추가
    echo "<h3>2. deleted_by_user_id 컬럼 확인 및 추가:</h3>";
    $check_deleted_by = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_by_user_id'");
    if ($check_deleted_by->num_rows == 0) {
        $add_deleted_by = "ALTER TABLE purchases ADD COLUMN deleted_by_user_id INT DEFAULT NULL";
        if ($conn->query($add_deleted_by)) {
            echo "✓ deleted_by_user_id 컬럼이 추가되었습니다.<br>";
        } else {
            echo "✗ deleted_by_user_id 컬럼 추가 실패: " . $conn->error . "<br>";
        }
    } else {
        echo "✓ deleted_by_user_id 컬럼이 이미 존재합니다.<br>";
    }
    
    // 3. 외래키 제약조건 추가 (선택사항)
    echo "<h3>3. 외래키 제약조건 추가:</h3>";
    try {
        $add_fk = "ALTER TABLE purchases ADD CONSTRAINT fk_purchases_deleted_by FOREIGN KEY (deleted_by_user_id) REFERENCES users(id)";
        if ($conn->query($add_fk)) {
            echo "✓ deleted_by_user_id 외래키 제약조건이 추가되었습니다.<br>";
        } else {
            echo "ℹ 외래키 제약조건이 이미 존재하거나 추가할 수 없습니다.<br>";
        }
    } catch (Exception $e) {
        echo "ℹ 외래키 제약조건 추가 건너뜀: " . $e->getMessage() . "<br>";
    }
    
    // 4. 최종 테이블 구조 확인
    echo "<h3>4. 최종 purchases 테이블 구조:</h3>";
    $result = $conn->query("DESCRIBE purchases");
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<br><h3>🎉 Soft Delete 설정 완료!</h3>";
    echo "이제 매입 내역을 삭제해도 실제로는 deleted_at 컬럼에 삭제 시간이 기록되며, 나중에 복원할 수 있습니다.<br>";
    echo '<a href="purchase_management.php">매입 관리로 돌아가기</a><br>';
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
th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
th { background-color: #f2f2f2; }
</style>