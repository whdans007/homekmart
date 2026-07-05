<?php
// Plan SC: F3 — Source List 항목 Delete (DB 삭제 + er_saved_state 정리)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$store_id = get_office_store_id();
$item_id  = $_POST['item_id'] ?? '';
$date     = $_POST['date']    ?? '';

if ($item_id === '') {
    echo json_encode(['success' => false, 'error' => 'item_id required']);
    exit;
}

$is_receipt = false;
if (preg_match('/^pc_(\d+)$/', $item_id, $m)) {
    $table = 'office_product_purchases';
    $id    = (int)$m[1];
} elseif (preg_match('/^p_(\d+)$/', $item_id, $m)) {
    $table = 'office_product_purchases';
    $id    = (int)$m[1];
} elseif (preg_match('/^e_(\d+)$/', $item_id, $m)) {
    $table = 'office_equipment_purchases';
    $id    = (int)$m[1];
} elseif (preg_match('/^r_(\d+)$/', $item_id, $m)) {
    $table      = 'office_receipts';
    $id         = (int)$m[1];
    $is_receipt = true;
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid item_id']);
    exit;
}

$conn = get_db_connection();

if ($is_receipt) {
    // 독립 영수증 삭제 (linked_purchase_id=0 또는 NULL인 경우만)
    $chk = $conn->prepare(
        "SELECT id FROM office_receipts WHERE id=? AND store_id=? AND (linked_purchase_id IS NULL OR linked_purchase_id=0) LIMIT 1"
    );
    $chk->bind_param('ii', $id, $store_id);
    $chk->execute();
    $found = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$found) {
        $conn->close();
        echo json_encode(['success' => false, 'error' => '구매 항목에 연결된 영수증은 삭제할 수 없습니다. 먼저 Unlink해주세요.']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM office_receipts WHERE id=? AND store_id=?");
    $stmt->bind_param('ii', $id, $store_id);
    $stmt->execute();
    $stmt->close();
} else {
    // Receipt 연동 확인 — 연결된 영수증이 있으면 삭제 불가
    $link_type = str_contains($table, 'product') ? 'product' : 'equipment';
    $chk = $conn->prepare(
        "SELECT id FROM office_receipts WHERE linked_purchase_type=? AND linked_purchase_id=? LIMIT 1"
    );
    $chk->bind_param('si', $link_type, $id);
    $chk->execute();
    $linked = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($linked) {
        $conn->close();
        echo json_encode(['success' => false, 'error' => 'Receipt가 연결되어 있어 삭제할 수 없습니다. 먼저 Receipt를 Unlink해주세요.']);
        exit;
    }

    // DB 삭제
    $stmt = $conn->prepare("DELETE FROM {$table} WHERE id=? AND store_id=?");
    $stmt->bind_param('ii', $id, $store_id);
    $stmt->execute();
    $stmt->close();
}

// er_saved_state에서 해당 item_id 제거
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $sv = $conn->prepare(
        "SELECT id, state_json FROM er_saved_state WHERE store_id=? AND save_date=?"
    );
    if ($sv) {
        $sv->bind_param('is', $store_id, $date);
        $sv->execute();
        $row = $sv->get_result()->fetch_assoc();
        $sv->close();

        if ($row) {
            $state = json_decode($row['state_json'], true);
            if ($state && isset($state['sections'])) {
                foreach ($state['sections'] as $sec => &$rows) {
                    $rows = array_values(array_filter($rows, fn($r) => ($r['item_id'] ?? '') !== $item_id));
                }
                unset($rows);
                $upd = $conn->prepare(
                    "UPDATE er_saved_state SET state_json=? WHERE id=?"
                );
                $json = json_encode($state, JSON_UNESCAPED_UNICODE);
                $upd->bind_param('si', $json, $row['id']);
                $upd->execute();
                $upd->close();
            }
        }
    }
}

$conn->close();
echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
