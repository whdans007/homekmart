<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'POST required']); exit; }

$store_id = get_office_store_id();
$date  = trim($_POST['date'] ?? '');
$state = trim($_POST['state'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$state) { echo json_encode(['success'=>false,'error'=>'Invalid params']); exit; }

$decoded = json_decode($state, true);
if (!$decoded || !isset($decoded['sections'])) { echo json_encode(['success'=>false,'error'=>'Invalid JSON']); exit; }

$conn = get_db_connection();
$conn->query("CREATE TABLE IF NOT EXISTS er_saved_state (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  save_date DATE NOT NULL,
  state_json MEDIUMTEXT NOT NULL,
  saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_er (store_id, save_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 이전 상태 로드 (diff용)
$prev_ids = ['p'=>[], 'pc'=>[], 'e'=>[], 'r'=>[]];
$prev_r_by_section = []; // ['selling'=>[5,7], 'other_exp_check'=>[12], ...]
$tbl2 = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if ($tbl2 && $tbl2->num_rows > 0) {
    $ps = $conn->prepare("SELECT state_json FROM er_saved_state WHERE store_id=? AND save_date=?");
    if ($ps) {
        $ps->bind_param('is', $store_id, $date);
        $ps->execute();
        $pr = $ps->get_result()->fetch_assoc();
        if ($pr) {
            $pdata = json_decode($pr['state_json'], true);
            if ($pdata && isset($pdata['sections'])) {
                foreach ($pdata['sections'] as $sec_name => $rows) {
                    foreach ($rows as $row) {
                        $bid = (string)($row['item_id'] ?? '');
                        foreach (['pc_','p_','e_','r_'] as $pfx) {
                            if (strncmp($bid, $pfx, strlen($pfx)) === 0) {
                                $n = (int)substr($bid, strlen($pfx));
                                if ($n > 0) {
                                    $prev_ids[rtrim($pfx,'_')][] = $n;
                                    if ($pfx === 'r_') $prev_r_by_section[$sec_name][] = $n;
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }
        $ps->close();
    }
}

$stmt = $conn->prepare("INSERT INTO er_saved_state (store_id,save_date,state_json) VALUES(?,?,?) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),saved_at=NOW()");
$stmt->bind_param('iss', $store_id, $date, $state);
$ok = $stmt->execute();
$stmt->close();

// 새 상태에서 ID 추출
$new_ids = ['p'=>[], 'pc'=>[], 'e'=>[], 'r'=>[]];
$new_r_by_section = []; // Plan SC: er_section 추적용
foreach ($decoded['sections'] as $sec_name => $rows) {
    foreach ($rows as $row) {
        $bid = (string)($row['item_id'] ?? '');
        foreach (['pc_','p_','e_','r_'] as $pfx) {
            if (strncmp($bid, $pfx, strlen($pfx)) === 0) {
                $n = (int)substr($bid, strlen($pfx));
                if ($n > 0) {
                    $new_ids[rtrim($pfx,'_')][] = $n;
                    if ($pfx === 'r_') $new_r_by_section[$sec_name][] = $n;
                }
                break;
            }
        }
    }
}

// is_er_placed 동기화 (클로저 없이 직접 처리)
function er_sync_placed($conn, $tbl, array $new_pp, array $prev_pp) {
    $chk = $conn->query("SHOW COLUMNS FROM {$tbl} LIKE 'is_er_placed'");
    if (!$chk || $chk->num_rows === 0) return;
    $to_place = array_values(array_diff($new_pp, $prev_pp));
    $to_free  = array_values(array_diff($prev_pp, $new_pp));
    if ($to_place) {
        $ph = implode(',', array_fill(0, count($to_place), '?'));
        $u  = $conn->prepare("UPDATE {$tbl} SET is_er_placed=1 WHERE id IN ($ph)");
        $u->bind_param(str_repeat('i', count($to_place)), ...$to_place);
        $u->execute(); $u->close();
    }
    if ($to_free) {
        $ph = implode(',', array_fill(0, count($to_free), '?'));
        $u  = $conn->prepare("UPDATE {$tbl} SET is_er_placed=0 WHERE id IN ($ph)");
        $u->bind_param(str_repeat('i', count($to_free)), ...$to_free);
        $u->execute(); $u->close();
    }
}

if ($ok) {
    // Product Purchase: p_ + pc_ 합산
    $pp_new  = array_values(array_unique(array_merge($new_ids['p'],  $new_ids['pc'])));
    $pp_prev = array_values(array_unique(array_merge($prev_ids['p'], $prev_ids['pc'])));
    er_sync_placed($conn, 'office_product_purchases',  $pp_new,       $pp_prev);
    er_sync_placed($conn, 'office_equipment_purchases', $new_ids['e'], $prev_ids['e']);

    // Design Ref: §3.1 — receipts는 er_section도 함께 업데이트
    er_sync_receipt_section($conn, $store_id, $new_r_by_section, $prev_ids['r'] ?? []);
}

// Plan SC: er_section 컬럼 업데이트 (receipts 전용)
function er_sync_receipt_section($conn, $store_id, array $new_by_section, array $prev_all) {
    $chk = $conn->query("SHOW COLUMNS FROM office_receipts LIKE 'er_section'");
    if (!$chk || $chk->num_rows === 0) return;

    // er_section 컬럼 기반 업데이트
    $new_all = [];
    foreach ($new_by_section as $section => $ids) {
        foreach ($ids as $id) $new_all[$id] = $section;
    }

    // 새로 배치된 것: is_er_placed=1, er_section=섹션명, payment_type 업데이트
    $check_sections = ['check_sup', 'other_exp_check'];
    foreach ($new_by_section as $section => $ids) {
        $to_place = array_values(array_diff($ids, $prev_all));
        if (!$to_place) continue;
        $ph   = implode(',', array_fill(0, count($to_place), '?'));
        $ptype = in_array($section, $check_sections) ? 'check' : 'cash';
        $u    = $conn->prepare(
            "UPDATE office_receipts SET er_section=?, payment_type=?
             WHERE id IN ($ph) AND store_id=?"
        );
        $params = array_merge([$section, $ptype], $to_place, [$store_id]);
        $u->bind_param('ss' . str_repeat('i', count($to_place)) . 'i', ...$params);
        $u->execute(); $u->close();
    }

    // 제거된 것: is_er_placed=0, er_section=NULL
    $to_free = array_values(array_diff($prev_all, array_keys($new_all)));
    if ($to_free) {
        $ph = implode(',', array_fill(0, count($to_free), '?'));
        $u  = $conn->prepare(
            "UPDATE office_receipts SET er_section=NULL WHERE id IN ($ph) AND store_id=?"
        );
        $params = array_merge($to_free, [$store_id]);
        $u->bind_param(str_repeat('i', count($to_free)) . 'i', ...$params);
        $u->execute(); $u->close();
    }
}

$conn->close();
echo json_encode(['success'=>$ok]);
