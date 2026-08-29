<?php
// Design Ref: §7 / module-5 — Daybook 인쇄 (Daybook Report v2 디자인 충실 재현)
require_once __DIR__ . '/../lib/office_helper.php';
require_once __DIR__ . '/lib/pos_recon_helper.php';
require_office_permission();

$store_id = get_office_store_id();
$date     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$shift    = $_GET['shift'] ?? '';
$pos_no   = (int)($_GET['pos_no'] ?? 0);
if (!pos_valid_shift($shift) || !pos_valid_pos($pos_no)) { http_response_code(400); exit('Invalid cell parameters'); }

$conn = get_db_connection();
$store_row  = $conn->query("SELECT name FROM stores WHERE id={$store_id} LIMIT 1")?->fetch_assoc();
$store_name = $store_row['name'] ?? 'Store #' . $store_id;

$qty       = pos_read_cash_counts($conn, $store_id, $date, $shift, $pos_no);
$alloc     = pos_allocate_starting_money($qty);
$expenses  = pos_read_expenses($conn, $store_id, $date, $shift, $pos_no);
$payments  = pos_read_payments($conn, $store_id, $date, $shift, $pos_no);
$wholesale = pos_read_wholesale_picks($conn, $store_id, $date, $shift, $pos_no);
$recon     = pos_read_reconciliation($conn, $store_id, $date, $shift, $pos_no) ?? [];
$conn->close();

$expense_total   = array_sum(array_column($expenses, 'amount'));
$payment_total   = array_sum(array_column($payments, 'amount'));
// wholesale_pick 분리: 섹션4(SUBSIDIARY COMPANY CREDITS)=credit/credit_doc, 섹션5(WHOLE SALES)=wholesale/delivery_k
// (daily_entry.php 모달의 sccPicked/wsPicked 분류 기준과 동일하게 맞춤)
$scc = array_values(array_filter($wholesale, fn($w) => in_array($w['source_type'] ?? '', ['credit','credit_doc'], true)));
$ws  = array_values(array_filter($wholesale, fn($w) => in_array($w['source_type'] ?? '', ['wholesale','delivery_k'], true)));
// 섹션4 신용거래·섹션5 도매는 매출(시제) 제외 → 셀 총액은 현금 입금분 + 기타결제만. §4·§5 소계는 참고용 표시.
$scc_total       = array_sum(array_column($scc, 'amount'));  // §4 참고용 소계
$ws_total        = array_sum(array_column($ws, 'amount'));    // §5 참고용 소계
// 기타결제(비현금) 표시 라벨
// credit_card/debit_card는 daily_entry.php에서 'card'(Credit/Debit Card)로 통합됨 — 과거 저장분 라벨도 함께 유지
$method_labels = ['card'=>'Credit/Debit Card','credit_card'=>'Credit Card','debit_card'=>'Debit Card','gcash'=>'GCash','paymaya'=>'PayMaya','phqr'=>'PhQR'];
// Expenses 카테고리 영문 라벨 (DB detail 은 한글 키로 저장됨 — daily_entry.php EXPENSE_CATS 와 동일)
$expense_labels = [
    '반품'=>'Returns',
    '인건비'=>'Labor',
    '전기세 · 관리비 · CDC · BIR'=>'Electricity · Maintenance · CDC · BIR',
    'PLDT · LPG · 방역'=>'PLDT · LPG · Pest Control',
    '사무실 (비품)'=>'Office (Supplies)',
    '농산 · 축산 · 수산 · 키친'=>'Produce · Meat · Seafood · Kitchen',
    '차량 유지비'=>'Vehicle Maintenance',
    '일반할인 5%'=>'General Discount 5%',
    '한인회 5%'=>'Korean Association 5%',
    '생수'=>'Drinking Water',
    '기타'=>'Others',
    '포인트 사용'=>'Points Used',
];
// 매출 현금분 = 센 현금 − 준비금 10,000 (현금 0이면 0). recon 누락 시 fallback.
$fallback_cash   = $alloc['cash_total'] > 0 ? $alloc['deposit_cash'] : 0.0;
$total_amount    = (float)($recon['total_amount'] ?? $fallback_cash);
$over_short      = (isset($recon['over_short']) && $recon['over_short'] !== null && $recon['over_short'] !== '') ? (float)$recon['over_short'] : null;

