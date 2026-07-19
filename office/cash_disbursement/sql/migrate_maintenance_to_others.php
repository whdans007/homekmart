<?php
/**
 * Cash Disbursement "4. MAINTENANCE" 섹션 폐지 마이그레이션
 *
 * 배경: MAINTENANCE 섹션을 더 이상 사용하지 않기로 하면서 OTHERS가 "5. OTHERS" →
 *       "4. OTHERS"로 재번호되었다. cd_supplier_section_map에 이미 저장된 거래처 →
 *       섹션 매핑 중 section='maintenance'인 행은 화면에서 더 이상 자동 배치될 곳이
 *       없으므로 'others'로 옮겨준다. (cd_saved_state의 과거 JSON 데이터는 화면 로드
 *       시 index.php의 ensureAllSections()가 자동으로 maintenance→others 병합 처리함)
 *
 * 접속: http://main.homekmart.net/office/cash_disbursement/sql/migrate_maintenance_to_others.php
 * 주의: 실행 후 이 파일은 반드시 삭제하세요! (1회성 작업이며, 재실행 시 이미 옮겨진
 *       상태이므로 안전하게 스킵됩니다)
 */
require_once __DIR__ . '/../../../config/db_config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];
function step(array &$steps, string $status, string $msg) { $steps[] = [$status, $msg]; }

$chk = $conn->query("SHOW TABLES LIKE 'cd_supplier_section_map'");
if (!$chk || $chk->num_rows === 0) {
    step($steps, 'OK', 'cd_supplier_section_map 테이블이 없습니다. 옮길 데이터가 없어 종료합니다.');
} else {
    $before = $conn->query("SELECT COUNT(*) cnt FROM cd_supplier_section_map WHERE section='maintenance'")->fetch_assoc();
    $count  = (int)$before['cnt'];

    if ($count === 0) {
        step($steps, 'OK', "section='maintenance'인 행이 없습니다. 옮길 데이터가 없어 종료합니다.");
    } else {
        step($steps, 'INFO', "section='maintenance' {$count}건 발견. 'others'로 이전합니다.");

        $rows = $conn->query("SELECT store_id, supplier_name FROM cd_supplier_section_map WHERE section='maintenance'")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            step($steps, 'INFO', "  store_id={$r['store_id']} supplier=\"{$r['supplier_name']}\"");
        }

        // 이미 같은 (store_id, supplier_name)으로 'others' 매핑이 있으면 UNIQUE KEY 충돌 →
        // maintenance 쪽 중복 행은 삭제, 없으면 UPDATE로 옮김.
        $conn->query(
            "DELETE m FROM cd_supplier_section_map m
             JOIN cd_supplier_section_map o
               ON o.store_id = m.store_id AND o.supplier_name = m.supplier_name AND o.section = 'others'
             WHERE m.section = 'maintenance'"
        );
        $conn->query("UPDATE cd_supplier_section_map SET section='others', updated_at=NOW() WHERE section='maintenance'");

        $after = $conn->query("SELECT COUNT(*) cnt FROM cd_supplier_section_map WHERE section='maintenance'")->fetch_assoc();
        if ((int)$after['cnt'] === 0) {
            step($steps, 'OK', "이전 완료. section='maintenance' 잔여 건수: 0");
        } else {
            step($steps, 'ERROR', "이전 후에도 {$after['cnt']}건이 남아있습니다. 확인이 필요합니다.");
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>MAINTENANCE 섹션 폐지 마이그레이션</title>
<style>body{font-family:monospace;padding:2em;white-space:pre-wrap;} .ok{color:green;} .error{color:red;font-weight:bold;} .info{color:#0066cc;}</style>
</head>
<body>
<h2>Cash Disbursement — MAINTENANCE → OTHERS 이전 결과</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일은 삭제하세요!</strong></p>
</body>
</html>
