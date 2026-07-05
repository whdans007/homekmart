<?php
// Design Ref: §3 / module-2 — POS 현금 정산 공유 계산 헬퍼 (pos-payment-detail)
// 입력 저장(ajax_save_pos_cell)과 Daybook 인쇄(print_daybook)가 동일 로직을 공유한다.
// 모든 금액은 서버에서 권위적으로 재계산한다(클라이언트 값 불신).

require_once __DIR__ . '/../../lib/office_helper.php';

// ── 상수 ────────────────────────────────────────────────
// 권종 집합 (POS Shift Entry v10 기준 — ₱200 포함)
const POS_DENOMS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];
// 준비금(거스름돈 float) 보존 우선순위: 소액권(특히 ₱100)부터 ₱10,000 까지 최대한 retain.
// 거스름용으로 유용한 ₱100 을 먼저 남기고, 남는 권종은 입금으로 보낸다. (v10 RETAIN_PRIORITY 동일)
const POS_RETAIN_PRIORITY = [100, 50, 20, 10, 5, 1, 200, 500, 1000];
// 목표 준비금
const POS_STARTING_TARGET = 10000;
const POS_SHIFTS = ['gy', 'morning', 'mid'];

function pos_valid_shift(string $s): bool { return in_array($s, POS_SHIFTS, true); }
function pos_valid_pos(int $n): bool { return $n === 1 || $n === 2; }

/**
 * 권종별 매수 맵을 정규화 (모든 권종 키 보장, 음수 제거).
 * @param array $qty [denom => qty]
 * @return array [denom(int) => qty(int)]
 */
function pos_normalize_qty(array $qty): array {
    $out = [];
    foreach (POS_DENOMS as $d) {
        $out[$d] = max(0, (int)($qty[$d] ?? $qty[(string)$d] ?? 0));
    }
    return $out;
}

/**
 * 준비금(Starting Money) ₱10,000 배분 (v10 retain 방식).
 * 준비금 target ₱10,000 을 소액권(₱100 우선) 순으로 서랍에 retain 하고, 남은 권종을 입금으로 둔다.
 * → 거스름돈용 ₱100 을 최대한 float 으로 보존. 현금이 10,000 미만이면 전액 retain·부족 표시.
 *
 * @return array {
 *   cash_total, starting_money, deposit_cash, shortage(bool),
 *   start_qty[denom=>qty], deposit_qty[denom=>qty]
 * }
 */
function pos_allocate_starting_money(array $qty): array {
    $qty = pos_normalize_qty($qty);

    $cash_total = 0.0;
    foreach (POS_DENOMS as $d) { $cash_total += $d * $qty[$d]; }

    // 현금이 10,000 미만이면 부족(shortage).
    $shortage = $cash_total < POS_STARTING_TARGET;

    // 입금(Deposit)은 큰 권종 우선으로 (현금 − 10,000) 만큼 구성한다.
    // → 소액권·₱100 은 최대한 준비금(거스름)으로 남고, 정확히 10,000 을 맞추기 위한 소액만 입금으로 빠진다.
    //   (소액권을 무조건 전부 준비금으로 잡지 않음)
    $deposit_qty = array_fill_keys(POS_DENOMS, 0);
    if ($cash_total > POS_STARTING_TARGET) {
        $dep_rem = (int)round($cash_total - POS_STARTING_TARGET);
        foreach (POS_DENOMS as $d) { // POS_DENOMS = 큰 권종 → 작은 권종
            if ($dep_rem <= 0) break;
            $take = min($qty[$d], intdiv($dep_rem, $d));
            $deposit_qty[$d] = $take;
            $dep_rem -= $take * $d;
        }
        // $dep_rem > 0 (드묾): 지폐 구성상 정확히 못 맞춘 잔여 → 준비금이 그만큼 10,000 초과
    }

    // 준비금 권종 = 센 현금 − 입금. 준비금 금액 = 현금 − 입금(권종 합계 기반, 보통 정확히 10,000).
    $start_qty = array_fill_keys(POS_DENOMS, 0);
    foreach (POS_DENOMS as $d) { $start_qty[$d] = $qty[$d] - $deposit_qty[$d]; }
    $starting_money = 0;
    foreach (POS_DENOMS as $d) { $starting_money += $d * $start_qty[$d]; }
    $deposit_cash = round($cash_total - $starting_money, 2);

    return [
        'cash_total'     => round($cash_total, 2),
        'starting_money' => round($starting_money, 2),
        'deposit_cash'   => $deposit_cash,
        'shortage'       => $shortage,
        'start_qty'      => $start_qty,
        'deposit_qty'    => $deposit_qty,
    ];
}

/**
 * 셀 단위 정산 요약 재계산 (저장/표시 공용).
 *
 * @param array      $qty             권종별 매수
 * @param float      $other_total     기타결제 합계
 * @param float      $wholesale_total Whole Sale 선택 합계
 * @param float      $expense_total   지출 합계
 * @param float|null $expected_cash   POS 마감 금액 (Z-reading, null=미입력)
 * @return array 정산 요약 (DB 컬럼 + start_qty/deposit_qty)
 *
 * Design(v10/Daybook v2): 셀 Total(매출/시제) = (현금 − 준비금 10,000) + 기타결제.
 *   준비금 10,000은 이월 float(매출 아님)이므로 현금에서 차감한 입금분만 매출에 산입.
 *   현금 미입력(0) 셀은 −10,000 환영(phantom)을 막기 위해 현금 매출분 0으로 보정.
 *   섹션4 신용거래·섹션5 도매(wholesale_total)는 매출 제외(참고·기록용) — total 에서 빼되 컬럼엔 보존.
 *   Over/Short = 셀 Total − POS 마감 금액.
 */
