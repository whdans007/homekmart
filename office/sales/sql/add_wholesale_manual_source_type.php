<?php
/**
 * 마이그레이션: sales_pos_wholesale_pick.source_type 에 'wholesale_manual' 추가
 *
 * POS 셀 §5(Whole Sale)에서 그날 admin이 등록하지 않은 매출을 거래처 검색으로 직접
 * 입력할 수 있도록 새 source_type 'wholesale_manual' 을 추가한다. 집계 시 'wholesale'과
 * 동일하게 취급되며(§5 소계 · Daybook · 월간 리포트), wholesale_sales 결제상태 동기화
 * 대상에서는 제외된다(실제 wholesale_sales 행이 아니므로).
 *
 * 실행: php office/sales/sql/add_wholesale_manual_source_type.php
 *   또는 브라우저에서 이 파일 접근.
 * 멱등(idempotent): 이미 'wholesale_manual' 이 있으면 변경하지 않는다.
 */

require_once __DIR__ . '/../../../config/db_config.php';

$is_cli = (php_sapi_name() === 'cli');
$nl = $is_cli ? "\n" : "<br>\n";
if (!$is_cli) { header('Content-Type: text/plain; charset=utf-8'); }

echo "sales_pos_wholesale_pick.source_type 에 'wholesale_manual' 추가 중...{$nl}";

try {
    $conn = get_db_connection();

    // 현재 enum 정의 확인
    $res = $conn->query("SHOW COLUMNS FROM sales_pos_wholesale_pick LIKE 'source_type'");
    $col = $res ? $res->fetch_assoc() : null;
    if (!$col) {
        echo "❌ source_type 컬럼을 찾을 수 없습니다. (테이블 미생성?){$nl}";
        $conn->close();
        exit(1);
    }

    echo "현재 정의: {$col['Type']}{$nl}";

    if (stripos($col['Type'], "'wholesale_manual'") !== false) {
        echo "ℹ️  'wholesale_manual' 값이 이미 존재합니다. 변경 없음.{$nl}";
    } else {
        $sql = "ALTER TABLE `sales_pos_wholesale_pick`
                MODIFY COLUMN `source_type`
                ENUM('wholesale','delivery_k','credit','credit_doc','wholesale_manual') NOT NULL";
        if ($conn->query($sql)) {
            echo "✅ 'wholesale_manual' 값이 성공적으로 추가되었습니다.{$nl}";
        } else {
            echo "❌ ALTER 실패: " . $conn->error . "{$nl}";
        }
    }

    // 변경 후 정의 확인
    $res = $conn->query("SHOW COLUMNS FROM sales_pos_wholesale_pick LIKE 'source_type'");
    $col = $res ? $res->fetch_assoc() : null;
    if ($col) { echo "적용 후 정의: {$col['Type']}{$nl}"; }

    $conn->close();

} catch (Exception $e) {
    echo "❌ 오류 발생: " . $e->getMessage() . "{$nl}";
    exit(1);
}

echo "{$nl}완료!{$nl}";
