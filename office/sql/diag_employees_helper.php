<?php
/**
 * office_helper.php 배포 상태 진단 스크립트 (일회성)
 * employees.php에서 흰 화면이 뜨는 원인(office_helper.php 미배포/함수 누락/컬럼-테이블 누락)을
 * 브라우저에서 바로 확인하기 위한 도구.
 * 사용법: office/sql/diag_employees_helper.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>office_helper.php 진단</title>";
echo "<style>body{font-family:'Malgun Gothic',sans-serif;margin:20px;} .y{color:#16a34a;font-weight:bold;} .n{color:#dc2626;font-weight:bold;} pre{background:#f3f4f6;padding:10px;border-radius:6px;white-space:pre-wrap;}</style>";
echo "</head><body>";
echo "<h1>office_helper.php 배포 상태 진단</h1>";

echo "<h2>1. require_once 자체가 되는가?</h2>";
try {
    require_once __DIR__ . '/../lib/office_helper.php';
    echo "<p class='y'>OK — office_helper.php를 정상적으로 불러왔습니다.</p>";
} catch (Throwable $e) {
    echo "<p class='n'>실패 — office_helper.php를 불러오는 중 오류가 발생했습니다:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . "</pre>";
    echo "</body></html>";
    exit;
}

echo "<h2>2. 신규 함수들이 정의되어 있는가?</h2><ul>";
$funcs = [
    'get_employee_records_by_store',
    'add_employee_record',
    'update_employee_record',
    'delete_employee_record',
    'get_store_representative_name',
    'generate_employee_no',
    'get_main_office_store_summaries',
];
foreach ($funcs as $fn) {
    $ok = function_exists($fn);
    echo "<li>{$fn}: <span class='" . ($ok ? 'y' : 'n') . "'>" . ($ok ? '있음' : '없음 (구버전 파일)') . "</span></li>";
}
echo "</ul>";

echo "<h2>3. DB 컬럼/테이블 마이그레이션이 실제로 적용됐는가?</h2><ul>";
try {
    $conn = get_db_connection();

    $col = $conn->query("SHOW COLUMNS FROM office_employees LIKE 'employee_no'");
    $has_col = $col && $col->num_rows > 0;
    echo "<li>office_employees.employee_no 컬럼: <span class='" . ($has_col ? 'y' : 'n') . "'>" . ($has_col ? '있음' : '없음') . "</span></li>";

    $tbl = $conn->query("SHOW TABLES LIKE 'office_employee_records'");
    $has_tbl = $tbl && $tbl->num_rows > 0;
    echo "<li>office_employee_records 테이블: <span class='" . ($has_tbl ? 'y' : 'n') . "'>" . ($has_tbl ? '있음' : '없음') . "</span></li>";

    $rep = $conn->query("SHOW COLUMNS FROM stores LIKE 'representative_user_id'");
    $has_rep = $rep && $rep->num_rows > 0;
    echo "<li>stores.representative_user_id 컬럼: <span class='" . ($has_rep ? 'y' : 'n') . "'>" . ($has_rep ? '있음' : '없음 (별도 마이그레이션 스크립트 실행 필요)') . "</span></li>";

    $conn->close();
} catch (Throwable $e) {
    echo "<li class='n'>DB 확인 중 오류: " . htmlspecialchars($e->getMessage()) . "</li>";
}
echo "</ul>";

echo "<h2>4. get_employee_records_by_store() 실제 호출 테스트</h2>";
if (function_exists('get_employee_records_by_store')) {
    try {
        $test = get_employee_records_by_store((int)($_SESSION['store_id'] ?? 0));
        echo "<p class='y'>OK — 정상 호출됨 (결과 " . count($test) . "건)</p>";
    } catch (Throwable $e) {
        echo "<p class='n'>호출 중 오류 발생:</p>";
        echo "<pre>" . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . "</pre>";
    }
} else {
    echo "<p class='n'>함수 자체가 없어 테스트 불가</p>";
}

echo "<p style='color:#6b7280;font-size:12px;margin-top:30px;'><strong>중요:</strong> 확인 후 이 파일을 삭제하세요.</p>";
echo "</body></html>";
