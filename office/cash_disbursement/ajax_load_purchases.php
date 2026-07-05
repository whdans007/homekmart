<?php
// Design Ref: CD는 ER의 현금 섹션(selling, not_selling, other_exp) 기반으로 로드
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

// ── 공급처-섹션 매핑 ──────────────────────────────────────────────────────
$mappings = [];
$map_stmt = $conn->prepare("SELECT supplier_name, section FROM cd_supplier_section_map WHERE store_id=?");
if ($map_stmt) {
    $map_stmt->bind_param('i', $store_id);
    $map_stmt->execute();
    foreach ($map_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $mappings[strtolower(trim($r['supplier_name']))] = $r['section'];
    }
    $map_stmt->close();
}

// ── ER 저장 상태 기반 item ID 파싱 ────────────────────────────────────────
// ER 현금 섹션: selling, not_selling, other_exp
$er_cash_sections = ['selling', 'not_selling', 'other_exp'];

function parse_er_ids(array $sections, array $keys): array {
    $ids = [];
    foreach ($keys as $sec) {
        foreach ($sections[$sec] ?? [] as $row) {
            $bid = (string)($row['item_id'] ?? '');
            if ($bid) $ids[] = $bid;
        }
    }
    return array_values(array_unique($ids));
}

// ── ER item ID → 실제 DB 레코드 로드 ─────────────────────────────────────
function load_items_by_ids($conn, array $ids, array $mappings): array {
    if (empty($ids)) return [];

    $pp_ids = $ep_ids = $rc_ids = [];
    foreach ($ids as $id) {
        if (strncmp($id, 'p_', 2) === 0)     $pp_ids[] = (int)substr($id, 2);
        elseif (strncmp($id, 'e_', 2) === 0) $ep_ids[] = (int)substr($id, 2);
        elseif (strncmp($id, 'r_', 2) === 0) $rc_ids[] = (int)substr($id, 2);
    }

    $items = [];

    if ($pp_ids) {
        $chk = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'cv_no'");
        $cv  = ($chk && $chk->num_rows > 0) ? 'cv_no' : "'' AS cv_no";
        $ph  = implode(',', $pp_ids);
        $res = $conn->query("SELECT id, supplier_name, delivery_content, amount, payment_date, payment_type, {$cv} FROM office_product_purchases WHERE id IN ($ph)");
        if ($res) while ($r = $res->fetch_assoc()) {
            $key = strtolower(trim($r['supplier_name']));
            $items[] = ['id'=>'p_'.$r['id'], 'type'=>$r['payment_type']==='check'?'check':'product',
                        'supplier_name'=>$r['supplier_name'], 'details'=>$r['delivery_content'],
                        'amount'=>(float)$r['amount'], 'date'=>$r['payment_date'],
                        'cv_no'=>$r['cv_no']??'', 'auto_section'=>$mappings[$key]??null];
        }
    }
    if ($ep_ids) {
        $ph  = implode(',', $ep_ids);
        $res = $conn->query("SELECT id, supplier_name, delivery_content, amount, payment_date FROM office_equipment_purchases WHERE id IN ($ph)");
        if ($res) while ($r = $res->fetch_assoc()) {
            $key = strtolower(trim($r['supplier_name']));
            $items[] = ['id'=>'e_'.$r['id'], 'type'=>'equipment',
                        'supplier_name'=>$r['supplier_name'], 'details'=>$r['delivery_content'],
                        'amount'=>(float)$r['amount'], 'date'=>$r['payment_date'],
                        'cv_no'=>'', 'auto_section'=>$mappings[$key]??null];
        }
    }
    if ($rc_ids) {
        $ph  = implode(',', $rc_ids);
        $res = $conn->query("SELECT id, supplier_name, description, amount, receipt_date, cv_no FROM office_receipts WHERE id IN ($ph)");
        if ($res) while ($r = $res->fetch_assoc()) {
            $key = strtolower(trim($r['supplier_name']));
            $items[] = ['id'=>'r_'.$r['id'], 'type'=>'receipt',
                        'supplier_name'=>$r['supplier_name'], 'details'=>$r['description'],
                        'amount'=>(float)$r['amount'], 'date'=>$r['receipt_date'],
                        'cv_no'=>$r['cv_no']??'', 'auto_section'=>$mappings[$key]??null];
        }
    }
    return $items;
}

