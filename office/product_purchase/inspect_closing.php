<?php
// 임시 진단 스크립트 — 문제 해결 후 삭제할 것.
// "사무실 경비 (부속품 일체)" 계산 검증: STORE EXP = Sales Report 값, 인건비/전기세/월세 = Fixed Expenses Report 자동분류.
if (function_exists('opcache_reset')) { @opcache_reset(); }

ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/../lib/daily_report_helper.php';
require_once __DIR__ . '/../lib/sales_report_helper.php';
require_once __DIR__ . '/../lib/monthly_closing_helper.php';
ob_end_clean();

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? 2026);
$month    = (int)($_GET['month'] ?? 8);

header('Content-Type: text/html; charset=utf-8');
echo "<style>body{font-family:monospace;padding:20px;font-size:13px} h3{margin-top:20px;background:#eee;padding:6px} pre{background:#f9f9f9;padding:10px;border:1px solid #ddd;overflow:auto;max-height:400px}</style>";
echo "<h2>Monthly Closing 진단 — store_id={$store_id}, year={$year}, month={$month}</h2>";
echo "<p>다른 달로 보려면 URL에 <code>?year=YYYY&month=M</code> 를 붙이세요.</p>";

$conn = get_db_connection();

// ── 1) office_monthly_fixed 저장값 ──────────────────────────────
echo "<h3>1) office_monthly_fixed (수동 입력 한국월급/월세) 저장값</h3>";
$sf = $conn->prepare("SELECT korean_salary, monthly_rent, updated_at FROM office_monthly_fixed WHERE store_id=? AND year=? AND month=?");
$sf->bind_param('iii', $store_id, $year, $month);
$sf->execute();
$sf_row = $sf->get_result()->fetch_assoc();
$sf->close();
if ($sf_row) {
    echo "<p style='color:green'>✅ 저장된 행 있음 (rent_saved=true) — monthly_rent=" . number_format((float)$sf_row['monthly_rent'], 2) . ", korean_salary=" . number_format((float)$sf_row['korean_salary'], 2) . ", updated_at={$sf_row['updated_at']}</p>";
} else {
    echo "<p style='color:orange'>⚠️ 저장된 행 없음 (rent_saved=false) → monthly_rent은 Fixed Expenses Rent 카테고리 자동집계값으로 대체됨</p>";
}

// ── 2) Sales Report STORE EXP (col_totals['equip']) ─────────────
echo "<h3>2) office/sales/monthly_report.php와 동일한 get_monthly_sales_report()의 STORE EXP</h3>";
$sales_report = get_monthly_sales_report($store_id, $year, $month);
$store_exp = (float)($sales_report['col_totals']['equip'] ?? 0);
echo "<p>STORE EXP(점지출) = " . number_format($store_exp, 2) . "</p>";

// ── 3) Fixed Expenses Report 자동분류 일자별 분해 ────────────────
echo "<h3>3) 일자별 get_daily_fixed_expense_totals() 분해 (Salary / Electricity / Rent)</h3>";
echo "<table border='1' cellpadding='3' style='border-collapse:collapse'><tr><th>day</th><th>total</th><th>Salary</th><th>Electricity</th><th>Rent</th></tr>";
$sum_salary = 0.0; $sum_electric = 0.0; $sum_rent = 0.0;
$days_in_month = (int)date('t', mktime(0,0,0,$month,1,$year));
for ($d = 1; $d <= $days_in_month; $d++) {
    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $day_exp  = get_daily_fixed_expense_totals($conn, $store_id, $date_str);
    $tp = (float)$day_exp['total'];
    $sa = (float)($day_exp['by_category']['Salary'] ?? 0);
    $el = (float)($day_exp['by_category']['Electricity'] ?? 0);
    $re = (float)($day_exp['by_category']['Rent'] ?? 0);
    $sum_salary += $sa; $sum_electric += $el; $sum_rent += $re;
    if ($tp != 0) {
        echo "<tr><td>{$d}</td><td>" . number_format($tp,2) . "</td><td>" . number_format($sa,2) . "</td><td>" . number_format($el,2) . "</td><td>" . number_format($re,2) . "</td></tr>";
    }
}
echo "<tr><th>합계</th><th></th><th>" . number_format($sum_salary,2) . "</th><th>" . number_format($sum_electric,2) . "</th><th>" . number_format($sum_rent,2) . "</th></tr>";
echo "</table>";

// ── 4) 계산식 재현 ────────────────────────────────────────────
$monthly_rent_used = $sf_row ? (float)$sf_row['monthly_rent'] : $sum_rent;
echo "<h3>4) 계산식 재현</h3>";
echo "<pre>";
echo "STORE EXP (Sales Report)        = " . number_format($store_exp,2) . "\n";
echo "총 인건비 (Fixed Exp Salary)     = " . number_format($sum_salary,2) . "\n";
echo "총 전기세 (Fixed Exp Electricity) = " . number_format($sum_electric,2) . "\n";
echo "월세 (저장값 있으면 그 값, 없으면 Fixed Exp Rent 합계) = " . number_format($monthly_rent_used,2) . "\n";
echo "-------------------------------------------\n";
$calc = $store_exp - $sum_salary - $sum_electric - $monthly_rent_used;
echo "예상 사무실경비 = STORE_EXP - 인건비 - 전기세 - 월세 = " . number_format($calc,2) . "\n";
echo "</pre>";

// ── 5) get_monthly_closing_report() 실제 반환값 (화면이 실제로 쓰는 값) ──
echo "<h3>5) get_monthly_closing_report() 실제 반환값 (items 배열)</h3>";
$report = get_monthly_closing_report($store_id, $year, $month);
echo "<pre>";
foreach ($report['items'] as $it) {
    echo str_pad($it['label'], 30) . " : " . number_format((float)$it['amount'], 2) . "\n";
}
echo "</pre>";
echo "<p>total_expense = " . number_format((float)$report['total_expense'], 2) . "</p>";

$conn->close();

// ── 6) 서버가 실제로 읽고 있는 monthly_closing_helper.php 원본 확인 (배포 여부 체크) ──
echo "<h3>6) 서버가 실제로 읽는 monthly_closing_helper.php 원본 (배포 확인용)</h3>";
$helper_path = __DIR__ . '/../lib/monthly_closing_helper.php';
echo "<p>파일 수정시각(mtime): " . htmlspecialchars(date('Y-m-d H:i:s', filemtime($helper_path))) . " / 크기: " . filesize($helper_path) . " bytes</p>";
$src = file_get_contents($helper_path);
$uses_sales_report_equip = str_contains($src, "sales_report['col_totals']['equip']");
echo "<p>total_office 계산식에 Sales Report col_totals['equip'] 사용 여부: " . ($uses_sales_report_equip ? "<b style='color:green'>YES ✅ (최신 버전 배포됨)</b>" : "<b style='color:red'>NO ❌ (구버전 — 파일 업로드 필요)</b>") . "</p>";
