<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$store_id = get_office_store_id();
$date     = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success'=>false,'error'=>'Invalid date']);
    exit;
}

$conn = get_db_connection();
$items = [];

// ── 이전 ER 저장 상태에서 배치 완료 항목 ID 수집 ────────────────────────
// er_saved_state가 존재할 때만 조회 (60일 이내 이전 날짜)
$placed_pp = []; // office_product_purchases IDs (p_ + pc_ 통합)
$placed_ep = []; // office_equipment_purchases IDs
$placed_rc = []; // office_receipts IDs
$er_tbl = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if ($er_tbl && $er_tbl->num_rows > 0) {
    $er_q = $conn->prepare(
        "SELECT state_json FROM er_saved_state
         WHERE store_id=? AND save_date<? AND save_date>=DATE_SUB(?,INTERVAL 60 DAY)"
    );
    if ($er_q) {
        $er_q->bind_param('iss', $store_id, $date, $date);
        $er_q->execute();
        foreach ($er_q->get_result()->fetch_all(MYSQLI_ASSOC) as $er_row) {
            $state = json_decode($er_row['state_json'], true);
            foreach ($state['sections'] ?? [] as $rows) {
                foreach ($rows as $r) {
                    $bid = (string)($r['item_id'] ?? '');
                    if (strncmp($bid, 'pc_', 3) === 0) {
                        $n = (int)substr($bid, 3); if ($n > 0) $placed_pp[] = $n;
                    } elseif (strncmp($bid, 'p_', 2) === 0) {
                        $n = (int)substr($bid, 2); if ($n > 0) $placed_pp[] = $n;
                    } elseif (strncmp($bid, 'e_', 2) === 0) {
                        $n = (int)substr($bid, 2); if ($n > 0) $placed_ep[] = $n;
                    } elseif (strncmp($bid, 'r_', 2) === 0) {
                        $n = (int)substr($bid, 2); if ($n > 0) $placed_rc[] = $n;
                    }
                }
            }
        }
        $er_q->close();
    }
}
$placed_pp = array_values(array_unique($placed_pp));
$placed_ep = array_values(array_unique($placed_ep));
$placed_rc = array_values(array_unique($placed_rc));

// NOT IN 절 생성 헬퍼 (테이블 alias 포함)
function make_not_in($col, $ids) {
    if (empty($ids)) return '';
    return " AND {$col} NOT IN (" . implode(',', array_map('intval', $ids)) . ')';
}

// cv_no 컬럼 존재 여부
$chk_cv = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'cv_no'");
$cv_col  = ($chk_cv && $chk_cv->num_rows > 0) ? "pp.cv_no" : "'' AS cv_no";

// ── 1) Product Purchase — Cash ────────────────────────────────────────────
// 선택 날짜 전체 + 이전 60일 중 미배치(placed_pp 제외) 이월
$pp_excl = make_not_in('pp.id', $placed_pp);
$sql = "SELECT pp.id, pp.supplier_name, pp.delivery_content, pp.amount, pp.payment_date AS item_date,
               {$cv_col}, COALESCE(r.cv_no,'') AS receipt_cv
        FROM office_product_purchases pp
        LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
        WHERE pp.store_id=? AND pp.payment_type='cash'
          AND (
            pp.payment_date=?
            OR (pp.payment_date<? AND pp.payment_date>=DATE_SUB(?,INTERVAL 60 DAY){$pp_excl})
          )
        ORDER BY pp.payment_date, pp.id";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('isss', $store_id, $date, $date, $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = [
            'id'           => 'p_' . $r['id'],
            'type'         => 'product_cash',
            'supplier'     => $r['supplier_name'],
            'details'      => $r['delivery_content'],
            'amount'       => (float)$r['amount'],
            'date'         => $r['item_date'],
            'cv_no'        => $r['receipt_cv'] ?: ($r['cv_no'] ?? ''),
            'auto_section' => 'selling',
        ];
    }
    $stmt->close();
}

// ── 2) Product Purchase — Check ───────────────────────────────────────────
$sql = "SELECT pp.id, pp.supplier_name, pp.delivery_content, pp.amount, pp.check_issued_date AS item_date,
               {$cv_col}, COALESCE(r.cv_no,'') AS receipt_cv
        FROM office_product_purchases pp
        LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
        WHERE pp.store_id=? AND pp.payment_type='check'
          AND (
            pp.check_issued_date=?
            OR (pp.check_issued_date<? AND pp.check_issued_date>=DATE_SUB(?,INTERVAL 60 DAY){$pp_excl})
          )
        ORDER BY pp.check_issued_date, pp.id";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('isss', $store_id, $date, $date, $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = [
            'id'           => 'pc_' . $r['id'],
            'type'         => 'product_check',
            'supplier'     => $r['supplier_name'],
            'details'      => $r['delivery_content'],
            'amount'       => (float)$r['amount'],
            'date'         => $r['item_date'],
            'cv_no'        => $r['receipt_cv'] ?: ($r['cv_no'] ?? ''),
            'auto_section' => 'check_sup',
        ];
    }
    $stmt->close();
}

