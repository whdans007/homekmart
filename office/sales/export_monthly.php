<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$store_id    = get_office_store_id();
$year        = (int)($_GET['year']  ?? date('Y'));
$month       = (int)($_GET['month'] ?? date('n'));
$days        = (int)date('t', mktime(0,0,0,$month,1,$year));
$month_label = date('F Y', mktime(0,0,0,$month,1,$year));

$conn = get_db_connection();

// 리포트 제목에 표시할 현재 지점명 조회
// 예) "SUNSET (선셋점)" → 브랜딩 형식에 맞춰 앞의 영문 부분만 대문자로 표시
$store_display = '';
$st_name = $conn->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st_name->bind_param('i', $store_id);
$st_name->execute();
if ($sr = $st_name->get_result()->fetch_assoc()) {
    $store_display = $sr['name'] ?? '';
}
$st_name->close();
if (($_p = strpos($store_display, ' (')) !== false) {
    $store_display = substr($store_display, 0, $_p);
}
$store_display = strtoupper(trim($store_display));

// POS 매출 — 셀 마감액(expected_cash, POS Z리딩) 기준. daily_entry DAY TOTAL과 동일 기준 (monthly_report.php 와 동일 로직).
$stmt = $conn->prepare("SELECT DAY(sale_date) AS d, shift, pos_no, expected_cash FROM sales_pos_reconciliation WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?");
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$sales_by_day = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $d = (int)$r['d'];
    if (!isset($sales_by_day[$d])) $sales_by_day[$d] = [];
    $sales_by_day[$d]["{$r['shift']}_pos{$r['pos_no']}"] = $r['expected_cash'];
}
$stmt->close();

// Delivery K는 셀 단위가 아니므로 sales_daily에서 그대로
$stmt = $conn->prepare("SELECT DAY(sale_date) AS d, delivery_k FROM sales_daily WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?");
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $d = (int)$r['d'];
    if (!isset($sales_by_day[$d])) $sales_by_day[$d] = [];
    $sales_by_day[$d]['delivery_k'] = $r['delivery_k'];
}
$stmt->close();

// §4/§5 — POS 셀에 실제 pick된 금액 기준 (credit/credit_doc/wholesale). monthly_report.php 와 동일 로직.
// credit_doc 는 SALES TOTAL 포함(별도 컬럼), credit(POS 외상)은 정보용 컬럼(SALES TOTAL 제외).
// wholesale은 wholesale_sales 테이블 전체가 아니라 셀에 pick된 금액만 집계 (daily_entry DAY TOTAL과 동일 기준).
$pos_credit_by_day = [];
$credit_doc_by_day = [];
$ws_by_day = [];
$pick_tbl = $conn->query("SHOW TABLES LIKE 'sales_pos_wholesale_pick'");
if ($pick_tbl && $pick_tbl->num_rows > 0) {
    $stmt = $conn->prepare("SELECT DAY(sale_date) AS d, source_type, SUM(amount) AS total FROM sales_pos_wholesale_pick WHERE store_id=? AND source_type IN ('credit','credit_doc','wholesale') AND YEAR(sale_date)=? AND MONTH(sale_date)=? GROUP BY DAY(sale_date), source_type");
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $d = (int)$r['d'];
        if ($r['source_type'] === 'credit') { $pos_credit_by_day[$d] = (float)$r['total']; }
        elseif ($r['source_type'] === 'credit_doc') { $credit_doc_by_day[$d] = (float)$r['total']; }
        else { $ws_by_day[$d] = (float)$r['total']; }
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT DAY(payment_date) AS d, SUM(amount) AS total FROM office_product_purchases WHERE store_id=? AND payment_type='cash' AND YEAR(payment_date)=? AND MONTH(payment_date)=? GROUP BY DAY(payment_date)");
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$pu_day = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $pu_day[(int)$r['d']] = (float)$r['total']; }
$stmt->close();

$stmt = $conn->prepare("SELECT DAY(check_issued_date) AS d, SUM(amount) AS total FROM office_product_purchases WHERE store_id=? AND payment_type='check' AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=? GROUP BY DAY(check_issued_date)");
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $pu_day[(int)$r['d']] = ($pu_day[(int)$r['d']] ?? 0) + (float)$r['total']; }
$stmt->close();

