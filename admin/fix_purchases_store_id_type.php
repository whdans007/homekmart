<?php
/**
 * purchases 테이블 store_id 컬럼 타입 수정 및 외래키 추가
 * 실행일: 2025-10-25
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// 관리자만 실행 가능
ensure_logged_in();
if ($_SESSION['role'] !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();
$conn->autocommit(false);

try {
    echo "<h2>purchases 테이블 store_id 컬럼 타입 수정</h2>";
    echo "<pre>";

    // 1. 현재 상태 확인
    echo "\n[1단계] 현재 컬럼 타입 확인...\n";
    $result = $conn->query("SHOW COLUMNS FROM purchases LIKE 'store_id'");
    if ($row = $result->fetch_assoc()) {
        echo "현재 purchases.store_id 타입: {$row['Type']}\n";
    }

    $result = $conn->query("SHOW COLUMNS FROM stores LIKE 'id'");
    if ($row = $result->fetch_assoc()) {
        echo "현재 stores.id 타입: {$row['Type']}\n";
    }

    // 2. NULL 값 확인
    echo "\n[2단계] NULL 값 확인 및 처리...\n";
    $null_check = $conn->query("SELECT COUNT(*) as cnt FROM purchases WHERE store_id IS NULL");
    $null_row = $null_check->fetch_assoc();
    if ($null_row['cnt'] > 0) {
        echo "⚠️  NULL 값이 {$null_row['cnt']}건 존재합니다. 기본값(1)으로 설정합니다.\n";
        $conn->query("UPDATE purchases SET store_id = 1 WHERE store_id IS NULL");
        echo "✓ NULL 값 처리 완료\n";
    } else {
        echo "✓ NULL 값 없음\n";
    }

    // 3. 컬럼 타입 변경
    echo "\n[3단계] store_id 컬럼 타입을 INT UNSIGNED로 변경 중...\n";
    $modify_sql = "ALTER TABLE purchases
                   MODIFY COLUMN store_id INT(11) UNSIGNED NOT NULL DEFAULT 1 COMMENT '점포 ID'";

    if ($conn->query($modify_sql)) {
        echo "✓ 컬럼 타입 변경 완료\n";
    } else {
        throw new Exception("컬럼 타입 변경 실패: " . $conn->error);
    }

    // 4. 기존 외래키 제거 (있는 경우)
    echo "\n[4단계] 기존 외래키 확인 및 제거...\n";
    $fk_check = $conn->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'purchases'
        AND COLUMN_NAME = 'store_id'
        AND CONSTRAINT_NAME != 'PRIMARY'
    ");

    if ($fk_check->num_rows > 0) {
        while ($fk_row = $fk_check->fetch_assoc()) {
            $constraint_name = $fk_row['CONSTRAINT_NAME'];
            echo "기존 외래키 발견: {$constraint_name}\n";
            $drop_fk_sql = "ALTER TABLE purchases DROP FOREIGN KEY {$constraint_name}";
            if ($conn->query($drop_fk_sql)) {
                echo "✓ 기존 외래키 제거 완료\n";
            }
        }
    } else {
        echo "✓ 기존 외래키 없음\n";
    }

    // 5. 새 외래키 추가
    echo "\n[5단계] 외래키 제약조건 추가 중...\n";
    $fk_sql = "ALTER TABLE purchases
               ADD CONSTRAINT fk_purchases_store_id
               FOREIGN KEY (store_id) REFERENCES stores(id)
               ON DELETE RESTRICT
               ON UPDATE CASCADE";

    if ($conn->query($fk_sql)) {
        echo "✓ 외래키 제약조건 추가 완료\n";
    } else {
        throw new Exception("외래키 추가 실패: " . $conn->error);
    }

    // 6. 인덱스 확인 및 추가
    echo "\n[6단계] 인덱스 확인 및 추가...\n";
    $idx_check = $conn->query("SHOW INDEX FROM purchases WHERE Column_name = 'store_id' AND Key_name != 'fk_purchases_store_id'");

    if ($idx_check->num_rows == 0) {
        $idx_sql = "CREATE INDEX idx_purchases_store_id ON purchases(store_id)";
        if ($conn->query($idx_sql)) {
            echo "✓ 인덱스 추가 완료\n";
        } else {
            echo "⚠️  인덱스 추가 실패 (계속 진행): " . $conn->error . "\n";
        }
    } else {
        echo "✓ 인덱스가 이미 존재합니다\n";
    }

    // 7. 결과 확인
    echo "\n[7단계] 최종 확인...\n";
    $result = $conn->query("SHOW COLUMNS FROM purchases LIKE 'store_id'");
    if ($row = $result->fetch_assoc()) {
        echo "변경된 purchases.store_id 타입: {$row['Type']}\n";
        echo "NULL 허용: {$row['Null']}\n";
        echo "기본값: {$row['Default']}\n";
    }

    // 외래키 확인
    $fk_check = $conn->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'purchases'
        AND COLUMN_NAME = 'store_id'
    ");

    echo "\n설정된 외래키:\n";
    while ($fk_row = $fk_check->fetch_assoc()) {
        echo "  - {$fk_row['CONSTRAINT_NAME']}\n";
    }

    $conn->commit();
    echo "\n✅ 수정 완료!\n";
    echo "</pre>";

} catch (Exception $e) {
    $conn->rollback();
    echo "\n❌ 오류 발생: " . $e->getMessage() . "\n";
    echo "트랜잭션 롤백됨\n";
    echo "</pre>";
}

$conn->close();

echo "<br><br><a href='purchase_management.php'>매입 관리로 이동</a>";
?>