// Design Ref: §7.1 — CD 로딩: r_ 아이템은 DB 컬럼 기반, p_/e_ 아이템은 기존 방식
$has_er_section = (bool)$conn->query("SHOW COLUMNS FROM office_receipts LIKE 'er_section'")->num_rows;
$has_cd_col_rc  = (bool)$conn->query("SHOW COLUMNS FROM office_receipts LIKE 'is_cd_paid'")->num_rows;

$er_tbl   = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
$has_er   = ($er_tbl && $er_tbl->num_rows > 0);
$er_found = false;
$items    = [];

// ── [당일+이월] r_ 아이템 — DB 컬럼 기반 ─────────────────────────────────
if ($has_er_section && $has_cd_col_rc) {
    // 당일 현금 섹션 영수증
    $rc_today = $conn->prepare(
        "SELECT id, supplier_name, description, amount, receipt_date,
                COALESCE(cv_no,'') AS cv_no, er_section
         FROM office_receipts
         WHERE store_id=?
           AND er_section IN ('selling','not_selling','other_exp_cash')
           AND is_cd_paid=0
           AND receipt_date=?"
    );
    if ($rc_today) {
        $rc_today->bind_param('is', $store_id, $date);
        $rc_today->execute();
        foreach ($rc_today->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $key = strtolower(trim($r['supplier_name']));
            $items[] = [
                'id'           => 'r_' . $r['id'],
                'type'         => 'receipt',
                'supplier_name'=> $r['supplier_name'],
                'details'      => $r['description'],
                'amount'       => (float)$r['amount'],
                'date'         => $r['receipt_date'],
                'cv_no'        => $r['cv_no'],
                'auto_section' => $mappings[$key] ?? null,
            ];
        }
        $rc_today->close();
    }

    // 이전 날짜 이월 (현금 섹션 영수증 중 미처리)
    $today_rc_nums = array_map(fn($i) => (int)substr($i['id'], 2),
        array_filter($items, fn($i) => strncmp($i['id'],'r_',2)===0));
    $excl = $today_rc_nums ? ' AND id NOT IN (' . implode(',', $today_rc_nums) . ')' : '';

    $rc_carry = $conn->prepare(
        "SELECT id, supplier_name, description, amount, receipt_date,
                COALESCE(cv_no,'') AS cv_no, er_section
         FROM office_receipts
         WHERE store_id=?
           AND er_section IN ('selling','not_selling','other_exp_cash')
           AND is_cd_paid=0
           AND receipt_date < ?
           AND receipt_date >= DATE_SUB(?, INTERVAL 60 DAY){$excl}"
    );
    if ($rc_carry) {
        $rc_carry->bind_param('iss', $store_id, $date, $date);
        $rc_carry->execute();
        foreach ($rc_carry->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $key = strtolower(trim($r['supplier_name']));
            $items[] = [
                'id'           => 'r_' . $r['id'],
                'type'         => 'receipt',
                'supplier_name'=> $r['supplier_name'],
                'details'      => $r['description'],
                'amount'       => (float)$r['amount'],
                'date'         => $r['receipt_date'],
                'cv_no'        => $r['cv_no'],
                'auto_section' => $mappings[$key] ?? null,
            ];
        }
        $rc_carry->close();
    }
}

// ── [당일+이월] p_/e_ 아이템 — 기존 ER JSON 방식 유지 ────────────────────
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
            // r_ 제외하고 p_/e_ 만 로드
            $today_ids_pe = array_filter(
                parse_er_ids($er_state['sections'] ?? [], $er_cash_sections),
                fn($id) => strncmp($id,'r_',2) !== 0
            );
            $pe_items = load_items_by_ids($conn, array_values($today_ids_pe), $mappings);
            $items = array_merge($items, $pe_items);
        }
    }
}

