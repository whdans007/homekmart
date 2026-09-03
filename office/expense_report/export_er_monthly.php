<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

$store_id = get_office_store_id();
$today    = date('Y-m-d');
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));

// 1일 ~ 오늘(당월이면 오늘, 과거달이면 말일)
$first    = sprintf('%04d-%02d-01', $year, $month);
$last_day = ($year === (int)date('Y') && $month === (int)date('n'))
            ? $today
            : date('Y-m-t', strtotime($first));

$conn = get_db_connection();

// er_saved_state 전체 로드 (해당 월)
$saved_states = [];
$tbl = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if ($tbl && $tbl->num_rows > 0) {
    $st = $conn->prepare(
        "SELECT save_date, state_json FROM er_saved_state
         WHERE store_id=? AND save_date BETWEEN ? AND ?
         ORDER BY save_date"
    );
    if ($st) {
        $st->bind_param('iss', $store_id, $first, $last_day);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $d = json_decode($r['state_json'], true);
            if ($d && isset($d['sections'])) {
                $sec = $d['sections'];
                // 구 데이터 하위 호환: other_exp → other_exp_cash
                if (isset($sec['other_exp']) && !isset($sec['other_exp_check']) && !isset($sec['other_exp_cash'])) {
                    $sec['other_exp_cash']  = $sec['other_exp'];
                    $sec['other_exp_check'] = [];
                    unset($sec['other_exp']);
                }
                // 스냅샷에 굳어있는 공급처명을 원본 테이블 기준 최신 이름으로 갱신
                foreach ($sec as $sec_name => $sec_rows) {
                    $sec[$sec_name] = office_refresh_supplier_names($conn, $sec_rows);
                }
                $saved_states[$r['save_date']] = $sec;
            }
        }
        $st->close();
    }
}

// DB에서 날짜별 데이터 로드 함수
$chk_cv = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'cv_no'");
$cv_col  = ($chk_cv && $chk_cv->num_rows > 0) ? "pp.cv_no" : "'' AS cv_no";
$chk_et  = $conn->query("SHOW COLUMNS FROM office_equipment_purchases LIKE 'expense_type'");
$has_et  = ($chk_et && $chk_et->num_rows > 0);
$et_col  = $has_et ? "ep.expense_type" : "'consumable' AS expense_type";

function loadDbSections($conn, $store_id, $date, $cv_col, $et_col) {
    $secs = ['selling'=>[], 'not_selling'=>[], 'check_sup'=>[], 'other_exp_check'=>[], 'other_exp_cash'=>[]];

    // Cash PP
    $s = $conn->prepare(
        "SELECT pp.supplier_name AS supplier, pp.delivery_content AS details,
                pp.amount, COALESCE(r.cv_no,'') AS cv_no
         FROM office_product_purchases pp
         LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
         WHERE pp.store_id=? AND pp.payment_type='cash' AND pp.payment_date=?
         ORDER BY pp.id"
    );
    if ($s) {
        $s->bind_param('is', $store_id, $date); $s->execute();
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $secs['selling'][] = $r;
        $s->close();
    }

    // Check PP
    $s = $conn->prepare(
        "SELECT pp.supplier_name AS supplier, pp.delivery_content AS details,
                pp.amount, COALESCE(r.cv_no,'') AS cv_no
         FROM office_product_purchases pp
         LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
         WHERE pp.store_id=? AND pp.payment_type='check' AND pp.check_issued_date=?
         ORDER BY pp.id"
    );
    if ($s) {
        $s->bind_param('is', $store_id, $date); $s->execute();
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $secs['check_sup'][] = $r;
        $s->close();
    }

    // Equipment
    $s = $conn->prepare(
        "SELECT ep.supplier_name AS supplier, ep.delivery_content AS details,
                ep.amount, COALESCE(r.cv_no,'') AS cv_no, {$et_col}
         FROM office_equipment_purchases ep
         LEFT JOIN office_receipts r ON r.linked_purchase_type='equipment' AND r.linked_purchase_id=ep.id
         WHERE ep.store_id=? AND ep.payment_date=?
         ORDER BY ep.id"
    );
    if ($s) {
        $s->bind_param('is', $store_id, $date); $s->execute();
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $key = ($r['expense_type'] === 'other_expense') ? 'other_exp_cash' : 'not_selling';
            $secs[$key][] = $r;
        }
        $s->close();
    }
    return $secs;
}

