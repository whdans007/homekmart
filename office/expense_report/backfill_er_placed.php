<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

$store_id = get_office_store_id();
$conn     = get_db_connection();

// er_saved_state 전체 조회
$rows = $conn->query("SELECT state_json FROM er_saved_state WHERE store_id={$store_id}");
if (!$rows) {
    echo 'er_saved_state 테이블 없음 또는 오류: ' . $conn->error;
    $conn->close(); exit;
}

$pp_ids = [];
$ep_ids = [];

while ($row = $rows->fetch_assoc()) {
    $state = json_decode($row['state_json'], true);
    foreach ($state['sections'] ?? [] as $section_rows) {
        foreach ($section_rows as $r) {
            $bid = (string)($r['item_id'] ?? '');
            if (strncmp($bid, 'e_', 2) === 0) {
                $n = (int)substr($bid, 2);
                if ($n > 0) $ep_ids[$n] = $n;
            } elseif (strncmp($bid, 'pc_', 3) === 0) {
                $n = (int)substr($bid, 3);
                if ($n > 0) $pp_ids[$n] = $n;
            } elseif (strncmp($bid, 'p_', 2) === 0) {
                $n = (int)substr($bid, 2);
                if ($n > 0) $pp_ids[$n] = $n;
            }
        }
    }
}

$pp_count = $ep_count = 0;

if ($pp_ids) {
    $ph = implode(',', array_fill(0, count($pp_ids), '?'));
    $u  = $conn->prepare("UPDATE office_product_purchases SET is_er_placed=1 WHERE id IN ($ph)");
    $u->bind_param(str_repeat('i', count($pp_ids)), ...array_values($pp_ids));
    $u->execute();
    $pp_count = $u->affected_rows;
    $u->close();
}

if ($ep_ids) {
    $ph = implode(',', array_fill(0, count($ep_ids), '?'));
    $u  = $conn->prepare("UPDATE office_equipment_purchases SET is_er_placed=1 WHERE id IN ($ph)");
    $u->bind_param(str_repeat('i', count($ep_ids)), ...array_values($ep_ids));
    $u->execute();
    $ep_count = $u->affected_rows;
    $u->close();
}

$conn->close();

header('Content-Type: text/plain; charset=utf-8');
echo "✅ 백필 완료\n";
echo "Product Purchase 업데이트: {$pp_count}건\n";
echo "Equipment Purchase 업데이트: {$ep_count}건\n";
echo "\nDone. Please delete this file.\n";
