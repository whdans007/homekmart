<?php
/**
 * wholesale_customers 테이블에 discount_rate(할인율) 컬럼 추가 마이그레이션
 * 실행일: 2026-07-15
 */

require_once __DIR__ . '/../config/db_config.php';

// 에러 표시 활성화
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Wholesale Customers 마이그레이션</title></head><body>";
echo "<h1>wholesale_customers 테이블 discount_rate 추가 마이그레이션</h1>";
echo "<pre>";

$conn = get_db_connection();

if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

try {
    $conn->autocommit(false);

    echo "[1/2] discount_rate 컬럼 존재 여부 확인 중...\n";
    $check_column = $conn->query("SHOW COLUMNS FROM wholesale_customers LIKE 'discount_rate'");

    if ($check_column->num_rows > 0) {
        echo "✓ discount_rate 컬럼이 이미 존재합니다. 마이그레이션을 건너뜁니다.\n";
        $conn->rollback();
        echo "</pre></body></html>";
        exit;
    }

    echo "✓ discount_rate 컬럼이 존재하지 않습니다. 마이그레이션을 진행합니다.\n\n";

    echo "[2/2] discount_rate 컬럼 추가 중 (기본값 15.00%)...\n";
    $sql = "ALTER TABLE `wholesale_customers`
            ADD COLUMN `discount_rate` DECIMAL(5,2) NOT NULL DEFAULT 15.00 COMMENT '거래처 할인율(%)' AFTER `memo`";

    if ($conn->query($sql)) {
        echo "✓ discount_rate 컬럼이 추가되었습니다.\n\n";
    } else {
        throw new Exception("컬럼 추가 실패: " . $conn->error);
    }

    $conn->commit();

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    echo "변경된 테이블 구조:\n";
    $result = $conn->query("SHOW COLUMNS FROM wholesale_customers");
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['Field']}: {$row['Type']} " .
             ($row['Null'] === 'NO' ? 'NOT NULL' : 'NULL') .
             ($row['Key'] ? " [{$row['Key']}]" : '') . "\n";
    }

} catch (Exception $e) {
    $conn->rollback();
    echo "\n❌ 오류 발생: " . $e->getMessage() . "\n";
    echo "모든 변경사항이 롤백되었습니다.\n";
}

$conn->autocommit(true);
$conn->close();

echo "</pre>";
echo "<p><strong>중요:</strong> 마이그레이션 완료 후 이 파일을 삭제하거나 이름을 변경하세요.</p>";
echo "</body></html>";
?>