// 날짜별 데이터 수집 (데이터 있는 날만)
$days_data = [];
$cur = strtotime($first);
$end = strtotime($last_day);
while ($cur <= $end) {
    $d = date('Y-m-d', $cur);
    if (isset($saved_states[$d])) {
        $secs = $saved_states[$d];
    } else {
        $secs = loadDbSections($conn, $store_id, $d, $cv_col, $et_col);
    }
    $days_data[$d] = $secs; // 데이터 없어도 시트 생성
    $cur = strtotime('+1 day', $cur);
}
$conn->close();

// ── Excel 출력 ────────────────────────────────────────────────
function xe($s) { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }
function fmtN($n) { return number_format((float)$n, 2); }
function xmlRow2($h, $c) { return "<Row" . ($h ? " ss:Height=\"$h\"" : '') . ">$c</Row>\n"; }
function xmlCells2(array $cells) {
    $out = '';
    foreach ($cells as $c) {
        $val=$c[0]??''; $sid=$c[1]??'Default';
        $ma=isset($c[2])&&$c[2]>0?" ss:MergeAcross=\"{$c[2]}\"":"";
        $md=isset($c[3])&&$c[3]>0?" ss:MergeDown=\"{$c[3]}\"":"";
        $tp=$c[4]??'String'; $ix=isset($c[5])?" ss:Index=\"{$c[5]}\"":"";
        if ($val===''||$val===null) $out.="<Cell{$ix} ss:StyleID=\"{$sid}\"{$ma}{$md}/>";
        else $out.="<Cell{$ix} ss:StyleID=\"{$sid}\"{$ma}{$md}><Data ss:Type=\"{$tp}\">".xe($val)."</Data></Cell>";
    }
    return $out;
}

$days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$filename = sprintf('DailyExpense_%04d_%02d.xls', $year, $month);
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: max-age=0');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:x="urn:schemas-microsoft-com:office:excel">
<Styles>
 <Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/></Style>
 <Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/><Interior ss:Color="#B8D4E8" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="7" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_name"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_banner"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#1F3864"/><Interior ss:Color="#B8D4E8" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_date"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_bal"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_bal_val"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
 <Style ss:ID="s_colhd"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#C6D9F1" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_data"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Courier New" ss:Size="8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
 <Style ss:ID="s_total"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#FFFF99" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_total_amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Courier New" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#FFFF99" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
 <Style ss:ID="s_chkhd"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#FFC000" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_coh"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1" ss:Color="#CC0000"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_empty"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/></Style>
</Styles>

<?php
foreach ($days_data as $date => $secs):
    $ts2        = strtotime($date);
    $date_label = date('M j, Y ', $ts2) . '(' . $days_en[date('w',$ts2)] . ')';
    $sheet_name = date('M d', $ts2);   // "May 01"

    $cash_rows            = $secs['selling']          ?? [];
    $consumable_rows      = $secs['not_selling']       ?? [];
    $check_rows           = $secs['check_sup']         ?? [];
    $other_exp_check_rows = $secs['other_exp_check']   ?? [];
    $other_exp_cash_rows  = array_merge($secs['other_exp_cash'] ?? [], $secs['other_exp'] ?? []);

    $cash_total             = array_sum(array_column($cash_rows,            'amount'));
    $check_total            = array_sum(array_column($check_rows,           'amount'));
    $consumable_total       = array_sum(array_column($consumable_rows,      'amount'));
    $other_exp_check_total  = array_sum(array_column($other_exp_check_rows, 'amount'));
    $other_exp_cash_total   = array_sum(array_column($other_exp_cash_rows,  'amount'));
    $other_exp_total        = $other_exp_check_total + $other_exp_cash_total;
    $equip_total            = $consumable_total + $other_exp_total;
    $grand_total            = $cash_total + $consumable_total;

    $MAX_DATA  = max(10, count($cash_rows), count($consumable_rows));

    // OTHER EXPENSES 우측: sub-header + data 혼합 배열
    $has_oe_check2 = !empty($other_exp_check_rows);
    $has_oe_cash2  = !empty($other_exp_cash_rows);
    $right_items2  = [];
    if ($has_oe_check2) {
        if ($has_oe_cash2) $right_items2[] = ['_t'=>'hdr','label'=>'수표 (CHECK)'];
        foreach ($other_exp_check_rows as $r2) $right_items2[] = $r2 + ['_t'=>'data'];
        if ($has_oe_cash2) $right_items2[] = ['_t'=>'sub','amount'=>$other_exp_check_total,'label'=>'소계(수표)'];
    }
    if ($has_oe_cash2) {
        if ($has_oe_check2) $right_items2[] = ['_t'=>'hdr','label'=>'현금 (CASH)'];
        foreach ($other_exp_cash_rows as $r2) $right_items2[] = $r2 + ['_t'=>'data'];
        if ($has_oe_check2) $right_items2[] = ['_t'=>'sub','amount'=>$other_exp_cash_total,'label'=>'소계(현금)'];
    }
    $MAX_CHECK = max(10, count($check_rows), count($right_items2));
