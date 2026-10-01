<?php
// ADONAI BUKO PIE AND COCONUT DEALER 공급처명 오타 보정 (1회 실행 후 삭제 권장)
// 사용법(CLI): php office/run_fix_adonai_supplier_typo.php          → 미리보기(dry-run, 변경 없음)
//              php office/run_fix_adonai_supplier_typo.php --apply  → 실제 반영 (트랜잭션)
// 접속 대상은 config/db_config.php 설정을 따른다 (로컬 실행 시 로컬 DB, 운영 서버 실행 시 운영 DB).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../config/db_config.php';

$apply   = in_array('--apply', $argv, true);
$correct = 'ADONAI BUKO PIE AND COCONUT DEALER';

// [영수증 id => 현재 오타명] — 일치할 때만 변경
$receipt_fixes = [
    123  => 'ADONAI BUKO PIE AND COCONUT REALER',
    236  => 'ADONAI BUKO PIE AND COCONUT REALER',
    301  => 'ADONAI BUKO PIE AND COCONUT REALER',
    1175 => 'ADONIA BUKO PIE AND COCONUT DEALER',
    2013 => 'ADONIA BUKO PIE AND COCONUT DEALER',
];
$typo_supplier_id   = 104;
$typo_supplier_name = 'ADONIA BUKO PIE AND COCONUT DEALER';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = get_db_connection();
$conn->set_charset('utf8mb4');
echo "DB: " . DB_HOST . "/" . DB_NAME . "\n";
echo $apply ? "[APPLY 모드]\n" : "[DRY-RUN 모드 — 변경 없음, 반영하려면 --apply]\n";

$conn->autocommit(false);
$changed = 0;

// 1) office_receipts 공급처명 보정
$sel = $conn->prepare("SELECT supplier_name FROM office_receipts WHERE id = ?");
$upd = $conn->prepare("UPDATE office_receipts SET supplier_name = ? WHERE id = ? AND supplier_name = ?");
foreach ($receipt_fixes as $id => $typo) {
    $sel->bind_param('i', $id);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    if (!$row) { echo "receipt #{$id}: 없음 (건너뜀)\n"; continue; }
    if ($row['supplier_name'] !== $typo) { echo "receipt #{$id}: 현재 [{$row['supplier_name']}] — 예상값과 달라 건너뜀\n"; continue; }
    echo "receipt #{$id}: [{$typo}] → [{$correct}]\n";
    if ($apply) {
        $upd->bind_param('sis', $correct, $id, $typo);
        $upd->execute();
        $changed += $upd->affected_rows;
    }
}
$sel->close();
$upd->close();

// 2) suppliers 오타 항목(id 104) 삭제 — 다른 테이블에서 supplier_id로 참조 중이면 삭제하지 않음
$chk = $conn->prepare("SELECT id FROM suppliers WHERE id = ? AND name = ?");
$chk->bind_param('is', $typo_supplier_id, $typo_supplier_name);
$chk->execute();
$exists = $chk->get_result()->num_rows > 0;
$chk->close();

if (!$exists) {
    echo "suppliers #{$typo_supplier_id}: 없음 또는 이름 불일치 (건너뜀)\n";
} else {
    $refs = 0;
    $cols = $conn->prepare(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'supplier_id' AND TABLE_NAME <> 'suppliers'"
    );
    $cols->execute();
    foreach ($cols->get_result()->fetch_all(MYSQLI_ASSOC) as $t) {
        $tbl = str_replace('`', '', $t['TABLE_NAME']);
        $r = $conn->prepare("SELECT COUNT(*) c FROM `{$tbl}` WHERE supplier_id = ?");
        $r->bind_param('i', $typo_supplier_id);
        $r->execute();
        $c = (int)$r->get_result()->fetch_assoc()['c'];
        $r->close();
        if ($c > 0) { echo "  참조: {$tbl} {$c}건\n"; $refs += $c; }
    }
    $cols->close();

    if ($refs > 0) {
        echo "suppliers #{$typo_supplier_id}: 참조 {$refs}건이 있어 삭제하지 않음 (수동 병합 필요)\n";
    } else {
        echo "suppliers #{$typo_supplier_id}: 참조 없음 → 삭제 대상 [{$typo_supplier_name}]\n";
        if ($apply) {
            $del = $conn->prepare("DELETE FROM suppliers WHERE id = ? AND name = ?");
            $del->bind_param('is', $typo_supplier_id, $typo_supplier_name);
            $del->execute();
            $changed += $del->affected_rows;
            $del->close();
        }
    }
}

if ($apply) {
    $conn->commit();
    echo "완료: 변경 {$changed}건\n";
} else {
    $conn->rollback();
}
$conn->close();
