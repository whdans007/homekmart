<?php
// Design Ref: §6.1 — CER 로딩: DB 컬럼 기반 (er_section + is_cer_placed)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$store_id = get_office_store_id();
$date     = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success'=>false,'error'=>'Invalid date']); exit;
}

$conn = get_db_connection();

// ── DB 컬럼 존재 여부 확인 ────────────────────────────────────────────────
$has_er_section  = (bool)$conn->query("SHOW COLUMNS FROM office_receipts LIKE 'er_section'")->num_rows;
$has_cer_placed  = (bool)$conn->query("SHOW COLUMNS FROM office_receipts LIKE 'is_cer_placed'")->num_rows;
$items = [];

// ── [당일] receipt — er_section 기반 (DB 컬럼 방식) ─────────────────────
if ($has_er_section && $has_cer_placed) {
    // Plan SC: check 섹션에 배치된 당일 영수증
    $rc_q = $conn->prepare(
        "SELECT id, supplier_name, description, amount, receipt_date,
                COALESCE(cv_no,'') AS cv_no, er_section, payment_type
         FROM office_receipts
         WHERE store_id=?
           AND er_section IN ('check_sup','other_exp_check')
           AND is_cer_placed=0
           AND receipt_date=?"
    );
    if ($rc_q) {
        $rc_q->bind_param('is', $store_id, $date);
        $rc_q->execute();
        foreach ($rc_q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $items[] = [
                'id'           => 'r_' . $r['id'],
                'supplier_name'=> $r['supplier_name'],
                'particular'   => $r['description'],
                'amount'       => (float)$r['amount'],
                'date'         => $r['receipt_date'],
                'sales_invoice'=> $r['cv_no'],
                'auto_section' => null,
            ];
        }
        $rc_q->close();
    }
}

// ── [당일] product_purchases (check) — 기존 방식 유지 ───────────────────
$er_tbl   = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
$has_er   = ($er_tbl && $er_tbl->num_rows > 0);
$er_found = false;

if ($has_er) {
    $er_q = $conn->prepare("SELECT state_json FROM er_saved_state WHERE store_id=? AND save_date=?");
    if ($er_q) {
        $er_q->bind_param('is', $store_id, $date);
        $er_q->execute();
        $er_row = $er_q->get_result()->fetch_assoc();
        $er_q->close();
        if ($er_row) {
            $er_found = true;
            $er_state = json_decode($er_row['state_json'], true);

            // check_sup 섹션의 pc_ 아이템만 로드 (기존 product_purchases)
            $pc_ids = [];
            foreach ($er_state['sections']['check_sup'] ?? [] as $row) {
                $bid = (string)($row['item_id'] ?? '');
                if (strncmp($bid, 'pc_', 3) === 0) {
                    $n = (int)substr($bid, 3); if ($n > 0) $pc_ids[] = $n;
                }
            }
            // other_exp_check 섹션의 pc_ 아이템
            foreach ($er_state['sections']['other_exp_check'] ?? [] as $row) {
                $bid = (string)($row['item_id'] ?? '');
                if (strncmp($bid, 'pc_', 3) === 0) {
                    $n = (int)substr($bid, 3); if ($n > 0) $pc_ids[] = $n;
                }
            }
            $pc_ids = array_values(array_unique($pc_ids));

            if ($pc_ids) {
                $ph  = implode(',', $pc_ids);
                $res = $conn->query(
                    "SELECT pp.id, pp.supplier_name, pp.delivery_content, pp.amount,
                            pp.check_issued_date,
                            COALESCE(r.cv_no,'') AS sales_invoice
                     FROM office_product_purchases pp
                     LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
                     WHERE pp.id IN ($ph)"
                );
                $existing_ids = array_column($items, 'id');
                if ($res) while ($r = $res->fetch_assoc()) {
                    $iid = 'pc_' . $r['id'];
                    if (!in_array($iid, $existing_ids)) {
                        $items[] = [
                            'id'           => $iid,
                            'supplier_name'=> $r['supplier_name'],
                            'particular'   => $r['delivery_content'],
                            'amount'       => (float)$r['amount'],
                            'date'         => $r['check_issued_date'],
                            'sales_invoice'=> $r['sales_invoice'],
                            'auto_section' => null,
                        ];
                    }
                }
            }

            // other_exp_check 섹션의 e_ 아이템 (equipment purchases)
            $eq_ids = [];
            foreach ($er_state['sections']['other_exp_check'] ?? [] as $row) {
                $bid = (string)($row['item_id'] ?? '');
                if (strncmp($bid, 'e_', 2) === 0) {
                    $n = (int)substr($bid, 2); if ($n > 0) $eq_ids[] = $n;
                }
            }
            $eq_ids = array_values(array_unique($eq_ids));
            if ($eq_ids) {
                $ph  = implode(',', $eq_ids);
                $res = $conn->query(
                    "SELECT id, supplier_name, delivery_content, amount, payment_date
                     FROM office_equipment_purchases WHERE id IN ($ph)"
                );
                $existing_ids = array_flip(array_column($items, 'id'));
                if ($res) while ($r = $res->fetch_assoc()) {
                    $iid = 'e_' . $r['id'];
                    if (!isset($existing_ids[$iid])) {
                        $items[] = [
                            'id'           => $iid,
                            'supplier_name'=> $r['supplier_name'],
                            'particular'   => $r['delivery_content'],
                            'amount'       => (float)$r['amount'],
                            'date'         => $r['payment_date'],
                            'sales_invoice'=> '',
                            'auto_section' => null,
                        ];
                    }
                }
            }
        }
    }
}

