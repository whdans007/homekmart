<?php
// Design Ref: sales-expenses-report.design.md §2.2/§3 — 매출(현금/카드)·지출(현금/수표) 월간 요약을
// 기존 소스(sales_pos_reconciliation, sales_pos_payment, er_saved_state)에서 읽기 전용으로 재집계한다.
// SALES CREDITS는 office/daily_report/index.php의 "크레딧(CARD, E-MONEY)" 섹션(get_daily_credit_breakdown)과
// 동일한 소스(sales_pos_payment)로 집계해 두 화면 합계가 항상 일치하도록 한다.
// 지출 소스는 Expense Report(office/expense_report, er_saved_state) 그대로다 —
// cd_saved_state/cer_saved_state(Cash Disbursement/Cheque Expense Report)나 sales_pos_expense
// (Daily Report 전용 POS 셀 지출)는 Expense Report 화면 데이터가 아니라서 쓰지 않는다.
// Plan §1.4 제약: 일자별 반복 쿼리 금지 — 월 전체를 배치 쿼리로 가져온다.
require_once __DIR__ . '/../../config/db_config.php';

/**
 * 월간 매출(CASH/CREDITS)·지출(CASH/CHEQUE) 요약을 일자별로 집계한다.
 * @return array{days:int, rows:array<int,array>, col_totals:array, profit_loss:float}
 */
function get_monthly_sales_expenses_report(int $store_id, int $year, int $month): array {
    $days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));

    $conn = get_db_connection();

    // 1) 매출 CASH — sales_pos_reconciliation 일자별 합계 (1쿼리)
    $cash_by_day = [];
    $stmt = $conn->prepare(
        "SELECT DAY(sale_date) AS d, SUM(deposit_cash) AS cash
         FROM sales_pos_reconciliation
         WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?
         GROUP BY DAY(sale_date)"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $cash_by_day[(int)$r['d']] = (float)$r['cash'];
    }
    $stmt->close();

    // 1b) 매출 CREDITS — sales_pos_payment 일자별 합계 (office/daily_report/index.php의
    // "크레딧(CARD, E-MONEY)" 섹션(get_daily_credit_breakdown)과 동일 소스·동일 합계가 되도록
    // sales_pos_reconciliation.other_total 캐시값이 아니라 원본 결제 테이블에서 직접 재집계한다.
    $credit_by_day = [];
    $stmt = $conn->prepare(
        "SELECT DAY(sale_date) AS d, SUM(amount) AS credit
         FROM sales_pos_payment
         WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?
         GROUP BY DAY(sale_date)"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $credit_by_day[(int)$r['d']] = (float)$r['credit'];
    }
    $stmt->close();

    // 2)+3) 지출 CASH/CHEQUE — Expense Report(er_saved_state) 월 전체 배치 조회
    $expense_by_day    = sales_expenses_get_expense_by_day($conn, $store_id, $year, $month);
    $cash_exp_by_day    = $expense_by_day['cash'];
    $cheque_exp_by_day  = $expense_by_day['cheque'];

    // 4) PARTICULAR(일자별 메모) — sales_expenses_particular 월 전체 배치 조회
    $particular_by_day = sales_expenses_get_particulars($conn, $store_id, $year, $month);

    $conn->close();

    $col_totals = [
        'cash_sales' => 0.0, 'credit_sales' => 0.0,
        'cash_expense' => 0.0, 'cheque_expense' => 0.0,
        'sales_total' => 0.0, 'expense_total' => 0.0, 'net' => 0.0,
    ];

    $rows = [];
    for ($d = 1; $d <= $days; $d++) {
        $cash_sales     = $cash_by_day[$d] ?? 0.0;
        $credit_sales   = $credit_by_day[$d] ?? 0.0;
        $cash_expense   = $cash_exp_by_day[$d] ?? 0.0;
        $cheque_expense = $cheque_exp_by_day[$d] ?? 0.0;
        $sales_total    = $cash_sales + $credit_sales;
        $expense_total  = $cash_expense + $cheque_expense;
        $net            = $sales_total - $expense_total;

        $particular = $particular_by_day[$d] ?? '';

        $rows[$d] = compact(
            'cash_sales', 'credit_sales', 'cash_expense', 'cheque_expense',
            'sales_total', 'expense_total', 'net', 'particular'
        );

        $col_totals['cash_sales']     += $cash_sales;
        $col_totals['credit_sales']   += $credit_sales;
        $col_totals['cash_expense']   += $cash_expense;
        $col_totals['cheque_expense'] += $cheque_expense;
        $col_totals['sales_total']    += $sales_total;
        $col_totals['expense_total']  += $expense_total;
        $col_totals['net']            += $net;
    }

    return [
        'days'        => $days,
        'rows'        => $rows,
        'col_totals'  => $col_totals,
        'profit_loss' => $col_totals['net'],
    ];
}

