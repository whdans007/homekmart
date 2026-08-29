<?php
// Design Ref: daily-report.design.md §2.0/§9 — Daily Report 6개 섹션 집계 함수 (SSOT).
// index.php / export_daily_report.php / export_daily_report_monthly.php / print_daily_report.php는
// 이 파일의 함수를 통해서만 데이터에 접근한다. 직접 SQL을 작성하지 않는다.
require_once __DIR__ . '/office_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';

// ── 기타지출 12개 고정 카테고리 (참고 이미지 서식 순서/키는 고정, 표시 텍스트만 언어별 번역) ──
function get_daily_report_categories(): array {
    return [
        'return'         => t('daily_report.cat_return'),
        'payroll'        => t('daily_report.cat_payroll'),
        'utilities'      => t('daily_report.cat_utilities'),
        'pldt_lpg'       => t('daily_report.cat_pldt_lpg'),
        'office_supply'  => t('daily_report.cat_office_supply'),
        'produce'        => t('daily_report.cat_produce'),
        'vehicle'        => t('daily_report.cat_vehicle'),
        'discount5'      => t('daily_report.cat_discount5'),
        'koreanchamber5' => t('daily_report.cat_koreanchamber5'),
        'maintenance'    => t('daily_report.cat_maintenance'),
        'other'          => t('daily_report.cat_other'),
        'points'         => t('daily_report.cat_points'),
    ];
}

// Plan SC: 3교대(gy/morning/mid) x POS1/POS2 현금/크레딧/도매(참고 서식 컬럼명 "도매")
function get_daily_pos_summary(mysqli $conn, int $store_id, string $date): array {
    $shift_labels = ['gy' => '12AM-8AM', 'morning' => '8AM-5PM', 'mid' => '5PM-12AM'];
    $cells = [];
    foreach ([1, 2] as $pos_no) {
        foreach ($shift_labels as $shift => $label) {
            $cells["{$pos_no}_{$shift}"] = [
                'pos' => $pos_no, 'shift' => $shift,
                'label' => "포스{$pos_no}({$label})",
                'cash' => 0.0, 'credit' => 0.0, 'delivery_slip' => 0.0, 'total' => 0.0,
            ];
        }
    }

    // Layout Ref: daily_entry.php 모달 기준 — 현금=Deposit(입금할 현금, deposit_cash),
    // 크레딧=3.Other payments Subtotal(other_total) + 2.Expenses Total(expense_total)
    $stmt = $conn->prepare(
        "SELECT shift, pos_no, deposit_cash, other_total, expense_total
         FROM sales_pos_reconciliation
         WHERE store_id=? AND sale_date=?"
    );
    $stmt->bind_param('is', $store_id, $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $key = $row['pos_no'] . '_' . $row['shift'];
        if (!isset($cells[$key])) continue;
        $cells[$key]['cash']   = (float)$row['deposit_cash'];
        $cells[$key]['credit'] = (float)$row['other_total'] + (float)$row['expense_total'];
    }
    $stmt->close();

    $stmt2 = $conn->prepare(
        "SELECT shift, pos_no, SUM(amount) AS total
         FROM sales_pos_wholesale_pick
         WHERE store_id=? AND sale_date=?
         GROUP BY shift, pos_no"
    );
    $stmt2->bind_param('is', $store_id, $date);
    $stmt2->execute();
    foreach ($stmt2->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $key = $row['pos_no'] . '_' . $row['shift'];
        if (!isset($cells[$key])) continue;
        $cells[$key]['delivery_slip'] = (float)$row['total'];
    }
    $stmt2->close();

    $totals = ['cash' => 0.0, 'credit' => 0.0, 'delivery_slip' => 0.0, 'total' => 0.0];
    $rows = [];
    foreach ($cells as $cell) {
        $cell['total'] = $cell['cash'] + $cell['credit'] + $cell['delivery_slip'];
        $totals['cash']          += $cell['cash'];
        $totals['credit']        += $cell['credit'];
        $totals['delivery_slip'] += $cell['delivery_slip'];
        $totals['total']         += $cell['total'];
        $rows[] = $cell;
    }

    return ['rows' => $rows, 'totals' => $totals];
}

