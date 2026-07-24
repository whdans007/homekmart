<?php
// Design Ref: daily-report.design.md §11.2 step 7 — 월별 일괄 엑셀 (워크북 1개, 날짜별 시트)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/daily_report_render.php';
ob_end_clean();

$is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';
$store_id = get_office_store_id();
$today = date('Y-m-d');
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($is_super_admin) {
    $req_store_id = (int)($_GET['store_id'] ?? 0);
    if ($req_store_id > 0) $store_id = $req_store_id;
}

// 1일 ~ 오늘(당월이면 오늘, 과거달이면 말일) — expense_report 월별 export와 동일 관례
$first    = sprintf('%04d-%02d-01', $year, $month);
$last_day = ($year === (int)date('Y') && $month === (int)date('n'))
            ? $today
            : date('Y-m-t', strtotime($first));

$conn = get_db_connection();

$store_stmt = $conn->prepare("SELECT name AS label FROM stores WHERE id=?");
$store_stmt->bind_param('i', $store_id);
$store_stmt->execute();
$store_label = $store_stmt->get_result()->fetch_assoc()['label'] ?? 'SUNSET';
$store_stmt->close();

$sheets_xml = '';
$cursor = $first;
while ($cursor <= $last_day) {
    $rows_xml = dr_render_sheet_rows($conn, $store_id, $cursor, $store_label);
    $sheets_xml .= "<Worksheet ss:Name=\"" . dr_xe($cursor) . "\">\n"
        . "<WorksheetOptions xmlns=\"urn:schemas-microsoft-com:office:excel\">\n"
        . " <PageSetup><Layout x:Orientation=\"Landscape\"/><PageMargins x:Bottom=\"0.3\" x:Left=\"0.2\" x:Right=\"0.2\" x:Top=\"0.3\"/></PageSetup>\n"
        . " <FitToPage/>\n"
        . " <Print><FitWidth>1</FitWidth><FitHeight>32767</FitHeight><PaperSizeIndex>9</PaperSizeIndex></Print>\n"
        . "</WorksheetOptions>\n"
        . "<Table ss:DefaultRowHeight=\"14\">\n"
        . $rows_xml
        . "\n</Table>\n</Worksheet>\n";
    $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
}
$conn->close();

$filename = 'DailyReport_' . dr_xe($store_label) . '_' . sprintf('%04d-%02d', $year, $month) . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: max-age=0');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:o="urn:schemas-microsoft-com:office:office">
<?php echo dr_xls_styles(); ?>
<?php echo $sheets_xml; ?>
</Workbook>
