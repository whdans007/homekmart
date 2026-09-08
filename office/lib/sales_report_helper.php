<?php
// Design Ref: sales-report-main-office — office/sales/monthly_report.php의 집계 로직을
// 공용 함수로 분리. office(점포 세션)와 main_office(전 점포 열람)가 동일 로직을 공유한다.
// 원본 SQL/계산식은 monthly_report.php에서 그대로 이전한 것으로, 과거 버그 수정 이력이
// 반영되어 있으므로 임의로 계산식을 변경하지 않는다.
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/daily_report_helper.php';
require_once __DIR__ . '/../../lib/store_config_helper.php';

/**
 * 지정 점포/연월의 월간 판매 리포트 데이터를 집계합니다.
 * @param int $store_id
 * @param int $year
 * @param int $month
 * @return array{days:int, days_with_sales:int, rows:array, col_totals:array, pos_keys:array,
 *               retail_total:float, wholesale_total:float, sales_total_s:float,
 *               avg_daily:float, avg_retail:float, avg_wholesale:float,
 *               purchase_ratio:float, expense_ratio:float, net_margin:float,
 *               retail_pct:float, wholesale_pct:float, delivery_k_pct:float,
 *               credit_doc_pct:float, whole_sale_pct:float}
 *         `pos_keys`는 이 점포의 pos_count 기준 "{shift}_pos{n}" 키 목록(gy_pos1, gy_pos2, ... 순서 보장) —
 *         화면/엑셀에서 열을 렌더할 때 이 배열을 순회한다(Design Ref: homekmart-store-config §4.2).
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

    // 1c) 일별 §4 집계 — POS 셀에 실제 pick된 금액 기준 (daily_entry DAY TOTAL의 cellManualDR과 동일 소스)
    //     source_type='credit'      → POS 외상: 미수금 성격(정보용 컬럼, SALES TOTAL 제외 · daily_entry §4 취급과 동일)
    //     source_type='credit_doc'  → 거래명세서: SALES TOTAL 에 포함
    $pos_credit_by_day = []; // §4 POS 외상 (credit)
    $credit_doc_by_day = []; // §4 거래명세서 (credit_doc)
    $pick_tbl = $conn->query("SHOW TABLES LIKE 'sales_pos_wholesale_pick'");
    if ($pick_tbl && $pick_tbl->num_rows > 0) {
        $stmt = $conn->prepare(
            "SELECT DAY(sale_date) AS d, source_type, SUM(amount) AS total
             FROM sales_pos_wholesale_pick
             WHERE store_id=? AND source_type IN ('credit','credit_doc')
               AND YEAR(sale_date)=? AND MONTH(sale_date)=?
             GROUP BY DAY(sale_date), source_type"
        );
        $stmt->bind_param('iii', $store_id, $year, $month);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $d = (int)$r['d'];
            if ($r['source_type'] === 'credit') {
                $pos_credit_by_day[$d] = (float)$r['total'];
            } else { // credit_doc
                $credit_doc_by_day[$d] = (float)$r['total'];
            }
        }
        $stmt->close();
    }

    // 1d) 일별 §5 Whole Sale — wholesale_sales(admin 등록) 전체를 day 단위로 직접 집계.
    //     POS 셀 pick 여부와 무관하게 등록 즉시 반영 (daily_entry WHOLESALE_DAY_TOTAL·ws_entry.php와 동일 기준).
    //     과거엔 pick된 금액만 집계했으나, 이는 daily_entry 자체의 WHOLESALE_DAY_TOTAL 표시값과도 어긋나
    //     "등록했는데 월간 리포트에 안 보인다"는 불일치를 유발했다.
    $ws_by_day = [];
    $stmt = $conn->prepare(
        "SELECT DAY(sale_date) AS d, SUM(final_amount) AS total
         FROM wholesale_sales
         WHERE store_id=? AND status != 'cancelled'
           AND YEAR(sale_date)=? AND MONTH(sale_date)=?
         GROUP BY DAY(sale_date)"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $ws_by_day[(int)$r['d']] = (float)$r['total'];
    }
    $stmt->close();

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

    // 2c) ER 저장 상태에서 r_(영수증) 아이템 추출 → PURCHASE 합산
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
            foreach ($er_q->get_result()->fetch_all(MYSQLI_ASSOC) as $er_row) {
                $d     = (int)$er_row['d'];
                $state = json_decode($er_row['state_json'], true);
                if (!$state || !isset($state['sections'])) continue;
                foreach ($state['sections'] as $sec_name => $rows_) {
                    if (!in_array($sec_name, $er_purchase_secs)) continue;
                    foreach ($rows_ as $row) {
                        // r_ 아이템만 (p_/pc_/e_ 는 purchase 테이블 쿼리에서 이미 처리)
                        if (strncmp((string)($row['item_id'] ?? ''), 'r_', 2) !== 0) continue;
                        $amt = (float)($row['amount'] ?? 0);
                        if ($amt <= 0) continue;
                        $purchase_by_day[$d] = ($purchase_by_day[$d] ?? 0) + $amt;
                    }
                }
            }
            $er_q->close();
        }
    }

    // 3) 일별 점지출(STORE EXP) — office/daily_report/index.php의 "기타지출" 합계(total_placed)와
    // 완전히 동일한 소스를 쓰도록 get_daily_other_expense_categories()를 하루 단위로 그대로 호출한다.
    // (Design Ref: Sales Report의 STORE EXP는 Daily Report 수동분류 기준을 그대로 유지 — Fixed Expenses
    //  Report 자동분류 기준으로의 전환은 office/product_purchase/monthly_closing.php에만 적용한다.)
    $equip_by_day = [];
    for ($d = 1; $d <= $days; $d++) {
        $date_str = date('Y-m-d', mktime(0, 0, 0, $month, $d, $year));
        $day_exp  = get_daily_other_expense_categories($conn, $store_id, $date_str);
        if ($day_exp['total_placed'] > 0) {
            $equip_by_day[$d] = (float)$day_exp['total_placed'];
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

    // 4a-2) 일별 재고이동 IN (자동) — 다른 점포가 등록한 정식 점간이동 수신분
    //       (store_transfers, to_store_id=이 점포). transfer.php의 "Transfer (Auto)" IN과 동일 기준.
    $stmt = $conn->prepare(
        "SELECT DAY(transfer_date) AS d, SUM(final_amount) AS total
         FROM store_transfers
         WHERE to_store_id=? AND status != 'cancelled'
           AND YEAR(transfer_date)=? AND MONTH(transfer_date)=?
         GROUP BY DAY(transfer_date)"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $d = (int)$r['d'];
        $tr_in_day[$d] = ($tr_in_day[$d] ?? 0) + (float)$r['total'];
    }
    $stmt->close();

    // 4a-3) 일별 재고이동 IN (자동) — 물류센터(CENTER) 배송완료 주문 (lc_orders, status='delivered')
    //       transfer.php의 lc_order 자동 IN과 동일 기준 (total_amount는 lc_order_items 합계로 직접 계산).
    $center_row       = $conn->query("SELECT id FROM stores WHERE name='CENTER (물류센터)' LIMIT 1")->fetch_assoc();
    $center_store_id  = $center_row ? (int)$center_row['id'] : 0;
    if ($center_store_id > 0) {
        $stmt = $conn->prepare(
            "SELECT DAY(o.delivered_at) AS d,
                    COALESCE((SELECT SUM(oi.total_amount) FROM lc_order_items oi WHERE oi.order_id = o.id), 0) AS total
             FROM lc_orders o
             WHERE o.store_id=? AND o.status='delivered'
               AND YEAR(o.delivered_at)=? AND MONTH(o.delivered_at)=?"
        );
        $stmt->bind_param('iii', $store_id, $year, $month);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $d = (int)$r['d'];
            $tr_in_day[$d] = ($tr_in_day[$d] ?? 0) + (float)$r['total'];
        }
        $stmt->close();
    }

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
    // Design Ref: homekmart-store-config §4.2 — POS 열은 점포별 pos_count 기준 가변.
    // 키 형식은 pos_preload_date()와 동일한 "{shift}_pos{n}"을 유지한다(daily_entry.php JS와 공유).
    $max_pos  = get_store_pos_count($store_id);
    $pos_keys = [];
    foreach (STORE_SHIFT_KEYS as $shift) {
        for ($p = 1; $p <= $max_pos; $p++) {
            $pos_keys[] = "{$shift}_pos{$p}";
        }
    }

    $col_totals = array_fill_keys(array_merge($pos_keys, [
        'delivery_k','pos_credit','credit_doc','whole_sale','sales_total','purchase','equip','transfer','net'
    ]), 0.0);

    $rows = [];
    for ($d = 1; $d <= $days; $d++) {
        $s = $sales_by_day[$d] ?? [];
        $pos_vals = [];
        $retail_day = 0.0;
        foreach ($pos_keys as $k) {
            $v = (float)($s[$k] ?? 0);
            $pos_vals[$k] = $v;
            $retail_day  += $v;
        }
        $dk = (float)($s['delivery_k']??0);
        $pc = $pos_credit_by_day[$d] ?? 0.0; // §4 POS 외상 (정보용 · SALES TOTAL 제외)
        $cd = $credit_doc_by_day[$d] ?? 0.0; // §4 거래명세서 (SALES TOTAL 포함)
        $ws = $ws_by_day[$d] ?? 0.0; // §5 Whole Sale — wholesale_sales 등록 전체 합계 (pick 여부 무관, 즉시 반영)
        $st = $retail_day+$dk+$cd+$ws; // 거래명세서 포함, POS 외상 제외
        $pu = $purchase_by_day[$d] ?? 0.0;
        $eq = $equip_by_day[$d]    ?? 0.0;
        $tr = $transfer_by_day[$d] ?? 0.0;
        $net= $st - $pu - $eq - $tr;
        $rows[$d] = $pos_vals + compact('dk','pc','cd','ws','st','pu','eq','tr','net');
        // accumulate
        foreach ($pos_keys as $k) { $col_totals[$k] += $pos_vals[$k]; }
        $col_totals['delivery_k'] += $dk;  $col_totals['pos_credit']  += $pc;
        $col_totals['credit_doc'] += $cd;  $col_totals['whole_sale']  += $ws;
        $col_totals['sales_total']+= $st;  $col_totals['purchase']    += $pu;
        $col_totals['equip']      += $eq;  $col_totals['transfer']    += $tr;
        $col_totals['net']        += $net;
    }

    // Summary statistics
    $retail_total = 0.0;
    foreach ($pos_keys as $k) { $retail_total += $col_totals[$k]; }
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
        'days', 'days_with_sales', 'rows', 'col_totals', 'pos_keys',
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
