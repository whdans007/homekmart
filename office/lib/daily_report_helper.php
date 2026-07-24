<?php
// Design Ref: daily-report.design.md §2.0/§9 — Daily Report 6개 섹션 집계 함수 (SSOT).
// index.php / export_daily_report.php / export_daily_report_monthly.php / print_daily_report.php는
// 이 파일의 함수를 통해서만 데이터에 접근한다. 직접 SQL을 작성하지 않는다.
require_once __DIR__ . '/office_helper.php';

// ── 기타지출 12개 고정 카테고리 (참고 이미지 서식 순서 그대로) ──────────────
function get_daily_report_categories(): array {
    return [
        'return'         => '반품',
        'payroll'        => '인건비 (SSS,PAG-IBIG,PHIL HEALTH)',
        'utilities'      => '전기세 & 관리비 & CDC & BIR',
        'pldt_lpg'       => 'PLDT & LPG & 방역',
        'office_supply'  => '사무실(비품) & 판매소품',
        'produce'        => '농산 축산 수산 키친',
        'vehicle'        => '차량 유지비',
        'discount5'      => '일반할인 5%',
        'koreanchamber5' => '한인회 5%',
        'maintenance'    => 'MAINTENANCE',
        'other'          => '기타 (공병 보증금, PLASTIC CONTAINER, MEDICAL)',
        'points'         => '포인트 사용',
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

// Plan SC: 매입 거래처별 현금/체크 — expense_report의 selling(CASH SELLING)/check_sup(PAY THRU CHECK)
// 섹션(er_saved_state)을 소스로 사용한다. 이 점포는 office_product_purchases 별도 입력 화면을
// 쓰지 않고 Daily Expense Report의 드래그앤드롭으로만 매입을 기록하기 때문 (기타지출과 동일 소스 패턴).
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
            $by_supplier[$name] ??= ['supplier' => $name, 'cash' => 0.0, 'check' => 0.0];
            $by_supplier[$name]['cash'] += (float)($item['amount'] ?? 0);
        }
        foreach ($sections['check_sup'] ?? [] as $item) {
            $name = trim((string)($item['supplier'] ?? ''));
            if ($name === '') continue;
            $by_supplier[$name] ??= ['supplier' => $name, 'cash' => 0.0, 'check' => 0.0];
            $by_supplier[$name]['check'] += (float)($item['amount'] ?? 0);
        }
    }

    $totals = ['cash' => 0.0, 'check' => 0.0, 'total' => 0.0];
    $rows = [];
    foreach ($by_supplier as $row) {
        $row['total'] = $row['cash'] + $row['check'];
        $totals['cash']  += $row['cash'];
        $totals['check'] += $row['check'];
        $totals['total'] += $row['total'];
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
function get_daily_wholesale_summary(mysqli $conn, int $store_id, string $date): array {
    $stmt = $conn->prepare(
        "SELECT item_type, description, amount
         FROM sales_daily_items
         WHERE store_id=? AND sale_date=? AND item_type IN ('delivery_k','whole_sale')
         ORDER BY id"
    );
    $stmt->bind_param('is', $store_id, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $fallback_label = ['delivery_k' => 'DK DELIVERY', 'whole_sale' => 'WHOLE SALE'];
    $out = ['delivery_k' => [], 'whole_sale' => [], 'total' => 0.0];
    foreach ($rows as $row) {
        $type = $row['item_type'];
        $out[$type][] = [
            'customer' => trim((string)$row['description']) !== '' ? $row['description'] : $fallback_label[$type],
            'amount'   => (float)$row['amount'],
        ];
        $out['total'] += (float)$row['amount'];
    }
    return $out;
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