// ── [이월] receipt — check_sup만 이월 (other_exp_check는 날짜 고정) ───────
if ($has_er_section && $has_cer_placed) {
    $today_rc_ids = array_map(fn($i) => $i['id'], array_filter($items, fn($i) => strncmp($i['id'],'r_',2)===0));
    $today_rc_nums = array_map(fn($id) => (int)substr($id, 2), $today_rc_ids);

    $excl = '';
    if ($today_rc_nums) {
        $excl = ' AND id NOT IN (' . implode(',', $today_rc_nums) . ')';
    }

    $rc_carry = $conn->prepare(
        "SELECT id, supplier_name, description, amount, receipt_date,
                COALESCE(cv_no,'') AS cv_no, er_section
         FROM office_receipts
         WHERE store_id=?
           AND er_section = 'check_sup'
           AND is_cer_placed=0
           AND receipt_date < ?
           AND receipt_date >= DATE_SUB(?, INTERVAL 60 DAY){$excl}"
    );
    if ($rc_carry) {
        $rc_carry->bind_param('iss', $store_id, $date, $date);
        $rc_carry->execute();
        foreach ($rc_carry->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $items[] = [
                'id'           => 'r_' . $r['id'],
                'supplier_name'=> $r['supplier_name'],
                'particular'   => $r['description'],
                'amount'       => (float)$r['amount'],
                'date'         => $r['receipt_date'],
                'sales_invoice'=> $r['cv_no'],
                'auto_section' => null,
            ];
        }
        $rc_carry->close();
    }
}