// ── [이월] p_/e_ 아이템 — 기존 방식 유지 ─────────────────────────────────
if ($has_er) {
    $cd_placed = [];
    $cd_tbl = $conn->query("SHOW TABLES LIKE 'cd_saved_state'");
    if ($cd_tbl && $cd_tbl->num_rows > 0) {
        $cd_q = $conn->prepare("SELECT state_json FROM cd_saved_state WHERE store_id=? AND save_date<? AND save_date>=DATE_SUB(?,INTERVAL 60 DAY)");
        if ($cd_q) {
            $cd_q->bind_param('iss', $store_id, $date, $date);
            $cd_q->execute();
            foreach ($cd_q->get_result()->fetch_all(MYSQLI_ASSOC) as $cd_row) {
                $cd_st = json_decode($cd_row['state_json'], true);
                foreach ($cd_st['sections'] ?? [] as $rows) {
                    foreach ($rows as $r) {
                        if (!empty($r['item_id'])) $cd_placed[] = $r['item_id'];
                        foreach ($r['batch_ids'] ?? [] as $bid) $cd_placed[] = (string)$bid;
                    }
                }
            }
            $cd_q->close();
        }
    }
    $cd_placed = array_flip(array_unique($cd_placed));

    $prev_q = $conn->prepare("SELECT state_json FROM er_saved_state WHERE store_id=? AND save_date<? AND save_date>=DATE_SUB(?,INTERVAL 60 DAY)");
    if ($prev_q) {
        $prev_q->bind_param('iss', $store_id, $date, $date);
        $prev_q->execute();
        $prev_ids = [];
        foreach ($prev_q->get_result()->fetch_all(MYSQLI_ASSOC) as $prev_er) {
            $prev_state = json_decode($prev_er['state_json'], true);
            $ids = parse_er_ids($prev_state['sections'] ?? [], $er_cash_sections);
            // r_ 아이템은 DB 컬럼 방식으로 처리됐으므로 제외
            $ids = array_filter($ids, fn($id) => strncmp($id,'r_',2) !== 0);
            $prev_ids = array_merge($prev_ids, array_values($ids));
        }
        $prev_q->close();

        $today_set = array_flip(array_column($items, 'id'));
        $carryover_ids = array_values(array_unique(array_filter(
            $prev_ids,
            fn($id) => !isset($cd_placed[$id]) && !isset($today_set[$id])
        )));

        $chk_paid = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'is_cd_paid'");
        if ($chk_paid && $chk_paid->num_rows > 0) {
            $pp_carryover = array_filter($carryover_ids, fn($id) => strncmp($id,'p_',2)===0);
            if ($pp_carryover) {
                $pp_nums = implode(',', array_map(fn($id)=>(int)substr($id,2), $pp_carryover));
                $paid_res = $conn->query("SELECT id FROM office_product_purchases WHERE id IN ($pp_nums) AND is_cd_paid=1");
                $paid_set = [];
                if ($paid_res) while ($pr = $paid_res->fetch_assoc()) $paid_set['p_'.$pr['id']] = true;
                $carryover_ids = array_values(array_filter($carryover_ids, fn($id) => !isset($paid_set[$id])));
            }
        }

        $items = array_merge($items, load_items_by_ids($conn, $carryover_ids, $mappings));
    }
}

// ── Lazy backfill: CD 배치 항목 is_cd_paid=1 보정 ─────────────────────────
$chk_paid_col = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'is_cd_paid'");
$has_paid_col = ($chk_paid_col && $chk_paid_col->num_rows > 0);
if ($has_paid_col) {
    $cd_tbl2 = $conn->query("SHOW TABLES LIKE 'cd_saved_state'");
    if ($cd_tbl2 && $cd_tbl2->num_rows > 0) {
        $bf = $conn->prepare("SELECT state_json FROM cd_saved_state WHERE store_id=? AND save_date<=?");
        if ($bf) {
            $bf->bind_param('is', $store_id, $date);
            $bf->execute();
            $bf_ids = [];
            foreach ($bf->get_result()->fetch_all(MYSQLI_ASSOC) as $st_row) {
                $st = json_decode($st_row['state_json'], true);
                foreach ($st['sections'] ?? [] as $rows) {
                    foreach ($rows as $row) {
                        if (!empty($row['item_id']) && strncmp($row['item_id'],'p_',2)===0)
                            { $n=(int)substr($row['item_id'],2); if($n>0) $bf_ids[]=$n; }
                        foreach ($row['batch_ids']??[] as $bid)
                            if (strncmp((string)$bid,'p_',2)===0)
                                { $n=(int)substr($bid,2); if($n>0) $bf_ids[]=$n; }
                    }
                }
            }
            $bf->close();
            if ($bf_ids) {
                $ph = implode(',', array_unique($bf_ids));
                $conn->query("UPDATE office_product_purchases SET is_cd_paid=1 WHERE id IN ($ph) AND is_cd_paid=0");
            }
        }
    }
}

