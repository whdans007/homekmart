<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'POST required']);
    exit;
}

$store_id = get_office_store_id();
$date     = trim($_POST['date'] ?? '');
$state    = trim($_POST['state'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$state) {
    echo json_encode(['success'=>false,'error'=>'Invalid params']);
    exit;
}

$decoded = json_decode($state, true);
if (!$decoded || !isset($decoded['sections'])) {
    echo json_encode(['success'=>false,'error'=>'Invalid state JSON']);
    exit;
}

// Extract PP numeric IDs from all section rows (normal + return rows)
function extract_placed_pp_ids(array $sections): array {
    $ids = [];
    foreach ($sections as $rows) {
        foreach ($rows as $row) {
            $bid = $row['item_id'] ?? '';
            if (strncmp((string)$bid, 'p_', 2) === 0) {
                $n = (int)substr($bid, 2);
                if ($n > 0) $ids[$n] = $n;
            }
        }
    }
    return array_values($ids);
}

// Design Ref: §4.1 — r_ (receipts) is_cer_placed 업데이트용
function extract_placed_receipt_ids_cer(array $sections): array {
    $ids = [];
    foreach ($sections as $rows) {
        foreach ($rows as $row) {
            $bid = $row['item_id'] ?? '';
            if (strncmp((string)$bid, 'r_', 2) === 0) {
                $n = (int)substr($bid, 2);
                if ($n > 0) $ids[$n] = $n;
            }
        }
    }
    return array_values($ids);
}

// Extract {pp_id => check_no} map from sections (for saving cer_check_no)
function extract_check_no_map(array $sections): array {
    $map = [];
    foreach ($sections as $rows) {
        foreach ($rows as $row) {
            $bid = $row['item_id'] ?? '';
            if (strncmp((string)$bid, 'p_', 2) !== 0) continue;
            $n = (int)substr($bid, 2);
            if ($n <= 0) continue;
            // Return rows: check_no is the ORIGINAL number, new_check_no is the replacement
            $map[$n] = [
                'check_no'     => $row['check_no'] ?? '',
                'new_check_no' => $row['new_check_no'] ?? '',
                'is_return'    => !empty($row['is_return']),
            ];
        }
    }
    return $map;
}

$conn = get_db_connection();

// Check is_cer_placed column exists
$has_col = false;
$chk = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'is_cer_placed'");
if ($chk && $chk->num_rows > 0) $has_col = true;

$conn->query("CREATE TABLE IF NOT EXISTS cer_saved_state (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  save_date  DATE NOT NULL,
  state_json MEDIUMTEXT NOT NULL,
  saved_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cer (store_id, save_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Load previous state to diff placed IDs (pp + receipt 모두 사용)
$prev_ids = [];
$prev_row = null;
$prev_stmt = $conn->prepare("SELECT state_json FROM cer_saved_state WHERE store_id=? AND save_date=?");
if ($prev_stmt) {
    $prev_stmt->bind_param('is', $store_id, $date);
    $prev_stmt->execute();
    $prev_row = $prev_stmt->get_result()->fetch_assoc();
    if ($prev_row && $has_col) {
        $prev = json_decode($prev_row['state_json'], true);
        if ($prev && isset($prev['sections'])) {
            $prev_ids = extract_placed_pp_ids($prev['sections']);
        }
    }
    $prev_stmt->close();
}

$stmt = $conn->prepare(
    "INSERT INTO cer_saved_state (store_id, save_date, state_json)
     VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE state_json=VALUES(state_json), saved_at=NOW()"
);
$stmt->bind_param('iss', $store_id, $date, $state);
$ok = $stmt->execute();
$stmt->close();

// Reconcile is_cer_placed + save check numbers + handle returns
if ($ok && $has_col) {
    $new_ids    = extract_placed_pp_ids($decoded['sections']);
    $check_map  = extract_check_no_map($decoded['sections']);
    $to_place   = array_values(array_diff($new_ids, $prev_ids));
    $to_free    = array_values(array_diff($prev_ids, $new_ids));

    // Mark newly placed (non-return) as is_cer_placed=1
    $to_place_normal = array_filter($to_place, fn($id) => empty($check_map[$id]['is_return']));
    if ($to_place_normal) {
        $ph = implode(',', array_fill(0, count($to_place_normal), '?'));
        $u  = $conn->prepare("UPDATE office_product_purchases SET is_cer_placed=1 WHERE id IN ($ph)");
        $u->bind_param(str_repeat('i', count($to_place_normal)), ...array_values($to_place_normal));
        $u->execute(); $u->close();
    }

    // Mark return rows as is_cer_returned=1
    $chk_ret = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'is_cer_returned'");
    $has_ret = ($chk_ret && $chk_ret->num_rows > 0);
    if ($has_ret) {
        $to_return = array_filter($to_place, fn($id) => !empty($check_map[$id]['is_return']));
        foreach ($to_return as $rid) {
            $u = $conn->prepare("UPDATE office_product_purchases SET is_cer_returned=1 WHERE id=?");
            $u->bind_param('i', $rid); $u->execute(); $u->close();
        }
    }

    // Un-place removed rows
    if ($to_free) {
        $ph = implode(',', array_fill(0, count($to_free), '?'));
        $u  = $conn->prepare("UPDATE office_product_purchases SET is_cer_placed=0 WHERE id IN ($ph)");
        $u->bind_param(str_repeat('i', count($to_free)), ...$to_free);
        $u->execute(); $u->close();
    }

    // Save cer_check_no for each placed item
    $chk_cno = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'cer_check_no'");
    if ($chk_cno && $chk_cno->num_rows > 0) {
        foreach ($check_map as $pp_id => $info) {
            $cn = $info['is_return']
                ? ($info['new_check_no'] ?: $info['check_no'])
                : $info['check_no'];
            if ($cn === '') continue;
            $u = $conn->prepare("UPDATE office_product_purchases SET cer_check_no=? WHERE id=?");
            $u->bind_param('si', $cn, $pp_id); $u->execute(); $u->close();
        }
    }
}

// Plan SC: is_cer_placed 동기화 (r_ 아이템)
$chk_cer_col = $conn->query("SHOW COLUMNS FROM office_receipts LIKE 'is_cer_placed'");
if ($ok && $chk_cer_col && $chk_cer_col->num_rows > 0) {
    $new_rc_ids  = extract_placed_receipt_ids_cer($decoded['sections']);
    $prev_state  = isset($prev_row) ? json_decode($prev_row['state_json'] ?? '{}', true) : [];
    $prev_rc_ids = extract_placed_receipt_ids_cer($prev_state['sections'] ?? []);

    $to_cer_place = array_values(array_diff($new_rc_ids, $prev_rc_ids));
    $to_cer_free  = array_values(array_diff($prev_rc_ids, $new_rc_ids));

    if ($to_cer_place) {
        $ph = implode(',', array_fill(0, count($to_cer_place), '?'));
        $u  = $conn->prepare("UPDATE office_receipts SET is_cer_placed=1 WHERE id IN ($ph)");
        $u->bind_param(str_repeat('i', count($to_cer_place)), ...$to_cer_place);
        $u->execute(); $u->close();
    }
    if ($to_cer_free) {
        $ph = implode(',', array_fill(0, count($to_cer_free), '?'));
        $u  = $conn->prepare("UPDATE office_receipts SET is_cer_placed=0 WHERE id IN ($ph)");
        $u->bind_param(str_repeat('i', count($to_cer_free)), ...$to_cer_free);
        $u->execute(); $u->close();
    }
}

$conn->close();

echo json_encode(['success' => $ok]);
