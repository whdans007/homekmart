<?php
// 읽기 전용 진단 스크립트 — BFF KIMCHI(BANSANG) 결제 상태 확인용.
// 아무것도 변경하지 않습니다. 확인 후 삭제해도 됩니다.
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: text/html; charset=utf-8');
$store_id = get_office_store_id();
$conn = get_db_connection();

$stmt = $conn->prepare(
    "SELECT id, entry_date, amount, notes, status, paid_date, receipt_id
     FROM deferred_entries
     WHERE store_id=? AND supplier LIKE '%KIMCHI%'
     ORDER BY entry_date ASC, id ASC"
);
$stmt->bind_param('i', $store_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!doctype html><html><head><meta charset="utf-8"><title>KIMCHI Debug</title>
<style>body{font-family:monospace;font-size:13px;padding:16px}
table{border-collapse:collapse}th,td{border:1px solid #ccc;padding:4px 8px}
.paid{color:#15803d;font-weight:bold}.pending{color:#b45309;font-weight:bold}</style>
</head><body>
<h3>deferred_entries — supplier LIKE '%KIMCHI%' (store_id=<?php echo $store_id; ?>)</h3>
<table>
<tr><th>id</th><th>entry_date</th><th>amount</th><th>notes</th><th>status</th><th>paid_date</th><th>receipt_id</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
<td><?php echo $r['id']; ?></td>
<td><?php echo $r['entry_date']; ?></td>
<td style="text-align:right"><?php echo number_format((float)$r['amount'],2); ?></td>
<td><?php echo htmlspecialchars($r['notes'] ?? ''); ?></td>
<td class="<?php echo $r['status']; ?>"><?php echo $r['status']; ?></td>
<td><?php echo $r['paid_date'] ?? ''; ?></td>
<td><?php echo $r['receipt_id'] ?? ''; ?></td>
</tr>
<?php endforeach; ?>
</table>
<p>Total rows: <?php echo count($rows); ?></p>
</body></html>
