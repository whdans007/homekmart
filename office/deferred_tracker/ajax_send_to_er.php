<?php
// Deferred에서 선택한 항목을 특정 날짜 ER 섹션에 추가
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'POST required']); exit;
}

$store_id  = get_office_store_id();
$er_date   = trim($_POST['er_date'] ?? '');
$item_ids  = json_decode($_POST['item_ids'] ?? '[]', true); // ['p_5','p_12',...]

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $er_date) || empty($item_ids)) {
    echo json_encode(['success'=>false,'error'=>'Invalid params']); exit;
}

$conn = get_db_connection();

// ── ER 저장 상태 테이블 확인 ─────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS er_saved_state (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  save_date DATE NOT NULL,
  state_json MEDIUMTEXT NOT NULL,
  saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_er (store_id, save_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── 기존 ER 상태 로드 ────────────────────────────────────────────────────
$sections = ['selling'=>[], 'not_selling'=>[], 'check_sup'=>[], 'other_exp'=>[]];
$sv = $conn->prepare("SELECT state_json FROM er_saved_state WHERE store_id=? AND save_date=?");
if ($sv) {
    $sv->bind_param('is', $store_id, $er_date);
    $sv->execute();
    $sv_row = $sv->get_result()->fetch_assoc();
    if ($sv_row) {
        $existing = json_decode($sv_row['state_json'], true);
        if ($existing && isset($existing['sections'])) $sections = $existing['sections'];
    }
    $sv->close();
}

// 이미 배치된 item_id 세트
$already_placed = [];
foreach ($sections as $rows) {
    foreach ($rows as $r) {
        if (!empty($r['item_id'])) $already_placed[$r['item_id']] = true;
    }
}

// ── 항목 로드 및 섹션 배치 ───────────────────────────────────────────────
$rowCounter = 0;
foreach ($sections as $rows) {
    foreach ($rows as $r) {
        $n = (int)preg_replace('/\D/', '', $r['id'] ?? '');
        if ($n > $rowCounter) $rowCounter = $n;
    }
}

$added = 0;
foreach ($item_ids as $item_id) {
    $item_id = (string)$item_id;
    if (isset($already_placed[$item_id])) continue; // 이미 배치됨

    // 항목 정보 로드
    $item = null;
    if (strncmp($item_id, 'p_', 2) === 0) {
        $n = (int)substr($item_id, 2);
        $q = $conn->prepare("SELECT id, supplier_name, delivery_content, amount, payment_date, payment_type FROM office_product_purchases WHERE id=? AND store_id=?");
        if ($q) {
            $q->bind_param('ii', $n, $store_id);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            $q->close();
            if ($r) {
                $item = [
                    'id'       => $item_id,
                    'type'     => $r['payment_type'],
                    'supplier' => $r['supplier_name'],
                    'details'  => $r['delivery_content'],
                    'amount'   => (float)$r['amount'],
                    'date'     => $r['payment_date'],
                ];
            }
        }
    } elseif (strncmp($item_id, 'r_', 2) === 0) {
        $n = (int)substr($item_id, 2);
        $q = $conn->prepare("SELECT id, supplier_name, description, amount, receipt_date FROM office_receipts WHERE id=? AND store_id=?");
        if ($q) {
            $q->bind_param('ii', $n, $store_id);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            $q->close();
            if ($r) {
                $item = [
                    'id'       => $item_id,
                    'type'     => 'cash',
                    'supplier' => $r['supplier_name'],
                    'details'  => $r['description'],
                    'amount'   => (float)$r['amount'],
                    'date'     => $r['receipt_date'],
                ];
            }
        }
    }

    if (!$item) continue;

    // 결제 유형에 따라 섹션 결정
    $target_sec = ($item['type'] === 'check') ? 'check_sup' : 'selling';

    $row = [
        'id'       => 'row_' . (++$rowCounter),
        'item_id'  => $item['id'],
        'supplier' => $item['supplier'],
        'details'  => $item['details'],
        'amount'   => $item['amount'],
        'cv_no'    => '',
        'date'     => $item['date'],
    ];
    $sections[$target_sec][] = $row;
    $already_placed[$item_id] = true;
    $added++;
}

// ── ER 상태 저장 ─────────────────────────────────────────────────────────
$state_json = json_encode(['sections' => $sections], JSON_UNESCAPED_UNICODE);
$stmt = $conn->prepare("INSERT INTO er_saved_state (store_id, save_date, state_json) VALUES(?,?,?) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json), saved_at=NOW()");
$stmt->bind_param('iss', $store_id, $er_date, $state_json);
$ok = $stmt->execute();
$stmt->close();

// ── is_er_placed=1 마킹 ──────────────────────────────────────────────────
if ($ok) {
    $pp_ids = [];
    foreach ($item_ids as $iid) {
        if (strncmp($iid, 'p_', 2) === 0) $pp_ids[] = (int)substr($iid, 2);
    }
    if ($pp_ids) {
        $chk = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'is_er_placed'");
        if ($chk && $chk->num_rows > 0) {
            $ph = implode(',', $pp_ids);
            $conn->query("UPDATE office_product_purchases SET is_er_placed=1 WHERE id IN ($ph)");
        }
    }
}

$conn->close();
echo json_encode(['success'=>$ok, 'added'=>$added, 'er_date'=>$er_date]);