// Plan SC: 크레딧(CARD, E-MONEY) BDO/GCASH/MAYA/QR 자동 집계
// 참고 서식(포스코 일계표.xlsx)은 BDO/GCASH/MAYA/QR 4개 버킷을 사용한다 (OTHERS 아님).
// method='credit_card'/'debit_card'(카드 단말기, 발급 은행 BDO)→BDO, 'gcash'→GCASH, 'paymaya'→MAYA, 'phqr'→QR
// method/description에 'bdo' 포함(대소문자 무관) 시에도 BDO로 강제 분류 (수기 라벨 대응)
function get_daily_credit_breakdown(mysqli $conn, int $store_id, string $date): array {
    $buckets = ['BDO' => 0.0, 'GCASH' => 0.0, 'MAYA' => 0.0, 'QR' => 0.0];

    $stmt = $conn->prepare(
        "SELECT method, description, amount
         FROM sales_pos_payment
         WHERE store_id=? AND sale_date=?"
    );
    $stmt->bind_param('is', $store_id, $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $method = strtolower((string)$row['method']);
        $desc   = strtolower((string)$row['description']);
        $amount = (float)$row['amount'];

        if (str_contains($method, 'bdo') || str_contains($desc, 'bdo')) {
            $buckets['BDO'] += $amount;
        } elseif ($method === 'gcash') {
            $buckets['GCASH'] += $amount;
        } elseif ($method === 'paymaya') {
            $buckets['MAYA'] += $amount;
        } elseif ($method === 'phqr' || str_contains($desc, 'qr')) {
            $buckets['QR'] += $amount;
        } elseif ($method === 'credit_card' || $method === 'debit_card') {
            $buckets['BDO'] += $amount;
        } else {
            $buckets['QR'] += $amount; // 미분류 수기 라벨은 QR(기타)로 폴백
        }
    }
    $stmt->close();

    $buckets['total'] = $buckets['BDO'] + $buckets['GCASH'] + $buckets['MAYA'] + $buckets['QR'];
    return $buckets;
}

