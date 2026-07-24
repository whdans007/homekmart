<?php
// 임시 진단 스크립트 — 문제 해결 후 삭제할 것.
// OPcache가 daily_report_helper.php의 이전 버전을 캐싱하고 있을 가능성 확인/해소
$opcache_status = 'N/A (opcache 함수 없음)';
if (function_exists('opcache_get_status')) {
    $st = @opcache_get_status(false);
    $opcache_status = $st ? ('enabled=' . var_export($st['opcache_enabled'] ?? null, true)
        . ', validate_timestamps=' . var_export(ini_get('opcache.validate_timestamps'), true)) : 'opcache_get_status() 실패';
}
if (function_exists('opcache_reset')) {
    @opcache_reset();
} elseif (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__ . '/../lib/daily_report_helper.php', true);
}

ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/../lib/daily_report_helper.php';
ob_end_clean();

$store_id = get_office_store_id();
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');

header('Content-Type: text/html; charset=utf-8');
echo "<style>body{font-family:monospace;padding:20px;font-size:13px} h3{margin-top:20px;background:#eee;padding:6px} pre{background:#f9f9f9;padding:10px;border:1px solid #ddd;overflow:auto;max-height:400px}</style>";
echo "<h2>Daily Report 진단 — store_id={$store_id}, date={$date}</h2>";
echo "<p>opcache 상태: " . htmlspecialchars($opcache_status) . " (이 페이지 로드 시 opcache_reset 시도함)</p>";
echo "<p>다른 날짜로 보려면 URL에 <code>?date=YYYY-MM-DD</code> 를 붙이세요.</p>";

$conn = get_db_connection();