function pos_recalc_cell(array $qty, float $other_total, float $wholesale_total,
                         float $expense_total, ?float $expected_cash): array {
    $a = pos_allocate_starting_money($qty);

    // 매출 현금분 = 입금액(현금총액 − 실제 준비금). 현금 미입력/목표 이하 셀은 0.
    $sales_cash   = $a['deposit_cash'];
    // 셀 Total(매출/시제) = 현금 입금분 + 기타결제만. 도매(wholesale_total)는 매출 제외.
    $total_amount = round($sales_cash + $other_total, 2);

    $over_short = ($expected_cash === null)
        ? null
        : round($total_amount - $expected_cash, 2);

    return [
        'cash_total'      => $a['cash_total'],
        'other_total'     => round($other_total, 2),
        'wholesale_total' => round($wholesale_total, 2),
        'expense_total'   => round($expense_total, 2),
        'starting_money'  => $a['starting_money'],
        'deposit_cash'    => $a['deposit_cash'],
        'expected_cash'   => $expected_cash === null ? null : round($expected_cash, 2),
        'over_short'      => $over_short,
        'shortage_flag'   => $a['shortage'] ? 1 : 0,
        'total_amount'    => $total_amount,
        'start_qty'       => $a['start_qty'],
        'deposit_qty'     => $a['deposit_qty'],
    ];
}

// ── 셀 단위 조회 (저장 핸들러 / 인쇄 공용) ────────────────────

/** 권종별 매수 맵 조회 [denom => qty] */
function pos_read_cash_counts(mysqli $conn, int $store_id, string $sale_date, string $shift, int $pos_no): array {
    $stmt = $conn->prepare(
        "SELECT denomination, qty FROM sales_pos_cash_count
         WHERE store_id=? AND sale_date=? AND shift=? AND pos_no=?"
    );
    $stmt->bind_param('issi', $store_id, $sale_date, $shift, $pos_no);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $qty = array_fill_keys(POS_DENOMS, 0);
    foreach ($rows as $r) {
        $d = (int)$r['denomination'];
        if (isset($qty[$d])) $qty[$d] = (int)$r['qty'];
    }
    return $qty;
}

/** 기타결제 라인 조회 */
function pos_read_payments(mysqli $conn, int $store_id, string $sale_date, string $shift, int $pos_no): array {
    $stmt = $conn->prepare(
        "SELECT method, description, amount FROM sales_pos_payment
         WHERE store_id=? AND sale_date=? AND shift=? AND pos_no=? ORDER BY sort_order, id"
    );
    $stmt->bind_param('issi', $store_id, $sale_date, $shift, $pos_no);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** 지출 라인 조회 */
function pos_read_expenses(mysqli $conn, int $store_id, string $sale_date, string $shift, int $pos_no): array {
    $stmt = $conn->prepare(
        "SELECT detail, amount FROM sales_pos_expense
         WHERE store_id=? AND sale_date=? AND shift=? AND pos_no=? ORDER BY sort_order, id"
    );
    $stmt->bind_param('issi', $store_id, $sale_date, $shift, $pos_no);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Whole Sale 선택 조회 */
function pos_read_wholesale_picks(mysqli $conn, int $store_id, string $sale_date, string $shift, int $pos_no): array {
    $stmt = $conn->prepare(
        "SELECT source_type, source_id, client, remark, amount FROM sales_pos_wholesale_pick
         WHERE store_id=? AND sale_date=? AND shift=? AND pos_no=? ORDER BY id"
    );
    $stmt->bind_param('issi', $store_id, $sale_date, $shift, $pos_no);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** 정산 요약 1행 조회 (없으면 null) */
function pos_read_reconciliation(mysqli $conn, int $store_id, string $sale_date, string $shift, int $pos_no): ?array {
    $stmt = $conn->prepare(
        "SELECT * FROM sales_pos_reconciliation
         WHERE store_id=? AND sale_date=? AND shift=? AND pos_no=?"
    );
    $stmt->bind_param('issi', $store_id, $sale_date, $shift, $pos_no);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * 하루치 6칸 상세 전체 프리로드 (daily_entry.php → JS 주입용).
 * @return array ["{shift}_pos{n}" => [cash, payments, expenses, wholesale, recon]]
 */
function pos_preload_date(mysqli $conn, int $store_id, string $sale_date): array {
    $out = [];
    foreach (POS_SHIFTS as $shift) {
        for ($pos = 1; $pos <= 2; $pos++) {
            $key = "{$shift}_pos{$pos}";
            $out[$key] = [
                'cash'      => pos_read_cash_counts($conn, $store_id, $sale_date, $shift, $pos),
                'payments'  => pos_read_payments($conn, $store_id, $sale_date, $shift, $pos),
                'expenses'  => pos_read_expenses($conn, $store_id, $sale_date, $shift, $pos),
                'wholesale' => pos_read_wholesale_picks($conn, $store_id, $sale_date, $shift, $pos),
                'recon'     => pos_read_reconciliation($conn, $store_id, $sale_date, $shift, $pos),
            ];
        }
    }
    return $out;
}
