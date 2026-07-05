<?php
// Design Ref: §4.5 — 월별 근무시간 xlsx. export_cd.php SpreadsheetML 패턴 재사용.
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) { http_response_code(400); exit('Invalid month'); }

$raw = get_monthly_attendance($store_id, $year, $month);

// 구조화
$by_emp = [];
foreach ($raw as $r) {
    $eid  = (int)$r['employee_id'];
    $date = $r['work_date'];
    if (!isset($by_emp[$eid])) {
        $by_emp[$eid] = ['name' => $r['name'], 'job_role' => $r['job_role'], 'days' => []];
    }
    $by_emp[$eid]['days'][$date][] = ['event_type' => $r['event_type'], 'event_time' => $r['event_time']];
}

$days_in_month = (int)date('t', mktime(0,0,0,$month,1,$year));

function xe2(mixed $s): string { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }
function fmt_m(int $mins): string {
    if ($mins <= 0) return '';
    return sprintf('%dh%02dm', intdiv($mins, 60), $mins % 60);
}
function fmt_t(?string $dt): string { return $dt ? date('H:i', strtotime($dt)) : ''; }

function cell_s(mixed $v, string $style, int $merge = 0): string {
    $m = $merge > 0 ? " ss:MergeAcross=\"{$merge}\"" : '';
    if ($v === '' || $v === null) return "<Cell ss:StyleID=\"{$style}\"{$m}/>";
    return "<Cell ss:StyleID=\"{$style}\"{$m}><Data ss:Type=\"String\">" . xe2($v) . "</Data></Cell>";
}
function cell_n(mixed $v, string $style): string {
    if ($v === '' || $v === null || $v == 0) return "<Cell ss:StyleID=\"{$style}\"/>";
    return "<Cell ss:StyleID=\"{$style}\"><Data ss:Type=\"Number\">" . xe2($v) . "</Data></Cell>";
}

$month_str = sprintf('%04d-%02d', $year, $month);
$filename  = 'Attendance_' . $month_str . '.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:o="urn:schemas-microsoft-com:office:office">

<Styles>
  <Style ss:ID="s_def"><Font ss:FontName="Arial" ss:Size="9"/><Alignment ss:Vertical="Center"/></Style>
  <Style ss:ID="s_title">
    <Font ss:FontName="Arial" ss:Size="12" ss:Bold="1"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Interior ss:Color="#1E3A5F" ss:Pattern="Solid"/>
    <Font ss:Color="#FFFFFF" ss:FontName="Arial" ss:Size="12" ss:Bold="1"/>
  </Style>
  <Style ss:ID="s_hdr">
    <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
    <Interior ss:Color="#C6D9F1" ss:Pattern="Solid"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>
  <Style ss:ID="s_emp">
    <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
    <Alignment ss:Vertical="Center"/>
    <Interior ss:Color="#EBF3FB" ss:Pattern="Solid"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>
  <Style ss:ID="s_cell">
    <Font ss:FontName="Arial" ss:Size="8"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>
    </Borders>
  </Style>
  <Style ss:ID="s_ot">
    <Font ss:FontName="Arial" ss:Size="8" ss:Bold="1" ss:Color="#C0392B"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Interior ss:Color="#FDECEA" ss:Pattern="Solid"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DDDDDD"/>
    </Borders>
  </Style>
  <Style ss:ID="s_total">
    <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Interior ss:Color="#E8F5E9" ss:Pattern="Solid"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    </Borders>
  </Style>
</Styles>

<Worksheet ss:Name="<?= xe2($year.'년 '.$month.'월') ?>">
<Table ss:DefaultRowHeight="16">
  <!-- 열 너비 설정 -->
  <Column ss:Width="60"/><!-- 날짜 -->
  <Column ss:Width="55"/><!-- 출근 -->
  <Column ss:Width="55"/><!-- 브레이크시작 -->
  <Column ss:Width="55"/><!-- 브레이크종료 -->
  <Column ss:Width="55"/><!-- 퇴근 -->
  <Column ss:Width="60"/><!-- 실근무 -->
  <Column ss:Width="55"/><!-- OT -->

  <!-- 타이틀 -->
  <Row ss:Height="28">
    <Cell ss:StyleID="s_title" ss:MergeAcross="6">
      <Data ss:Type="String">HOME K MART — Attendance Report <?= xe2($year.'년 '.$month.'월') ?></Data>
    </Cell>
  </Row>

<?php foreach ($by_emp as $eid => $emp):
    $total_net = $total_ot = $total_days = 0;

    // 직원 헤더 행
    echo '<Row ss:Height="20">';
    echo cell_s($emp['name'] . ' (' . get_job_role_label($emp['job_role']) . ')', 's_emp', 6);
    echo '</Row>' . "\n";

    // 컬럼 헤더
    echo '<Row ss:Height="18">';
    foreach (['날짜', '출근', '브레이크↑', '브레이크↓', '퇴근', '실근무', 'OT'] as $h) {
        echo cell_s($h, 's_hdr');
    }
    echo '</Row>' . "\n";

    // 날짜별 행
    for ($d = 1; $d <= $days_in_month; $d++) {
        $date   = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $events = $emp['days'][$date] ?? [];
        if (empty($events)) continue;

        $ci = $bs = $be = $co = null;
        foreach ($events as $e) {
            switch ($e['event_type']) {
                case 'clock_in':    $ci = $e['event_time']; break;
                case 'break_start': $bs = $e['event_time']; break;
                case 'break_end':   $be = $e['event_time']; break;
                case 'clock_out':   $co = $e['event_time']; break;
            }
        }
        $s   = calc_work_summary($events);
        $dow = ['일','월','화','수','목','금','토'][date('w', strtotime($date))];

        if ($s['net_minutes'] > 0) $total_days++;
        $total_net += $s['net_minutes'];
        $total_ot  += $s['overtime_minutes'];

        $ot_style = $s['overtime_minutes'] > 0 ? 's_ot' : 's_cell';

        echo '<Row ss:Height="15">';
        echo cell_s($d . '(' . $dow . ')', 's_cell');
        echo cell_s(fmt_t($ci), 's_cell');
        echo cell_s(fmt_t($bs), 's_cell');
        echo cell_s(fmt_t($be), 's_cell');
        echo cell_s(fmt_t($co), 's_cell');
        echo cell_s(fmt_m($s['net_minutes']), 's_cell');
        echo cell_s($s['overtime_minutes'] > 0 ? '+' . fmt_m($s['overtime_minutes']) : '', $ot_style);
        echo '</Row>' . "\n";
    }

    // 합계 행
    $reg = max(0, $total_net - $total_ot);
    echo '<Row ss:Height="18">';
    echo cell_s('합계 ' . $total_days . '일', 's_total');
    echo cell_s('', 's_total');
    echo cell_s('', 's_total');
    echo cell_s('', 's_total');
    echo cell_s('', 's_total');
    echo cell_s(fmt_m($total_net) . ' (정규 ' . fmt_m($reg) . ')', 's_total');
    echo cell_s($total_ot > 0 ? 'OT ' . fmt_m($total_ot) : '', 's_total');
    echo '</Row>' . "\n";

    echo '<Row ss:Height="6"><Cell ss:StyleID="s_def"/></Row>' . "\n"; // 빈 줄
endforeach; ?>

</Table>
</Worksheet>
</Workbook>