?>
<Worksheet ss:Name="<?php echo xe($sheet_name); ?>">
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
 <PageSetup>
  <Layout x:Orientation="Landscape"/>
  <PageMargins x:Bottom="0.3" x:Left="0.2" x:Right="0.2" x:Top="0.3"/>
 </PageSetup>
 <FitToPage/>
 <Print><FitWidth>1</FitWidth><FitHeight>32767</FitHeight><PaperSizeIndex>9</PaperSizeIndex></Print>
</WorksheetOptions>
<Table ss:DefaultRowHeight="14">
<?php
$cols2 = [80,80,65,65,55,55,55,65,65,80,80,65,65,55,55,55,65,65];
foreach ($cols2 as $w) echo "<Column ss:AutoFitWidth=\"0\" ss:Width=\"{$w}\"/>\n";

echo xmlRow2(18, xmlCells2([
    ['HOME PLUS (SUNSET)','s_title',2,1],
    ['PREPARED BY:','s_hdr',1],['CHECKED:','s_hdr',1],['APPROVED:','s_hdr',1],
    ['HOME PLUS (SUNSET)','s_title',2,1,'String',10],
    ['PREPARED BY:','s_hdr',1],['CHECKED:','s_hdr',1],['APPROVED:','s_hdr',1],
]));
echo xmlRow2(35, xmlCells2([
    ['ROMALYN','s_name',1,0,'String',4],['','s_hdr',1],['SIR MIN','s_name',1],
    ['ROMALYN','s_name',1,0,'String',13],['','s_hdr',1],['SIR MIN','s_name',1],
]));
echo xmlRow2(17, xmlCells2([['BUYING EXPENSES','s_banner',8],['OTHER EXPENSES','s_banner',8,0,'String',10]]));
echo xmlRow2(4,  xmlCells2([['','s_empty',17]]));
echo xmlRow2(14, xmlCells2([['DATE:','s_date',2],[xe($date_label),'s_date',5],['DATE:','s_date',2,0,'String',10],[xe($date_label),'s_date',5]]));
echo xmlRow2(14, xmlCells2([['BEGINNING BALANCE:','s_bal',2],['','s_bal_val',5],['BEGINNING BALANCE:','s_bal',2,0,'String',10],['','s_bal_val',5]]));
echo xmlRow2(14, xmlCells2([['','s_empty',2],['','s_empty',5],['ADDITIONAL AMOUNT:','s_bal',2,0,'String',10],['','s_bal_val',5]]));
echo xmlRow2(14, xmlCells2([['TOTAL AMOUNT:','s_bal',2],[fmtN($cash_total+$check_total),'s_bal_val',5,0,'Number'],['TOTAL AMOUNT:','s_bal',2,0,'String',10],[fmtN($equip_total),'s_bal_val',5,0,'Number']]));
echo xmlRow2(4,  xmlCells2([['','s_empty',17]]));
echo xmlRow2(15, xmlCells2([['CV NO.','s_colhd'],['PARTICULARS  (CASH SELLING)','s_colhd',5],['AMOUNT','s_colhd',1],['CV NO.','s_colhd',0,0,'String',10],['PARTICULARS  (CASH NOT SELLING)','s_colhd',5],['AMOUNT','s_colhd',1]]));

