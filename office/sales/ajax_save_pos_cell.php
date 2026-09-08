<?php
// Design Ref: §5.1 / module-3 — 셀(POS×교대조) 단위 상세 저장 + 정산 재계산 + sales_daily 갱신
// Plan SC-4: sales_daily 칸 값 = 셀 상세 합계 일치 (서버 권위적 재계산)
require_once __DIR__ . '/../lib/office_helper.php';
require_once __DIR__ . '/lib/pos_recon_helper.php';
require_office_permission();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"error":"Method not allowed"}'); }

$store_id  = get_office_store_id();
$sale_date = post_date('sale_date');
$shift     = post_str('shift');
$pos_no    = post_int('pos_no');

if (!$sale_date || !pos_valid_shift($shift) || !store_valid_pos_no($store_id, $pos_no)) {
    echo json_encode(['success'=>false, 'error'=>'Invalid cell parameters']); exit;
}

// ── 입력 파싱 ────────────────────────────────────────────
// 현금 권종: cash[denom] => qty
$cash_in = (array)($_POST['cash'] ?? []);
$qty     = pos_normalize_qty($cash_in);

// 기타결제 라인: pay[i][method|description|amount]
$pay_rows = [];
$other_total = 0.0;
foreach ((array)($_POST['pay'] ?? []) as $p) {
    $method = substr(trim($p['method'] ?? ''), 0, 30);
    $amount = (float)($p['amount'] ?? 0);
    if ($method === '' || $amount == 0.0) continue;
    $pay_rows[] = [
        'method' => $method,
        'desc'   => substr(trim($p['description'] ?? ''), 0, 255),
        'amount' => $amount,
    ];
    $other_total += $amount;
}

// 지출 라인: exp[i][detail|amount]
$exp_rows = [];
$expense_total = 0.0;
foreach ((array)($_POST['exp'] ?? []) as $e) {
    $detail = substr(trim($e['detail'] ?? ''), 0, 255);
    $amount = (float)($e['amount'] ?? 0);
    if ($amount == 0.0 && $detail === '') continue;
    $exp_rows[] = ['detail' => $detail, 'amount' => $amount];
    $expense_total += $amount;
}

// Whole Sale 선택: ws[i][source_type|source_id|client|remark|amount]
$ws_rows = [];
$wholesale_total = 0.0;
$credit_total = 0.0;
$seen_ws = [];
foreach ((array)($_POST['ws'] ?? []) as $w) {
    $stype = $w['source_type'] ?? '';
    if (!in_array($stype, ['wholesale','delivery_k','credit','credit_doc'], true)) continue;
    $sid    = (int)($w['source_id'] ?? 0);
    $amount = (float)($w['amount'] ?? 0);
    $dedupe = $stype . ':' . $sid;
    if (isset($seen_ws[$dedupe])) continue;   // 셀 내 중복 선택 방지
    $seen_ws[$dedupe] = true;
    $ws_rows[] = [
        'stype'  => $stype,
        'sid'    => $sid,
        'client' => substr(trim($w['client'] ?? ''), 0, 255),
        'remark' => substr(trim($w['remark'] ?? ''), 0, 255),
        'amount' => $amount,
    ];
    // §5 Whole Sale은 셀 총액(매출)에 합산. 4번 POS 등록 외상(credit)은 총 매출에 합산(신용 판매분).
    // 거래명세서(credit_doc)·Delivery K는 참고·기록용으로 저장만 하고 매출 계산에서 제외.
    if ($stype === 'wholesale') { $wholesale_total += $amount; }
    elseif ($stype === 'credit') { $credit_total += $amount; }
}

// 마감 현금 기대치 (빈 값이면 null)
$exp_raw       = trim($_POST['expected_cash'] ?? '');
$expected_cash = ($exp_raw === '') ? null : (float)$exp_raw;

// ── 서버 권위적 정산 재계산 ──────────────────────────────
$r  = pos_recalc_cell($qty, $other_total, $wholesale_total, $expense_total, $expected_cash, $credit_total);
$by = (int)($_SESSION['user_id'] ?? 0) ?: null;

