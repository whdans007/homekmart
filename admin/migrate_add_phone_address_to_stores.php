<?php
/**
 * stores 테이블에 phone(전화번호), address(주소) 컬럼 추가 마이그레이션
 * 실행일: 2026-07-15
 *
 * - stores.phone   : 지점 전화번호 (신규)
 * - stores.address : 지점 주소 (신규)
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
    echo "<h2>stores 테이블 phone/address 컬럼 추가 마이그레이션</h2>";
    echo "<pre>";

    // 1. 현재 테이블 구조 확인
    echo "\n[1단계] 현재 stores 테이블 구조 확인...\n";
    $columns = $conn->query("SHOW COLUMNS FROM stores");
    $has_phone = false;
    $has_address = false;
    echo "stores 테이블 컬럼 목록:\n";
    while ($col = $columns->fetch_assoc()) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
        if ($col['Field'] === 'phone') {
            $has_phone = true;
        }
        if ($col['Field'] === 'address') {
            $has_address = true;
        }
    }

    // 2. phone 컬럼 추가
    echo "\n[2단계] phone 컬럼 확인 및 추가...\n";
    if ($has_phone) {
        echo "⚠️  phone 컬럼이 이미 존재합니다. 추가 작업을 건너뜁니다.\n";
    } else {
        $alter_sql = "ALTER TABLE stores
                      ADD COLUMN phone VARCHAR(50) NULL COMMENT '지점 전화번호'
                      AFTER company_name";
        if ($conn->query($alter_sql)) {
            echo "✓ phone 컬럼 추가 완료\n";
        } else {
            throw new Exception("phone 컬럼 추가 실패: " . $conn->error);
        }
    }

    // 3. address 컬럼 추가
    echo "\n[3단계] address 컬럼 확인 및 추가...\n";
    if ($has_address) {
        echo "⚠️  address 컬럼이 이미 존재합니다. 추가 작업을 건너뜁니다.\n";
    } else {
        $alter_sql = "ALTER TABLE stores
                      ADD COLUMN address VARCHAR(255) NULL COMMENT '지점 주소'
                      AFTER phone";
        if ($conn->query($alter_sql)) {
            echo "✓ address 컬럼 추가 완료\n";
        } else {
            throw new Exception("address 컬럼 추가 실패: " . $conn->error);
        }
    }

    // 4. 결과 확인
    echo "\n[4단계] 마이그레이션 결과 확인...\n";
    $result = $conn->query("SELECT id, name, company_name, phone, address FROM stores ORDER BY id DESC LIMIT 10");
    echo "\n지점 목록 (최대 10건):\n";
    echo str_pad("ID", 6) . str_pad("지점명", 22) . str_pad("전화번호", 18) . "주소\n";
    echo str_repeat("-", 80) . "\n";
    while ($row = $result->fetch_assoc()) {
        echo str_pad($row['id'], 6) .
             str_pad($row['name'] ?? '', 22) .
             str_pad($row['phone'] ?? '(미입력)', 18) .
             ($row['address'] ?? '(미입력)') . "\n";
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

echo "<br><br><a href='store_management.php'>지점 관리로 이동</a>";
?>