// ── CD 저장 상태 ──────────────────────────────────────────────────────────
$saved_state = null;
$tbl_check = $conn->query("SHOW TABLES LIKE 'cd_saved_state'");
if ($tbl_check && $tbl_check->num_rows > 0) {
    $sv = $conn->prepare("SELECT state_json, DATE_FORMAT(saved_at,'%Y-%m-%d %H:%i') AS saved_at FROM cd_saved_state WHERE store_id=? AND save_date=?");
    if ($sv) {
        $sv->bind_param('is', $store_id, $date);
        $sv->execute();
        $sv_row = $sv->get_result()->fetch_assoc();
        if ($sv_row) {
            $decoded = json_decode($sv_row['state_json'], true);
            if ($decoded) $saved_state = array_merge($decoded, ['saved_at'=>$sv_row['saved_at']]);
        }
        $sv->close();
    }
}

// ── 누적 미결제 (이전 ER 현금 항목 기준, 미CD 처리) ─────────────────────
// 위에서 수집한 이전 ER 현금 항목 중 is_cd_paid=0 pp만 그룹핑
$accumulated = [];
if ($has_paid_col && $has_er) {
    // 이전 ER 현금 pp IDs 수집
    $prev_er_pp_ids = [];
    $p2 = $conn->prepare("SELECT state_json FROM er_saved_state WHERE store_id=? AND save_date<? AND save_date>=DATE_SUB(?,INTERVAL 60 DAY)");
    if ($p2) {
        $p2->bind_param('iss', $store_id, $date, $date);
        $p2->execute();
        foreach ($p2->get_result()->fetch_all(MYSQLI_ASSOC) as $p2row) {
            $p2st = json_decode($p2row['state_json'], true);
            foreach (parse_er_ids($p2st['sections']??[], $er_cash_sections) as $eid) {
                if (strncmp($eid,'p_',2)===0) $prev_er_pp_ids[]=(int)substr($eid,2);
            }
        }
        $p2->close();
    }
    $prev_er_pp_ids = array_values(array_unique($prev_er_pp_ids));

    if ($prev_er_pp_ids) {
        $ph = implode(',', $prev_er_pp_ids);
        $esc_date = $conn->real_escape_string($date);
        $acc_stmt = $conn->query(
            "SELECT supplier_name, COUNT(*) AS cnt, SUM(amount) AS total,
                    MIN(payment_date) AS from_date, MAX(payment_date) AS to_date,
                    GROUP_CONCAT(id ORDER BY payment_date SEPARATOR ',') AS id_list,
                    GROUP_CONCAT(CONCAT(DATE_FORMAT(payment_date,'%b %e'),': ₱',FORMAT(amount,2)) ORDER BY payment_date SEPARATOR ' / ') AS details_str
             FROM office_product_purchases
             WHERE id IN ($ph) AND is_cd_paid=0 AND payment_date<'{$esc_date}'
             GROUP BY supplier_name ORDER BY total DESC"
        );
        if ($acc_stmt) while ($r = $acc_stmt->fetch_assoc()) {
            $accumulated[] = [
                'supplier'    => $r['supplier_name'],
                'cnt'         => (int)$r['cnt'],
                'total'       => (float)$r['total'],
                'from_date'   => $r['from_date'],
                'to_date'     => $r['to_date'],
                'ids'         => array_map(fn($id)=>'p_'.$id, explode(',', $r['id_list'])),
                'details_str' => $r['details_str'],
            ];
        }
    }
}

$conn->close();

echo json_encode([
    'success'      => true,
    'items'        => $items,
    'date'         => $date,
    'saved_state'  => $saved_state,
    'accumulated'  => $accumulated,
    'er_found'     => $er_found,
], JSON_UNESCAPED_UNICODE);
