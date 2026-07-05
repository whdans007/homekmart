<?php
/**
 * 거래처(업체)별 도매가 예외 테이블(wholesale_customer_prices) 생성 마이그레이션
 * 실행일: 2026-06-26
 * 실행 후 이 파일은 삭제/이름변경 권장.
 */

require_once __DIR__ . '/../config/db_config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>거래처별 도매가 예외 테이블 마이그레이션</title></head><body>";
echo "<h1>wholesale_customer_prices 테이블 생성</h1><pre>";

$conn = get_db_connection();
if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

try {
    echo "[1/2] 테이블 존재 여부 확인 중...\n";
    $check = $conn->query("SHOW TABLES LIKE 'wholesale_customer_prices'");
    if ($check && $check->num_rows > 0) {
        echo "✓ wholesale_customer_prices 테이블이 이미 존재합니다. 건너뜁니다.\n";
        echo "</pre></body></html>";
        $conn->close();
        exit;
    }
    echo "✓ 테이블이 없습니다. 생성을 진행합니다.\n\n";

    echo "[2/2] 테이블 생성 중...\n";
    $sql = file_get_contents(__DIR__ . '/sql/create_wholesale_customer_prices.sql');
    if ($sql === false) {
        throw new Exception("SQL 파일을 읽을 수 없습니다: sql/create_wholesale_customer_prices.sql");
    }
    if ($conn->query($sql)) {
        echo "✓ wholesale_customer_prices 테이블이 생성되었습니다.\n\n";
    } else {
        throw new Exception("테이블 생성 실패: " . $conn->error);
    }

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    echo "생성된 테이블 구조:\n";
    $result = $conn->query("SHOW COLUMNS FROM wholesale_customer_prices");
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['Field']}: {$row['Type']} " .
             ($row['Null'] === 'NO' ? 'NOT NULL' : 'NULL') .
             ($row['Key'] ? " [{$row['Key']}]" : '') . "\n";
    }
} catch (Exception $e) {
    echo "\n❌ 오류 발생: " . $e->getMessage() . "\n";
}

$conn->close();
echo "</pre>";
echo "<p><strong>중요:</strong> 마이그레이션 완료 후 이 파일을 삭제하거나 이름을 변경하세요.</p>";
echo "</body></html>";