$conn = get_db_connection();
$conn->begin_transaction();
try {
    // 셀 단위 delete-then-insert (4개 상세 테이블)
    foreach (['sales_pos_cash_count','sales_pos_payment','sales_pos_expense','sales_pos_wholesale_pick'] as $tbl) {
        $del = $conn->prepare("DELETE FROM {$tbl} WHERE store_id=? AND sale_date=? AND shift=? AND pos_no=?");
        $del->bind_param('issi', $store_id, $sale_date, $shift, $pos_no);
        $del->execute();
        $del->close();
    }

    // 현금 권종 (qty>0만 저장)
    $ins = $conn->prepare(
        "INSERT INTO sales_pos_cash_count (store_id, sale_date, shift, pos_no, denomination, qty)
         VALUES (?,?,?,?,?,?)"
    );
    foreach (POS_DENOMS as $d) {
        if ($qty[$d] <= 0) continue;
        $denom = (float)$d; $q = $qty[$d];
        $ins->bind_param('issidi', $store_id, $sale_date, $shift, $pos_no, $denom, $q);
        $ins->execute();
    }
    $ins->close();

    // 기타결제
    if ($pay_rows) {
        $ins = $conn->prepare(
            "INSERT INTO sales_pos_payment (store_id, sale_date, shift, pos_no, method, description, amount, sort_order)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        foreach ($pay_rows as $i => $p) {
            $ins->bind_param('ississdi', $store_id, $sale_date, $shift, $pos_no, $p['method'], $p['desc'], $p['amount'], $i);
            $ins->execute();
        }
        $ins->close();
    }

    // 지출
    if ($exp_rows) {
        $ins = $conn->prepare(
            "INSERT INTO sales_pos_expense (store_id, sale_date, shift, pos_no, detail, amount, sort_order)
             VALUES (?,?,?,?,?,?,?)"
        );
        foreach ($exp_rows as $i => $e) {
            $ins->bind_param('issisdi', $store_id, $sale_date, $shift, $pos_no, $e['detail'], $e['amount'], $i);
            $ins->execute();
        }
        $ins->close();
    }

    // Whole Sale 선택
    if ($ws_rows) {
        $ins = $conn->prepare(
            "INSERT INTO sales_pos_wholesale_pick (store_id, sale_date, shift, pos_no, source_type, source_id, client, remark, amount)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        foreach ($ws_rows as $w) {
            $ins->bind_param('issisissd', $store_id, $sale_date, $shift, $pos_no, $w['stype'], $w['sid'], $w['client'], $w['remark'], $w['amount']);
            $ins->execute();
        }
        $ins->close();
    }

    // 참고: Whole Sale 선택은 셀 집계(§5 소계 · Daybook)에만 반영되며,
    // wholesale_sales.payment_status는 도매판매 화면의 결제완료 처리 버튼으로만 변경한다
    // (POS 셀 저장으로 자동 결제완료 처리되지 않도록 함 — 2026-07 사고 이후 제거).

    // 정산 요약 upsert
    $up = $conn->prepare(
        "INSERT INTO sales_pos_reconciliation
         (store_id, sale_date, shift, pos_no, cash_total, other_total, wholesale_total, expense_total,
          starting_money, deposit_cash, expected_cash, over_short, shortage_flag, total_amount, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           cash_total=VALUES(cash_total), other_total=VALUES(other_total),
           wholesale_total=VALUES(wholesale_total), expense_total=VALUES(expense_total),
           starting_money=VALUES(starting_money), deposit_cash=VALUES(deposit_cash),
           expected_cash=VALUES(expected_cash), over_short=VALUES(over_short),
           shortage_flag=VALUES(shortage_flag), total_amount=VALUES(total_amount),
           updated_at=NOW()"
    );
    $up->bind_param(
        'issiddddddddidi',
        $store_id, $sale_date, $shift, $pos_no,
        $r['cash_total'], $r['other_total'], $r['wholesale_total'], $r['expense_total'],
        $r['starting_money'], $r['deposit_cash'], $r['expected_cash'], $r['over_short'],
        $r['shortage_flag'], $r['total_amount'], $by
    );
    $up->execute();
    $up->close();

    // Design Ref: homekmart-store-config §3.4 — sales_daily의 gy_pos1~mid_pos2 미러 컬럼은
    // pos2_entry.php(삭제됨) 전용이었다. 권위 소스는 sales_pos_reconciliation이며 모든 리포트가
    // 그쪽을 읽으므로(office/lib/sales_report_helper.php, daily_report_helper.php) 더 이상 미러링하지 않는다.

    $conn->commit();
} catch (Throwable $ex) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['success'=>false, 'error'=>'Save failed: ' . $ex->getMessage()]);
    exit;
}
$conn->close();

echo json_encode([
    'success'         => true,
    'shift'           => $shift,
    'pos_no'          => $pos_no,
    'cash_total'      => $r['cash_total'],
    'other_total'     => $r['other_total'],
    'wholesale_total' => $r['wholesale_total'],
    'expense_total'   => $r['expense_total'],
    'starting_money'  => $r['starting_money'],
    'deposit_cash'    => $r['deposit_cash'],
    'expected_cash'   => $r['expected_cash'],
    'over_short'      => $r['over_short'],
    'shortage'        => (bool)$r['shortage_flag'],
    'total_amount'    => $r['total_amount'],
    'start_qty'       => $r['start_qty'],
    'deposit_qty'     => $r['deposit_qty'],
]);