for ($i=0; $i<$MAX_DATA; $i++) {
    $lft=$cash_rows[$i]??null; $rgt=$consumable_rows[$i]??null;
    echo xmlRow2(13, xmlCells2([
        [$lft?xe($lft['cv_no']??''):'','s_data'],
        [$lft?xe(strtoupper($lft['supplier']??'')):'','s_data',2],
        [$lft?xe(strtoupper($lft['details']??'')):'','s_data',2],
        [$lft?(float)$lft['amount']:'', $lft?'s_amt':'s_data',1,0,'Number'],
        [$rgt?xe($rgt['cv_no']??''):'','s_data',0,0,'String',10],
        [$rgt?xe(strtoupper($rgt['supplier']??'')):'','s_data',2],
        [$rgt?xe(strtoupper($rgt['details']??'')):'','s_data',2],
        [$rgt?(float)$rgt['amount']:'', $rgt?'s_amt':'s_data',1,0,'Number'],
    ]));
}
echo xmlRow2(14, xmlCells2([['TOTAL:','s_total',6],[(float)$cash_total,'s_total_amt',1,0,'Number'],['TOTAL:','s_total',6,0,'String',10],[(float)$consumable_total,'s_total_amt',1,0,'Number']]));
echo xmlRow2(14, xmlCells2([['','s_empty',8],['CASH TOTAL AMOUNT','s_total',6,0,'String',10],[(float)$grand_total,'s_total_amt',1,0,'Number']]));
// CHECK(좌) / OTHER EXPENSES 통합(우) 헤더
echo xmlRow2(15, xmlCells2([['CHECK NO.','s_chkhd'],['SUPLIERS  (PAY THRU CHECK)','s_chkhd',5],['AMOUNT CHECK','s_chkhd',1],['CV NO.','s_chkhd',0,0,'String',10],['OTHER EXPENSES  (SALARY/ELECTRIC/WATER/RENT)','s_chkhd',5],['AMOUNT','s_chkhd',1]]));

for ($i=0; $i<$MAX_CHECK; $i++) {
    $chk=$check_rows[$i]??null;
    $ri=$right_items2[$i]??null;
    $rt=$ri['_t']??null;

    $left2=[
        [$chk?xe($chk['cv_no']??''):'','s_data'],
        [$chk?xe(strtoupper($chk['supplier']??'')):'','s_data',2],
        [$chk?xe(strtoupper($chk['details']??'')):'','s_data',2],
        [$chk?(float)$chk['amount']:'', $chk?'s_amt':'s_data',1,0,'Number'],
    ];
    if ($rt==='hdr') {
        $right2=[[$ri['label'],'s_chkhd',8,0,'String',10]];
    } elseif ($rt==='sub') {
        $right2=[[$ri['label'].':','s_total',6,0,'String',10],[(float)$ri['amount'],'s_total_amt',1,0,'Number']];
    } else {
        $right2=[
            [$ri?xe($ri['cv_no']??''):'','s_data',0,0,'String',10],
            [$ri?xe(strtoupper($ri['supplier']??'')):'','s_data',2],
            [$ri?xe(strtoupper($ri['details']??'')):'','s_data',2],
            [$ri?(float)$ri['amount']:'', $ri?'s_amt':'s_data',1,0,'Number'],
        ];
    }
    echo xmlRow2(13, xmlCells2(array_merge($left2, $right2)));
}
echo xmlRow2(14, xmlCells2([['TOTAL:','s_total',6],[$check_total?(float)$check_total:'', $check_total?'s_total_amt':'s_total',1,0,'Number'],['TOTAL:','s_total',6,0,'String',10],[$other_exp_total?(float)$other_exp_total:'', $other_exp_total?'s_total_amt':'s_total',1,0,'Number']]));
echo xmlRow2(14, xmlCells2([['CASH ON HAND : 50,000.00  (FOR BILLS)','s_coh',8],['CASH ON HAND : 50,000.00  (FOR BILLS)','s_coh',8,0,'String',10]]));
?>
</Table>
</Worksheet>
<?php endforeach; ?>
</Workbook>