$admin_name   = get_store_representative_name($store_id) ?: '-';
$manager_name = get_store_manager_name($store_id) ?: '-';

$nf = fn($n) => number_format((float)$n);
$shift_cells = [
    'morning'=>['MORNING','8:00AM-5:00PM'],
    'mid'    =>['MIDSHIFT','5:00 PM - 12:00 AM'],
    'gy'     =>['GY','12:00 AM -8:00 AM'],
];
// 빈 줄 패딩 (양식 느낌)
// §5 WHOLE SALES 가 기본 3줄을 초과하면 늘어난 줄 수만큼 §4 SUBSIDIARY COMPANY CREDITS의
// 빈 줄 패딩을 줄여, 한 페이지 안에 들어가도록 전체 줄 수를 맞춘다.
$ws_overflow = max(0, count($ws) - 3);
$exp_pad     = max(0, 4 - count($expenses));
$credits_pad = max(0, 4 - count($payments));
$scc_pad     = max(0, 3 - count($scc) - $ws_overflow);
$ws_pad      = max(0, 3 - count($ws));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>POS <?php echo $pos_no; ?> DAYBOOK — <?php echo htmlspecialchars($date); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  * { box-sizing:border-box; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  body { font-family:'Times New Roman',Georgia,serif; background:#dcdfdd; margin:0; padding:10px; color:#000; }
  .page { width:820px; margin:0 auto; background:#fff; padding:14px 18px 18px; box-shadow:0 10px 40px rgba(0,0,0,.15); }
  .title { text-align:center; font-size:24px; font-weight:700; margin:0 0 8px; }
  .title .red { color:#e11d1d; }
  table { border-collapse:collapse; }
  .bd, .bd th, .bd td { border:1.5px solid #000; }
  .shift { margin:0 auto 8px; }
  .shift td { padding:4px 14px; text-align:center; font-weight:700; font-size:13px; line-height:1.3; }
  .shift td.on { background:#ff69b4; color:#000; }
  .date { font-size:17px; font-weight:700; margin:4px 0 8px; }
  .date .ln { display:inline-block; border-bottom:1.5px solid #000; min-width:190px; padding:0 6px; }
  .cols { width:100%; }
  .cols > tbody > tr > td { vertical-align:top; width:50%; padding:0 6px; border:none; }
  .sec-h { text-align:center; font-weight:700; font-size:15px; margin:8px 0 3px; }
  .grid { width:100%; font-size:14px; }
  .grid th { padding:3px 6px; font-weight:700; text-align:center; }
  .grid td { padding:5px 7px; height:31px; }
  .grid td.u { text-align:center; font-weight:700; width:58px; }
  .grid td.star { text-align:center; width:24px; }
  .grid td.q { text-align:center; width:60px; }
  .grid td.amt { text-align:right; }
  .grid tr.total td { font-weight:700; }
  .grid tr.total td.lbl { text-align:center; }
  .num { font-variant-numeric:tabular-nums; }
  .totbox { width:100%; font-size:15px; font-weight:700; margin-top:14px; }
  .totbox td { padding:11px 10px; }
  .totbox td.v { text-align:right; width:130px; }
  .sigbox { width:calc(100% - 12px); margin:26px auto 0; }
  .sigbox th { background:#f1f1f1; font-weight:700; font-size:13px; letter-spacing:.04em; padding:6px; text-align:center; }
  .sigbox td { height:95px; vertical-align:bottom; text-align:center; padding:4px 8px 10px; }
  .sigbox .nm { display:block; border-top:1px solid #999; margin-top:auto; padding-top:4px; font-size:11px; color:#777; }
  .sigbox .sig-name { font-size:13px; font-weight:700; color:#111; margin-bottom:34px; }
  .toolbar { width:820px; margin:0 auto 8px; text-align:right; }
  .btn { font-family:sans-serif; border:1px solid #111; background:#111; color:#fff; padding:7px 14px; border-radius:6px; font-size:13px; cursor:pointer; }
  @media print { body { background:#fff; padding:0; } .toolbar { display:none; } .page { box-shadow:none; width:194mm; padding:0; } @page { size:A4 portrait; margin:8mm; } }
</style>
</head>
<body>

<div class="toolbar"><button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button></div>

<div class="page">
  <h1 class="title">POS <?php echo $pos_no; ?> <span class="red"><?php echo htmlspecialchars(strtoupper($store_name)); ?> DAYBOOK</span></h1>

  <table class="bd shift">
    <tr>
      <?php foreach ($shift_cells as $sk => $c): ?>
      <td class="<?php echo $sk === $shift ? 'on' : ''; ?>"><?php echo $c[0]; ?><br><?php echo $c[1]; ?></td>
      <?php endforeach; ?>
    </tr>
  </table>

  <div class="date">DATE : <span class="ln num">&nbsp;<?php echo htmlspecialchars($date); ?></span></div>

  <table class="cols"><tbody><tr>
    <!-- LEFT -->
    <td>
      <div class="sec-h">(STARTING MONEY)</div>
      <table class="bd grid" style="width:100%">
        <thead><tr><th style="width:58px">UNIT</th><th colspan="3">PRICE</th></tr></thead>
        <tbody>
          <?php foreach (POS_DENOMS as $d): $q = $alloc['start_qty'][$d] ?? 0; ?>
          <tr><td class="u num"><?php echo $nf($d); ?></td><td class="star">*</td><td class="q num"><?php echo $q ?: ''; ?></td><td class="amt num"><?php echo $q ? $nf($d*$q) : ''; ?></td></tr>
          <?php endforeach; ?>
          <tr class="total"><td class="lbl" colspan="3">TOTAL:</td><td class="amt num"><?php echo $nf($alloc['starting_money']); ?></td></tr>
        </tbody>
      </table>

      <div class="sec-h">(TODAY TOTAL ACCOUNT)</div>
      <table class="bd grid" style="width:100%">
        <thead><tr><th style="width:58px">UNIT</th><th colspan="3">PRICE</th></tr></thead>
        <tbody>
          <?php foreach (POS_DENOMS as $d): $q = $alloc['deposit_qty'][$d] ?? 0; ?>
          <tr><td class="u num"><?php echo $nf($d); ?></td><td class="star">*</td><td class="q num"><?php echo $q ?: ''; ?></td><td class="amt num"><?php echo $q ? $nf($d*$q) : ''; ?></td></tr>
          <?php endforeach; ?>
          <tr class="total"><td class="lbl" colspan="3" style="font-size:12px">TOTAL AMOUNT:</td><td class="amt num"><?php echo $nf($alloc['deposit_cash']); ?></td></tr>
        </tbody>
      </table>

      <table class="bd totbox">
        <tr><td>TOTAL AMOUNT</td><td class="v num"><?php echo $nf($total_amount); ?></td></tr>
        <tr><td>OVER / SHORT (+/-)</td>
          <td class="v num" style="color:<?php echo $over_short===null ? '#000' : ($over_short>=0 ? '#1d4ed8' : '#dc2626'); ?>">
            <?php
              if ($over_short === null) echo '—';
              else echo ($over_short >= 0 ? '+ ₱ ' : '− ₱ ') . number_format(abs($over_short), 2);
            ?>
          </td></tr>
      </table>
    </td>

    <!-- RIGHT -->
    <td>
      <div class="sec-h">(EXPENSES)</div>
      <table class="bd grid" style="width:100%">
        <thead><tr><th>DETAIL</th><th style="width:120px">PRICE</th></tr></thead>
        <tbody>
          <?php foreach ($expenses as $e): ?>
          <tr><td><?php echo htmlspecialchars($expense_labels[$e['detail']] ?? $e['detail']); ?></td><td class="amt num"><?php echo $nf($e['amount']); ?></td></tr>
          <?php endforeach; ?>
          <?php for ($i=0; $i<$exp_pad; $i++): ?>
          <tr><td>&nbsp;</td><td></td></tr>
          <?php endfor; ?>
          <tr class="total"><td class="lbl" style="text-align:center">TOTAL</td><td class="amt num"><?php echo $nf($expense_total); ?></td></tr>
        </tbody>
      </table>

      <div class="sec-h">(CREDITS)</div>
      <table class="bd grid" style="width:100%">
        <thead><tr><th>CLIENT</th><th>REMARK</th><th style="width:90px">AMOUNT</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
          <tr><td><?php echo htmlspecialchars($method_labels[$p['method']] ?? $p['method']); ?></td><td><?php echo htmlspecialchars($p['description']); ?></td><td class="amt num"><?php echo $nf($p['amount']); ?></td></tr>
          <?php endforeach; ?>
          <?php for ($i=0; $i<$credits_pad; $i++): ?>
          <tr><td>&nbsp;</td><td></td><td class="amt num"></td></tr>
          <?php endfor; ?>
          <tr class="total"><td colspan="2" class="lbl" style="text-align:center">TOTAL</td><td class="amt num"><?php echo $nf($payment_total); ?></td></tr>
        </tbody>
      </table>

      <div class="sec-h">(SUBSIDIARY COMPANY CREDITS)</div>
      <table class="bd grid" style="width:100%">
        <thead><tr><th>CLIENT</th><th>REMARK</th><th style="width:90px">AMOUNT</th></tr></thead>
        <tbody>
          <?php foreach ($scc as $w): ?>
          <tr><td><?php echo htmlspecialchars($w['client'] ?: '—'); ?></td><td><?php echo htmlspecialchars($w['remark']); ?></td><td class="amt num"><?php echo $nf($w['amount']); ?></td></tr>
          <?php endforeach; ?>
          <?php for ($i=0; $i<$scc_pad; $i++): ?>
          <tr><td>&nbsp;</td><td></td><td class="amt num"></td></tr>
          <?php endfor; ?>
          <tr class="total"><td colspan="2" class="lbl" style="text-align:center">TOTAL</td><td class="amt num"><?php echo $nf($scc_total); ?></td></tr>
        </tbody>
      </table>

      <div class="sec-h">(WHOLE SALES)</div>
      <table class="bd grid" style="width:100%">
        <thead><tr><th>CLIENT</th><th>REMARK</th><th style="width:90px">AMOUNT</th></tr></thead>
        <tbody>
          <?php foreach ($ws as $w): ?>
          <tr><td><?php echo htmlspecialchars($w['client'] ?: '—'); ?></td><td><?php echo htmlspecialchars($w['remark']); ?></td><td class="amt num"><?php echo $nf($w['amount']); ?></td></tr>
          <?php endforeach; ?>
          <?php for ($i=0; $i<$ws_pad; $i++): ?>
          <tr><td>&nbsp;</td><td></td><td class="amt num"></td></tr>
          <?php endfor; ?>
          <tr class="total"><td colspan="2" class="lbl" style="text-align:center">TOTAL</td><td class="amt num"><?php echo $nf($ws_total); ?></td></tr>
        </tbody>
      </table>
    </td>
  </tr></tbody></table>

  <table class="bd sigbox">
    <thead><tr><th style="width:33.33%">CASHIER</th><th style="width:33.33%">ADMIN</th><th style="width:33.34%">MANAGER</th></tr></thead>
    <tbody><tr>
      <td><span class="nm">Signature</span></td>
      <td><div class="sig-name"><?php echo htmlspecialchars($admin_name); ?></div><span class="nm">Signature</span></td>
      <td><div class="sig-name"><?php echo htmlspecialchars($manager_name); ?></div><span class="nm">Signature</span></td>
    </tr></tbody>
  </table>
</div>

</body>
</html>
