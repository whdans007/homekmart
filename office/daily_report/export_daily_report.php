<?php
// Design Ref: daily-report.design.md §11.2 step 5 — 일일 엑셀 다운로드
// 이 프로젝트의 기존 export 컨벤션(office/expense_report/export_er.php 등)을 따라
// PhpSpreadsheet 대신 SpreadsheetML(XML) 직접 생성 방식을 사용한다 — 셀 병합/색상을
// 참고 이미지와 정확히 맞추기 위해 이 프로젝트 전역에서 이미 채택된 방식.
// 실제 행 렌더링은 daily_report_render.php의 dr_render_sheet_rows()를 공유해
// 화면(index.php)/일일/월별 엑셀의 숫자가 항상 일치하도록 한다.
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/daily_report_render.php';
ob_end_clean();

$is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';
$store_id = get_office_store_id();
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
if ($is_super_admin) {
    $req_store_id = (int)($_GET['store_id'] ?? 0);
    if ($req_store_id > 0) $store_id = $req_store_id;
}

$conn = get_db_connection();

$store_stmt = $conn->prepare("SELECT name AS label FROM stores WHERE id=?");
$store_stmt->bind_param('i', $store_id);
$store_stmt->execute();
$store_label = $store_stmt->get_result()->fetch_assoc()['label'] ?? 'SUNSET';
$store_stmt->close();

$sheet_rows_xml = dr_render_sheet_rows($conn, $store_id, $date, $store_label);
$conn->close();

$filename = 'DailyReport_' . dr_xe($store_label) . '_' . $date . '.xls';
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
<Worksheet ss:Name="Daily Report">
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
 <PageSetup>
  <Layout x:Orientation="Landscape"/>
  <PageMargins x:Bottom="0.3" x:Left="0.2" x:Right="0.2" x:Top="0.3"/>
 </PageSetup>
 <FitToPage/>
 <Print><FitWidth>1</FitWidth><FitHeight>32767</FitHeight><PaperSizeIndex>9</PaperSizeIndex></Print>
</WorksheetOptions>
<Table ss:DefaultRowHeight="14">
<?php echo $sheet_rows_xml; ?>
</Table>
</Worksheet>
</Workbook>