// Plan SC: 매입 거래처별 현금/체크/점간이동 — expense_report의 selling(CASH SELLING)/check_sup(PAY THRU CHECK)
// 섹션(er_saved_state)을 소스로 사용한다. 이 점포는 office_product_purchases 별도 입력 화면을
// 쓰지 않고 Daily Expense Report의 드래그앤드롭으로만 매입을 기록하기 때문 (기타지출과 동일 소스 패턴).
// 점간이동(transfer)은 다른 점포/물류센터에서 당일 받은 재고의 원가로, monthly_report.php(sales_report_helper.php)의
// 재고이동 IN 집계와 동일한 3개 소스를 하루 단위로 합산한다.
function get_daily_purchase_summary(mysqli $conn, int $store_id, string $date): array {
    $by_supplier = [];

    $stmt = $conn->prepare(
        "SELECT state_json FROM er_saved_state WHERE store_id=? AND save_date=?"
    );
    $stmt->bind_param('is', $store_id, $date);
    $stmt->execute();
    $state_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($state_row) {
        $state = json_decode($state_row['state_json'], true) ?: [];
        $sections = $state['sections'] ?? [];

        foreach ($sections['selling'] ?? [] as $item) {
            $name = trim((string)($item['supplier'] ?? ''));
            if ($name === '') continue;
            $by_supplier[$name] ??= ['supplier' => $name, 'cash' => 0.0, 'check' => 0.0, 'transfer' => 0.0];
            $by_supplier[$name]['cash'] += (float)($item['amount'] ?? 0);
        }
        foreach ($sections['check_sup'] ?? [] as $item) {
            $name = trim((string)($item['supplier'] ?? ''));
            if ($name === '') continue;
            $by_supplier[$name] ??= ['supplier' => $name, 'cash' => 0.0, 'check' => 0.0, 'transfer' => 0.0];
            $by_supplier[$name]['check'] += (float)($item['amount'] ?? 0);
        }
    }

    // 점간이동 IN (1) 수기입력 (sales_transfers, direction='in')
    $stmt3 = $conn->prepare(
        "SELECT s.name AS supplier, SUM(t.amount) AS total
         FROM sales_transfers t
         JOIN stores s ON s.id = t.other_store_id
         WHERE t.store_id=? AND t.direction='in' AND t.transfer_date=?
         GROUP BY s.name"
    );
    $stmt3->bind_param('is', $store_id, $date);
    $stmt3->execute();
    foreach ($stmt3->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $name = $row['supplier'];
        $by_supplier[$name] ??= ['supplier' => $name, 'cash' => 0.0, 'check' => 0.0, 'transfer' => 0.0];
        $by_supplier[$name]['transfer'] += (float)$row['total'];
    }
    $stmt3->close();

    // 점간이동 IN (2) 정식 점간이동 자동 (store_transfers, to_store_id=이 점포)
    $stmt4 = $conn->prepare(
        "SELECT s.name AS supplier, SUM(st.final_amount) AS total
         FROM store_transfers st
         JOIN stores s ON s.id = st.from_store_id
         WHERE st.to_store_id=? AND st.status != 'cancelled' AND st.transfer_date=?
         GROUP BY s.name"
    );
    $stmt4->bind_param('is', $store_id, $date);
    $stmt4->execute();
    foreach ($stmt4->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $name = $row['supplier'];
        $by_supplier[$name] ??= ['supplier' => $name, 'cash' => 0.0, 'check' => 0.0, 'transfer' => 0.0];
        $by_supplier[$name]['transfer'] += (float)$row['total'];
    }
    $stmt4->close();

    // 점간이동 IN (3) 물류센터(CENTER) 배송완료(lc_orders, status='delivered')
    $center_row = $conn->query("SELECT id FROM stores WHERE name='CENTER (물류센터)' LIMIT 1")->fetch_assoc();
    $center_id  = $center_row ? (int)$center_row['id'] : 0;
    if ($center_id > 0) {
        $stmt5 = $conn->prepare(
            "SELECT COALESCE(SUM(oi.total_amount),0) AS total
             FROM lc_orders o
             JOIN lc_order_items oi ON oi.order_id = o.id
             WHERE o.store_id=? AND o.status='delivered' AND DATE(o.delivered_at)=?"
        );
        $stmt5->bind_param('is', $store_id, $date);
        $stmt5->execute();
        $center_total = (float)($stmt5->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt5->close();
        if ($center_total > 0.0) {
            $name = 'CENTER (물류센터)';
            $by_supplier[$name] ??= ['supplier' => $name, 'cash' => 0.0, 'check' => 0.0, 'transfer' => 0.0];
            $by_supplier[$name]['transfer'] += $center_total;
        }
    }

    $totals = ['cash' => 0.0, 'check' => 0.0, 'transfer' => 0.0, 'total' => 0.0];
    $rows = [];
    foreach ($by_supplier as $row) {
        $row['total'] = $row['cash'] + $row['check'] + $row['transfer'];
        $totals['cash']     += $row['cash'];
        $totals['check']    += $row['check'];
        $totals['transfer'] += $row['transfer'];
        $totals['total']    += $row['total'];
        $rows[] = $row;
    }

    return ['rows' => $rows, 'totals' => $totals];
}

// Plan SC: 외상판매 / 외상수금
function get_daily_ar_summary(mysqli $conn, int $store_id, string $date): array {
    $sales = [];
    $stmt = $conn->prepare(
        "SELECT cc.name AS customer_name, ct.final_amount AS amount
         FROM credit_transactions ct
         JOIN credit_customers cc ON cc.id = ct.customer_id
         WHERE ct.store_id=? AND ct.transaction_date=? AND ct.status='confirmed'
         ORDER BY cc.name"
    );
    $stmt->bind_param('is', $store_id, $date);
    $stmt->execute();
    $sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $collections = [];
    $stmt2 = $conn->prepare(
        "SELECT cc.name AS customer_name, cp.amount AS amount
         FROM credit_payments cp
         JOIN credit_customers cc ON cc.id = cp.customer_id
         WHERE cp.store_id=? AND cp.payment_date=?
         ORDER BY cc.name"
    );
    $stmt2->bind_param('is', $store_id, $date);
    $stmt2->execute();
    $collections = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    return [
        'credit_sales'       => $sales,
        'credit_sales_total' => array_sum(array_column($sales, 'amount')),
        'collections'        => $collections,
        'collections_total'  => array_sum(array_column($collections, 'amount')),
    ];
}

// Plan SC: 도매 판매(거래명세서) / Delivery K
// 소스 2개를 합산: (1) 레거시 수동입력(sales_daily_items — dk_entry.php/ws_entry.php),
// (2) daily_entry.php POS 셀 4/5번 섹션에서 선택한 항목(sales_pos_wholesale_pick).
// Delivery K는 상세내용 없이 "DELIVERY K"만, Whole Sale은 거래처명(client)을 표시한다.
function get_daily_wholesale_summary(mysqli $conn, int $store_id, string $date): array {
    $out = ['delivery_k' => [], 'whole_sale' => [], 'total' => 0.0];

    // 1) 레거시 수동입력
    // delivery_k 항목 중 daily_entry.php POS 셀에서 이미 선택(pick)된 건은 제외 —
    // 그렇지 않으면 아래 2)의 sales_pos_wholesale_pick 조회와 합쳐질 때 같은 건이 두 번 출력됨.
    $stmt = $conn->prepare(
        "SELECT si.item_type, si.description, si.amount
         FROM sales_daily_items si
         WHERE si.store_id=? AND si.sale_date=? AND si.item_type IN ('delivery_k','whole_sale')
           AND NOT (si.item_type='delivery_k' AND EXISTS (
               SELECT 1 FROM sales_pos_wholesale_pick p
               WHERE p.store_id=si.store_id AND p.sale_date=si.sale_date
                 AND p.source_type='delivery_k' AND p.source_id=si.id
           ))
         ORDER BY si.id"
    );
    $stmt->bind_param('is', $store_id, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $type = $row['item_type'];
        $label = $type === 'delivery_k'
            ? 'DELIVERY K'
            : (trim((string)$row['description']) !== '' ? $row['description'] : 'WHOLE SALE');
        $out[$type][] = ['customer' => $label, 'amount' => (float)$row['amount']];
        $out['total'] += (float)$row['amount'];
    }

    // 2) daily_entry.php POS 셀에서 선택된 Whole Sale / Delivery K
    $stmt2 = $conn->prepare(
        "SELECT source_type, client, remark, amount
         FROM sales_pos_wholesale_pick
         WHERE store_id=? AND sale_date=? AND source_type IN ('wholesale','delivery_k')
         ORDER BY id"
    );
    $stmt2->bind_param('is', $store_id, $date);
    $stmt2->execute();
    $rows2 = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    foreach ($rows2 as $row) {
        $type = $row['source_type'] === 'wholesale' ? 'whole_sale' : 'delivery_k';
        if ($type === 'delivery_k') {
            $label = 'DELIVERY K';
        } else {
            $client = trim((string)$row['client']);
            $remark = trim((string)$row['remark']);
            $label = $client !== '' ? $client : ($remark !== '' ? $remark : 'WHOLE SALE');
        }
        $out[$type][] = ['customer' => $label, 'amount' => (float)$row['amount']];
        $out['total'] += (float)$row['amount'];
    }
    return $out;
}

// 수수료 코너 — 점포별로 등록해둔 입점업체 목록과, 그 업체명이 pos_sales_data.supplier와
// 정확히 일치하는 해당 날짜의 NET SALES 합계를 반환한다. (Plan §4.2 Out of Scope 재검토 — 등록 UI 추가)
function get_daily_commission_summary(mysqli $conn, int $store_id, string $date): array {
    $out = ['rows' => [], 'total' => 0.0];

    $stmt = $conn->prepare(
        "SELECT id, supplier_name FROM daily_report_commission_companies
         WHERE store_id=? ORDER BY id ASC"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $companies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($companies)) return $out;

    // pos_sales_data.sale_date는 업로드 파일마다 저장 포맷이 다를 수 있어(Y-m-d, m/d/Y 등)
    // 샘플 1건으로 포맷을 감지한 뒤, 조회 날짜($date, 항상 Y-m-d)를 동일 포맷 문자열로 변환해 매칭한다.
    $sample = $conn->query(
        "SELECT sale_date FROM pos_sales_data
         WHERE upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id}) LIMIT 1"
    )->fetch_assoc();
    $match_date = pos_sales_date_to_stored_format($sample['sale_date'] ?? '', $date);

    $names        = array_column($companies, 'supplier_name');
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt2 = $conn->prepare(
        "SELECT d.supplier, SUM(d.net_sales) AS total
         FROM pos_sales_data d
         WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id=?)
           AND d.sale_date=?
           AND d.supplier IN ({$placeholders})
         GROUP BY d.supplier"
    );
    $types  = 'is' . str_repeat('s', count($names));
    $params = array_merge([$store_id, $match_date], $names);
    $stmt2->bind_param($types, ...$params);
    $stmt2->execute();
    $sales_by_supplier = [];
    foreach ($stmt2->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $sales_by_supplier[$r['supplier']] = (float)$r['total'];
    }
    $stmt2->close();

    foreach ($companies as $c) {
        $amount = $sales_by_supplier[$c['supplier_name']] ?? 0.0;
        $out['rows'][] = ['id' => (int)$c['id'], 'supplier_name' => $c['supplier_name'], 'amount' => $amount];
        $out['total'] += $amount;
    }
    return $out;
}

