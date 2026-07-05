<?php
// Design Ref: §6.2 — Monthly report matching image format
$page_title      = 'Sales Report';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));

// Month navigation
$prev_ts  = mktime(0,0,0,$month-1,1,$year);
$next_ts  = mktime(0,0,0,$month+1,1,$year);
$prev_y   = (int)date('Y',$prev_ts); $prev_m = (int)date('n',$prev_ts);
$next_y   = (int)date('Y',$next_ts); $next_m = (int)date('n',$next_ts);
$days     = (int)date('t', mktime(0,0,0,$month,1,$year));
$today    = date('Y-m-d');
$month_label = date('F Y', mktime(0,0,0,$month,1,$year));

// 리포트 제목에 표시할 현재 지점명 (header.php에서 설정된 전역 변수 사용)
// 예) "SUNSET (선셋점)" → 브랜딩 형식에 맞춰 앞의 영문 부분만 대문자로 표시
$store_display = $_office_store_name ?? '';
if (($_p = strpos($store_display, ' (')) !== false) {
    $store_display = substr($store_display, 0, $_p);
}
$store_display = strtoupper(trim($store_display));

$conn = get_db_connection();

// 1) 일별 POS 매출 — 셀 마감액(expected_cash, POS Z리딩) 기준. daily_entry DAY TOTAL과 동일 기준.
//    (기존 sales_daily.{shift}_pos{n} = 시제 재계산액(total_amount)은 도매(wholesale)를 제외하고 저장되어
//     daily_entry 화면의 실제 마감 리딩과 어긋났음 → 원인 규명 후 expected_cash 기준으로 통일)
$stmt = $conn->prepare(
    "SELECT DAY(sale_date) AS d, shift, pos_no, expected_cash
     FROM sales_pos_reconciliation
     WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?"
);
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
$stmt = $conn->prepare(
    "SELECT DAY(sale_date) AS d, delivery_k
     FROM sales_daily WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $d = (int)$r['d'];
    if (!isset($sales_by_day[$d])) $sales_by_day[$d] = [];
    $sales_by_day[$d]['delivery_k'] = $r['delivery_k'];
}
$stmt->close();

// 1c) 일별 §4/§5 집계 — POS 셀에 실제 pick된 금액 기준 (daily_entry DAY TOTAL의 cellManualDR과 동일 소스)
//     source_type='credit'      → POS 외상: 미수금 성격(정보용 컬럼, SALES TOTAL 제외 · daily_entry §4 취급과 동일)
//     source_type='credit_doc'  → 거래명세서: SALES TOTAL 에 포함
//     source_type='wholesale'   → Whole Sale: 셀에 pick된 금액만 집계 (wholesale_sales 테이블 전체가 아님 —
//                                 pick 안 된 미반영 매출/중복 pick 오류가 daily_entry와의 불일치 원인이었음)
$pos_credit_by_day = []; // §4 POS 외상 (credit)
$credit_doc_by_day = []; // §4 거래명세서 (credit_doc)
$ws_by_day         = []; // §5 Whole Sale (wholesale, 셀 pick 기준)
$pick_tbl = $conn->query("SHOW TABLES LIKE 'sales_pos_wholesale_pick'");
if ($pick_tbl && $pick_tbl->num_rows > 0) {
    $stmt = $conn->prepare(
        "SELECT DAY(sale_date) AS d, source_type, SUM(amount) AS total
         FROM sales_pos_wholesale_pick
         WHERE store_id=? AND source_type IN ('credit','credit_doc','wholesale')
           AND YEAR(sale_date)=? AND MONTH(sale_date)=?
         GROUP BY DAY(sale_date), source_type"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $d = (int)$r['d'];
        if ($r['source_type'] === 'credit') {
            $pos_credit_by_day[$d] = (float)$r['total'];
        } elseif ($r['source_type'] === 'credit_doc') {
            $credit_doc_by_day[$d] = (float)$r['total'];
        } else { // wholesale
            $ws_by_day[$d] = (float)$r['total'];
        }
    }
    $stmt->close();
}

// 2) 일별 들품대금 — 현금만 (수표는 2b에서 check_issued_date 기준으로 별도 처리)
$stmt = $conn->prepare(
    "SELECT DAY(payment_date) AS d, SUM(amount) AS total
     FROM office_product_purchases
     WHERE store_id=? AND payment_type='cash'
       AND YEAR(payment_date)=? AND MONTH(payment_date)=?
     GROUP BY DAY(payment_date)"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$purchase_by_day = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $purchase_by_day[(int)$r['d']] = (float)$r['total'];
}
$stmt->close();

