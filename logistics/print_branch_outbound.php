<?php
// 출고대기(draft) 단건 인쇄 — branch_outbound_list.php 의 Print 버튼에서 호출
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';
require_once __DIR__ . '/lib/unit_helper.php'; // FEFO 피킹 미리보기 (Picking order)
require_once __DIR__ . '/lib/print_layout.php';

lc_session_start();
lc_require_staff();

$draft_id = (int)($_GET['draft_id'] ?? 0);

$header = null;
$items  = [];
try {
    $conn = get_lc_db();

    $st = $conn->prepare(
        "SELECT o.id, o.order_date, o.created_at, o.total_amount, o.notes, o.status,
                s.name AS store_name,
                u.full_name AS created_by_name
         FROM lc_orders o
         LEFT JOIN stores s ON o.store_id = s.id
         LEFT JOIN users u ON o.created_by = u.id
         WHERE o.id = ?"
    );
    $st->bind_param('i', $draft_id);
    $st->execute();
    $header = $st->get_result()->fetch_assoc();
    $st->close();

    if ($header) {
        $st2 = $conn->prepare(
            "SELECT oi.id, oi.product_id, oi.quantity, oi.unit_price,
                    COALESCE(oi.order_unit, 'PCS') AS order_unit,
                    p.name_en, p.name_ko, p.capacity, p.unit, p.pieces_per_box,
                    b.name_en AS brand_en, b.name_ko AS brand_ko,
                    COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS product_code
             FROM lc_order_items oi
             LEFT JOIN lc_products p ON oi.product_id = p.id
             LEFT JOIN lc_brands b ON p.brand_id = b.id
             WHERE oi.order_id = ?
             ORDER BY oi.id ASC"
        );
        $st2->bind_param('i', $draft_id);
        $st2->execute();
        $items = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
        $st2->close();

        // 각 품목의 FEFO 피킹 오더(위치·유통기한·수량) 계산 — Edit 화면과 동일 로직
        foreach ($items as $idx => $it) {
            $preview = lc_unit_fefo_preview_allow_negative(
                $conn, (int)$it['product_id'], (int)$it['quantity'], $it['order_unit']
            );
            $items[$idx]['picks']     = $preview['picks'];
            $items[$idx]['shortfall'] = $preview['shortfall'];
        }
    }

    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

$extraCss = <<<CSS
  /* 테이블 전체 폭 고정 (A4 가로 콘텐츠 폭 = 277mm - 좌우 패딩 8mm = 261mm) */
  #srcTable, #pages table:not(.signoff-table) { width: 261mm; table-layout: fixed; }

  colgroup .c-no    { width: 10mm; }
  colgroup .c-code  { width: 34mm; }
  colgroup .c-prod  { width: 85mm; }
  colgroup .c-qty   { width: 16mm; }
  colgroup .c-unit  { width: 13mm; }
  colgroup .c-pkg   { width: 13mm; }
  colgroup .c-price { width: 15mm; }
  colgroup .c-sub   { width: 15mm; }
  colgroup .c-pick  { width: 60mm; }
  .pick-line { display: block; }
  .pick-line + .pick-line { margin-top: 2px; }
  .pick-loc { font-family: 'Consolas', monospace; font-weight: 700; }
  .pick-short { color: #c00; font-weight: 700; }

  /* 바코드 셀 (order_detail.php 인쇄와 동일 스타일) */
  .barcode-svg { display: block; margin: 0 auto; max-width: 100%; height: auto; }
  .barcode-number { font-family: 'Consolas', monospace; font-size: 9px; text-align: center; margin-top: 1px; }

  /* Qty 강조: 연한 빨강 배경 + 확대 (출력 색 보정 포함) */
  th.qty-head { background: #fecaca !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  td.qty-cell { background: #fef2f2 !important; font-size: 13px; font-weight: 800; text-align: center;
                -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

  /* 결제(서명)란 — order_detail.php 인쇄와 동일 구성 (Prepared by / Received by) */
  .signoff { margin-top: 6mm; }
  .signoff-table { width: 120mm; margin-left: auto; border-collapse: collapse; table-layout: fixed; border: 1px solid #000; }
  .signoff-table th, .signoff-table td { border: 1px solid #000; padding: 4px 8px; box-sizing: border-box; font-size: 9px; }
  .signoff-table th { text-align: center; font-weight: 700; background: #f0f0f0;
                      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .signoff .sign-space { height: 22px; }
  .signoff .sign-line { text-align: right; }

  /* 미리보기·출력 모두 글자색 검정 (흐린 회색/색상 방지) */
  body, h1, .meta, .meta span, .sub, td, th,
  .pick-loc, .pick-short, .page-footer { color: #000 !important; }
CSS;

lc_print_head('Picking Order - Print', $extraCss);

$total_qty = 0;
foreach ($items as $it) { $total_qty += (int)$it['quantity']; }

$metaItems = [
    'Printed: ' . date('Y-m-d H:i'),
];
if ($header) {
    $metaItems[] = 'No: #' . str_pad($header['id'], 4, '0', STR_PAD_LEFT);
    $metaItems[] = 'Store: ' . htmlspecialchars($header['store_name'] ?? '-');
    $metaItems[] = 'Outbound Date: ' . htmlspecialchars($header['order_date']);
    $metaItems[] = 'Created By: ' . htmlspecialchars($header['created_by_name'] ?? '-');
    $metaItems[] = 'Total Qty: ' . number_format($total_qty);
    $metaItems[] = 'Est. Amount: ' . number_format($header['total_amount'], 2);
}
lc_print_doc_header('Picking Order', $metaItems);

if (isset($db_error)): ?>
<p style="color:#c00;"><?php echo htmlspecialchars($db_error); ?></p>
<?php elseif (!$header): ?>
<p style="color:#c00;">Pending outbound #<?php echo $draft_id; ?> not found.</p>
<?php endif; ?>

<table id="srcTable">
  <colgroup>
    <col class="c-no"><col class="c-code"><col class="c-prod">
    <col class="c-qty"><col class="c-unit"><col class="c-pkg"><col class="c-price"><col class="c-sub"><col class="c-pick">
  </colgroup>
  <thead>
    <tr>
      <th>#</th>
      <th>Barcode</th>
      <th>Product Name</th>
      <th class="qty-head">Qty</th>
      <th>Unit</th>
      <th>PKG</th>
      <th>Unit Price</th>
      <th>Subtotal</th>
      <th>Picking Order (Location) / (Expiry * Qty)</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($items)): ?>
    <tr><td colspan="9" class="center">No items.</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $i => $row): ?>
    <tr>
      <td class="center"><?php echo $i + 1; ?></td>
      <td class="center">
        <?php if (!empty($row['product_code'])): $bc = trim((string)$row['product_code']); ?>
        <svg class="barcode-svg" data-sku="<?php echo htmlspecialchars($bc); ?>"></svg>
        <div class="barcode-number"><?php echo htmlspecialchars($bc); ?></div>
        <?php else: ?>-<?php endif; ?>
      </td>
      <td>
        <?php
          $brand_parts = array_filter([trim((string)($row['brand_en'] ?? '')), trim((string)($row['brand_ko'] ?? ''))]);
          $brand_label = $brand_parts ? '[' . implode(' ', $brand_parts) . '] ' : '';
          $cap_suffix  = $row['capacity'] ? ' ' . $row['capacity'] : '';
        ?>
        <span style="display:block;"><?php echo htmlspecialchars($brand_label . $row['name_en'] . $cap_suffix); ?></span>
        <?php if ($row['name_ko']): ?><span class="sub" style="display:block;"><?php echo htmlspecialchars($row['name_ko'] . $cap_suffix); ?></span><?php endif; ?>
      </td>
      <td class="qty-cell"><?php echo number_format($row['quantity']); ?></td>
      <td class="center"><?php echo htmlspecialchars($row['order_unit']); ?></td>
      <td class="center"><?php echo !empty($row['pieces_per_box']) ? (int)$row['pieces_per_box'] : '-'; ?></td>
      <td class="right"><?php echo number_format($row['unit_price'], 2); ?></td>
      <td class="right"><?php echo number_format($row['quantity'] * $row['unit_price'], 2); ?></td>
      <td>
        <?php if (!empty($row['picks'])): ?>
          <?php foreach ($row['picks'] as $pk): ?>
          <span class="pick-line">
            (<span class="pick-loc"><?php echo $pk['storage_location'] ? htmlspecialchars($pk['storage_location']) : 'No location'; ?></span>)
            / (<?php echo $pk['expiry_date'] ? htmlspecialchars(date('Y-m-d', strtotime($pk['expiry_date']))) : 'No expiry'; ?> * <?php echo number_format($pk['quantity']); ?>)
          </span>
          <?php endforeach; ?>
          <?php if (!empty($row['shortfall']) && $row['shortfall'] > 0): ?>
          <span class="pick-line pick-short">&#9888; Shortfall: <?php echo number_format($row['shortfall']); ?> (negative stock)</span>
          <?php endif; ?>
        <?php elseif (!empty($row['shortfall']) && $row['shortfall'] > 0): ?>
          <span class="pick-short">&#9888; No stock — <?php echo number_format($row['shortfall']); ?> as negative stock</span>
        <?php else: ?>
          <span style="color:#999;">-</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- 바코드 렌더링: 페이지 분할(paginate) 전에 동기 실행 → 행 높이 측정에 반영됨 -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
(function() {
    if (typeof JsBarcode === 'undefined') return;
    // order_detail.php 인쇄와 동일한 심볼로지 판별 (자릿수 기준)
    var common = { displayValue: false, height: 24, margin: 0, lineColor: '#000', background: '#fff' };
    document.querySelectorAll('.barcode-svg').forEach(function(svg) {
        var sku = svg.getAttribute('data-sku');
        if (!sku) return;
        function draw(value, opts) { JsBarcode(svg, value, Object.assign({}, common, opts)); }
        try {
            if (/^\d{13}$/.test(sku)) { draw(sku, { format: 'EAN13', width: 1.1 }); }
            else if (/^\d{12}$/.test(sku)) { draw('0' + sku, { format: 'EAN13', width: 1.1 }); } // UPC-A → EAN13
            else if (/^\d{8}$/.test(sku)) { draw(sku, { format: 'EAN8', width: 1.4 }); }
            else { draw(sku, { format: 'CODE128', width: 1.1 }); }
        } catch (e) {
            try { draw(sku, { format: 'CODE128', width: 1.1 }); } catch (e2) { /* 인코딩 불가 무시 */ }
        }
    });
})();
</script>

<!-- 결제(서명)란 — paginate() 가 마지막 페이지 하단으로 이동시킴 (order_detail.php 인쇄와 동일) -->
<?php $prepared_by = $header['created_by_name'] ?? ($_SESSION['full_name'] ?? ($_SESSION['username'] ?? '')); ?>
<div id="signOffSrc" class="signoff">
  <table class="signoff-table">
    <thead>
      <tr>
        <th style="width:60mm;">Prepared by</th>
        <th style="width:60mm;">Received by</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td style="vertical-align:top;">
          <div>Name: <strong><?php echo htmlspecialchars($prepared_by); ?></strong></div>
          <div class="sign-space"></div>
          <div class="sign-line">Signature: _____________________</div>
        </td>
        <td style="vertical-align:top;">
          <div>Name: _____________________</div>
          <div class="sign-space"></div>
          <div class="sign-line">Signature: _____________________</div>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<?php lc_print_tail(); ?>
<script>
// 화면용 인쇄 도구막대 (인쇄 시에는 숨김). 미리보기에서 실제 출력 실행.
(function() {
    var st = document.createElement('style');
    st.textContent =
        '#printToolbar{position:fixed;top:14px;right:16px;z-index:1000;display:flex;gap:8px;}' +
        '#printToolbar button{font-family:inherit;cursor:pointer;border:0;border-radius:8px;' +
        'padding:9px 18px;font-size:13px;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.2);}' +
        '#btnDoPrint{background:#0f766e;color:#fff;}' +
        '#btnClosePrint{background:#e5e7eb;color:#374151;}' +
        '@media print{#printToolbar{display:none !important;}}';
    document.head.appendChild(st);

    var bar = document.createElement('div');
    bar.id = 'printToolbar';
    bar.innerHTML =
        '<button id="btnDoPrint" onclick="window.print()"><i class="fas fa-print" style="margin-right:6px"></i>Print</button>' +
        '<button id="btnClosePrint" onclick="window.close()">Close</button>';
    document.body.appendChild(bar);
})();
</script>
