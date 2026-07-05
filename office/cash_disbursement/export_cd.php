<?php
// Design Ref: §7.2 — Cash Disbursement Excel SpreadsheetML
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['data'])) {
    http_response_code(400); exit('No data');
}

$payload  = json_decode($_POST['data'], true);
$date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date'] ?? '') ? $payload['date'] : date('Y-m-d');
$sections = $payload['sections'] ?? ['korean'=>[],'local'=>[],'fixed'=>[],'maintenance'=>[],'others'=>[]];

$ts    = strtotime($date_str);
$year  = date('Y', $ts);
$month = date('n', $ts);
$day   = date('j', $ts);

function xe($s) { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }
function fa($n) { return number_format((float)($n ?? 0), 2); }
function xrow($h, $cells) { return '<Row ss:Height="'.$h.'">'.$cells."</Row>\n"; }
function xcell($v, $sid, $ma=0, $type='String') {
    $m = $ma > 0 ? ' ss:MergeAcross="'.$ma.'"' : '';
    $s = ' ss:StyleID="'.$sid.'"';
    if ($v === '' || $v === null) return '<Cell'.$s.$m.'/>';
    return '<Cell'.$s.$m.'><Data ss:Type="'.$type.'">'.xe($v).'</Data></Cell>';
}

$sum = fn($sec) => array_sum(array_column($sections[$sec] ?? [], 'amount'));
$k = $sum('korean'); $l = $sum('local');
$f = $sum('fixed');  $m = $sum('maintenance'); $o = $sum('others');
$supplier_total = $k + $l;
$other_exp      = $f + $m + $o;
$grand_total    = $supplier_total + $other_exp;

$section_labels = [
    'korean'      => '1. KOREAN',
    'local'       => '2. LOCAL',
    'fixed'       => '3. FIXED EXPENSES',
    'maintenance' => '4. MAINTENANCE',
    'others'      => '5. OTHERS',
];

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="CashDisbursement_'.$date_str.'.xls"');
header('Pragma: no-cache');

$xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
$xml .= '<?mso-application progid="Excel.Sheet"?>'."\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'."\n";
$xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'."\n";
$xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"'."\n";
$xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office">'."\n";

$xml .= '<Styles>'."\n";
$xml .= '<Style ss:ID="s_def"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/></Style>'."\n";
$xml .= '<Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#B8D4E8" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_sub"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#C6D9F1" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_meta"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="7" ss:Bold="1"/><Interior ss:Color="#f3f4f6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="7" ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_sec"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#FFC000" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_sec_amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1" ss:Color="#991b1b"/><Interior ss:Color="#FFC000" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_data"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_data_ctr"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_data_rgt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#cccccc"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_total"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#FFFF99" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'."\n";
$xml .= '<Style ss:ID="s_grand"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#FFC000" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'."\n";
$xml .= '</Styles>'."\n";

$xml .= '<Worksheet ss:Name="Cash Disbursement">'."\n";
$xml .= '<Table ss:DefaultRowHeight="14">'."\n";
// Columns: NO(40) OR/SI(90) DATE(80) COMPANY(160) DETAILS(160) AMOUNT(90)
foreach ([40,90,80,160,160,90] as $w) {
    $xml .= '<Column ss:Width="'.$w.'"/>'."\n";
}

// Title
$xml .= xrow(20, xcell('Cash Disbursement', 's_title', 5));
$xml .= xrow(14, xcell('(Home Plus Sunset Corporation)', 's_sub', 5));
// Meta
$xml .= xrow(13,
    xcell('YEAR: '.$year,  's_meta', 1) .
    xcell('MONTH: '.$month,'s_meta', 1) .
    xcell('DAY: '.$day,    's_meta') .
    xcell('PREPARED: LIZA   APPROVED: SIR MIN', 's_meta')
);
// Headers
$xml .= xrow(13,
    xcell('NO.',                  's_hdr') .
    xcell('OR/SI NO.',            's_hdr') .
    xcell('DATE OF PURCHASE',     's_hdr') .
    xcell('COMPANY (SUPPLIER NAME)','s_hdr') .
    xcell('DETAILS',              's_hdr') .
    xcell('AMOUNT',               's_hdr')
);

$overall_no = 0;
foreach ($section_labels as $sec_key => $sec_label) {
    $rows      = $sections[$sec_key] ?? [];
    $sec_total = array_sum(array_column($rows, 'amount'));
    $xml .= xrow(13,
        xcell($sec_label, 's_sec', 4) .
        xcell(fa($sec_total), 's_sec_amt')
    );
    if (empty($rows)) {
        $xml .= xrow(12,
            xcell('', 's_data_ctr') .
            xcell('— no entries —', 's_data', 4) .
            xcell('', 's_data_rgt')
        );
    } else {
        foreach ($rows as $row) {
            $overall_no++;
            $xml .= xrow(12,
                xcell((string)$overall_no, 's_data_ctr') .
                xcell($row['or_si_no'] ?? '', 's_data') .
                xcell($row['date'] ?? '', 's_data_ctr') .
                xcell(strtoupper($row['supplier'] ?? ''), 's_data') .
                xcell(strtoupper($row['details'] ?? ''), 's_data') .
                xcell(fa($row['amount'] ?? 0), 's_data_rgt')
            );
        }
    }
}

// Totals
$xml .= xrow(14, xcell('TOTAL:', 's_total', 4) . xcell(fa($grand_total), 's_total'));
$xml .= xrow(13, xcell('SUPPLIER (KOREAN + LOCAL):', 's_total', 4) . xcell(fa($supplier_total), 's_total'));
$xml .= xrow(14, xcell('OTHER EXPENSES (FIXED + MAINTENANCE + OTHERS):', 's_grand', 4) . xcell(fa($other_exp), 's_grand'));

$xml .= '</Table>'."\n";
$xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'."\n";
$xml .= '<PageSetup><Layout x:Orientation="Portrait"/><PageMargins x:Top="0.5" x:Bottom="0.5" x:Left="0.5" x:Right="0.5"/></PageSetup>'."\n";
$xml .= '<FitToPage/><Print><FitWidth>1</FitWidth><FitHeight>0</FitHeight><ValidPrinterInfo/><PaperSizeIndex>9</PaperSizeIndex></Print>'."\n";
$xml .= '</WorksheetOptions>'."\n";
$xml .= '</Worksheet>'."\n";
$xml .= '</Workbook>';

echo $xml;
