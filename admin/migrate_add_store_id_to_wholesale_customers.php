<?php
/**
 * wholesale_customers 테이블에 store_id 컬럼 추가 마이그레이션
 * 실행일: 2025-10-26
 */

require_once __DIR__ . '/../config/db_config.php';

// 에러 표시 활성화
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Wholesale Customers 마이그레이션</title></head><body>";
echo "<h1>wholesale_customers 테이블 store_id 추가 마이그레이션</h1>";
echo "<pre>";

$conn = get_db_connection();

if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

try {
    // 트랜잭션 시작
    $conn->autocommit(false);

    echo "[1/6] store_id 컬럼 존재 여부 확인 중...\n";
    $check_column = $conn->query("SHOW COLUMNS FROM wholesale_customers LIKE 'store_id'");

    if ($check_column->num_rows > 0) {
        echo "✓ store_id 컬럼이 이미 존재합니다. 마이그레이션을 건너뜁니다.\n";
        $conn->rollback();
        echo "</pre></body></html>";
        exit;
    }

    echo "✓ store_id 컬럼이 존재하지 않습니다. 마이그레이션을 진행합니다.\n\n";

    // 1. store_id 컬럼 추가
    echo "[2/6] store_id 컬럼 추가 중...\n";
    $sql1 = "ALTER TABLE `wholesale_customers`
             ADD COLUMN `store_id` INT UNSIGNED NULL COMMENT '점포 ID' AFTER `id`";

    if ($conn->query($sql1)) {
        echo "✓ store_id 컬럼이 추가되었습니다.\n\n";
    } else {
        throw new Exception("컬럼 추가 실패: " . $conn->error);
    }

    // 2. 기존 데이터에 기본 점포 할당
    echo "[3/6] 기존 거래처에 기본 점포(store_id = 1) 할당 중...\n";
    $sql2 = "UPDATE `wholesale_customers`
             SET `store_id` = 1
             WHERE `store_id` IS NULL";

    if ($conn->query($sql2)) {
        $affected = $conn->affected_rows;
        echo "✓ {$affected}개 거래처에 기본 점포가 할당되었습니다.\n\n";
    } else {
        throw new Exception("기본값 설정 실패: " . $conn->error);
    }

    // 3. store_id를 NOT NULL로 변경
    echo "[4/6] store_id를 NOT NULL로 변경 중...\n";
    $sql3 = "ALTER TABLE `wholesale_customers`
             MODIFY COLUMN `store_id` INT UNSIGNED NOT NULL COMMENT '점포 ID'";

    if ($conn->query($sql3)) {
        echo "✓ store_id가 NOT NULL로 변경되었습니다.\n\n";
    } else {
        throw new Exception("NOT NULL 변경 실패: " . $conn->error);
    }

    // 4. 외래키 제약 조건 추가
    echo "[5/6] stores 테이블과 외래키 제약 조건 추가 중...\n";
    $sql4 = "ALTER TABLE `wholesale_customers`
             ADD CONSTRAINT `fk_wholesale_customers_store_id`
             FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`)
             ON DELETE RESTRICT
             ON UPDATE CASCADE";

    if ($conn->query($sql4)) {
        echo "✓ 외래키 제약 조건이 추가되었습니다.\n\n";
    } else {
        throw new Exception("외래키 추가 실패: " . $conn->error);
    }

    // 5. 인덱스 추가
    echo "[6/6] 인덱스 추가 중...\n";
    $sql5 = "ALTER TABLE `wholesale_customers`
             ADD INDEX `idx_store_id` (`store_id`),
             ADD INDEX `idx_store_active` (`store_id`, `is_active`)";

    if ($conn->query($sql5)) {
        echo "✓ 인덱스가 추가되었습니다.\n\n";
    } else {
        throw new Exception("인덱스 추가 실패: " . $conn->error);
    }

    // 커밋
    $conn->commit();

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    // 결과 확인
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
