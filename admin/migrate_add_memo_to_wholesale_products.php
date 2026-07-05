<?php
/**
 * wholesale_products 테이블에 memo(특이사항) 컬럼 추가 마이그레이션
 * 실행일: 2026-06-24
 */

require_once __DIR__ . '/../config/db_config.php';

// 에러 표시 활성화
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Wholesale Products 메모 컬럼 마이그레이션</title></head><body>";
echo "<h1>wholesale_products 테이블 memo 컬럼 추가 마이그레이션</h1>";
echo "<pre>";

$conn = get_db_connection();

if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

try {
    echo "[1/2] memo 컬럼 존재 여부 확인 중...\n";
    $check_column = $conn->query("SHOW COLUMNS FROM wholesale_products LIKE 'memo'");

    if ($check_column->num_rows > 0) {
        echo "✓ memo 컬럼이 이미 존재합니다. 마이그레이션을 건너뜁니다.\n";
        echo "</pre></body></html>";
        $conn->close();
        exit;
    }

    echo "✓ memo 컬럼이 존재하지 않습니다. 마이그레이션을 진행합니다.\n\n";

    echo "[2/2] memo 컬럼 추가 중...\n";
    $sql = "ALTER TABLE `wholesale_products`
            ADD COLUMN `memo` TEXT DEFAULT NULL COMMENT '도매 상품 메모(특이사항)' AFTER `wholesale_description`";

    if ($conn->query($sql)) {
        echo "✓ memo 컬럼이 추가되었습니다.\n\n";
    } else {
        throw new Exception("컬럼 추가 실패: " . $conn->error);
    }

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    echo "변경된 테이블 구조:\n";
    $result = $conn->query("SHOW COLUMNS FROM wholesale_products");
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
?>
