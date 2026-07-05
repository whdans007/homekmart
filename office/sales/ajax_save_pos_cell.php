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

if (!$sale_date || !pos_valid_shift($shift) || !pos_valid_pos($pos_no)) {
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
    // 섹션4 신용거래(credit·credit_doc·delivery_k)는 시제 제외 → §5 Whole Sale 만 셀 총액에 합산. 행은 기록용으로 저장.
    if ($stype === 'wholesale') { $wholesale_total += $amount; }
}

// 마감 현금 기대치 (빈 값이면 null)
$exp_raw       = trim($_POST['expected_cash'] ?? '');
$expected_cash = ($exp_raw === '') ? null : (float)$exp_raw;

// ── 서버 권위적 정산 재계산 ──────────────────────────────
$r  = pos_recalc_cell($qty, $other_total, $wholesale_total, $expense_total, $expected_cash);
$by = (int)($_SESSION['user_id'] ?? 0) ?: null;

$conn = get_db_connection();
$conn->begin_transaction();
try {
    // 저장 전 이 셀의 기존 Whole Sale(wholesale) pick id 확보 — 선택 해제 시 결제상태 되돌림 판단용
    $old_ws_ids = [];
    $q0 = $conn->prepare("SELECT source_id FROM sales_pos_wholesale_pick WHERE store_id=? AND sale_date=? AND shift=? AND pos_no=? AND source_type='wholesale'");
    $q0->bind_param('issi', $store_id, $sale_date, $shift, $pos_no);
    $q0->execute();
    $rs0 = $q0->get_result();
    while ($row = $rs0->fetch_assoc()) { $old_ws_ids[(int)$row['source_id']] = true; }
    $q0->close();

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

    // ── Whole Sale 선택 → wholesale_sales 결제상태 동기화 ──
    // 이번 저장에 포함된 wholesale pick = 결제완료(paid), 제거된 것 = (다른 셀에도 없으면) 미결제(unpaid)
    $new_ws_ids = [];
    foreach ($ws_rows as $w) { if (($w['stype'] ?? '') === 'wholesale' && (int)$w['sid'] > 0) $new_ws_ids[(int)$w['sid']] = true; }

    if ($new_ws_ids) {
        $up_paid = $conn->prepare(
            "UPDATE wholesale_sales SET payment_status='paid', paid_at=NOW(), payment_method='POS', updated_at=NOW()
             WHERE id=? AND store_id=? AND COALESCE(payment_status,'unpaid') <> 'paid'"
        );
        foreach (array_keys($new_ws_ids) as $wid) { $up_paid->bind_param('ii', $wid, $store_id); $up_paid->execute(); }
        $up_paid->close();
    }

    $removed_ws = array_diff_key($old_ws_ids, $new_ws_ids);
    if ($removed_ws) {
        // 다른 셀에서 아직 선택 중이면 유지, 아무 셀에도 없으면 미결제로 되돌림 (insert 후 현재 상태 기준)
        $chk = $conn->prepare("SELECT 1 FROM sales_pos_wholesale_pick WHERE source_type='wholesale' AND source_id=? AND store_id=? LIMIT 1");
        $up_unpaid = $conn->prepare(
            "UPDATE wholesale_sales SET payment_status='unpaid', paid_at=NULL, payment_method=NULL, updated_at=NOW()
             WHERE id=? AND store_id=?"
        );
        foreach (array_keys($removed_ws) as $wid) {
            $chk->bind_param('ii', $wid, $store_id); $chk->execute();
            $still = $chk->get_result()->fetch_row();
            if (!$still) { $up_unpaid->bind_param('ii', $wid, $store_id); $up_unpaid->execute(); }
        }
        $chk->close();
        $up_unpaid->close();
    }

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

    // sales_daily 칸 동시 갱신 (셀 Total = total_amount). shift/pos_no는 화이트리스트 검증 완료 → 컬럼 안전
    $col = "{$shift}_pos{$pos_no}";
    $sd = $conn->prepare(
        "INSERT INTO sales_daily (store_id, sale_date, `{$col}`) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE `{$col}`=VALUES(`{$col}`), updated_at=NOW()"
    );
    $sd->bind_param('isd', $store_id, $sale_date, $r['total_amount']);
    $sd->execute();
    $sd->close();

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
