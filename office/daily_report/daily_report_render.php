<?php
// Design Ref: daily-report.design.md §2.0/§9 — SpreadsheetML(XML) 렌더링 SSOT.
// Layout Ref: '1. reference/포스코 일계표 7월 - 복사본.xlsx' 실제 셀 구조를 그대로 재현
// (인쇄영역 A1:K51, 11개 컬럼). export_daily_report.php(일일)와 export_daily_report_monthly.php(월별,
// 날짜별 시트)가 이 파일의 dr_render_sheet_rows()를 공유해 화면·일일·월별 숫자가 항상 일치하도록 한다.
require_once __DIR__ . '/../lib/daily_report_helper.php';

function dr_xe($s) { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }

function dr_xml_row($height, $cells_xml) {
    $h = $height ? " ss:Height=\"{$height}\"" : '';
    return "<Row{$h}>{$cells_xml}</Row>\n";
}
function dr_xml_cells(array $cells) {
    $out = '';
    foreach ($cells as $c) {
        $val  = $c[0] ?? '';
        $sid  = $c[1] ?? 'Default';
        $ma   = isset($c[2]) && $c[2] > 0 ? " ss:MergeAcross=\"{$c[2]}\"" : '';
        $md   = isset($c[3]) && $c[3] > 0 ? " ss:MergeDown=\"{$c[3]}\"" : '';
        $type = $c[4] ?? 'String';
        $idx  = isset($c[5]) ? " ss:Index=\"{$c[5]}\"" : '';
        if ($val === '' || $val === null) {
            $out .= "<Cell{$idx} ss:StyleID=\"{$sid}\"{$ma}{$md}/>";
        } else {
            $out .= "<Cell{$idx} ss:StyleID=\"{$sid}\"{$ma}{$md}><Data ss:Type=\"{$type}\">" . dr_xe($val) . "</Data></Cell>";
        }
    }
    return $out;
}
function dr_blank_row($n, $sid = 's_empty') {
    return dr_xml_cells(array_fill(0, $n, ['', $sid]));
}

// 참고 파일 실측: A~K 11개 컬럼이 거의 균등 폭(~10.16 엑셀 단위)
function dr_xls_columns(): string {
    $out = '';
    for ($i = 0; $i < 11; $i++) $out .= "<Column ss:AutoFitWidth=\"0\" ss:Width=\"72\"/>\n";
    return $out;
}

function dr_xls_styles(): string {
    return <<<'XML'
<Styles>
 <Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11"/></Style>
 <Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#B8D4E8" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_date_val"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#FFFF99" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_hdr_val"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0"/></Style>
 <Style ss:ID="s_profit"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#C6E0B4" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0"/></Style>
 <Style ss:ID="s_sec"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_colhd"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_data"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0;;&quot;-&quot;"/></Style>
 <Style ss:ID="s_total"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_total_amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0"/></Style>
 <Style ss:ID="s_empty"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11"/></Style>
 <Style ss:ID="s_note"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Italic="1" ss:Color="#808080"/></Style>
</Styles>
XML;
}

