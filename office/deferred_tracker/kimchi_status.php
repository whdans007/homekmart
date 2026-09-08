<?php
// 1회성 점검/수정 스크립트 — BFF KIMCHI(BANSANG) 8월 결제 상태 확인 및 복구용.
// GET  : 현재 상태만 보여줌 (변경 없음)
// POST : 화면에서 선택한 항목만 status='pending'으로 되돌림 (paid_date/receipt_id 초기화,
//        고아가 된 영수증은 삭제) — ajax_cancel_dtr.php의 cancel 로직과 동일한 방식.
// 확인 후 삭제해도 됩니다.
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: text/html; charset=utf-8');
$store_id = get_office_store_id();
$conn = get_db_connection();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_ids = $_POST['ids'] ?? [];
    $ids = array_filter(array_map('intval', (array)$raw_ids));

    if (empty($ids)) {
        $message = '<p style="color:#b45309">선택된 항목이 없습니다.</p>';
    } else {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "SELECT id, receipt_id FROM deferred_entries
             WHERE id IN ({$ph}) AND store_id=? AND status='paid'"
        );
        $params = array_merge($ids, [$store_id]);
        $stmt->bind_param(str_repeat('i', count($ids)).'i', ...$params);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($target)) {
            $message = '<p style="color:#b45309">되돌릴 수 있는 paid 항목이 없습니다 (이미 pending일 수 있음).</p>';
        } else {
            $confirmed_ids = array_column($target, 'id');
            $receipt_ids   = array_filter(array_unique(array_column($target, 'receipt_id')));
            $ph2 = implode(',', array_fill(0, count($confirmed_ids), '?'));

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "UPDATE deferred_entries
                     SET status='pending', paid_date=NULL, receipt_id=NULL
                     WHERE id IN ({$ph2}) AND store_id=?"
                );
                $p = array_merge($confirmed_ids, [$store_id]);
                $stmt->bind_param(str_repeat('i', count($confirmed_ids)).'i', ...$p);
                $stmt->execute();
                $stmt->close();

                $deleted_receipts = 0;
                foreach ($receipt_ids as $rid) {
                    $chk = $conn->prepare(
                        "SELECT COUNT(*) AS cnt FROM deferred_entries WHERE receipt_id=? AND store_id=?"
                    );
                    $chk->bind_param('ii', $rid, $store_id);
                    $chk->execute();
                    $cnt = (int)$chk->get_result()->fetch_assoc()['cnt'];
                    $chk->close();
                    if ($cnt === 0) {
                        $conn->query("DELETE FROM office_receipts WHERE id={$rid} AND store_id={$store_id}");
                        $deleted_receipts++;
                    }
                }

                $conn->commit();
                $message = '<p style="color:#15803d;font-weight:bold">✅ ' . count($confirmed_ids)
                          . '건을 pending으로 되돌렸습니다. (연결 영수증 삭제: ' . $deleted_receipts . '건)</p>';
            } catch (Throwable $e) {
                $conn->rollback();
                $message = '<p style="color:#dc2626">❌ 오류: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }
    }
}

$stmt = $conn->prepare(
    "SELECT id, entry_date, amount, notes, status, paid_date, receipt_id
     FROM deferred_entries
     WHERE store_id=? AND (supplier LIKE '%KIMCHI%' OR supplier LIKE '%BANSANG%')
     ORDER BY entry_date ASC, id ASC"
);
$stmt->bind_param('i', $store_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!doctype html><html><head><meta charset="utf-8"><title>KIMCHI Status</title>
<style>body{font-family:monospace;font-size:13px;padding:16px}
table{border-collapse:collapse}th,td{border:1px solid #ccc;padding:4px 8px}
.paid{color:#15803d;font-weight:bold}.pending{color:#b45309;font-weight:bold}
button{padding:6px 14px;margin-top:10px}</style>
</head><body>
<h3>deferred_entries — KIMCHI/BANSANG (store_id=<?php echo $store_id; ?>)</h3>
<?php echo $message; ?>
<form method="POST">
<table>
<tr><th></th><th>id</th><th>entry_date</th><th>amount</th><th>notes</th><th>status</th><th>paid_date</th><th>receipt_id</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
<td><?php if ($r['status']==='paid'): ?><input type="checkbox" name="ids[]" value="<?php echo $r['id']; ?>"><?php endif; ?></td>
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
<button type="submit" onclick="return confirm('체크한 항목을 pending(미결제)으로 되돌립니다. 계속할까요?');">
체크한 항목 → pending으로 되돌리기
</button>
</form>
</body></html>
