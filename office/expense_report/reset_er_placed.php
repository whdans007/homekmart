<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

$store_id = get_office_store_id();
$conn     = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    // 모든 is_er_placed를 0으로 초기화
    $conn->query("UPDATE office_product_purchases SET is_er_placed=0 WHERE store_id={$store_id}");
    $pp = $conn->affected_rows;
    $conn->query("UPDATE office_equipment_purchases SET is_er_placed=0 WHERE store_id={$store_id}");
    $ep = $conn->affected_rows;
    $conn->close();
    header('Content-Type: text/plain; charset=utf-8');
    echo "✅ 초기화 완료\nProduct Purchase: {$pp}건\nEquipment Purchase: {$ep}건\n\nDone. Delete this file.";
    exit;
}

// 현재 is_er_placed=1 건수 확인
$r1 = $conn->query("SELECT COUNT(*) AS cnt FROM office_product_purchases WHERE store_id={$store_id} AND is_er_placed=1");
$pp_cnt = $r1 ? $r1->fetch_assoc()['cnt'] : 0;
$r2 = $conn->query("SELECT COUNT(*) AS cnt FROM office_equipment_purchases WHERE store_id={$store_id} AND is_er_placed=1");
$ep_cnt = $r2 ? $r2->fetch_assoc()['cnt'] : 0;
$conn->close();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>body{font-family:sans-serif;padding:20px}.box{background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:16px;margin-bottom:16px}</style>
</head><body>
<div class="box">
  <h3 style="color:#b91c1c;margin:0 0 10px">is_er_placed 초기화</h3>
  <p style="font-size:13px">현재 처리완료로 표시된 항목:<br>
  &nbsp;• Product Purchase: <strong><?php echo $pp_cnt; ?>건</strong><br>
  &nbsp;• Equipment Purchase: <strong><?php echo $ep_cnt; ?>건</strong></p>
  <p style="font-size:12px;color:#6b7280;margin-top:8px">초기화하면 Items 패널에 모두 다시 표시됩니다.</p>
</div>
<form method="POST">
  <input type="hidden" name="confirm" value="yes">
  <button type="submit" style="background:#dc2626;color:white;padding:8px 24px;border:none;border-radius:6px;cursor:pointer;font-size:14px">
    전체 초기화 (is_er_placed=0)
  </button>
</form>
</body></html>