// 2b) 수표 기준: check_issued_date 기준 합산
$stmt = $conn->prepare(
    "SELECT DAY(check_issued_date) AS d, SUM(amount) AS total
     FROM office_product_purchases
     WHERE store_id=? AND payment_type='check'
       AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=?
     GROUP BY DAY(check_issued_date)"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $purchase_by_day[(int)$r['d']] = ($purchase_by_day[(int)$r['d']] ?? 0) + (float)$r['total'];
}
$stmt->close();

// 3) 일별 점지출(비품구매) — e_ 아이템
$stmt = $conn->prepare(
    "SELECT DAY(payment_date) AS d, SUM(amount) AS total
     FROM office_equipment_purchases
     WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?
     GROUP BY DAY(payment_date)"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$equip_by_day = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $equip_by_day[(int)$r['d']] = (float)$r['total'];
}
$stmt->close();

// 2c+3b) ER 저장 상태에서 r_(영수증) 아이템 추출 → PURCHASE / STORE EXP 합산
// er_saved_state JSON을 직접 읽어 마이그레이션 의존성 없이 처리
$er_tbl = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if ($er_tbl && $er_tbl->num_rows > 0) {
    $er_q = $conn->prepare(
        "SELECT DAY(save_date) AS d, state_json
         FROM er_saved_state
         WHERE store_id=? AND YEAR(save_date)=? AND MONTH(save_date)=?"
    );
    if ($er_q) {
        $er_q->bind_param('iii', $store_id, $year, $month);
        $er_q->execute();
        $er_purchase_secs = ['selling', 'check_sup'];
        $er_expense_secs  = ['not_selling', 'other_exp_check', 'other_exp_cash'];
        foreach ($er_q->get_result()->fetch_all(MYSQLI_ASSOC) as $er_row) {
            $d     = (int)$er_row['d'];
            $state = json_decode($er_row['state_json'], true);
            if (!$state || !isset($state['sections'])) continue;
            foreach ($state['sections'] as $sec_name => $rows) {
                $is_pu  = in_array($sec_name, $er_purchase_secs);
                $is_exp = in_array($sec_name, $er_expense_secs);
                if (!$is_pu && !$is_exp) continue;
                foreach ($rows as $row) {
                    // r_ 아이템만 (p_/pc_/e_ 는 purchase 테이블 쿼리에서 이미 처리)
                    if (strncmp((string)($row['item_id'] ?? ''), 'r_', 2) !== 0) continue;
                    $amt = (float)($row['amount'] ?? 0);
                    if ($amt <= 0) continue;
                    if ($is_pu)  $purchase_by_day[$d] = ($purchase_by_day[$d] ?? 0) + $amt;
                    else         $equip_by_day[$d]    = ($equip_by_day[$d]    ?? 0) + $amt;
                }
            }
        }
        $er_q->close();
    }
}

// 4a) 일별 재고이동 IN — from sales_transfers (office input, direction='in')
$stmt = $conn->prepare(
    "SELECT DAY(transfer_date) AS d, SUM(amount) AS total
     FROM sales_transfers
     WHERE store_id=? AND direction='in'
       AND YEAR(transfer_date)=? AND MONTH(transfer_date)=?
     GROUP BY DAY(transfer_date)"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$tr_in_day = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $tr_in_day[(int)$r['d']] = (float)$r['total'];
}
$stmt->close();

// 4b) 일별 재고이동 OUT — from admin's store_transfers (from_store_id = this store)
$stmt = $conn->prepare(
    "SELECT DAY(transfer_date) AS d, SUM(final_amount) AS total
     FROM store_transfers
     WHERE from_store_id=? AND status != 'cancelled'
       AND YEAR(transfer_date)=? AND MONTH(transfer_date)=?
     GROUP BY DAY(transfer_date)"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$tr_out_day = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $tr_out_day[(int)$r['d']] = (float)$r['total'];
}
$stmt->close();

$transfer_by_day = [];
$all_days = array_unique(array_merge(array_keys($tr_in_day), array_keys($tr_out_day)));
foreach ($all_days as $d) {
    $transfer_by_day[$d] = ($tr_in_day[$d] ?? 0) - ($tr_out_day[$d] ?? 0);
}
$conn->close();