// ER 저장 상태에서 r_(영수증) 아이템 → PURCHASE / STORE EXP 합산
$eq_day = [];
$er_tbl2 = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if ($er_tbl2 && $er_tbl2->num_rows > 0) {
    $er_q2 = $conn->prepare("SELECT DAY(save_date) AS d, state_json FROM er_saved_state WHERE store_id=? AND YEAR(save_date)=? AND MONTH(save_date)=?");
    if ($er_q2) {
        $er_q2->bind_param('iii', $store_id, $year, $month);
        $er_q2->execute();
        $er_pu_secs  = ['selling', 'check_sup'];
        $er_exp_secs = ['not_selling', 'other_exp_check', 'other_exp_cash'];
        foreach ($er_q2->get_result()->fetch_all(MYSQLI_ASSOC) as $er_row2) {
            $d2    = (int)$er_row2['d'];
            $state2 = json_decode($er_row2['state_json'], true);
            if (!$state2 || !isset($state2['sections'])) continue;
            foreach ($state2['sections'] as $sec2 => $rows2) {
                $is_pu2  = in_array($sec2, $er_pu_secs);
                $is_exp2 = in_array($sec2, $er_exp_secs);
                if (!$is_pu2 && !$is_exp2) continue;
                foreach ($rows2 as $row2) {
                    if (strncmp((string)($row2['item_id'] ?? ''), 'r_', 2) !== 0) continue;
                    $amt2 = (float)($row2['amount'] ?? 0);
                    if ($amt2 <= 0) continue;
                    if ($is_pu2)  $pu_day[$d2]  = ($pu_day[$d2]  ?? 0) + $amt2;
                    else          $eq_day[$d2]   = ($eq_day[$d2]  ?? 0) + $amt2;
                }
            }
        }
        $er_q2->close();
    }
}

$stmt = $conn->prepare("SELECT DAY(payment_date) AS d, SUM(amount) AS total FROM office_equipment_purchases WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=? GROUP BY DAY(payment_date)");
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $eq_day[(int)$r['d']] = ($eq_day[(int)$r['d']] ?? 0) + (float)$r['total']; }
$stmt->close();


// Transfer IN from office sales_transfers
$stmt = $conn->prepare("SELECT DAY(transfer_date) AS d, SUM(amount) AS total FROM sales_transfers WHERE store_id=? AND direction='in' AND YEAR(transfer_date)=? AND MONTH(transfer_date)=? GROUP BY DAY(transfer_date)");
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$tr_in_day = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $tr_in_day[(int)$r['d']] = (float)$r['total']; }
$stmt->close();

// Transfer OUT from admin store_transfers
$stmt = $conn->prepare("SELECT DAY(transfer_date) AS d, SUM(final_amount) AS total FROM store_transfers WHERE from_store_id=? AND status != 'cancelled' AND YEAR(transfer_date)=? AND MONTH(transfer_date)=? GROUP BY DAY(transfer_date)");
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$tr_out_day = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $tr_out_day[(int)$r['d']] = (float)$r['total']; }
$stmt->close();

$tr_day = [];
foreach (array_unique(array_merge(array_keys($tr_in_day), array_keys($tr_out_day))) as $d) {
    $tr_day[$d] = ($tr_in_day[$d] ?? 0) - ($tr_out_day[$d] ?? 0);
}
$conn->close();