// pos_sales_data.sale_date 저장 포맷 감지 규칙 — office/pos_data/report.php의
// detect_date_format()과 유사하되, 월/일 자리수가 항상 zero-pad라고 가정하지 않고
// 샘플에서 실제 관측된 자리수(1자리 vs 2자리)를 그대로 따라간다.
// (예: 샘플이 "07-23-2026"이면 zero-pad 유지, "7/23/2026"이면 zero 제거)
function pos_sales_date_to_stored_format(string $sample, string $ymd): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sample)) return $ymd;
    [$y, $m, $d] = explode('-', $ymd); // 항상 Y, 2자리 m, 2자리 d

    if (preg_match('#^(\d{1,2})/(\d{1,2})/\d{4}$#', $sample, $mm)) {
        $mo = strlen($mm[1]) === 2 ? $m : ltrim($m, '0');
        $da = strlen($mm[2]) === 2 ? $d : ltrim($d, '0');
        return $mo . '/' . $da . '/' . $y;
    }
    if (preg_match('#^(\d{1,2})-(\d{1,2})-\d{4}$#', $sample, $mm)) {
        $mo = strlen($mm[1]) === 2 ? $m : ltrim($m, '0');
        $da = strlen($mm[2]) === 2 ? $d : ltrim($d, '0');
        return $mo . '-' . $da . '-' . $y;
    }
    return $ymd;
}