// Build rows + totals
$col_totals = array_fill_keys([
    'gy_pos1','gy_pos2','morning_pos1','morning_pos2','mid_pos1','mid_pos2',
    'delivery_k','pos_credit','credit_doc','whole_sale','sales_total','purchase','equip','transfer','net'
], 0.0);

$rows = [];
for ($d = 1; $d <= $days; $d++) {
    $s  = $sales_by_day[$d] ?? [];
    $gy1= (float)($s['gy_pos1']??0);
    $gy2= (float)($s['gy_pos2']??0);
    $mo1= (float)($s['morning_pos1']??0);
    $mo2= (float)($s['morning_pos2']??0);
    $mi1= (float)($s['mid_pos1']??0);
    $mi2= (float)($s['mid_pos2']??0);
    $dk = (float)($s['delivery_k']??0);
    $pc = $pos_credit_by_day[$d] ?? 0.0; // §4 POS 외상 (정보용 · SALES TOTAL 제외)
    $cd = $credit_doc_by_day[$d] ?? 0.0; // §4 거래명세서 (SALES TOTAL 포함)
    $ws = $ws_by_day[$d] ?? 0.0; // §5 Whole Sale — POS 셀에 pick된 금액 합계 (daily_entry DAY TOTAL과 동일 기준)
    $st = $gy1+$gy2+$mo1+$mo2+$mi1+$mi2+$dk+$cd+$ws; // 거래명세서 포함, POS 외상 제외
    $pu = $purchase_by_day[$d] ?? 0.0;
    $eq = $equip_by_day[$d]    ?? 0.0;
    $tr = $transfer_by_day[$d] ?? 0.0;
    $net= $st - $pu - $eq - $tr;
    $rows[$d] = compact('gy1','gy2','mo1','mo2','mi1','mi2','dk','pc','cd','ws','st','pu','eq','tr','net');
    // accumulate
    $col_totals['gy_pos1']    += $gy1; $col_totals['gy_pos2']     += $gy2;
    $col_totals['morning_pos1']+=$mo1; $col_totals['morning_pos2']+=$mo2;
    $col_totals['mid_pos1']   += $mi1; $col_totals['mid_pos2']    += $mi2;
    $col_totals['delivery_k'] += $dk;  $col_totals['pos_credit']  += $pc;
    $col_totals['credit_doc'] += $cd;  $col_totals['whole_sale']  += $ws;
    $col_totals['sales_total']+= $st;  $col_totals['purchase']    += $pu;
    $col_totals['equip']      += $eq;  $col_totals['transfer']    += $tr;
    $col_totals['net']        += $net;
}

function fmtC($n) {
    if ($n == 0) return '';
    return number_format((float)$n, 2);
}
function fmtT($n) { return number_format((float)$n, 2); }
function fmtPct($n) { return number_format((float)$n, 1) . '%'; }

$days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

// Summary statistics
$retail_total    = $col_totals['gy_pos1'] + $col_totals['gy_pos2']
                 + $col_totals['morning_pos1'] + $col_totals['morning_pos2']
                 + $col_totals['mid_pos1'] + $col_totals['mid_pos2'];
$wholesale_total = $col_totals['delivery_k'] + $col_totals['credit_doc'] + $col_totals['whole_sale'];
$sales_total_s   = $col_totals['sales_total'];
$days_with_sales = count(array_filter($rows, fn($r) => $r['st'] > 0));

$avg_daily        = $days_with_sales > 0 ? $sales_total_s / $days_with_sales : 0;
$avg_retail       = $days_with_sales > 0 ? $retail_total    / $days_with_sales : 0;
$avg_wholesale    = $days_with_sales > 0 ? $wholesale_total / $days_with_sales : 0;
$purchase_ratio   = $sales_total_s > 0 ? ($col_totals['purchase'] + $col_totals['transfer']) / $sales_total_s * 100 : 0;
$expense_ratio    = $sales_total_s > 0 ? $col_totals['equip']    / $sales_total_s * 100 : 0;
$net_margin       = $sales_total_s > 0 ? $col_totals['net']       / $sales_total_s * 100 : 0;
$retail_pct       = $sales_total_s > 0 ? $retail_total    / $sales_total_s * 100 : 0;
$wholesale_pct    = $sales_total_s > 0 ? $wholesale_total / $sales_total_s * 100 : 0;
$delivery_k_pct   = $sales_total_s > 0 ? $col_totals['delivery_k']  / $sales_total_s * 100 : 0;
$credit_doc_pct   = $sales_total_s > 0 ? $col_totals['credit_doc']  / $sales_total_s * 100 : 0;
$whole_sale_pct   = $sales_total_s > 0 ? $col_totals['whole_sale']  / $sales_total_s * 100 : 0;
?>