// ── 3) Equipment Purchase ─────────────────────────────────────────────────
$chk_et = $conn->query("SHOW COLUMNS FROM office_equipment_purchases LIKE 'expense_type'");
$has_et  = ($chk_et && $chk_et->num_rows > 0);
$et_col  = $has_et ? "ep.expense_type" : "'consumable' AS expense_type";
$ep_excl = make_not_in('ep.id', $placed_ep);
$sql = "SELECT ep.id, ep.supplier_name, ep.delivery_content, ep.amount, ep.payment_date AS item_date,
               {$et_col}, COALESCE(r.cv_no,'') AS receipt_cv
        FROM office_equipment_purchases ep
        LEFT JOIN office_receipts r ON r.linked_purchase_type='equipment' AND r.linked_purchase_id=ep.id
        WHERE ep.store_id=?
          AND (
            ep.payment_date=?
            OR (ep.payment_date<? AND ep.payment_date>=DATE_SUB(?,INTERVAL 60 DAY){$ep_excl})
          )
        ORDER BY ep.payment_date, ep.id";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('isss', $store_id, $date, $date, $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $auto = ($r['expense_type'] === 'other_expense') ? 'other_exp_cash' : 'not_selling';
        $items[] = [
            'id'           => 'e_' . $r['id'],
            'type'         => 'equipment',
            'supplier'     => $r['supplier_name'],
            'details'      => $r['delivery_content'],
            'amount'       => (float)$r['amount'],
            'date'         => $r['item_date'],
            'cv_no'        => $r['receipt_cv'],
            'auto_section' => $auto,
        ];
    }
    $stmt->close();
}

// ── 4) office_receipts — PP/EP에 미연결된 독립 영수증 ────────────────────
$rc_excl = make_not_in('r2.id', $placed_rc);
$sql = "SELECT r2.id, r2.supplier_name, r2.description, r2.amount, r2.receipt_date, r2.cv_no
        FROM office_receipts r2
        WHERE r2.store_id=?
          AND (r2.linked_purchase_type IS NULL OR r2.linked_purchase_type='' OR r2.linked_purchase_id=0)
          AND (
            r2.receipt_date=?
            OR (r2.receipt_date<? AND r2.receipt_date>=DATE_SUB(?,INTERVAL 60 DAY){$rc_excl})
          )
        ORDER BY r2.receipt_date, r2.id";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('isss', $store_id, $date, $date, $date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $items[] = [
            'id'           => 'r_' . $r['id'],
            'type'         => 'receipt',
            'supplier'     => $r['supplier_name'],
            'details'      => $r['description'],
            'amount'       => (float)$r['amount'],
            'date'         => $r['receipt_date'],
            'cv_no'        => $r['cv_no'] ?? '',
            'auto_section' => 'selling',
        ];
    }
    $stmt->close();
}

// ── Saved state ───────────────────────────────────────────────────────────
$saved_state = null;
if ($er_tbl && $er_tbl->num_rows > 0) {
    $sv = $conn->prepare("SELECT state_json, DATE_FORMAT(saved_at,'%Y-%m-%d %H:%i') AS saved_at FROM er_saved_state WHERE store_id=? AND save_date=?");
    if ($sv) {
        $sv->bind_param('is', $store_id, $date);
        $sv->execute();
        $sv_row = $sv->get_result()->fetch_assoc();
        if ($sv_row) {
            $d = json_decode($sv_row['state_json'], true);
            if ($d) $saved_state = array_merge($d, ['saved_at' => $sv_row['saved_at']]);
        }
        $sv->close();
    }
}

// ── 이후 날짜 ER에 배치된 항목 ID 수집 (다른 날짜 ER에서 사용된 항목 표시용) ──
$externally_placed = [];
if ($er_tbl && $er_tbl->num_rows > 0) {
    $fq = $conn->prepare(
        "SELECT state_json FROM er_saved_state
         WHERE store_id=? AND save_date>? AND save_date<=DATE_ADD(?,INTERVAL 60 DAY)"
    );
    if ($fq) {
        $fq->bind_param('iss', $store_id, $date, $date);
        $fq->execute();
        foreach ($fq->get_result()->fetch_all(MYSQLI_ASSOC) as $frow) {
            $fstate = json_decode($frow['state_json'], true);
            foreach ($fstate['sections'] ?? [] as $rows) {
                foreach ($rows as $r) {
                    $bid = (string)($r['item_id'] ?? '');
                    if ($bid) $externally_placed[] = $bid;
                }
            }
        }
        $fq->close();
    }
}
$externally_placed = array_values(array_unique($externally_placed));

$conn->close();

echo json_encode([
    'success'            => true,
    'items'              => $items,
    'date'               => $date,
    'saved_state'        => $saved_state,
    'externally_placed'  => $externally_placed,
], JSON_UNESCAPED_UNICODE);