$days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$totals  = array_fill_keys(['gy1','gy2','mo1','mo2','mi1','mi2','dk','pc','cd','ws','st','pu','eq','tr','net'], 0.0);
$rows    = [];
for ($d = 1; $d <= $days; $d++) {
    $s   = $sales_by_day[$d] ?? [];
    $gy1 = (float)($s['gy_pos1']??0);    $gy2 = (float)($s['gy_pos2']??0);
    $mo1 = (float)($s['morning_pos1']??0); $mo2 = (float)($s['morning_pos2']??0);
    $mi1 = (float)($s['mid_pos1']??0);   $mi2 = (float)($s['mid_pos2']??0);
    $dk  = (float)($s['delivery_k']??0);
    $pc  = $pos_credit_by_day[$d] ?? 0.0; // §4 POS 외상 (정보용 · SALES TOTAL 제외)
    $cd  = $credit_doc_by_day[$d] ?? 0.0; // §4 거래명세서 (SALES TOTAL 포함)
    $ws  = $ws_by_day[$d] ?? 0.0;
    $st  = $gy1+$gy2+$mo1+$mo2+$mi1+$mi2+$dk+$cd+$ws; // 거래명세서 포함, POS 외상 제외
    $pu  = $pu_day[$d] ?? 0.0;
    $eq  = $eq_day[$d] ?? 0.0;
    $tr  = $tr_day[$d] ?? 0.0;
    $net = $st - $pu - $eq - $tr;
    $dt  = date('Y-m-d', mktime(0,0,0,$month,$d,$year));
    $dow = $days_en[date('w', strtotime($dt))];
    $rows[$d] = compact('gy1','gy2','mo1','mo2','mi1','mi2','dk','pc','cd','ws','st','pu','eq','tr','net','dow');
    foreach (['gy1','gy2','mo1','mo2','mi1','mi2','dk','pc','cd','ws','st','pu','eq','tr','net'] as $k) {
        $totals[$k] += $$k;
    }
}

// XML helpers
function xesc(string $v): string {
    return htmlspecialchars($v, ENT_XML1, 'UTF-8');
}
function xnum(float $n, bool $zeroBlank = true): string {
    if ($zeroBlank && $n == 0) return '';
    return number_format($n, 2);
}
function xrow(int $h, string $cells): string {
    return '<Row ss:Height="' . $h . '">' . $cells . "</Row>\n";
}
function xcell(string $val, string $style, int $mergeAcross = 0, string $type = 'String'): string {
    $ma  = $mergeAcross > 0 ? ' ss:MergeAcross="' . $mergeAcross . '"' : '';
    $sid = ' ss:StyleID="' . $style . '"';
    if ($val === '') return '<Cell' . $sid . $ma . '/>';
    return '<Cell' . $sid . $ma . '><Data ss:Type="' . $type . '">' . xesc($val) . '</Data></Cell>';
}
function xnum_cell(float $n, string $style, bool $blank = true): string {
    return xcell(xnum($n, $blank), $style, 0, 'Number');
}

ob_end_clean();

// Output headers
$filename = 'SalesReport_' . date('Y_m', mktime(0,0,0,$month,1,$year)) . '.xls';
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Build XML entirely as a string to avoid any stray whitespace
$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
$xml .= '  xmlns:o="urn:schemas-microsoft-com:office:office">' . "\n";

// Styles
$xml .= '<Styles>' . "\n";
// Default
$xml .= '<Style ss:ID="s_def"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Title
$xml .= '<Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1a5276" ss:Pattern="Solid"/></Style>' . "\n";
// Month
$xml .= '<Style ss:ID="s_month"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2e86c1" ss:Pattern="Solid"/></Style>' . "\n";
// Header
$xml .= '<Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#f3f4f6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
// Date cell
$xml .= '<Style ss:ID="s_date"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Data number
$xml .= '<Style ss:ID="s_num"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Sales total (red bg)
$xml .= '<Style ss:ID="s_tot"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1" ss:Color="#991b1b"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Negative net
$xml .= '<Style ss:ID="s_neg"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Color="#dc2626"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Grand total row
$xml .= '<Style ss:ID="s_grand"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_grand_date"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '</Styles>' . "\n";

// Worksheet
$xml .= '<Worksheet ss:Name="Sales Report">' . "\n";
$xml .= '<Table ss:DefaultRowHeight="16">' . "\n";
// Column widths: Date(120) + 2×GY + 2×MRN + 2×MID + DK + POS + CreditInvoice + WS + SalesTotal + Purchase + StoreExp + Transfer + Net
$widths = [120, 72,72, 72,72, 72,72, 72, 72,82, 72, 85, 78, 78, 78, 82];
foreach ($widths as $w) { $xml .= '<Column ss:Width="' . $w . '"/>' . "\n"; }