/**
 * er_saved_state(Expense Report)를 월 단위로 배치 조회해 일자별 CASH/CHEQUE 지출을 계산한다.
 * office/expense_report/print_er.php가 쓰는 것과 동일한 5개 섹션을 그대로 결제수단별로 합산한다
 * (print_er.php의 "CASH TOTAL" 라벨 = selling+not_selling과 동일한 계산식).
 *   CASH   = selling(CASH SELLING) + not_selling(CASH NOT SELLING) + other_exp_cash(기타지출-현금)
 *   CHEQUE = check_sup(PAY THRU CHECK) + other_exp_check(기타지출-체크)
 * @return array{cash:array<int,float>, cheque:array<int,float>}
 */
function sales_expenses_get_expense_by_day(mysqli $conn, int $store_id, int $year, int $month): array {
    $cash_by_day   = [];
    $cheque_by_day = [];

    $tbl_check = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
    if ($tbl_check && $tbl_check->num_rows > 0) {
        $stmt = $conn->prepare(
            "SELECT DAY(save_date) AS d, state_json
             FROM er_saved_state
             WHERE store_id=? AND YEAR(save_date)=? AND MONTH(save_date)=?"
        );
        $stmt->bind_param('iii', $store_id, $year, $month);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $d     = (int)$r['d'];
            $state = json_decode($r['state_json'], true);
            if (!is_array($state)) {
                error_log("sales_expenses_report_helper: invalid state_json in er_saved_state (store={$store_id}, day={$d})");
                continue;
            }
            $sections = $state['sections'] ?? [];
            // 구 데이터 하위 호환: other_exp → other_exp_cash (daily_report_helper.php와 동일 처리)
            if (isset($sections['other_exp']) && !isset($sections['other_exp_check']) && !isset($sections['other_exp_cash'])) {
                $sections['other_exp_cash']  = $sections['other_exp'];
                $sections['other_exp_check'] = [];
            }

            $cash = 0.0;
            foreach (['selling', 'not_selling', 'other_exp_cash'] as $sec) {
                foreach ($sections[$sec] ?? [] as $item) { $cash += (float)($item['amount'] ?? 0); }
            }
            $cheque = 0.0;
            foreach (['check_sup', 'other_exp_check'] as $sec) {
                foreach ($sections[$sec] ?? [] as $item) { $cheque += (float)($item['amount'] ?? 0); }
            }

            $cash_by_day[$d]   = ($cash_by_day[$d] ?? 0.0) + $cash;
            $cheque_by_day[$d] = ($cheque_by_day[$d] ?? 0.0) + $cheque;
        }
        $stmt->close();
    }

    return ['cash' => $cash_by_day, 'cheque' => $cheque_by_day];
}

/**
 * sales_expenses_particular(일자별 메모)를 월 단위로 배치 조회한다.
 * @return array<int,string> [day => particular text]
 */
function sales_expenses_get_particulars(mysqli $conn, int $store_id, int $year, int $month): array {
    $tbl_check = $conn->query("SHOW TABLES LIKE 'sales_expenses_particular'");
    if (!$tbl_check || $tbl_check->num_rows === 0) return [];

    $out  = [];
    $stmt = $conn->prepare(
        "SELECT DAY(note_date) AS d, particular
         FROM sales_expenses_particular
         WHERE store_id=? AND YEAR(note_date)=? AND MONTH(note_date)=?"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[(int)$r['d']] = (string)$r['particular'];
    }
    $stmt->close();
    return $out;
}

function ser_fmtC(float $n): string {
    return $n == 0 ? '' : number_format($n, 2);
}
function ser_fmtT(float $n): string {
    return number_format($n, 2);
}