echo "<h3>1) er_saved_state 원본 행 존재 여부</h3>";
$stmt = $conn->prepare("SELECT save_date, saved_at, state_json FROM er_saved_state WHERE store_id=? AND save_date=?");
$stmt->bind_param('is', $store_id, $date);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo "<p style='color:red'>❌ er_saved_state 에 store_id={$store_id}, save_date={$date} 인 행이 없습니다.</p>";
    echo "<p>→ Expense Report(office/expense_report)에서 이 날짜로 이동해 SAVE 버튼을 눌렀는지 확인하세요.</p>";
} else {
    echo "<p style='color:green'>✅ 행 존재. saved_at={$row['saved_at']}</p>";
    $state = json_decode($row['state_json'], true);
    $sections = $state['sections'] ?? [];
    echo "<h3>2) sections 안의 키와 항목 수</h3>";
    echo "<pre>";
    foreach ($sections as $key => $items) {
        echo htmlspecialchars($key) . " => " . count($items) . "개\n";
    }
    echo "</pre>";

    echo "<h3>3) selling / check_sup / not_selling / other_exp_check / other_exp_cash 원본 항목 상세</h3>";
    foreach (['selling', 'check_sup', 'not_selling', 'other_exp_check', 'other_exp_cash', 'other_exp'] as $k) {
        if (!isset($sections[$k])) continue;
        echo "<b>{$k}</b> (" . count($sections[$k]) . "개)<pre>" . htmlspecialchars(json_encode($sections[$k], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
    }
}

echo "<h3>4a) sales_pos_expense 원본 (daily_entry.php POS 셀 Expenses)</h3>";
$estmt = $conn->prepare("SELECT shift, pos_no, detail, amount FROM sales_pos_expense WHERE store_id=? AND sale_date=? ORDER BY id");
$estmt->bind_param('is', $store_id, $date);
$estmt->execute();
$erows = $estmt->get_result()->fetch_all(MYSQLI_ASSOC);
$estmt->close();
echo "<p>" . count($erows) . "건</p><pre>" . htmlspecialchars(json_encode($erows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";

echo "<h3>4b) get_daily_other_expense_categories() 실제 반환값 (by_category 포함)</h3>";
$result = get_daily_other_expense_categories($conn, $store_id, $date);
echo "<pre>";
echo "unplaced_count: " . $result['unplaced_count'] . "\n";
echo "total_placed: " . $result['total_placed'] . "\n";
echo "items count: " . count($result['items']) . "\n";
echo "by_category:\n" . json_encode($result['by_category'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo "items:\n" . json_encode($result['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo "</pre>";

echo "<h3>5) daily_report_expense_category 테이블 존재 여부</h3>";
$tbl = $conn->query("SHOW TABLES LIKE 'daily_report_expense_category'");
echo ($tbl && $tbl->num_rows > 0) ? "<p style='color:green'>✅ 테이블 존재</p>" : "<p style='color:red'>❌ 테이블 없음 — run_migration.php를 먼저 실행하세요.</p>";

echo "<h3>7) office_product_purchases 원본 데이터 (매입)</h3>";
$pstmt = $conn->prepare(
    "SELECT id, payment_type, supplier_name, amount, payment_date, check_issued_date
     FROM office_product_purchases
     WHERE store_id=? AND (payment_date=? OR check_issued_date=?)
     ORDER BY id"
);
$pstmt->bind_param('iss', $store_id, $date, $date);
$pstmt->execute();
$prows = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pstmt->close();
echo "<p>payment_date=" . htmlspecialchars($date) . " 또는 check_issued_date=" . htmlspecialchars($date) . " 인 행: " . count($prows) . "건</p>";
echo "<pre>" . htmlspecialchars(json_encode($prows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";

echo "<p>참고: store_id={$store_id}의 office_product_purchases 전체 건수(날짜 무관): ";
$cstmt = $conn->prepare("SELECT COUNT(*) AS cnt, MIN(payment_date) AS min_d, MAX(payment_date) AS max_d FROM office_product_purchases WHERE store_id=?");
$cstmt->bind_param('i', $store_id);
$cstmt->execute();
$crow = $cstmt->get_result()->fetch_assoc();
$cstmt->close();
echo "{$crow['cnt']}건 (payment_date 범위: {$crow['min_d']} ~ {$crow['max_d']})</p>";

echo "<h3>8) get_daily_purchase_summary() 실제 반환값</h3>";
$presult = get_daily_purchase_summary($conn, $store_id, $date);
echo "<pre>" . htmlspecialchars(json_encode($presult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";

echo "<h3>9) sales_pos_reconciliation 원본 (포스매출 현금/크레딧)</h3>";
$rstmt = $conn->prepare(
    "SELECT shift, pos_no, cash_total, other_total, expense_total, deposit_cash, total_amount
     FROM sales_pos_reconciliation WHERE store_id=? AND sale_date=? ORDER BY shift, pos_no"
);
$rstmt->bind_param('is', $store_id, $date);
$rstmt->execute();
$rrows = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rstmt->close();
echo "<pre>" . htmlspecialchars(json_encode($rrows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";

echo "<h3>10) get_daily_pos_summary() 실제 반환값</h3>";
$rsresult = get_daily_pos_summary($conn, $store_id, $date);
echo "<pre>" . htmlspecialchars(json_encode($rsresult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";

echo "<h3>11) sales_daily_items + sales_pos_wholesale_pick 원본 (도매판매/Delivery K)</h3>";
$wstmt = $conn->prepare("SELECT item_type, description, amount FROM sales_daily_items WHERE store_id=? AND sale_date=? AND item_type IN ('delivery_k','whole_sale')");
$wstmt->bind_param('is', $store_id, $date);
$wstmt->execute();
echo "<b>sales_daily_items</b><pre>" . htmlspecialchars(json_encode($wstmt->get_result()->fetch_all(MYSQLI_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
$wstmt->close();

$wstmt2 = $conn->prepare("SELECT shift, pos_no, source_type, client, remark, amount FROM sales_pos_wholesale_pick WHERE store_id=? AND sale_date=?");
$wstmt2->bind_param('is', $store_id, $date);
$wstmt2->execute();
echo "<b>sales_pos_wholesale_pick</b><pre>" . htmlspecialchars(json_encode($wstmt2->get_result()->fetch_all(MYSQLI_ASSOC), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
$wstmt2->close();

echo "<h3>12) get_daily_wholesale_summary() 실제 반환값</h3>";
$wresult = get_daily_wholesale_summary($conn, $store_id, $date);
echo "<pre>" . htmlspecialchars(json_encode($wresult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";

$conn->close();

echo "<h3>6) 서버가 실제로 읽고 있는 daily_report_helper.php 원본 (디스크 직접 읽기)</h3>";
$helper_path = __DIR__ . '/../lib/daily_report_helper.php';
echo "<p>경로: " . htmlspecialchars($helper_path) . "</p>";
echo "<p>realpath: " . htmlspecialchars(realpath($helper_path) ?: '(realpath 실패)') . "</p>";
echo "<p>파일 수정시각(mtime): " . htmlspecialchars(date('Y-m-d H:i:s', filemtime($helper_path))) . " / 파일 크기: " . filesize($helper_path) . " bytes</p>";
$src = file_get_contents($helper_path);
$has_not_selling = str_contains($src, "'not_selling'");
echo "<p>파일 내용에 'not_selling' 포함 여부: " . ($has_not_selling ? "<b style='color:green'>YES ✅</b>" : "<b style='color:red'>NO ❌</b>") . "</p>";
// get_daily_other_expense_categories 함수 본문 중 foreach 라인만 발췌
if (preg_match('/foreach \(\[[^\]]*\] as \$sec_key\)/', $src, $m)) {
    echo "<p>실제 foreach 섹션 목록 라인: <code>" . htmlspecialchars($m[0]) . "</code></p>";
} else {
    echo "<p style='color:red'>foreach (...as \$sec_key) 패턴을 찾지 못함</p>";
}
