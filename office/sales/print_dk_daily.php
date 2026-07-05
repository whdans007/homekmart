<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$store_id = get_office_store_id();
$ts       = strtotime($date_str);
$days_en  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$date_label = date('F j, Y', $ts) . ' (' . $days_en[date('w', $ts)] . ')';

$conn = get_db_connection();

// 점포명
$store_row = $conn->query("SELECT name FROM stores WHERE id={$store_id} LIMIT 1")?->fetch_assoc();
$store_name = $store_row['name'] ?? 'Store #' . $store_id;

// 해당 날짜 Delivery K 내역
$stmt = $conn->prepare(
    "SELECT id, description, amount
     FROM sales_daily_items
     WHERE store_id=? AND item_type='delivery_k' AND sale_date=?
     ORDER BY id ASC"
);
$stmt->bind_param('is', $store_id, $date_str);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$total = array_sum(array_column($rows, 'amount'));
$printed_at = date('Y-m-d H:i');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Delivery K — <?php echo htmlspecialchars($date_label); ?></title>
  <style>
    @page { size: A4; margin: 18mm 20mm; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, 'Malgun Gothic', sans-serif; font-size: 12px; color: #111; background: #fff; }

    .controls { display: flex; gap: 8px; padding: 10px 16px; border-bottom: 1px solid #ddd; background: #f8fafc; }
    .controls button { padding: 6px 16px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; font-size: 12px; background: #fff; }
    .controls button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
    .controls button.primary:hover { background: #1d4ed8; }

    .report { padding: 0; }

    .report-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 14px; }
    .report-header .title { font-size: 18px; font-weight: 700; letter-spacing: 1px; }
    .report-header .sub { font-size: 12px; color: #444; margin-top: 4px; }

    .meta { display: flex; justify-content: space-between; font-size: 11px; color: #555; margin-bottom: 14px; }

    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    thead tr { background: #1e3a5f; color: #fff; }
    thead th { padding: 7px 10px; text-align: left; font-weight: 600; }
    thead th.r { text-align: right; }
    tbody tr { border-bottom: 1px solid #e5e7eb; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody td { padding: 7px 10px; }
    tbody td.r { text-align: right; font-family: 'Courier New', monospace; }
    tbody td.no-data { text-align: center; color: #9ca3af; padding: 24px; }

    tfoot tr { border-top: 2px solid #000; }
    tfoot td { padding: 8px 10px; font-weight: 700; font-size: 13px; }
    tfoot td.r { text-align: right; font-family: 'Courier New', monospace; }

    .footer { margin-top: 20px; font-size: 10px; color: #9ca3af; text-align: right; }

    @media print {
      .controls { display: none !important; }
      body { padding: 0; }
    }
  </style>
</head>
<body>

<div class="controls">
  <button class="primary" onclick="window.print()"><i style="margin-right:4px">🖨</i>Print</button>
  <button onclick="window.close()">Close</button>
</div>

<div class="report" style="padding: 20px 24px;">

  <div class="report-header">
    <div class="title">DELIVERY K — DAILY REPORT</div>
    <div class="sub"><?php echo htmlspecialchars($store_name); ?></div>
  </div>

  <div class="meta">
    <span><strong>Date:</strong> <?php echo htmlspecialchars($date_label); ?></span>
    <span><strong>Printed:</strong> <?php echo $printed_at; ?></span>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:40px">#</th>
        <th>Description</th>
        <th class="r" style="width:140px">Amount</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="3" class="no-data">No entries for this date.</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $i => $row): ?>
      <tr>
        <td style="color:#9ca3af"><?php echo $i + 1; ?></td>
        <td><?php echo $row['description'] ? htmlspecialchars($row['description']) : '<span style="color:#ccc">—</span>'; ?></td>
        <td class="r">₱ <?php echo number_format((float)$row['amount'], 2); ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2">Total (<?php echo count($rows); ?> entries)</td>
        <td class="r">₱ <?php echo number_format($total, 2); ?></td>
      </tr>
    </tfoot>
  </table>

  <div class="footer">Delivery K Daily Report · <?php echo htmlspecialchars($store_name); ?> · <?php echo $printed_at; ?></div>
</div>

</body>
</html>
