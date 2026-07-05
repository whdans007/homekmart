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

// Design Ref: §5.1 — r_ (receipts) is_cd_paid 업데이트용
function extract_placed_receipt_ids_cd(array $sections): array {
    $ids = [];
    foreach ($sections as $rows) {
        foreach ($rows as $row) {
            $bid = $row['item_id'] ?? '';
            if (strncmp((string)$bid, 'r_', 2) === 0) {
                $n = (int)substr($bid, 2); if ($n > 0) $ids[$n] = $n;
            }
            foreach ($row['batch_ids'] ?? [] as $bbid) {
                if (strncmp((string)$bbid, 'r_', 2) === 0) {
                    $n = (int)substr($bbid, 2); if ($n > 0) $ids[$n] = $n;
                }
            }
        }
    }
    return array_values($ids);
}

// Extract all product purchase numeric IDs from sections (batch + regular)
function extract_batch_pp_ids(array $sections): array {
    $ids = [];
    foreach ($sections as $rows) {
        foreach ($rows as $row) {
            // batch 행: batch_ids 배열에서 추출
            if (!empty($row['is_batch']) && !empty($row['batch_ids'])) {
                foreach ($row['batch_ids'] as $bid) {
                    if (strncmp((string)$bid, 'p_', 2) === 0) {
                        $n = (int)substr($bid, 2);
                        if ($n > 0) $ids[$n] = $n;
                    }
                }
            }
            // 일반 행: item_id에서 추출
            if (!empty($row['item_id']) && strncmp((string)$row['item_id'], 'p_', 2) === 0) {
                $n = (int)substr($row['item_id'], 2);
                if ($n > 0) $ids[$n] = $n;
            }
        }
    }
    return array_values($ids);
}

$conn = get_db_connection();

// Check is_cd_paid column exists
$has_paid_col = false;
$chk = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'is_cd_paid'");
if ($chk && $chk->num_rows > 0) $has_paid_col = true;

$conn->query("CREATE TABLE IF NOT EXISTS cd_saved_state (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  save_date  DATE NOT NULL,
  state_json MEDIUMTEXT NOT NULL,
  saved_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cd_state (store_id, save_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Load previous state BEFORE save (to diff placed IDs)
$prev_batch_ids = [];
$prev_rc_ids    = [];
$has_rc_paid_col = (bool)$conn->query("SHOW COLUMNS FROM office_receipts LIKE 'is_cd_paid'")->num_rows;

$prev_stmt = $conn->prepare(
    "SELECT state_json FROM cd_saved_state WHERE store_id=? AND save_date=?"
);
if ($prev_stmt) {
    $prev_stmt->bind_param('is', $store_id, $date);
    $prev_stmt->execute();
    $prev_row = $prev_stmt->get_result()->fetch_assoc();
    if ($prev_row) {
        $prev = json_decode($prev_row['state_json'], true);
        if ($prev && isset($prev['sections'])) {
            $prev_batch_ids = extract_batch_pp_ids($prev['sections']);
            $prev_rc_ids    = extract_placed_receipt_ids_cd($prev['sections']);
        }
    }
    $prev_stmt->close();
}

$stmt = $conn->prepare(
    "INSERT INTO cd_saved_state (store_id, save_date, state_json)
     VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE state_json=VALUES(state_json), saved_at=NOW()"
);
$stmt->bind_param('iss', $store_id, $date, $state);
$ok = $stmt->execute();
$stmt->close();

// is_cd_paid 절대값 동기화 — 섹션에 있는 항목은 1, 없는 항목은 0
if ($ok && $has_paid_col) {
    $placed_ids = extract_batch_pp_ids($decoded['sections']); // 현재 섹션의 모든 pp ID

    // 섹션에 있는 항목 → is_cd_paid=1
    if ($placed_ids) {
        $ph = implode(',', array_fill(0, count($placed_ids), '?'));
        $u  = $conn->prepare("UPDATE office_product_purchases SET is_cd_paid=1 WHERE id IN ($ph)");
        $u->bind_param(str_repeat('i', count($placed_ids)), ...$placed_ids);
        $u->execute(); $u->close();
    }

    // 같은 날짜 항목 중 섹션에 없는 항목 → is_cd_paid=0
    if ($placed_ids) {
        $ph = implode(',', array_fill(0, count($placed_ids), '?'));
        $u  = $conn->prepare(
            "UPDATE office_product_purchases SET is_cd_paid=0
             WHERE store_id=? AND payment_date=? AND id NOT IN ($ph) AND is_cd_paid=1"
        );
        $params = array_merge([$store_id, $date], $placed_ids);
        $u->bind_param('is' . str_repeat('i', count($placed_ids)), ...$params);
        $u->execute(); $u->close();
    } else {
        // 섹션이 비었으면 해당 날짜 전체 is_cd_paid=0
        $u = $conn->prepare("UPDATE office_product_purchases SET is_cd_paid=0 WHERE store_id=? AND payment_date=? AND is_cd_paid=1");
        $u->bind_param('is', $store_id, $date);
        $u->execute(); $u->close();
    }
}

// Plan SC: is_cd_paid 동기화 (r_ 아이템) — prev_rc_ids는 저장 전에 이미 로드됨
if ($ok && $has_rc_paid_col) {
    $new_rc_ids  = extract_placed_receipt_ids_cd($decoded['sections']);
    $to_cd_place = array_values(array_diff($new_rc_ids, $prev_rc_ids));
    $to_cd_free  = array_values(array_diff($prev_rc_ids, $new_rc_ids));
    if ($to_cd_place) {
        $ph = implode(',', array_fill(0, count($to_cd_place), '?'));
        $u  = $conn->prepare("UPDATE office_receipts SET is_cd_paid=1 WHERE id IN ($ph)");
        $u->bind_param(str_repeat('i', count($to_cd_place)), ...$to_cd_place);
        $u->execute(); $u->close();
    }
    if ($to_cd_free) {
        $ph = implode(',', array_fill(0, count($to_cd_free), '?'));
        $u  = $conn->prepare("UPDATE office_receipts SET is_cd_paid=0 WHERE id IN ($ph)");
        $u->bind_param(str_repeat('i', count($to_cd_free)), ...$to_cd_free);
        $u->execute(); $u->close();
    }
}

$conn->close();

echo json_encode(['success' => $ok]);
