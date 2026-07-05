<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));

$conn = get_db_connection();

// ── 이전 DTR 저장 상태에서 배치 완료 항목 ID 수집 (최대 6개월) ─────────
$placed_prev_pp = []; // office_product_purchases IDs
$placed_prev_rc = []; // office_receipts IDs
$first_of_month = sprintf('%04d-%02d-01', $year, $month);
$six_months_ago = date('Y-m-d', strtotime($first_of_month . ' -6 months'));

$dtr_tbl = $conn->query("SHOW TABLES LIKE 'dtr_saved_state'");
if ($dtr_tbl && $dtr_tbl->num_rows > 0) {
    $dtr_q = $conn->prepare(
        "SELECT state_json FROM dtr_saved_state
         WHERE store_id=?
           AND (year<? OR (year=? AND month<?))
           AND STR_TO_DATE(CONCAT(year,'-',LPAD(month,2,'0'),'-01'),'%Y-%m-%d') >= ?"
    );
    if ($dtr_q) {
        $dtr_q->bind_param('iiiis', $store_id, $year, $year, $month, $six_months_ago);
        $dtr_q->execute();
        foreach ($dtr_q->get_result()->fetch_all(MYSQLI_ASSOC) as $dtr_row) {
            $state = json_decode($dtr_row['state_json'], true);
            foreach (array_keys($state['rows'] ?? []) as $item_id) {
                if (strncmp($item_id, 'p_', 2) === 0) {
                    $n = (int)substr($item_id, 2); if ($n > 0) $placed_prev_pp[] = $n;
                } elseif (strncmp($item_id, 'r_', 2) === 0) {
                    $n = (int)substr($item_id, 2); if ($n > 0) $placed_prev_rc[] = $n;
                }
            }
        }
        $dtr_q->close();
    }
}
$placed_prev_pp = array_values(array_unique($placed_prev_pp));
$placed_prev_rc = array_values(array_unique($placed_prev_rc));

$pp_prev_excl = $placed_prev_pp
    ? " AND pp.id NOT IN (" . implode(',', $placed_prev_pp) . ")"
    : "";
$rc_prev_excl = $placed_prev_rc
    ? " AND r2.id NOT IN (" . implode(',', $placed_prev_rc) . ")"
    : "";

// ── cv_no 컬럼 존재 여부 ─────────────────────────────────────────────────
$chk_cv = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'cv_no'");
$cv_col  = ($chk_cv && $chk_cv->num_rows > 0) ? "pp.cv_no" : "'' AS cv_no";

// ── Product Purchase 제외 조건 ────────────────────────────────────────────
$pp_exclude = [];
foreach (['is_cd_paid', 'is_cer_placed', 'is_er_placed', 'is_dtr_placed'] as $col) {
    $chk = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE '{$col}'");
    if ($chk && $chk->num_rows > 0) $pp_exclude[] = "pp.{$col}=0";
}
$pp_exclude_sql = $pp_exclude ? "AND " . implode(" AND ", $pp_exclude) : "";

// ── 1) Product Purchase (선택 월 + 이전 6개월 미배치 이월) ────────────────
$items = [];
$stmt = $conn->prepare(
    "SELECT pp.id, pp.supplier_name, pp.delivery_content, pp.amount,
            pp.payment_date, pp.payment_type, {$cv_col},
            COALESCE(r.cv_no,'') AS receipt_cv
     FROM office_product_purchases pp
     LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
     WHERE pp.store_id=?
       AND (
         (YEAR(pp.payment_date)=? AND MONTH(pp.payment_date)=?)
         OR (pp.payment_date<? AND pp.payment_date>=? {$pp_prev_excl})
       )
       {$pp_exclude_sql}
     ORDER BY pp.payment_date, pp.id"
);
if ($stmt) {
    $stmt->bind_param('iiiss', $store_id, $year, $month, $first_of_month, $six_months_ago);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = [
            'id'       => 'p_' . $r['id'],
            'supplier' => $r['supplier_name'],
            'details'  => $r['delivery_content'],
            'amount'   => (float)$r['amount'],
            'date'     => $r['payment_date'],
            'cv_no'    => $r['receipt_cv'] ?: ($r['cv_no'] ?? ''),
            'type'     => $r['payment_type'],
        ];
    }
    $stmt->close();
}

// ── 2) 독립 영수증 (office_receipts, 선택 월 + 이전 6개월 미배치 이월) ───
$rc_exclude = [];
// is_dtr_placed 컬럼 체크
$chk_dtr = $conn->query("SHOW COLUMNS FROM office_receipts LIKE 'is_dtr_placed'");
if ($chk_dtr && $chk_dtr->num_rows > 0) $rc_exclude[] = "r2.is_dtr_placed=0";
// er_section 기반 ER 배치 제외 (is_er_placed 대체)
$chk_sec = $conn->query("SHOW COLUMNS FROM office_receipts LIKE 'er_section'");
if ($chk_sec && $chk_sec->num_rows > 0) {
    $rc_exclude[] = "r2.er_section IS NULL";
}
$rc_exclude_sql = $rc_exclude ? "AND " . implode(" AND ", $rc_exclude) : "";

$stmt2 = $conn->prepare(
    "SELECT r2.id, r2.supplier_name, r2.description, r2.amount, r2.receipt_date, r2.cv_no
     FROM office_receipts r2
     WHERE r2.store_id=?
       AND (r2.linked_purchase_type IS NULL OR r2.linked_purchase_type='' OR r2.linked_purchase_id=0)
       AND (
         (YEAR(r2.receipt_date)=? AND MONTH(r2.receipt_date)=?)
         OR (r2.receipt_date<? AND r2.receipt_date>=? {$rc_prev_excl})
       )
       {$rc_exclude_sql}
     ORDER BY r2.receipt_date, r2.id"
);
if ($stmt2) {
    $stmt2->bind_param('iiiss', $store_id, $year, $month, $first_of_month, $six_months_ago);
    $stmt2->execute();
    foreach ($stmt2->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = [
            'id'       => 'r_' . $r['id'],
            'supplier' => $r['supplier_name'],
            'details'  => $r['description'],
            'amount'   => (float)$r['amount'],
            'date'     => $r['receipt_date'],
            'cv_no'    => $r['cv_no'] ?? '',
            'type'     => 'receipt',
        ];
    }
    $stmt2->close();
}

// ── 저장된 상태 로드 ──────────────────────────────────────────────────────
$saved_state = null;
if ($dtr_tbl && $dtr_tbl->num_rows > 0) {
    $sv = $conn->prepare("SELECT state_json, DATE_FORMAT(saved_at,'%Y-%m-%d %H:%i') AS saved_at FROM dtr_saved_state WHERE store_id=? AND year=? AND month=?");
    if ($sv) {
        $sv->bind_param('iii', $store_id, $year, $month);
        $sv->execute();
        $sv_row = $sv->get_result()->fetch_assoc();
        if ($sv_row) {
            $d = json_decode($sv_row['state_json'], true);
            if ($d) $saved_state = array_merge($d, ['saved_at' => $sv_row['saved_at']]);
        }
        $sv->close();
    }
}
$conn->close();

echo json_encode(['success'=>true,'items'=>$items,'year'=>$year,'month'=>$month,'saved_state'=>$saved_state], JSON_UNESCAPED_UNICODE);
