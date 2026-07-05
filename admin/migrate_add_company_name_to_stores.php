<?php
/**
 * stores 테이블에 company_name(실제 회사명/상호) 컬럼 추가 마이그레이션
 * 실행일: 2026-07-05
 *
 * - stores.name       : 지점명 (표시용)
 * - stores.company_name : 실제 회사명 / 사업자등록상 상호 (신규)
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
    echo "<h2>stores 테이블 company_name 컬럼 추가 마이그레이션</h2>";
    echo "<pre>";

    // 1. 현재 테이블 구조 확인
    echo "\n[1단계] 현재 stores 테이블 구조 확인...\n";
    $columns = $conn->query("SHOW COLUMNS FROM stores");
    $has_company_name = false;
    echo "stores 테이블 컬럼 목록:\n";
    while ($col = $columns->fetch_assoc()) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
        if ($col['Field'] === 'company_name') {
            $has_company_name = true;
        }
    }

    if ($has_company_name) {
        echo "\n⚠️  company_name 컬럼이 이미 존재합니다. 추가 작업을 건너뜁니다.\n";
    } else {
        // 2. company_name 컬럼 추가 (name 컬럼 뒤에 위치)
        echo "\n[2단계] company_name 컬럼 추가 중...\n";
        $alter_sql = "ALTER TABLE stores
                      ADD COLUMN company_name VARCHAR(255) NULL COMMENT '실제 회사명(상호)'
                      AFTER name";

        if ($conn->query($alter_sql)) {
            echo "✓ company_name 컬럼 추가 완료\n";
        } else {
            throw new Exception("company_name 컬럼 추가 실패: " . $conn->error);
        }
    }

    // 3. 결과 확인
    echo "\n[3단계] 마이그레이션 결과 확인...\n";
    $result = $conn->query("SELECT id, name, company_name, created_at FROM stores ORDER BY id DESC LIMIT 10");
    echo "\n지점 목록 (최대 10건):\n";
    echo str_pad("ID", 8) . str_pad("지점명", 24) . str_pad("회사명", 24) . "등록일\n";
    echo str_repeat("-", 70) . "\n";
    while ($row = $result->fetch_assoc()) {
        echo str_pad($row['id'], 8) .
             str_pad($row['name'] ?? '', 24) .
             str_pad($row['company_name'] ?? '(미입력)', 24) .
             ($row['created_at'] ?? '') . "\n";
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