// 하루치 데이터를 집계해 <Column>...<Row>...</Row> XML(=<Table> 내부 전체)을 반환한다.
// 참고 파일(A1:K51)과 동일하게 전 구간을 11개 컬럼(A~K) 폭에 맞춘다.
function dr_render_sheet_rows(mysqli $conn, int $store_id, string $date, string $store_label): string {
    $pos_summary   = get_daily_pos_summary($conn, $store_id, $date);
    $credit_detail = get_daily_credit_breakdown($conn, $store_id, $date);
    $purchase      = get_daily_purchase_summary($conn, $store_id, $date);
    $ar            = get_daily_ar_summary($conn, $store_id, $date);
    $wholesale     = get_daily_wholesale_summary($conn, $store_id, $date);
    $other_exp     = get_daily_other_expense_categories($conn, $store_id, $date);

    $commission_tbl = $conn->query("SHOW TABLES LIKE 'daily_report_commission_companies'");
    $commission = ($commission_tbl && $commission_tbl->num_rows > 0)
        ? get_daily_commission_summary($conn, $store_id, $date)
        : ['rows' => [], 'total' => 0.0];

    // 상단 헤더 요약행은 점간이동을 포함해 표시하므로(카톡 참고 이미지), 순이익도 매입 전체(현금+체크+점간이동)
    // 기준으로 계산한다. 다만 하단 매입 상세(거래처별) 표는 참고 파일의 고정 그리드(현금/체크 2열)를
    // 유지하며 점간이동 전용 행은 그 표에서 제외한다 — 아래 $purchase_cc_rows 참고.
    $grand_expense = $other_exp['total_placed'];
    $grand_sales    = $pos_summary['totals']['total'] + $ar['credit_sales_total'];
    $grand_purchase = $purchase['totals']['total'];
    $grand_profit   = $grand_sales - $grand_purchase - $grand_expense;

    $ts = strtotime($date);
    $days_en = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $date_label = date('Y-m-d', $ts) . ' ' . $days_en[date('w', $ts)];

    $wholesale_rows = array_merge($wholesale['delivery_k'], $wholesale['whole_sale']);

    ob_start();
    echo dr_xls_columns();

    // ── 제목 (참고 파일: A2:K2 단일 배너 — 로고는 이미지라 텍스트로 재현하지 않음) ──
    echo dr_xml_row(6, dr_blank_row(11));
    echo dr_xml_row(24, dr_xml_cells([[strtoupper(dr_xe($store_label)) . ' DAILY REPORT', 's_title', 10]]));
    echo dr_xml_row(6, dr_blank_row(11));

    // ── 헤더 요약행 (11 물리컬럼: 날짜1 + 매출4(포스+메뉴얼2/외상거래처1/수수료1) + 매입3(현금/체크/점간이동) + 기타지출1 + 거래처수금1 + 수익1) ──
    // Layout Ref: 카톡 참고 이미지(2026-08-28) — 외상거래처는 더 이상 2열 병합하지 않고 1열만 사용,
    // 그 뒤로 밀린 만큼을 매입 그룹의 신규 3번째 열(점간이동)이 흡수해 총 물리컬럼 수(11, A~K)는 그대로 유지된다.
    echo dr_xml_row(20, dr_xml_cells([
        ['날짜', 's_hdr', 0, 1, 'String', 1],
        ['매출', 's_hdr', 3, 0, 'String', 2],
        ['매입', 's_hdr', 2, 0, 'String', 6],
        ['기타지출', 's_hdr', 0, 1, 'String', 9],
        ['거래처수금', 's_hdr', 0, 0, 'String', 10],
        ['수익', 's_hdr', 0, 1, 'String', 11],
    ]));
    echo dr_xml_row(20, dr_xml_cells([
        ['포스+메뉴얼', 's_hdr', 1, 0, 'String', 2],
        ['외상거래처', 's_hdr', 0, 0, 'String', 4],
        ['수수료 코너', 's_hdr', 0, 0, 'String', 5],
        ['현금', 's_hdr', 0, 0, 'String', 6],
        ['체크', 's_hdr', 0, 0, 'String', 7],
        ['점간이동', 's_hdr', 0, 0, 'String', 8],
        ['현금', 's_hdr', 0, 0, 'String', 10],
    ]));
    echo dr_xml_row(20, dr_xml_cells([
        [dr_xe($date_label), 's_date_val', 0, 0, 'String', 1],
        [(float)$pos_summary['totals']['total'], 's_hdr_val', 1, 0, 'Number', 2],
        [$ar['credit_sales_total'] > 0 ? (float)$ar['credit_sales_total'] : '', 's_hdr_val', 0, 0, 'Number', 4],
        [$commission['total'] > 0 ? (float)$commission['total'] : '', 's_hdr_val', 0, 0, 'Number', 5],
        [(float)$purchase['totals']['cash'], 's_hdr_val', 0, 0, 'Number', 6],
        [(float)$purchase['totals']['check'], 's_hdr_val', 0, 0, 'Number', 7],
        [$purchase['totals']['transfer'] > 0 ? (float)$purchase['totals']['transfer'] : '', 's_hdr_val', 0, 0, 'Number', 8],
        [(float)$grand_expense, 's_hdr_val', 0, 0, 'Number', 9],
        [(float)$ar['collections_total'], 's_hdr_val', 0, 0, 'Number', 10],
        [(float)$grand_profit, 's_profit', 0, 0, 'Number', 11],
    ]));
    echo dr_xml_row(8, dr_blank_row(11));

    // ── 상단 그룹: 포스매출(A-E,5) | 수수료 코너(F-H,3) | 기타지출(I-K,3) — 갭 없이 인접 ──
    echo dr_xml_row(16, dr_xml_cells([
        ['포스매출', 's_sec', 4], ['수수료 코너', 's_sec', 2], ['기타지출', 's_sec', 2],
    ]));
    echo dr_xml_row(14, dr_xml_cells([
        ['포스', 's_colhd'], ['현금', 's_colhd'], ['크레딧+할인', 's_colhd'], ['도매', 's_colhd'], ['합계', 's_colhd'],
        ['업체명', 's_colhd', 1], ['매출', 's_colhd'],
        ['사용내역', 's_colhd', 1], ['사용금액', 's_colhd'],
    ]));

    $categories = array_values($other_exp['categories']);
    $cat_keys   = array_keys($other_exp['categories']);
    $top_rows   = max(count($pos_summary['rows']), count($categories), count($commission['rows']));
    for ($i = 0; $i < $top_rows; $i++) {
        $pos = $pos_summary['rows'][$i] ?? null;
        $cat_key = $cat_keys[$i] ?? null;
        $cat_amt = $cat_key !== null ? $other_exp['by_category'][$cat_key] : null;
        $com = $commission['rows'][$i] ?? null;
        echo dr_xml_row(13, dr_xml_cells([
            [$pos ? $pos['label'] : '', 's_data'],
            [$pos ? (float)$pos['cash'] : '', $pos ? 's_amt' : 's_data', 0, 0, 'Number'],
            [$pos ? (float)$pos['credit'] : '', $pos ? 's_amt' : 's_data', 0, 0, 'Number'],
            [$pos && $pos['delivery_slip'] > 0 ? (float)$pos['delivery_slip'] : '', 's_amt', 0, 0, 'Number'],
            [$pos ? (float)$pos['total'] : '', $pos ? 's_amt' : 's_data', 0, 0, 'Number'],
            [$com ? $com['supplier_name'] : '', 's_data', 1],
            [$com && $com['amount'] > 0 ? (float)$com['amount'] : '', $com ? 's_amt' : 's_data', 0, 0, 'Number'],
            [$cat_key !== null ? $categories[$i] : '', 's_data', 1],
            [$cat_key !== null && $cat_amt > 0 ? (float)$cat_amt : '', 's_amt', 0, 0, 'Number'],
        ]));
    }
    echo dr_xml_row(14, dr_xml_cells([
        ['합계', 's_total'],
        [(float)$pos_summary['totals']['cash'],           's_total_amt', 0, 0, 'Number'],
        [(float)$pos_summary['totals']['credit'],         's_total_amt', 0, 0, 'Number'],
        [(float)$pos_summary['totals']['delivery_slip'],  's_total_amt', 0, 0, 'Number'],
        [(float)$pos_summary['totals']['total'],          's_total_amt', 0, 0, 'Number'],
        ['합계', 's_total', 1], [(float)$commission['total'], 's_total_amt', 0, 0, 'Number'],
        ['합계', 's_total', 1], [(float)$other_exp['total_placed'], 's_total_amt', 0, 0, 'Number'],
    ]));
    if ($other_exp['unplaced_count'] > 0) {
        echo dr_xml_row(12, dr_xml_cells([
            ['* 미분류 ' . $other_exp['unplaced_count'] . '건은 합계에서 제외됨', 's_note', 10],
        ]));
    }
    echo dr_xml_row(8, dr_blank_row(11));

    // ── 중단+하단 그룹 (Layout Ref: 참고 파일은 외상판매/도매판매를 크레딧/외상수금 바로 아래,
    //    매입 표와 같은 높이(G~K열)에 붙여둔다 — 좌측(매입 A-F)과 우측(크레딧→외상수금→외상판매→도매판매
    //    G-K 세로 스택)을 별도 스트림으로 만들어 행 단위로 결합한다) ──
    $credit_buckets = ['BDO', 'GCASH', 'MAYA', 'QR'];

    // 좌측 스트림: 매입 (A-F, 6 논리컬럼: 거래처명(ma2)/현금매입/체크매입/합계)
    $left_rows = [];
    $left_rows[] = [['매입', 's_sec', 5]];
    $left_rows[] = [['거래처명', 's_colhd', 2], ['현금매입', 's_colhd'], ['체크매입', 's_colhd'], ['합계', 's_colhd']];
    // Layout Ref: 참고 파일은 매입 표에 실제 거래처 외 여유 행을 두어 표 전체가 약 20행
    // 점간이동(transfer)만 있는 행은 이 고정 서식(현금/체크 2열)에는 표시하지 않는다.
    $purchase_cc_rows = array_values(array_filter($purchase['rows'], fn($r) => $r['cash'] > 0 || $r['check'] > 0));
    $PURCHASE_MIN_ROWS = 20;
    $purchase_row_count = max(count($purchase_cc_rows), $PURCHASE_MIN_ROWS);
    for ($i = 0; $i < $purchase_row_count; $i++) {
        $p = $purchase_cc_rows[$i] ?? null;
        $p_total = $p ? (float)$p['cash'] + (float)$p['check'] : '';
        $left_rows[] = [
            [$p ? $p['supplier'] : '', 's_data', 2],
            [$p && $p['cash'] > 0 ? (float)$p['cash'] : '', 's_amt', 0, 0, 'Number'],
            [$p && $p['check'] > 0 ? (float)$p['check'] : '', 's_amt', 0, 0, 'Number'],
            [$p_total, $p ? 's_amt' : 's_data', 0, 0, 'Number'],
        ];
    }
    $left_rows[] = [
        ['합계', 's_total', 2], [(float)$purchase['totals']['cash'], 's_total_amt', 0, 0, 'Number'],
        [(float)$purchase['totals']['check'], 's_total_amt', 0, 0, 'Number'],
        [(float)$purchase['totals']['cash'] + (float)$purchase['totals']['check'], 's_total_amt', 0, 0, 'Number'],
    ];

    // 우측 스트림: 크레딧+외상수금 → 갭 → 외상판매+도매판매 (G-K, 5 논리컬럼)
    $right_rows = [];
    $right_rows[] = [['크레딧(CARD, E-MONEY)', 's_sec', 1], ['', 's_empty'], ['외상수금', 's_sec', 1]];
    $right_rows[] = [['거래처명', 's_colhd'], ['금액', 's_colhd'], ['', 's_empty'], ['거래처명', 's_colhd'], ['금액', 's_colhd']];
    $credit_row_count = max(count($credit_buckets), count($ar['collections']));
    for ($i = 0; $i < $credit_row_count; $i++) {
        $bucket = $credit_buckets[$i] ?? null;
        $col = $ar['collections'][$i] ?? null;
        $right_rows[] = [
            [$bucket ?? '', 's_data'],
            [$bucket && $credit_detail[$bucket] > 0 ? (float)$credit_detail[$bucket] : '', 's_amt', 0, 0, 'Number'],
            ['', 's_empty'],
            [$col ? $col['customer_name'] : '', 's_data'],
            [$col ? (float)$col['amount'] : '', $col ? 's_amt' : 's_data', 0, 0, 'Number'],
        ];
    }
    $right_rows[] = [
        ['합계', 's_total'], [(float)$credit_detail['total'], 's_total_amt', 0, 0, 'Number'],
        ['', 's_empty'],
        ['합계', 's_total'], [(float)$ar['collections_total'], 's_total_amt', 0, 0, 'Number'],
    ];
    $right_rows[] = [['', 's_empty', 4]]; // 갭 1행 (5칸)
    $right_rows[] = [['외상 판매', 's_sec', 2], ['도매 판매(거래명세서)', 's_sec', 1]];
    $right_rows[] = [['거래처명', 's_colhd'], ['포스등록', 's_colhd'], ['거래명세서', 's_colhd'], ['거래처명', 's_colhd'], ['금액', 's_colhd']];
    $bot_row_count = max(count($ar['credit_sales']), count($wholesale_rows), 1);
    for ($i = 0; $i < $bot_row_count; $i++) {
        $cs = $ar['credit_sales'][$i] ?? null;
        $ws = $wholesale_rows[$i] ?? null;
        $right_rows[] = [
            [$cs ? $cs['customer_name'] : '', 's_data'],
            ['', 's_data'],
            [$cs ? (float)$cs['amount'] : '', $cs ? 's_amt' : 's_data', 0, 0, 'Number'],
            [$ws ? $ws['customer'] : '', 's_data'],
            [$ws ? (float)$ws['amount'] : '', $ws ? 's_amt' : 's_data', 0, 0, 'Number'],
        ];
    }
    $bot_total_row = [
        ['합계', 's_total', 1], [(float)$ar['credit_sales_total'], 's_total_amt', 0, 0, 'Number'],
        ['합계', 's_total'], [(float)$wholesale['total'], 's_total_amt', 0, 0, 'Number'],
    ];

    // 좌/우 스트림을 행 단위로 결합 (짧은 쪽은 테두리 있는 빈 셀로 패딩 — 참고 파일처럼
    // 빈 구간도 표 테두리가 계속 이어지도록 s_empty(테두리 없음) 대신 s_data(테두리 있음) 사용)
    // 요청 사항: 외상판매/도매판매 합계행은 매입 표의 합계행과 같은 줄(마지막 줄)에 맞춘다 —
    // right_rows를 left_rows 길이-1 까지 공백으로 채운 뒤 합계행을 마지막에 배치.
    $right_blank = array_fill(0, 5, ['', 's_data']);  // G-K(5칸, 개별 셀 — 각 칸 테두리 표시)
    while (count($right_rows) < count($left_rows) - 1) {
        $right_rows[] = $right_blank;
    }
    $right_rows[] = $bot_total_row;

    $zip_rows = max(count($left_rows), count($right_rows));
    $left_blank  = array_fill(0, 6, ['', 's_data']);  // A-F(6칸, 개별 셀)
    for ($i = 0; $i < $zip_rows; $i++) {
        $l = $left_rows[$i]  ?? $left_blank;
        $r = $right_rows[$i] ?? $right_blank;
        echo dr_xml_row($i < 2 ? 15 : 13, dr_xml_cells(array_merge($l, $r)));
    }

    return ob_get_clean();
}
