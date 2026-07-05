<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$store_id = get_office_store_id();
$conn     = get_db_connection();

$target_date     = '2026-05-13';
$target_supplier = 'STOTSENBERG';
$target_amount   = 1.00;

// ── 해당 레코드 검색 ──────────────────────────────────────────
$found = [];

// product_purchases
$s = $conn->prepare(
    "SELECT id, supplier_name, delivery_content, amount, payment_type, payment_date, check_issued_date
     FROM office_product_purchases
     WHERE store_id=? AND supplier_name LIKE ? AND amount<=1.01
       AND (payment_date=? OR check_issued_date=?)"
);
$like = '%' . $target_supplier . '%';
$s->bind_param('isss', $store_id, $like, $target_date, $target_date);
$s->execute();
foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $r['_table'] = 'office_product_purchases';
    $r['_type']  = $r['payment_type'] === 'check' ? 'pc' : 'p';
    $found[] = $r;
}
$s->close();

// equipment_purchases
$s = $conn->prepare(
    "SELECT id, supplier_name, delivery_content, amount, payment_date
     FROM office_equipment_purchases
     WHERE store_id=? AND supplier_name LIKE ? AND amount<=1.01 AND payment_date=?"
);
$s->bind_param('iss', $store_id, $like, $target_date);
$s->execute();
foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $r['_table'] = 'office_equipment_purchases';
    $r['_type']  = 'e';
    $found[] = $r;
}
$s->close();

$do_delete = isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'yes';
$deleted   = [];
$errors    = [];

if ($do_delete && !empty($found)) {
    foreach ($found as $rec) {
        $id         = (int)$rec['id'];
        $table      = $rec['_table'];
        $link_type  = str_contains($table, 'product') ? 'product' : 'equipment';

        // 연결된 Receipt 먼저 해제 (linked_purchase_id → 0)
        $ul = $conn->prepare(
            "UPDATE office_receipts SET linked_purchase_type=NULL, linked_purchase_id=0
             WHERE linked_purchase_type=? AND linked_purchase_id=? AND store_id=?"
        );
        $ul->bind_param('sii', $link_type, $id, $store_id);
        $ul->execute();
        $unlinked = $ul->affected_rows;
        $ul->close();

        // DB 삭제
        $dl = $conn->prepare("DELETE FROM {$table} WHERE id=? AND store_id=?");
        $dl->bind_param('ii', $id, $store_id);
        $dl->execute();
        $ok = $dl->affected_rows > 0;
        $dl->close();

        // er_saved_state 에서 제거
        $item_id_str = $rec['_type'] . '_' . $id;
        $sv = $conn->prepare(
            "SELECT id, state_json FROM er_saved_state WHERE store_id=? AND save_date=?"
        );
        $sv->bind_param('is', $store_id, $target_date);
        $sv->execute();
        $sv_row = $sv->get_result()->fetch_assoc();
        $sv->close();
        if ($sv_row) {
            $state = json_decode($sv_row['state_json'], true);
            if ($state && isset($state['sections'])) {
                foreach ($state['sections'] as &$rows) {
                    $rows = array_values(array_filter($rows, fn($r) => ($r['item_id'] ?? '') !== $item_id_str));
                }
                unset($rows);
                $upd = $conn->prepare("UPDATE er_saved_state SET state_json=? WHERE id=?");
                $json = json_encode($state, JSON_UNESCAPED_UNICODE);
                $upd->bind_param('si', $json, $sv_row['id']);
                $upd->execute();
                $upd->close();
            }
        }

        if ($ok) {
            $deleted[] = "삭제 완료: [{$table}] id={$id} / {$rec['supplier_name']} / {$rec['amount']} (Receipt unlink: {$unlinked}건)";
        } else {
            $errors[] = "삭제 실패: [{$table}] id={$id}";
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <title>STOTSENBERG 1페소 삭제</title>
  <link href="../../admin/css/style.css" rel="stylesheet">
  <style>body{font-family:sans-serif;padding:30px;max-width:700px;margin:0 auto;}</style>
</head>
<body>
<h2 style="color:#dc2626;">🔧 잘못된 데이터 삭제 — STOTSENBERG 1페소 (5/13)</h2>

<?php if (!empty($deleted)): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;padding:15px;border-radius:8px;margin-bottom:20px;">
  <strong>✅ 삭제 완료</strong><br>
  <?php foreach ($deleted as $msg) echo htmlspecialchars($msg) . '<br>'; ?>
  <br><a href="index.php" style="color:#059669;font-weight:bold;">← Expense Report로 돌아가기</a>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;padding:15px;border-radius:8px;margin-bottom:20px;">
  <strong>❌ 오류</strong><br>
  <?php foreach ($errors as $msg) echo htmlspecialchars($msg) . '<br>'; ?>
</div>
<?php endif; ?>

<?php if (empty($found)): ?>
<div style="background:#fef3c7;border:1px solid #fcd34d;padding:15px;border-radius:8px;">
  ⚠️ 검색된 레코드가 없습니다.<br>
  조건: supplier LIKE '%STOTSENBERG%', amount ≤ 1.01, date = <?php echo $target_date; ?>
</div>
<?php else: ?>
<h3>검색된 레코드</h3>
<table border="1" cellpadding="6" style="border-collapse:collapse;width:100%;font-size:13px;">
  <tr style="background:#f3f4f6;">
    <th>테이블</th><th>ID</th><th>업체명</th><th>내용</th><th>금액</th><th>날짜</th>
  </tr>
  <?php foreach ($found as $r): ?>
  <tr>
    <td><?php echo htmlspecialchars($r['_table']); ?></td>
    <td><?php echo $r['id']; ?></td>
    <td><?php echo htmlspecialchars($r['supplier_name']); ?></td>
    <td><?php echo htmlspecialchars($r['delivery_content'] ?? ''); ?></td>
    <td><?php echo number_format($r['amount'], 2); ?></td>
    <td><?php echo htmlspecialchars($r['payment_date'] ?? $r['check_issued_date'] ?? ''); ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?php if (empty($deleted)): ?>
<form method="POST" style="margin-top:20px;"
      onsubmit="return confirm('위 레코드를 삭제하시겠습니까? 연결된 Receipt도 자동으로 unlink됩니다.');">
  <input type="hidden" name="confirm_delete" value="yes">
  <button type="submit"
          style="background:#dc2626;color:white;padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:bold;">
    🗑️ 위 레코드 삭제
  </button>
  <a href="index.php" style="margin-left:16px;color:#6b7280;">취소</a>
</form>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