// ── [이월] product_purchases (check_sup) — 기존 방식 유지 ────────────────
if ($has_er) {
    $cer_placed_pp = [];
    $cer_tbl = $conn->query("SHOW TABLES LIKE 'cer_saved_state'");
    if ($cer_tbl && $cer_tbl->num_rows > 0) {
        $cq = $conn->prepare("SELECT state_json FROM cer_saved_state WHERE store_id=? AND save_date<? AND save_date>=DATE_SUB(?,INTERVAL 60 DAY)");
        if ($cq) {
            $cq->bind_param('iss', $store_id, $date, $date);
            $cq->execute();
            foreach ($cq->get_result()->fetch_all(MYSQLI_ASSOC) as $cr) {
                $cs = json_decode($cr['state_json'], true);
                foreach ($cs['sections'] ?? [] as $rows) {
                    foreach ($rows as $r) {
                        if (!empty($r['item_id'])) $cer_placed_pp[] = $r['item_id'];
                    }
                }
            }
            $cq->close();
        }
    }
    $cer_placed_pp = array_flip(array_unique($cer_placed_pp));

    $prev_q = $conn->prepare("SELECT state_json FROM er_saved_state WHERE store_id=? AND save_date<? AND save_date>=DATE_SUB(?,INTERVAL 60 DAY)");
    if ($prev_q) {
        $prev_q->bind_param('iss', $store_id, $date, $date);
        $prev_q->execute();
        $prev_pc_ids = [];
        $prev_eq_ids = [];
        foreach ($prev_q->get_result()->fetch_all(MYSQLI_ASSOC) as $prev_er) {
            $prev_state = json_decode($prev_er['state_json'], true);
            foreach ($prev_state['sections']['check_sup'] ?? [] as $row) {
                $bid = (string)($row['item_id'] ?? '');
                if (strncmp($bid, 'pc_', 3) === 0) {
                    $n = (int)substr($bid, 3); if ($n > 0) $prev_pc_ids[] = $bid;
                }
            }
            foreach ($prev_state['sections']['other_exp_check'] ?? [] as $row) {
                $bid = (string)($row['item_id'] ?? '');
                if (strncmp($bid, 'e_', 2) === 0) {
                    $n = (int)substr($bid, 2); if ($n > 0) $prev_eq_ids[] = $bid;
                }
            }
        }
        $prev_q->close();

        $today_set = array_flip(array_column($items, 'id'));
        $carry_pc  = array_values(array_filter(
            array_unique($prev_pc_ids),
            fn($id) => !isset($cer_placed_pp[$id]) && !isset($today_set[$id])
        ));

        if ($carry_pc) {
            $nums = array_map(fn($id) => (int)substr($id, 3), $carry_pc);
            $ph   = implode(',', $nums);
            $res  = $conn->query(
                "SELECT pp.id, pp.supplier_name, pp.delivery_content, pp.amount,
                        pp.check_issued_date,
                        COALESCE(r.cv_no,'') AS sales_invoice
                 FROM office_product_purchases pp
                 LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
                 WHERE pp.id IN ($ph)"
            );
            if ($res) while ($r = $res->fetch_assoc()) {
                $items[] = [
                    'id'           => 'pc_' . $r['id'],
                    'supplier_name'=> $r['supplier_name'],
                    'particular'   => $r['delivery_content'],
                    'amount'       => (float)$r['amount'],
                    'date'         => $r['check_issued_date'],
                    'sales_invoice'=> $r['sales_invoice'],
                    'auto_section' => null,
                ];
            }
        }

        // 이월 e_ (other_exp_check 섹션의 equipment purchases)
        $carry_eq = array_values(array_filter(
            array_unique($prev_eq_ids),
            fn($id) => !isset($cer_placed_pp[$id]) && !isset($today_set[$id])
        ));
        if ($carry_eq) {
            $nums = array_map(fn($id) => (int)substr($id, 2), $carry_eq);
            $ph   = implode(',', $nums);
            $res  = $conn->query(
                "SELECT id, supplier_name, delivery_content, amount, payment_date
                 FROM office_equipment_purchases WHERE id IN ($ph)"
            );
            if ($res) while ($r = $res->fetch_assoc()) {
                $items[] = [
                    'id'           => 'e_' . $r['id'],
                    'supplier_name'=> $r['supplier_name'],
                    'particular'   => $r['delivery_content'],
                    'amount'       => (float)$r['amount'],
                    'date'         => $r['payment_date'],
                    'sales_invoice'=> '',
                    'auto_section' => null,
                ];
            }
        }
    }
}

// ── CER 저장 상태 ─────────────────────────────────────────────────────────
$saved_state = null;
$tbl = $conn->query("SHOW TABLES LIKE 'cer_saved_state'");
if ($tbl && $tbl->num_rows > 0) {
    $sv = $conn->prepare("SELECT state_json, DATE_FORMAT(saved_at,'%Y-%m-%d %H:%i') AS saved_at FROM cer_saved_state WHERE store_id=? AND save_date=?");
    if ($sv) {
        $sv->bind_param('is', $store_id, $date);
        $sv->execute();
        $sv_row = $sv->get_result()->fetch_assoc();
        if ($sv_row) {
            $decoded_sv = json_decode($sv_row['state_json'], true);
            if ($decoded_sv) {
                $saved_state = array_merge($decoded_sv, ['saved_at' => $sv_row['saved_at']]);
            }
        }
        $sv->close();
    }
}

// ── 마지막 체크번호 ────────────────────────────────────────────────────────
$last_check_no = '';
$lc = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'cer_check_no'");
if ($lc && $lc->num_rows > 0) {
    $ls = $conn->prepare("SELECT cer_check_no FROM office_product_purchases WHERE store_id=? AND payment_type='check' AND cer_check_no IS NOT NULL AND cer_check_no!='' ORDER BY id DESC LIMIT 1");
    if ($ls) {
        $ls->bind_param('i', $store_id);
        $ls->execute();
        $lr = $ls->get_result()->fetch_assoc();
        if ($lr) $last_check_no = $lr['cer_check_no'];
        $ls->close();
    }
}

$conn->close();

echo json_encode([
    'success'       => true,
    'items'         => array_values($items),
    'date'          => $date,
    'saved_state'   => $saved_state,
    'last_check_no' => $last_check_no,
    'er_found'      => $er_found,
], JSON_UNESCAPED_UNICODE);
