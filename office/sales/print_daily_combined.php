<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$store_id = get_office_store_id();
$ts       = strtotime($date_str);
$days_en  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$date_label = date('F j, Y', $ts) . ' (' . $days_en[date('w', $ts)] . ')';

$conn = get_db_connection();

$store_row  = $conn->query("SELECT name FROM stores WHERE id={$store_id} LIMIT 1")?->fetch_assoc();
$store_name = $store_row['name'] ?? 'Store #' . $store_id;

// Delivery K
$stmt = $conn->prepare(
    "SELECT description, amount FROM sales_daily_items
     WHERE store_id=? AND item_type='delivery_k' AND sale_date=?
     ORDER BY id ASC"
);
$stmt->bind_param('is', $store_id, $date_str);
$stmt->execute();
$dk_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$dk_total = array_sum(array_column($dk_rows, 'amount'));

// Wholesale Sales
$stmt = $conn->prepare(
    "SELECT ws.final_amount, wc.name AS customer_name
     FROM wholesale_sales ws
     LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
     WHERE ws.store_id=? AND ws.sale_date=? AND ws.status != 'cancelled'
     ORDER BY ws.id ASC"
);
$stmt->bind_param('is', $store_id, $date_str);
$stmt->execute();
$ws_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$ws_total    = array_sum(array_column($ws_rows, 'final_amount'));
$grand_total = $dk_total + $ws_total;
$printed_at  = date('Y-m-d H:i');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Daily Report — <?php echo htmlspecialchars($date_label); ?></title>
  <style>
    @page { size: A4; margin: 18mm 20mm; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, 'Malgun Gothic', sans-serif; font-size: 12px; color: #111; background: #fff; }

    .controls { display: flex; gap: 8px; padding: 10px 16px; border-bottom: 1px solid #ddd; background: #f8fafc; }
    .controls button { padding: 6px 16px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; font-size: 12px; background: #fff; }
    .controls button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
    .controls button.primary:hover { background: #1d4ed8; }

    .report { padding: 20px 24px; }

    .report-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 14px; }
    .report-header .title { font-size: 18px; font-weight: 700; letter-spacing: 1px; }
    .report-header .sub { font-size: 12px; color: #444; margin-top: 4px; }

    .meta { display: flex; justify-content: space-between; font-size: 11px; color: #555; margin-bottom: 18px; }

    .section { margin-bottom: 22px; }
    .section-title {
      font-size: 13px; font-weight: 700; padding: 5px 10px;
      border-left: 4px solid #2563eb; margin-bottom: 8px;
      background: #eff6ff; color: #1e40af;
    }
    .section-title.ws { border-color: #7c3aed; background: #f5f3ff; color: #5b21b6; }

    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    thead tr { background: #1e3a5f; color: #fff; }
    thead th { padding: 6px 10px; text-align: left; font-weight: 600; }
    thead th.r { text-align: right; }
    tbody tr { border-bottom: 1px solid #e5e7eb; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody td { padding: 6px 10px; }
    tbody td.r { text-align: right; font-family: 'Courier New', monospace; }
    tbody td.no-data { text-align: center; color: #9ca3af; padding: 16px; }

    tfoot tr { border-top: 2px solid #000; }
    tfoot td { padding: 7px 10px; font-weight: 700; }
    tfoot td.r { text-align: right; font-family: 'Courier New', monospace; }

    .grand-total {
      margin-top: 10px; padding: 10px 14px;
      background: #1e3a5f; color: #fff;
      display: flex; justify-content: space-between;
      font-size: 14px; font-weight: 700; border-radius: 4px;
    }
    .grand-total span { font-family: 'Courier New', monospace; }

    .footer { margin-top: 16px; font-size: 10px; color: #9ca3af; text-align: right; }

    @media print {
      .controls { display: none !important; }
    }
  </style>
</head>
<body>

<div class="controls">
  <button class="primary" onclick="window.print()">🖨 Print</button>
  <button onclick="window.close()">Close</button>
</div>

<div class="report">

  <div class="report-header">
    <div class="title">DAILY SALES REPORT</div>
    <div class="sub"><?php echo htmlspecialchars($store_name); ?></div>
  </div>

  <div class="meta">
    <span><strong>Date:</strong> <?php echo htmlspecialchars($date_label); ?></span>
    <span><strong>Printed:</strong> <?php echo $printed_at; ?></span>
  </div>

  <!-- Delivery K -->
  <div class="section">
    <div class="section-title">Delivery K</div>
    <table>
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th>Description</th>
          <th class="r" style="width:130px">Amount</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($dk_rows)): ?>
        <tr><td colspan="3" class="no-data">No entries.</td></tr>
      <?php else: ?>
        <?php foreach ($dk_rows as $i => $row): ?>
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
          <td colspan="2">Delivery K Total (<?php echo count($dk_rows); ?> entries)</td>
          <td class="r">₱ <?php echo number_format($dk_total, 2); ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Wholesale Sales -->
  <div class="section">
    <div class="section-title ws">Wholesale Sales</div>
    <table>
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th>Customer</th>
          <th class="r" style="width:130px">Amount</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($ws_rows)): ?>
        <tr><td colspan="3" class="no-data">No entries.</td></tr>
      <?php else: ?>
        <?php foreach ($ws_rows as $i => $row): ?>
        <tr>
          <td style="color:#9ca3af"><?php echo $i + 1; ?></td>
          <td><?php echo $row['customer_name'] ? htmlspecialchars($row['customer_name']) : '<span style="color:#ccc">—</span>'; ?></td>
          <td class="r">₱ <?php echo number_format((float)$row['final_amount'], 2); ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2">Wholesale Total (<?php echo count($ws_rows); ?> entries)</td>
          <td class="r">₱ <?php echo number_format($ws_total, 2); ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Grand Total -->
  <div class="grand-total">
    <div>Grand Total</div>
    <span>₱ <?php echo number_format($grand_total, 2); ?></span>
  </div>

  <div class="footer">Daily Sales Report · <?php echo htmlspecialchars($store_name); ?> · <?php echo $printed_at; ?></div>
</div>

</body>
</html>
