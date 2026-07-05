<?php
/**
 * 마이그레이션: sales_pos_wholesale_pick.source_type 에 'credit_doc' 추가
 *
 * POS 셀 §4(Subsidiary Company Credits)에서 admin 외상 거래명세서(credit_transactions)를
 * 선택할 수 있도록 새 source_type 'credit_doc' 를 추가한다.
 *   'credit_doc' = 거래명세서 참조. POS 셀 매출에는 포함하되, 외상거래 현황
 *   (admin/credit_transactions.php)의 POS외상 합계(source_type='credit')에서는 제외되어
 *   거래명세서 금액이 이중 집계되지 않는다.
 *
 * 실행: php office/sales/sql/add_credit_doc_source_type.php
 *   또는 브라우저에서 이 파일 접근.
 * 멱등(idempotent): 이미 'credit_doc' 가 있으면 변경하지 않는다.
 */

require_once __DIR__ . '/../../../config/db_config.php';

$is_cli = (php_sapi_name() === 'cli');
$nl = $is_cli ? "\n" : "<br>\n";
if (!$is_cli) { header('Content-Type: text/plain; charset=utf-8'); }

echo "sales_pos_wholesale_pick.source_type 에 'credit_doc' 추가 중...{$nl}";

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

    if (stripos($col['Type'], "'credit_doc'") !== false) {
        echo "ℹ️  'credit_doc' 값이 이미 존재합니다. 변경 없음.{$nl}";
    } else {
        $sql = "ALTER TABLE `sales_pos_wholesale_pick`
                MODIFY COLUMN `source_type`
                ENUM('wholesale','delivery_k','credit','credit_doc') NOT NULL";
        if ($conn->query($sql)) {
            echo "✅ 'credit_doc' 값이 성공적으로 추가되었습니다.{$nl}";
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