<style>
.report-table { font-size: 11px; border-collapse: collapse; width: 100%; }
.report-table th, .report-table td {
    border: 1px solid #ccc; padding: 3px 6px; white-space: nowrap;
    text-align: right; vertical-align: middle;
}
.report-table .col-date { text-align: left; min-width: 110px; }
.report-table thead th { background: #f3f4f6; font-weight: 600; text-align: center; font-size: 10px; }
.report-table .col-total { background: #fee2e2; font-weight: bold; color: #991b1b; }
.report-table .row-sum td { background: #fee2e2; font-weight: bold; }
.report-table .row-avg td { background: #eff6ff; color: #1e40af; font-size: 10px; }
.report-table .row-avg .col-date { font-style: italic; }
.report-table .row-data:hover td { background: #f9fafb; }
.report-table td.zero { color: #d1d5db; }
</style>

<div class="mb-4 flex items-center justify-between flex-wrap gap-2">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-chart-bar mr-2 text-green-600"></i>HOME K MART <?php echo htmlspecialchars($store_display); ?> SALES REPORT
  </h2>
  <div class="flex items-center gap-2">
    <a href="monthly_report.php?year=<?php echo $prev_y; ?>&month=<?php echo $prev_m; ?>"
       class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50">
      <i class="fa-solid fa-chevron-left"></i>
    </a>
    <span class="px-4 py-1.5 rounded-lg bg-gray-50 border border-gray-200 text-sm font-medium text-gray-800 min-w-32 text-center">
      <?php echo $month_label; ?>
    </span>
    <a href="monthly_report.php?year=<?php echo $next_y; ?>&month=<?php echo $next_m; ?>"
       class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50 <?php echo ($next_y>date('Y')||($next_y==date('Y')&&$next_m>date('n')))?'opacity-40 pointer-events-none':''; ?>">
      <i class="fa-solid fa-chevron-right"></i>
    </a>
    <a href="export_monthly.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>"
       target="_blank" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium">
      <i class="fa-solid fa-file-excel mr-1"></i>Excel
    </a>
  </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
<table class="report-table">
  <thead>
    <tr>
      <th class="col-date" rowspan="2">DATE</th>
      <th colspan="2">GY<br><span style="font-weight:normal;font-size:9px;">12:00AM-8:00AM</span></th>
      <th colspan="2">MORNING<br><span style="font-weight:normal;font-size:9px;">8:00AM-5:00PM</span></th>
      <th colspan="2">MID<br><span style="font-weight:normal;font-size:9px;">5:00PM-12:00AM</span></th>
      <th>DELIVERY K</th>
      <th>POS<br><span style="font-weight:normal;font-size:9px;">외상</span></th>
      <th>거래명세서<br><span style="font-weight:normal;font-size:9px;">Credit Invoice</span></th>
      <th>WHOLE SALE</th>
      <th class="col-total">SALES TOTAL</th>
      <th>PURCHASE<br>(매입)</th>
      <th>STORE EXP<br>(점지출)</th>
      <th>TRANSFER<br>(재고이동)</th>
      <th>NET<br>(매출-지출)</th>
    </tr>
    <tr>
      <th>POS 1</th><th>POS 2</th>
      <th>POS 1</th><th>POS 2</th>
      <th>POS 1</th><th>POS 2</th>
      <th colspan="9"></th>
    </tr>
  </thead>
  <tbody>
  <?php for ($d = 1; $d <= $days; $d++):
    $r  = $rows[$d];
    $dt = date('Y-m-d', mktime(0,0,0,$month,$d,$year));
    $dow= $days_en[date('w', strtotime($dt))];
    $is_today = ($dt === $today);
    $has_data = $r['st'] > 0;
  ?>
  <tr class="row-data <?php echo $is_today ? 'bg-blue-50' : ''; ?>">
    <td class="col-date text-left <?php echo $is_today ? 'font-bold text-blue-700' : 'text-gray-600'; ?>">
      <?php echo date('M j', strtotime($dt)); ?> <?php echo $dow; ?>
      <?php if ($is_today) echo ' <span class="text-xs text-blue-500">●</span>'; ?>
    </td>
    <td class="<?php echo !$r['gy1'] ? 'zero':'' ?>"><?php echo fmtC($r['gy1']); ?></td>
    <td class="<?php echo !$r['gy2'] ? 'zero':'' ?>"><?php echo fmtC($r['gy2']); ?></td>
    <td class="<?php echo !$r['mo1'] ? 'zero':'' ?>"><?php echo fmtC($r['mo1']); ?></td>
    <td class="<?php echo !$r['mo2'] ? 'zero':'' ?>"><?php echo fmtC($r['mo2']); ?></td>
    <td class="<?php echo !$r['mi1'] ? 'zero':'' ?>"><?php echo fmtC($r['mi1']); ?></td>
    <td class="<?php echo !$r['mi2'] ? 'zero':'' ?>"><?php echo fmtC($r['mi2']); ?></td>
    <td class="<?php echo !$r['dk'] ? 'zero':'' ?>"><?php echo fmtC($r['dk']); ?></td>
    <td class="<?php echo !$r['pc'] ? 'zero':'' ?>"><?php echo fmtC($r['pc']); ?></td>
    <td class="<?php echo !$r['cd'] ? 'zero':'' ?>"><?php echo fmtC($r['cd']); ?></td>
    <td class="<?php echo !$r['ws'] ? 'zero':'' ?>"><?php echo fmtC($r['ws']); ?></td>
    <td class="col-total <?php echo !$r['st'] ? 'zero':'' ?>"><?php echo fmtC($r['st']); ?></td>
    <td class="<?php echo !$r['pu'] ? 'zero':'' ?>"><?php echo fmtC($r['pu']); ?></td>
    <td class="<?php echo !$r['eq'] ? 'zero':'' ?>"><?php echo fmtC($r['eq']); ?></td>
    <td class="<?php echo !$r['tr'] ? 'zero':'' ?>"><?php echo fmtC($r['tr']); ?></td>
    <td class="<?php echo $r['net']<0 ? 'text-red-600 font-medium' : (!$r['net'] ? 'zero' : '') ?>"><?php echo fmtC($r['net']); ?></td>
  </tr>
  <?php endfor; ?>
  <!-- Total Row -->
  <tr class="row-sum">
    <td class="col-date text-left">TOTAL</td>
    <td><?php echo fmtT($col_totals['gy_pos1']); ?></td>
    <td><?php echo fmtT($col_totals['gy_pos2']); ?></td>
    <td><?php echo fmtT($col_totals['morning_pos1']); ?></td>
    <td><?php echo fmtT($col_totals['morning_pos2']); ?></td>
    <td><?php echo fmtT($col_totals['mid_pos1']); ?></td>
    <td><?php echo fmtT($col_totals['mid_pos2']); ?></td>
    <td><?php echo fmtT($col_totals['delivery_k']); ?></td>
    <td><?php echo fmtT($col_totals['pos_credit']); ?></td>
    <td><?php echo fmtT($col_totals['credit_doc']); ?></td>
    <td><?php echo fmtT($col_totals['whole_sale']); ?></td>
    <td class="col-total"><?php echo fmtT($col_totals['sales_total']); ?></td>
    <td><?php echo fmtT($col_totals['purchase']); ?></td>
    <td><?php echo fmtT($col_totals['equip']); ?></td>
    <td><?php echo fmtT($col_totals['transfer']); ?></td>
    <td><?php echo fmtT($col_totals['net']); ?></td>
  </tr>
  <!-- Daily Average Row -->
  <?php $div = $days_with_sales > 0 ? $days_with_sales : 1; ?>
  <tr class="row-avg">
    <td class="col-date text-left">AVG / DAY<br><span style="color:#93c5fd;font-size:9px;"><?php echo $days_with_sales; ?>영업일</span></td>
    <td><?php echo fmtT($col_totals['gy_pos1']/$div); ?></td>
    <td><?php echo fmtT($col_totals['gy_pos2']/$div); ?></td>
    <td><?php echo fmtT($col_totals['morning_pos1']/$div); ?></td>
    <td><?php echo fmtT($col_totals['morning_pos2']/$div); ?></td>
    <td><?php echo fmtT($col_totals['mid_pos1']/$div); ?></td>
    <td><?php echo fmtT($col_totals['mid_pos2']/$div); ?></td>
    <td><?php echo fmtT($col_totals['delivery_k']/$div); ?></td>
    <td><?php echo fmtT($col_totals['pos_credit']/$div); ?></td>
    <td><?php echo fmtT($col_totals['credit_doc']/$div); ?></td>
    <td><?php echo fmtT($col_totals['whole_sale']/$div); ?></td>
    <td class="col-total" style="background:#dbeafe;color:#1e3a8a;"><?php echo fmtT($col_totals['sales_total']/$div); ?></td>
    <td><?php echo fmtT($col_totals['purchase']/$div); ?></td>
    <td><?php echo fmtT($col_totals['equip']/$div); ?></td>
    <td><?php echo fmtT($col_totals['transfer']/$div); ?></td>
    <td><?php echo fmtT($col_totals['net']/$div); ?></td>
  </tr>
  </tbody>
</table>
</div>

<!-- Summary Section -->
<div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4">

  <!-- Sales Breakdown Table -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-100 font-semibold text-sm text-gray-700">
      <i class="fa-solid fa-chart-pie mr-2 text-blue-500"></i>매출 구성 (Sales Breakdown)
      <span class="ml-2 text-xs text-gray-400 font-normal"><?php echo $month_label; ?></span>
    </div>
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
          <th class="px-4 py-2 text-left font-semibold">구분</th>
          <th class="px-4 py-2 text-right font-semibold">금액</th>
          <th class="px-4 py-2 text-right font-semibold">비율</th>
          <th class="px-4 py-2 text-right font-semibold">일 평균</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <!-- Retail (POS only) -->
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-2.5 text-gray-700 font-medium">
            <span class="inline-block w-2 h-2 rounded-full bg-blue-400 mr-2"></span>소매 매출 (POS)
          </td>
          <td class="px-4 py-2.5 text-right font-mono text-gray-800"><?php echo fmtT($retail_total); ?></td>
          <td class="px-4 py-2.5 text-right">
            <span class="inline-block bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs font-medium"><?php echo fmtPct($retail_pct); ?></span>
          </td>
          <td class="px-4 py-2.5 text-right font-mono text-gray-600 text-xs"><?php echo fmtT($avg_retail); ?></td>
        </tr>
        <!-- Wholesale (Delivery K + Whole Sale) -->
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-2.5 text-gray-700 font-medium">
            <span class="inline-block w-2 h-2 rounded-full bg-green-500 mr-2"></span>도매 매출 (Wholesale)
          </td>
          <td class="px-4 py-2.5 text-right font-mono text-gray-800"><?php echo fmtT($wholesale_total); ?></td>
          <td class="px-4 py-2.5 text-right">
            <span class="inline-block bg-green-50 text-green-700 px-2 py-0.5 rounded text-xs font-medium"><?php echo fmtPct($wholesale_pct); ?></span>
          </td>
          <td class="px-4 py-2.5 text-right font-mono text-gray-600 text-xs"><?php echo fmtT($avg_wholesale); ?></td>
        </tr>
        <tr class="bg-green-50/40">
          <td class="px-4 py-2 text-gray-500 text-xs pl-8">└ Delivery K</td>
          <td class="px-4 py-2 text-right font-mono text-gray-500 text-xs"><?php echo fmtT($col_totals['delivery_k']); ?></td>
          <td class="px-4 py-2 text-right text-xs text-gray-400"><?php echo fmtPct($delivery_k_pct); ?></td>
          <td class="px-4 py-2 text-right font-mono text-gray-400 text-xs"><?php echo $days_with_sales>0 ? fmtT($col_totals['delivery_k']/$days_with_sales) : '0.00'; ?></td>
        </tr>
        <tr class="bg-green-50/40">
          <td class="px-4 py-2 text-gray-500 text-xs pl-8">└ 거래명세서 (Credit Invoice)</td>
          <td class="px-4 py-2 text-right font-mono text-gray-500 text-xs"><?php echo fmtT($col_totals['credit_doc']); ?></td>
          <td class="px-4 py-2 text-right text-xs text-gray-400"><?php echo fmtPct($credit_doc_pct); ?></td>
          <td class="px-4 py-2 text-right font-mono text-gray-400 text-xs"><?php echo $days_with_sales>0 ? fmtT($col_totals['credit_doc']/$days_with_sales) : '0.00'; ?></td>
        </tr>
        <tr class="bg-green-50/40">
          <td class="px-4 py-2 text-gray-500 text-xs pl-8">└ Whole Sale</td>
          <td class="px-4 py-2 text-right font-mono text-gray-500 text-xs"><?php echo fmtT($col_totals['whole_sale']); ?></td>
          <td class="px-4 py-2 text-right text-xs text-gray-400"><?php echo fmtPct($whole_sale_pct); ?></td>
          <td class="px-4 py-2 text-right font-mono text-gray-400 text-xs"><?php echo $days_with_sales>0 ? fmtT($col_totals['whole_sale']/$days_with_sales) : '0.00'; ?></td>
        </tr>
        <!-- Total -->
        <tr class="bg-red-50 font-bold border-t-2 border-red-200">
          <td class="px-4 py-3 text-red-800">
            <i class="fa-solid fa-sigma mr-1.5 text-red-500"></i>TOTAL SALES
          </td>
          <td class="px-4 py-3 text-right font-mono text-red-800"><?php echo fmtT($sales_total_s); ?></td>
          <td class="px-4 py-3 text-right text-red-600 text-sm">100%</td>
          <td class="px-4 py-3 text-right font-mono text-red-700 text-sm"><?php echo fmtT($avg_daily); ?></td>
        </tr>
      </tbody>
    </table>
    <div class="px-4 py-2 bg-gray-50 border-t border-gray-100 text-xs text-gray-400">
      영업일 <strong class="text-gray-600"><?php echo $days_with_sales; ?>일</strong> 기준 일 평균
    </div>
  </div>

  <!-- Key Ratios -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-100 font-semibold text-sm text-gray-700">
      <i class="fa-solid fa-percent mr-2 text-violet-500"></i>주요 비율 (Key Ratios)
    </div>
    <div class="p-4 grid grid-cols-2 gap-3">
      <!-- Purchase Ratio -->
      <div class="bg-orange-50 border border-orange-100 rounded-lg p-3">
        <div class="text-xs text-orange-600 font-medium mb-1">구매율 (Purchase Ratio)</div>
        <div class="text-2xl font-bold text-orange-700"><?php echo fmtPct($purchase_ratio); ?></div>
        <div class="text-xs text-orange-500 mt-1">₱<?php echo fmtT($col_totals['purchase'] + $col_totals['transfer']); ?> / ₱<?php echo fmtT($sales_total_s); ?></div>
      </div>
      <!-- Expense Ratio -->
      <div class="bg-yellow-50 border border-yellow-100 rounded-lg p-3">
        <div class="text-xs text-yellow-700 font-medium mb-1">운영비 비율 (Expense Ratio)</div>
        <div class="text-2xl font-bold text-yellow-700"><?php echo fmtPct($expense_ratio); ?></div>
        <div class="text-xs text-yellow-600 mt-1">₱<?php echo fmtT($col_totals['equip']); ?> / ₱<?php echo fmtT($sales_total_s); ?></div>
      </div>
      <!-- Net Margin -->
      <div class="<?php echo $net_margin>=0 ? 'bg-green-50 border-green-100' : 'bg-red-50 border-red-100'; ?> border rounded-lg p-3">
        <div class="text-xs <?php echo $net_margin>=0 ? 'text-green-700' : 'text-red-700'; ?> font-medium mb-1">순이익률 (Net Margin)</div>
        <div class="text-2xl font-bold <?php echo $net_margin>=0 ? 'text-green-700' : 'text-red-700'; ?>"><?php echo fmtPct($net_margin); ?></div>
        <div class="text-xs <?php echo $net_margin>=0 ? 'text-green-600' : 'text-red-600'; ?> mt-1">₱<?php echo fmtT($col_totals['net']); ?></div>
      </div>
      <!-- Wholesale Ratio -->
      <div class="bg-blue-50 border border-blue-100 rounded-lg p-3">
        <div class="text-xs text-blue-700 font-medium mb-1">도매 비율 (Wholesale Mix)</div>
        <div class="text-2xl font-bold text-blue-700"><?php echo fmtPct($wholesale_pct); ?></div>
        <div class="text-xs text-blue-600 mt-1">DK ₱<?php echo fmtT($col_totals['delivery_k']); ?> + WS ₱<?php echo fmtT($col_totals['whole_sale']); ?></div>
      </div>
    </div>

  </div>

</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
