<?php
// 월마감 REPORT(결산서) 집계 SSOT — office/product_purchase/monthly_closing.php(화면)와
// export_monthly_closing.php(엑셀)이 이 파일의 get_monthly_closing_report()만을 통해 데이터에
// 접근한다 (daily_report_helper.php의 화면/엑셀 공유 컨벤션과 동일).
require_once __DIR__ . '/office_helper.php';
require_once __DIR__ . '/sales_report_helper.php';
require_once __DIR__ . '/daily_report_helper.php';

function get_monthly_closing_report(int $store_id, int $year, int $month): array {
    $first    = sprintf('%04d-%02d-01', $year, $month);
    $last_day = date('Y-m-t', strtotime($first));

    $conn = get_db_connection();

    // ── 저장된 수동 입력값 로드 ───────────────────────────────────
    $korean_salary = 0.0;
    $monthly_rent  = 0.0;
    $conn->query("CREATE TABLE IF NOT EXISTS office_monthly_fixed (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      store_id INT UNSIGNED NOT NULL, year SMALLINT UNSIGNED NOT NULL,
      month TINYINT UNSIGNED NOT NULL, korean_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
      monthly_rent DECIMAL(15,2) NOT NULL DEFAULT 0,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_store_month (store_id, year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $sf = $conn->prepare("SELECT korean_salary, monthly_rent FROM office_monthly_fixed WHERE store_id=? AND year=? AND month=?");
    $sf->bind_param('iii', $store_id, $year, $month); $sf->execute();
    $sf_row = $sf->get_result()->fetch_assoc(); $sf->close();
    $rent_saved = (bool)$sf_row;
    if ($sf_row) { $korean_salary = (float)$sf_row['korean_salary']; $monthly_rent = (float)$sf_row['monthly_rent']; }

    // ── 1. 총 물품구매액 (원본, 이동 전) ────────────────────────────
    $total_purchase = 0.0;

    $s = $conn->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM office_product_purchases
         WHERE store_id=? AND payment_type='cash'
           AND YEAR(payment_date)=? AND MONTH(payment_date)=?"
    );
    $s->bind_param('iii', $store_id, $year, $month); $s->execute();
    $total_purchase += (float)$s->get_result()->fetch_row()[0]; $s->close();

    $s = $conn->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM office_product_purchases
         WHERE store_id=? AND payment_type='check'
           AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=?"
    );
    $s->bind_param('iii', $store_id, $year, $month); $s->execute();
    $total_purchase += (float)$s->get_result()->fetch_row()[0]; $s->close();

    // ── 2. 타 지점으로 물품 이동 OUT ─────────────────────────────
    $total_tr_out = 0.0;
    $s = $conn->prepare(
        "SELECT COALESCE(SUM(final_amount),0) FROM store_transfers
         WHERE from_store_id=? AND status!='cancelled'
           AND YEAR(transfer_date)=? AND MONTH(transfer_date)=?"
    );
    $s->bind_param('iii', $store_id, $year, $month); $s->execute();
    $total_tr_out = (float)$s->get_result()->fetch_row()[0]; $s->close();

    // ── 3. 타 지점으로부터 물품 이동 IN ──────────────────────────
    // office/sales/monthly_report.php(get_monthly_sales_report)와 동일하게 3개 소스를 모두 합산한다:
    // (a) sales_transfers 수기 입력 IN, (b) store_transfers 정식 점간이동 자동 수신,
    // (c) 물류센터(CENTER) lc_orders 배송완료 자동 수신. 하나만 쓰면 도매 리포트와 숫자가 어긋난다.
    $total_tr_in = 0.0;
    $s = $conn->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM sales_transfers
         WHERE store_id=? AND direction='in'
           AND YEAR(transfer_date)=? AND MONTH(transfer_date)=?"
    );
    $s->bind_param('iii', $store_id, $year, $month); $s->execute();
    $total_tr_in += (float)$s->get_result()->fetch_row()[0]; $s->close();

    $s = $conn->prepare(
        "SELECT COALESCE(SUM(final_amount),0) FROM store_transfers
         WHERE to_store_id=? AND status != 'cancelled'
           AND YEAR(transfer_date)=? AND MONTH(transfer_date)=?"
    );
    $s->bind_param('iii', $store_id, $year, $month); $s->execute();
    $total_tr_in += (float)$s->get_result()->fetch_row()[0]; $s->close();

    $center_row      = $conn->query("SELECT id FROM stores WHERE name='CENTER (물류센터)' LIMIT 1")->fetch_assoc();
    $center_store_id = $center_row ? (int)$center_row['id'] : 0;
    if ($center_store_id > 0) {
        $s = $conn->prepare(
            "SELECT COALESCE(SUM(oi.total_amount),0)
             FROM lc_orders o
             JOIN lc_order_items oi ON oi.order_id = o.id
             WHERE o.store_id=? AND o.status='delivered'
               AND YEAR(o.delivered_at)=? AND MONTH(o.delivered_at)=?"
        );
        $s->bind_param('iii', $store_id, $year, $month); $s->execute();
        $total_tr_in += (float)$s->get_result()->fetch_row()[0]; $s->close();
    }

    // ── 도매 판매 (Delivery K + Whole Sale) — 하단 요약용 ─────────
    $total_delivery_k = 0.0;
    $s = $conn->prepare(
        "SELECT COALESCE(SUM(delivery_k),0) FROM sales_daily
         WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?"
    );
    $s->bind_param('iii', $store_id, $year, $month); $s->execute();
    $total_delivery_k = (float)$s->get_result()->fetch_row()[0]; $s->close();

    $total_wholesale = 0.0;
    $s = $conn->prepare(
        "SELECT COALESCE(SUM(final_amount),0) FROM wholesale_sales
         WHERE store_id=? AND status!='cancelled'
           AND YEAR(sale_date)=? AND MONTH(sale_date)=?"
    );
    $s->bind_param('iii', $store_id, $year, $month); $s->execute();
    $total_wholesale = (float)$s->get_result()->fetch_row()[0]; $s->close();

    // office/sales/monthly_report.php의 "도매 매출(wholesale_total)"은 Delivery K + Whole Sale에
    // 거래명세서(credit_doc)까지 더한 값이다 — 이걸 빼면 도매 리포트보다 낮게 나온다.
    $total_credit_doc = 0.0;
    $pick_tbl = $conn->query("SHOW TABLES LIKE 'sales_pos_wholesale_pick'");
    if ($pick_tbl && $pick_tbl->num_rows > 0) {
        $s = $conn->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM sales_pos_wholesale_pick
             WHERE store_id=? AND source_type='credit_doc'
               AND YEAR(sale_date)=? AND MONTH(sale_date)=?"
        );
        $s->bind_param('iii', $store_id, $year, $month); $s->execute();
        $total_credit_doc = (float)$s->get_result()->fetch_row()[0]; $s->close();
    }

    $total_wholesale_sales = $total_delivery_k + $total_wholesale + $total_credit_doc;

    // ── 물품구매(r_ 영수증 보완) — er_saved_state JSON 스캔 ──────
    $total_rent = 0.0; // Fixed Expenses Report 자동분류의 Rent 카테고리 합계 (아래 루프에서 채움)

    $tbl = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
    if ($tbl && $tbl->num_rows > 0) {
        $er_q = $conn->prepare(
            "SELECT save_date, state_json FROM er_saved_state
             WHERE store_id=? AND save_date BETWEEN ? AND ?"
        );
        $er_q->bind_param('iss', $store_id, $first, $last_day);
        $er_q->execute();

        foreach ($er_q->get_result()->fetch_all(MYSQLI_ASSOC) as $er_row) {
            $state = json_decode($er_row['state_json'], true);
            if (!$state || !isset($state['sections'])) continue;

            // 물품구매 r_ 보완 (selling + check_sup)
            foreach (['selling', 'check_sup'] as $sec) {
                foreach ($state['sections'][$sec] ?? [] as $row) {
                    if (strncmp((string)($row['item_id'] ?? ''), 'r_', 2) !== 0) continue;
                    $total_purchase += (float)($row['amount'] ?? 0);
                }
            }
        }
        $er_q->close();
    }

    // ── 인건비(Salary)/전기세(Electricity)/월세(Rent) — Fixed Expenses Report 자동분류 ──────────
    // Design Ref: Monthly Closing의 인건비/전기세/월세는 office/cash_disbursement/fixed_expenses.php와
    // 동일한 텍스트 키워드 자동분류(get_daily_fixed_expense_totals)를 기준으로 삼는다 — Daily Report
    // 수동분류는 미분류 항목이 집계에서 빠지는 문제가 있어 여기서는 배제.
    $total_salary   = 0.0; // 현지 직원 인건비 (Salary 카테고리)
    $total_electric = 0.0; // 전기세 (Electricity 카테고리)
    $days_in_month  = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    for ($d = 1; $d <= $days_in_month; $d++) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $day_exp  = get_daily_fixed_expense_totals($conn, $store_id, $date_str);
        $total_salary    += (float)($day_exp['by_category']['Salary']      ?? 0);
        $total_electric  += (float)($day_exp['by_category']['Electricity'] ?? 0);
        $total_rent      += (float)($day_exp['by_category']['Rent']        ?? 0);
    }

    $conn->close();

    // 저장된 월세 값이 없으면 자동분류(Rent 카테고리) 합계를 기본값으로 사용
    if (!$rent_saved) { $monthly_rent = $total_rent; }

    // ── 총 매출 + STORE EXP — office/sales/monthly_report.php와 동일한 공용 함수(SSOT) 재사용 ──
    $sales_report     = get_monthly_sales_report($store_id, $year, $month);
    $total_sales      = $sales_report['sales_total_s'];
    $total_store_exp  = (float)($sales_report['col_totals']['equip'] ?? 0); // Sales Report의 STORE EXP(점지출) 그대로 사용

    // 사무실 경비 = Sales Report STORE EXP 전체 - 현지 직원 인건비 - 전기세 - 월세
    // Design Ref: STORE EXP는 office/sales/monthly_report.php 값을 그대로 쓰고, 인건비/전기세/월세만
    // Monthly Closing 자체 계산(Fixed Expenses Report 자동분류)을 뺀다 — 사용자 확정 사양.
    $total_office = $total_store_exp - $total_salary - $total_electric - $monthly_rent;

    // ── 총 물품구매액(순액, 매입 + IN - OUT) — 총 지출·하단 요약 공용 ──
    $total_purchase_with_transfer = $total_purchase + $total_tr_in - $total_tr_out;

    // ── 총 지출 = 총 물품구매액(순액) + 현지 인건비 + 사무실 경비 + 전기세 + 한국 월급 + 월세 ──
    // (도매 판매는 매출 항목이므로 지출 합계에는 포함하지 않는다 — 참고 결산서 양식 기준)
    $total_expense = $total_purchase_with_transfer + $total_salary + $total_office + $total_electric + $korean_salary + $monthly_rent;

    // ── 총 소매매출 = 총 매출 - (도매 + Delivery K) ──
    $total_retail_sales = $total_sales - $total_wholesale_sales;

    // ── 수수료 매장 월간 집계 + 저장된 세금(수동 입력) 로드 ──────────
    $conn2 = get_db_connection();
    $commission_tbl_ready = false;
    $ctbl = $conn2->query("SHOW TABLES LIKE 'daily_report_commission_companies'");
    if ($ctbl && $ctbl->num_rows > 0) $commission_tbl_ready = true;

    $commission = $commission_tbl_ready
        ? get_monthly_commission_summary($conn2, $store_id, $year, $month)
        : ['rows' => [], 'total' => 0.0, 'total_commission' => 0.0];

    $conn2->query("CREATE TABLE IF NOT EXISTS office_monthly_commission_tax (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      store_id    INT UNSIGNED   NOT NULL,
      year        SMALLINT UNSIGNED NOT NULL,
      month       TINYINT UNSIGNED  NOT NULL,
      company_id  INT UNSIGNED   NOT NULL,
      tax_amount  DECIMAL(15,2)  NOT NULL DEFAULT 0,
      updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_store_month_company (store_id, year, month, company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $tax_map = [];
    if (!empty($commission['rows'])) {
        $tq = $conn2->prepare(
            "SELECT company_id, tax_amount FROM office_monthly_commission_tax
             WHERE store_id=? AND year=? AND month=?"
        );
        $tq->bind_param('iii', $store_id, $year, $month);
        $tq->execute();
        foreach ($tq->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $tax_map[(int)$r['company_id']] = (float)$r['tax_amount'];
        }
        $tq->close();
    }
    $conn2->close();

    $commission_sales_total  = 0.0;
    $commission_fee_total    = 0.0;
    $commission_tax_total    = 0.0;
    $commission_payout_total = 0.0;
    foreach ($commission['rows'] as &$crow) {
        $tax            = $tax_map[$crow['id']] ?? 0.0;
        $crow['tax']    = $tax;
        $crow['payout'] = $crow['amount'] - $crow['commission'] - $tax;
        $commission_sales_total  += $crow['amount'];
        $commission_fee_total    += $crow['commission'];
        $commission_tax_total    += $tax;
        $commission_payout_total += $crow['payout'];
    }
    unset($crow);

    // ── 항목 정의 (상단 표) ───────────────────────────────────────
    $items = [
        ['label' => '총 물품구매액',                 'amount' => $total_purchase,  'input' => false],
        ['label' => '타 지점으로 물품 이동 (OUT)',    'amount' => $total_tr_out,    'input' => false],
        ['label' => '타 지점으로부터 물품 이동 (IN)', 'amount' => $total_tr_in,     'input' => false],
        ['label' => '현지 직원 인건비',               'amount' => $total_salary,    'input' => false],
        ['label' => '사무실 경비 (부속품 일체)',       'amount' => $total_office,    'input' => false],
        ['label' => '전기세',                         'amount' => $total_electric,  'input' => false],
        ['label' => '한국 직원 월급',                  'amount' => $korean_salary,   'input' => 'korean_salary'],
        ['label' => '월세',                           'amount' => $monthly_rent,    'input' => 'monthly_rent'],
    ];

    return [
        'korean_salary'                => $korean_salary,
        'monthly_rent'                 => $monthly_rent,
        'items'                        => $items,
        'total_sales'                  => $total_sales,
        'total_expense'                => $total_expense,
        'total_purchase_with_transfer' => $total_purchase_with_transfer,
        'total_wholesale_sales'        => $total_wholesale_sales,
        'total_retail_sales'           => $total_retail_sales,
        'commission_tbl_ready'         => $commission_tbl_ready,
        'commission_rows'              => $commission['rows'],
        'commission_sales_total'       => $commission_sales_total,
        'commission_fee_total'         => $commission_fee_total,
        'commission_tax_total'         => $commission_tax_total,
        'commission_payout_total'      => $commission_payout_total,
    ];
}