// Plan SC: 기타지출 12개 카테고리 — expense_report 기배치 항목(er_saved_state)을 소스로 사용
// 소스 섹션 3개: not_selling(PARTICULARS/CASH NOT SELLING), other_exp_check, other_exp_cash
// (selling/CASH SELLING, check_sup/PAY THRU CHECK 제외 — 매입 섹션은 office_product_purchases로 별도 자동집계됨)
function get_daily_other_expense_categories(mysqli $conn, int $store_id, string $date): array {
    $categories = get_daily_report_categories();

    $source_items = [];
    $stmt = $conn->prepare(
        "SELECT state_json FROM er_saved_state WHERE store_id=? AND save_date=?"
    );
    $stmt->bind_param('is', $store_id, $date);
    $stmt->execute();
    $state_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($state_row) {
        $state = json_decode($state_row['state_json'], true) ?: [];
        $sections = $state['sections'] ?? [];
        // 구 데이터 하위 호환: other_exp → other_exp_cash (expense_report의 export_er.php/print_er.php와 동일 처리)
        if (isset($sections['other_exp']) && !isset($sections['other_exp_check']) && !isset($sections['other_exp_cash'])) {
            $sections['other_exp_cash']  = $sections['other_exp'];
            $sections['other_exp_check'] = [];
        }
        foreach (['not_selling', 'other_exp_check', 'other_exp_cash'] as $sec_key) {
            foreach ($sections[$sec_key] ?? [] as $item) {
                $item_id = (string)($item['item_id'] ?? '');
                if ($item_id === '') continue;
                $source_items[$item_id] = [
                    'source_item_id' => $item_id,
                    'supplier'       => $item['supplier'] ?? '',
                    'details'        => $item['details'] ?? '',
                    'amount'         => (float)($item['amount'] ?? 0),
                    'category_key'   => null,
                ];
            }
        }
    }

    // 2) 이 리포트 전용 카테고리 배치 상태 조인
    if (!empty($source_items)) {
        $stmt2 = $conn->prepare(
            "SELECT source_item_id, category_key FROM daily_report_expense_category
             WHERE store_id=? AND sale_date=?"
        );
        $stmt2->bind_param('is', $store_id, $date);
        $stmt2->execute();
        foreach ($stmt2->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            if (isset($source_items[$row['source_item_id']])) {
                $source_items[$row['source_item_id']]['category_key'] = $row['category_key'];
            }
        }
        $stmt2->close();
    }

    $by_category = array_fill_keys(array_keys($categories), 0.0);
    $unplaced_count = 0;
    $total_placed = 0.0;
    foreach ($source_items as $item) {
        if ($item['category_key'] !== null && isset($by_category[$item['category_key']])) {
            $by_category[$item['category_key']] += $item['amount'];
            $total_placed += $item['amount'];
        } else {
            $unplaced_count++;
        }
    }

    // 3) office/sales/daily_entry.php(POS 셀 "2. Expenses" 고정 카테고리, sales_pos_expense)를
    // 추가 소스로 합산한다. 이미 카테고리가 고정되어 저장되므로 드래그앤드롭 분류 없이 바로 합계에 더한다.
    // Design Ref: daily_entry.php EXPENSE_CATS의 detail 값 → 이 리포트의 12개 카테고리 키 매핑
    $pos_expense_category_map = [
        '반품'                          => 'return',
        '인건비'                        => 'payroll',
        '전기세 · 관리비 · CDC · BIR'   => 'utilities',
        'PLDT · LPG · 방역'             => 'pldt_lpg',
        '사무실 (비품)'                 => 'office_supply',
        '농산 · 축산 · 수산 · 키친'     => 'produce',
        '차량 유지비'                   => 'vehicle',
        '일반할인 5%'                   => 'discount5',
        '한인회 5%'                     => 'koreanchamber5',
        '생수'                          => 'other',
        '기타'                          => 'other',
        '포인트 사용'                   => 'points',
    ];
    $stmt3 = $conn->prepare(
        "SELECT detail, SUM(amount) AS total FROM sales_pos_expense
         WHERE store_id=? AND sale_date=? GROUP BY detail"
    );
    $stmt3->bind_param('is', $store_id, $date);
    $stmt3->execute();
    foreach ($stmt3->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $cat_key = $pos_expense_category_map[$row['detail']] ?? null;
        if ($cat_key === null || !isset($by_category[$cat_key])) continue;
        $amt = (float)$row['total'];
        $by_category[$cat_key] += $amt;
        $total_placed += $amt;
    }
    $stmt3->close();

    return [
        'items'          => array_values($source_items),
        'categories'     => $categories,
        'by_category'    => $by_category,
        'unplaced_count' => $unplaced_count,
        'total_placed'   => $total_placed,
    ];
}