// Row 1: Title (spans all 16 cols → MergeAcross=15)
$report_title = 'HOME K MART' . ($store_display !== '' ? ' ' . $store_display : '') . ' SALES REPORT';
$xml .= xrow(26, xcell($report_title, 's_title', 15));
// Row 2: Month label
$xml .= xrow(18, xcell($month_label, 's_month', 15));
// Row 3: Headers (single row, simplified — no MergeDown)
$hdr_cells =
    xcell('DATE',          's_hdr') .
    xcell('GY POS1',       's_hdr') .
    xcell('GY POS2',       's_hdr') .
    xcell('MRN POS1',      's_hdr') .
    xcell('MRN POS2',      's_hdr') .
    xcell('MID POS1',      's_hdr') .
    xcell('MID POS2',      's_hdr') .
    xcell('DELIVERY K',    's_hdr') .
    xcell('POS (외상)',     's_hdr') .
    xcell('거래명세서',      's_hdr') .
    xcell('WHOLE SALE',    's_hdr') .
    xcell('SALES TOTAL',   's_hdr') .
    xcell('PURCHASE',      's_hdr') .
    xcell('STORE EXP',     's_hdr') .
    xcell('TRANSFER',      's_hdr') .
    xcell('NET',           's_hdr');
$xml .= xrow(22, $hdr_cells);

// Data rows
for ($d = 1; $d <= $days; $d++) {
    $r   = $rows[$d];
    $lbl = date('M j', mktime(0,0,0,$month,$d,$year)) . ' ' . $r['dow'];
    $ns  = 's_num';
    $xml .= xrow(16,
        xcell($lbl, 's_date') .
        xcell(xnum($r['gy1']), $ns, 0, 'Number') .
        xcell(xnum($r['gy2']), $ns, 0, 'Number') .
        xcell(xnum($r['mo1']), $ns, 0, 'Number') .
        xcell(xnum($r['mo2']), $ns, 0, 'Number') .
        xcell(xnum($r['mi1']), $ns, 0, 'Number') .
        xcell(xnum($r['mi2']), $ns, 0, 'Number') .
        xcell(xnum($r['dk']),  $ns, 0, 'Number') .
        xcell(xnum($r['pc']),  $ns, 0, 'Number') .
        xcell(xnum($r['cd']),  $ns, 0, 'Number') .
        xcell(xnum($r['ws']),  $ns, 0, 'Number') .
        xcell(xnum($r['st']),  's_tot', 0, 'Number') .
        xcell(xnum($r['pu']),  $ns, 0, 'Number') .
        xcell(xnum($r['eq']),  $ns, 0, 'Number') .
        xcell(xnum($r['tr']),  $ns, 0, 'Number') .
        xcell(xnum($r['net']), $r['net'] < 0 ? 's_neg' : $ns, 0, 'Number')
    );
}

// Total row
$xml .= xrow(18,
    xcell('TOTAL', 's_grand_date') .
    xcell(xnum($totals['gy1'], false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['gy2'], false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['mo1'], false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['mo2'], false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['mi1'], false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['mi2'], false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['dk'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['pc'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['cd'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['ws'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['st'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['pu'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['eq'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['tr'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['net'], false), 's_grand', 0, 'Number')
);

$xml .= '</Table>' . "\n";
$xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
$xml .= '<PageSetup><Layout x:Orientation="Landscape"/><PageMargins x:Top="0.5" x:Bottom="0.5" x:Left="0.5" x:Right="0.5"/></PageSetup>' . "\n";
$xml .= '<FitToPage/><Print><FitWidth>1</FitWidth><FitHeight>0</FitHeight><ValidPrinterInfo/><PaperSizeIndex>9</PaperSizeIndex></Print>' . "\n";
$xml .= '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane><ActivePane>2</ActivePane>' . "\n";
$xml .= '</WorksheetOptions>' . "\n";
$xml .= '</Worksheet>' . "\n";
$xml .= '</Workbook>';

echo $xml;
