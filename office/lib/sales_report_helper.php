<?php
// Design Ref: sales-report-main-office — office/sales/monthly_report.php의 집계 로직을
// 공용 함수로 분리. office(점포 세션)와 main_office(전 점포 열람)가 동일 로직을 공유한다.
// 원본 SQL/계산식은 monthly_report.php에서 그대로 이전한 것으로, 과거 버그 수정 이력이
// 반영되어 있으므로 임의로 계산식을 변경하지 않는다.
require_once __DIR__ . '/../../config/db_config.php';

/**
 * 지정 점포/연월의 월간 판매 리포트 데이터를 집계합니다.
 * @param int $store_id
 * @param int $year
 * @param int $month
 * @return array{days:int, days_with_sales:int, rows:array, col_totals:array,
 *               retail_total:float, wholesale_total:float, sales_total_s:float,
 *               avg_daily:float, avg_retail:float, avg_wholesale:float,
 *               purchase_ratio:float, expense_ratio:float, net_margin:float,
 *               retail_pct:float, wholesale_pct:float, delivery_k_pct:float,
 *               credit_doc_pct:float, whole_sale_pct:float}
 */
function get_monthly_sales_report(int $store_id, int $year, int $month): array {
    $days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));

    $conn = get_db_connection();

    // 1) 일별 POS 매출 — 셀 total_amount(그 POS의 총 매출 = 입금분+기타결제+지출 합계+POS 등록 외상) 기준.
    //    daily_entry DAY TOTAL/그리드와 동일 기준.
    $stmt = $conn->prepare(
        "SELECT DAY(sale_date) AS d, shift, pos_no, total_amount
         FROM sales_pos_reconciliation
         WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    $sales_by_day = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $d = (int)$r['d'];
        if (!isset($sales_by_day[$d])) $sales_by_day[$d] = [];
        $sales_by_day[$d]["{$r['shift']}_pos{$r['pos_no']}"] = $r['total_amount'];
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
                foreach ($state['sections'] as $sec_name => $rows_) {
                    $is_pu  = in_array($sec_name, $er_purchase_secs);
                    $is_exp = in_array($sec_name, $er_expense_secs);
                    if (!$is_pu && !$is_exp) continue;
                    foreach ($rows_ as $row) {
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

    return compact(
        'days', 'days_with_sales', 'rows', 'col_totals',
        'retail_total', 'wholesale_total', 'sales_total_s',
        'avg_daily', 'avg_retail', 'avg_wholesale',
        'purchase_ratio', 'expense_ratio', 'net_margin',
        'retail_pct', 'wholesale_pct', 'delivery_k_pct', 'credit_doc_pct', 'whole_sale_pct'
    );
}

function sr_fmtC($n) {
    if ($n == 0) return '';
    return number_format((float)$n, 2);
}
function sr_fmtT($n) { return number_format((float)$n, 2); }
function sr_fmtPct($n) { return number_format((float)$n, 1) . '%'; }
