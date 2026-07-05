<?php
/**
 * wholesale_sales 테이블에 vat_applied / ewt_applied 컬럼 추가 마이그레이션
 * 용도: 도매판매 명세서에서 V.A.T(+12%) / E.W.T(-1%) 적용 여부 저장,
 *       최종금액(final_amount) = TOTAL(total_amount) + VAT - EWT 재계산 지원
 * 실행일: 2026-07-02
 */

require_once __DIR__ . '/../config/db_config.php';

// 에러 표시 활성화
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Wholesale Sales VAT/EWT 컬럼 마이그레이션</title></head><body>";
echo "<h1>wholesale_sales 테이블 V.A.T / E.W.T 컬럼 추가 마이그레이션</h1>";
echo "<pre>";

$conn = get_db_connection();

if (!$conn) {
    die("데이터베이스 연결 실패: " . mysqli_connect_error());
}

try {
    // 추가할 컬럼 정의 (컬럼명 => ALTER 구문)
    $columns = [
        'vat_applied' => "ALTER TABLE `wholesale_sales`
            ADD COLUMN `vat_applied` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'V.A.T +12% 적용 여부 (1=적용, 0=미적용)' AFTER `final_amount`",
        'ewt_applied' => "ALTER TABLE `wholesale_sales`
            ADD COLUMN `ewt_applied` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'E.W.T -1% 적용 여부 (1=적용, 0=미적용)' AFTER `vat_applied`",
    ];

    $total = count($columns);
    $step = 0;

    foreach ($columns as $col => $sql) {
        $step++;
        echo "[{$step}/{$total}] {$col} 컬럼 존재 여부 확인 중...\n";
        $check = $conn->query("SHOW COLUMNS FROM `wholesale_sales` LIKE '{$col}'");

        if ($check->num_rows > 0) {
            echo "✓ {$col} 컬럼이 이미 존재합니다. 건너뜁니다.\n\n";
            continue;
        }

        echo "  → {$col} 컬럼 추가 중...\n";
        if ($conn->query($sql)) {
            echo "✓ {$col} 컬럼이 추가되었습니다.\n\n";
        } else {
            throw new Exception("{$col} 컬럼 추가 실패: " . $conn->error);
        }
    }

    echo "========================================\n";
    echo "✅ 마이그레이션이 성공적으로 완료되었습니다!\n";
    echo "========================================\n\n";

    echo "변경된 테이블 구조:\n";
    $result = $conn->query("SHOW COLUMNS FROM `wholesale_sales`");
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
