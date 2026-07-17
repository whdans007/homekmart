<?php
/**
 * wholesale_sales 테이블에 cost_amount(빠른등록 판매 원가) 컬럼 추가 마이그레이션
 * 실행일: 2026-07-16
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// 관리자만 실행 가능
ensure_logged_in();
if ($_SESSION['role'] !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>wholesale_sales 마이그레이션</title></head><body>";
echo "<h1>wholesale_sales cost_amount 추가 마이그레이션</h1>";
echo "<pre>";

$conn = get_db_connection();

if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

try {
    $conn->autocommit(false);

    echo "[1/2] cost_amount 컬럼 존재 여부 확인 중...\n";
    $check_column = $conn->query("SHOW COLUMNS FROM wholesale_sales LIKE 'cost_amount'");

    if ($check_column->num_rows > 0) {
        echo "✓ cost_amount 컬럼이 이미 존재합니다. 마이그레이션을 건너뜁니다.\n";
        $conn->rollback();
        echo "</pre></body></html>";
        exit;
    }

    echo "✓ cost_amount 컬럼이 존재하지 않습니다. 마이그레이션을 진행합니다.\n\n";

    echo "[2/2] cost_amount 컬럼 추가 중...\n";
    $sql = "ALTER TABLE `wholesale_sales`
            ADD COLUMN `cost_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '빠른등록 판매 원가 (선택 입력)' AFTER `final_amount`";

    if ($conn->query($sql)) {
        echo "✓ cost_amount 컬럼이 추가되었습니다.\n\n";
    } else {
        throw new Exception("컬럼 추가 실패: " . $conn->error);
    }

    $conn->commit();

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    echo "변경된 테이블 구조:\n";
    $result = $conn->query("SHOW COLUMNS FROM wholesale_sales");
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['Field']}: {$row['Type']} " .
             ($row['Null'] === 'NO' ? 'NOT NULL' : 'NULL') .
             ($row['Key'] ? " [{$row['Key']}]" : '') . "\n";
    }

    $conn->autocommit(true);
    $conn->close();

    echo "</pre>";
    echo "<p><strong>중요:</strong> 마이그레이션 완료 후 이 파일을 삭제하거나 이름을 변경하세요.</p>";
    echo "</body></html>";

} catch (Exception $e) {
    $conn->rollback();
    echo "\n❌ 오류 발생: " . $e->getMessage() . "\n";
    echo "모든 변경사항이 롤백되었습니다.\n";
    echo "</pre>";
    $conn->autocommit(true);
    $conn->close();
}
