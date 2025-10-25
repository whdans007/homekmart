<?php
/**
 * purchases 테이블에 store_id 컬럼 추가 마이그레이션
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
    echo "<h2>purchases 테이블 store_id 컬럼 추가 마이그레이션</h2>";
    echo "<pre>";

    // 1. 현재 테이블 구조 확인
    echo "\n[1단계] 현재 purchases 및 stores 테이블 구조 확인...\n";

    // purchases 테이블 확인
    $columns = $conn->query("SHOW COLUMNS FROM purchases");
    $has_store_id = false;
    echo "purchases 테이블 컬럼 목록:\n";
    while ($col = $columns->fetch_assoc()) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
        if ($col['Field'] === 'store_id') {
            $has_store_id = true;
        }
    }

    // stores 테이블의 id 컬럼 타입 확인
    echo "\nstores 테이블 구조:\n";
    $store_columns = $conn->query("SHOW COLUMNS FROM stores");
    $store_id_type = null;
    while ($col = $store_columns->fetch_assoc()) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
        if ($col['Field'] === 'id') {
            $store_id_type = $col['Type'];
        }
    }
    echo "\nstores.id 타입: {$store_id_type}\n";

    if ($has_store_id) {
        echo "\n⚠️  store_id 컬럼이 이미 존재합니다.\n";
    } else {
        // 2. store_id 컬럼 추가
        echo "\n[2단계] store_id 컬럼 추가 중...\n";

        // purchase_id 컬럼 확인
        $pk_check = $conn->query("SHOW COLUMNS FROM purchases LIKE 'purchase_id'");
        $after_column = $pk_check->num_rows > 0 ? 'purchase_id' : 'id';

        // stores.id 타입과 동일하게 설정 (기본값: INT UNSIGNED)
        $store_id_column_type = "INT UNSIGNED";
        if ($store_id_type && strpos(strtoupper($store_id_type), 'UNSIGNED') !== false) {
            $store_id_column_type = strtoupper($store_id_type);
        }

        $alter_sql = "ALTER TABLE purchases
                      ADD COLUMN store_id {$store_id_column_type} NULL COMMENT '점포 ID'
                      AFTER {$after_column}";

        if ($conn->query($alter_sql)) {
            echo "✓ store_id 컬럼 추가 완료\n";
        } else {
            throw new Exception("store_id 컬럼 추가 실패: " . $conn->error);
        }

        // 3. 기존 데이터에 store_id 설정
        echo "\n[3단계] 기존 매입 데이터에 store_id 설정 중...\n";

        // purchase_items를 통해 재고의 점포 정보 추출
        $update_sql = "
            UPDATE purchases p
            LEFT JOIN (
                SELECT
                    pi.purchase_id,
                    i.store_id,
                    COUNT(DISTINCT i.store_id) as store_count
                FROM purchase_items pi
                INNER JOIN inventory i ON pi.product_id = i.product_id
                GROUP BY pi.purchase_id
            ) pi_stores ON p.purchase_id = pi_stores.purchase_id
            SET p.store_id = CASE
                WHEN pi_stores.store_count = 1 THEN pi_stores.store_id
                ELSE 1
            END
            WHERE p.store_id IS NULL
        ";

        if ($conn->query($update_sql)) {
            $affected = $conn->affected_rows;
            echo "✓ {$affected}건의 매입 데이터에 store_id 설정 완료\n";
        } else {
            throw new Exception("store_id 설정 실패: " . $conn->error);
        }

        // 4. NOT NULL 제약조건 및 DEFAULT 값 설정
        echo "\n[4단계] NOT NULL 제약조건 및 DEFAULT 값 설정 중...\n";
        $modify_sql = "ALTER TABLE purchases
                       MODIFY COLUMN store_id {$store_id_column_type} NOT NULL DEFAULT 1 COMMENT '점포 ID'";

        if ($conn->query($modify_sql)) {
            echo "✓ NOT NULL 제약조건 및 DEFAULT 값 설정 완료\n";
        } else {
            throw new Exception("제약조건 설정 실패: " . $conn->error);
        }

        // 5. 외래키 제약조건 추가
        echo "\n[5단계] 외래키 제약조건 추가 중...\n";

        // 기존 외래키 확인
        $fk_check = $conn->query("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'purchases'
            AND COLUMN_NAME = 'store_id'
            AND CONSTRAINT_NAME != 'PRIMARY'
        ");

        if ($fk_check->num_rows == 0) {
            $fk_sql = "ALTER TABLE purchases
                       ADD CONSTRAINT fk_purchases_store_id
                       FOREIGN KEY (store_id) REFERENCES stores(id)
                       ON DELETE RESTRICT
                       ON UPDATE CASCADE";

            if ($conn->query($fk_sql)) {
                echo "✓ 외래키 제약조건 추가 완료\n";
            } else {
                echo "⚠️  외래키 추가 실패 (계속 진행): " . $conn->error . "\n";
            }
        } else {
            echo "✓ 외래키가 이미 존재합니다\n";
        }

        // 6. 인덱스 추가
        echo "\n[6단계] 인덱스 추가 중...\n";

        // 기존 인덱스 확인
        $idx_check = $conn->query("SHOW INDEX FROM purchases WHERE Column_name = 'store_id'");

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
    }

    // 7. 결과 확인
    echo "\n[7단계] 마이그레이션 결과 확인...\n";
    $check_sql = "
        SELECT
            p.purchase_id,
            p.store_id,
            s.name as store_name,
            p.purchase_date,
            p.total_amount
        FROM purchases p
        LEFT JOIN stores s ON p.store_id = s.id
        WHERE p.deleted_at IS NULL
        ORDER BY p.purchase_id DESC
        LIMIT 10
    ";

    $result = $conn->query($check_sql);
    echo "\n최근 매입 내역 (최대 10건):\n";
    echo str_pad("매입ID", 10) . str_pad("점포ID", 10) . str_pad("점포명", 20) . str_pad("매입일", 15) . "총액\n";
    echo str_repeat("-", 70) . "\n";

    while ($row = $result->fetch_assoc()) {
        echo str_pad($row['purchase_id'], 10) .
             str_pad($row['store_id'] ?? 'NULL', 10) .
             str_pad($row['store_name'] ?? '없음', 20) .
             str_pad($row['purchase_date'], 15) .
             number_format($row['total_amount'], 2) . "\n";
    }

    $conn->commit();
    echo "\n✅ 마이그레이션 완료!\n";
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
