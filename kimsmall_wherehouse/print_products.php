<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';

kw_session_start();
kw_require_staff();

$search   = trim($_GET['search'] ?? '');
$cat_id   = (int)($_GET['cat'] ?? 0);
$brand_id = (int)($_GET['brand'] ?? 0);

try {
    $conn = get_lc_db();

    $cat_name = '';
    $brand_name = '';
    if ($cat_id) {
        $st = $conn->prepare("SELECT name_en, name_ko FROM kw_categories WHERE id = ?");
        $st->bind_param('i', $cat_id);
        $st->execute();
        $c = $st->get_result()->fetch_assoc(); $st->close();
        if ($c) { $cat_name = $c['name_en'] . ($c['name_ko'] ? ' (' . $c['name_ko'] . ')' : ''); }
    }
    if ($brand_id) {
        $st = $conn->prepare("SELECT name_en, name_ko FROM kw_brands WHERE id = ?");
        $st->bind_param('i', $brand_id);
        $st->execute();
        $b = $st->get_result()->fetch_assoc(); $st->close();
        if ($b) { $brand_name = $b['name_en'] . ($b['name_ko'] ? ' (' . $b['name_ko'] . ')' : ''); }
    }

    $conds = []; $params = []; $types = '';
    if ($search) {
        $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
        $types .= 'sssss';
    }
    if ($cat_id)   { $conds[] = "p.category_id = ?"; $params[] = $cat_id;   $types .= 'i'; }
    if ($brand_id) { $conds[] = "p.brand_id = ?";    $params[] = $brand_id; $types .= 'i'; }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $sql = "SELECT p.*, b.name_en AS brand_name, c.name_en AS category_name
            FROM kw_products p
            LEFT JOIN kw_brands b ON p.brand_id = b.id
            LEFT JOIN kw_categories c ON p.category_id = c.id
            $where ORDER BY p.is_active DESC, p.name_en ASC";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $products = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
    $products = [];
}
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>Product List - Print</title>
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: 'Malgun Gothic', Arial, sans-serif; color: #111; background: #e5e7eb; }

  h1 { font-size: 16px; margin: 0 0 4px; }
  .meta { font-size: 11px; color: #555; margin-bottom: 8px; }
  .meta span { margin-right: 12px; }

  table { width: 100%; border-collapse: collapse; font-size: 10px; table-layout: fixed; }
  th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; overflow: hidden; word-break: break-word; }
  th { background: #f0f0f0; font-weight: 700; text-align: center; }
  td.center { text-align: center; }
  td.mono { font-family: 'Consolas', monospace; }
  tr.inactive { color: #999; }
  .name-ko { font-size: 9px; color: #666; }

  colgroup .c-cat   { width: 8%; }
  colgroup .c-brand { width: 8%; }
  colgroup .c-name  { width: 18%; }
  colgroup .c-cap   { width: 8%; }
  colgroup .c-unit  { width: 6%; }
  colgroup .c-ppb   { width: 6%; }
  colgroup .c-bc1   { width: 11%; }
  colgroup .c-bc2   { width: 11%; }
  colgroup .c-bc3   { width: 11%; }
  colgroup .c-exp   { width: 7%; }
  colgroup .c-stat  { width: 6%; }

  /* 페이지 단위 레이아웃 (A4 가로: 297mm x 210mm, 여백 10mm) */
  .print-page {
    position: relative;
    width: 277mm;
    height: 190mm;
    margin: 0 auto 10px;
    padding: 0;
    background: #fff;
    overflow: hidden;
    box-shadow: 0 0 4px rgba(0,0,0,0.25);
  }
  .print-page .page-content { padding: 8mm; height: 100%; box-sizing: border-box; overflow: hidden; }
  .page-footer {
    position: absolute;
    left: 0; right: 0; bottom: 0;
    text-align: center;
    font-size: 9px;
    color: #666;
    border-top: 1px solid #ccc;
    padding: 3px 0;
    background: #fff;
  }

  #srcWrap { visibility: hidden; position: absolute; top: -99999px; left: 0; width: 261mm; }

  @media print {
    body { background: #fff; }
    .print-page {
      width: auto; height: 190mm; margin: 0; box-shadow: none;
      page-break-after: always;
    }
    .print-page:last-child { page-break-after: auto; }
    @page { size: A4 landscape; margin: 10mm; }
  }
</style>
</head>
<body>
  <div id="pages"></div>

  <!-- 측정/페이지 분할용 원본 (화면에는 보이지 않음) -->
  <div id="srcWrap">
    <div class="doc-header">
      <h1>Product List</h1>
      <div class="meta">
        <span>Printed: <?php echo date('Y-m-d H:i'); ?></span>
        <span>Total: <?php echo number_format(count($products)); ?> record(s)</span>
        <?php if ($search): ?><span>Search: "<?php echo htmlspecialchars($search); ?>"</span><?php endif; ?>
        <?php if ($cat_name): ?><span>Category: <?php echo htmlspecialchars($cat_name); ?></span><?php endif; ?>
        <?php if ($brand_name): ?><span>Brand: <?php echo htmlspecialchars($brand_name); ?></span><?php endif; ?>
      </div>
    </div>

    <?php if (isset($db_error)): ?>
    <p style="color:#c00;"><?php echo htmlspecialchars($db_error); ?></p>
    <?php endif; ?>

    <table id="srcTable">
      <colgroup>
        <col class="c-cat"><col class="c-brand"><col class="c-name"><col class="c-cap"><col class="c-unit">
        <col class="c-ppb"><col class="c-bc1"><col class="c-bc2"><col class="c-bc3"><col class="c-exp"><col class="c-stat">
      </colgroup>
      <thead>
        <tr>
          <th>Category</th>
          <th>Brand</th>
          <th>Product Name</th>
          <th>Capacity</th>
          <th>Unit</th>
          <th>Units/Box</th>
          <th>Unit Barcode</th>
          <th>Box Barcode</th>
          <th>Logistics Code</th>
          <th>Expiry</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($products)): ?>
        <tr><td colspan="11" class="center">No products found.</td></tr>
        <?php endif; ?>
        <?php foreach ($products as $p): ?>
        <tr class="<?php echo !$p['is_active'] ? 'inactive' : ''; ?>">
          <td><?php echo htmlspecialchars($p['category_name'] ?? '-'); ?></td>
          <td><?php echo htmlspecialchars($p['brand_name'] ?? '-'); ?></td>
          <td>
            <?php echo htmlspecialchars($p['name_en']); ?>
            <?php if ($p['name_ko']): ?><div class="name-ko"><?php echo htmlspecialchars($p['name_ko']); ?></div><?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars($p['capacity'] ?? '-'); ?></td>
          <td><?php echo htmlspecialchars($p['unit']); ?></td>
          <td class="center"><?php echo $p['pieces_per_box'] > 1 ? $p['pieces_per_box'] : '-'; ?></td>
          <td class="mono"><?php echo htmlspecialchars($p['barcode_unit'] ?? '-'); ?></td>
          <td class="mono"><?php echo htmlspecialchars($p['barcode_box'] ?? '-'); ?></td>
          <td class="mono"><?php echo htmlspecialchars($p['barcode_logistics'] ?? '-'); ?></td>
          <td class="center"><?php echo $p['requires_expiry'] ? 'Required' : 'Optional'; ?></td>
          <td class="center"><?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

  <script>
    function paginate() {
      var srcWrap  = document.getElementById('srcWrap');
      var headerEl = srcWrap.querySelector('.doc-header');
      var errEl    = srcWrap.querySelector('p');
      var srcTable = document.getElementById('srcTable');
      var thead    = srcTable.querySelector('thead');
      var rows     = Array.prototype.slice.call(srcTable.querySelectorAll('tbody > tr'));
      var pagesEl  = document.getElementById('pages');
      pagesEl.innerHTML = '';

      // 페이지 1장의 사용 가능 높이(px) 측정
      var probe = document.createElement('div');
      probe.className = 'print-page';
      probe.style.visibility = 'hidden';
      var probeContent = document.createElement('div');
      probeContent.className = 'page-content';
      probe.appendChild(probeContent);
      document.body.appendChild(probe);
      var pageContentHeight = probeContent.clientHeight;
      document.body.removeChild(probe);

      var footerHeight = 22;
      var headerHeight = headerEl ? headerEl.offsetHeight + (errEl ? errEl.offsetHeight : 0) + 8 : 0;
      var theadHeight  = thead.offsetHeight;

      var availableFirst = pageContentHeight - headerHeight - theadHeight - footerHeight;
      var availableNext  = pageContentHeight - theadHeight - footerHeight;

      var pagesData = [];
      var current = [];
      var currentHeight = 0;
      var available = availableFirst;

      rows.forEach(function(row) {
        var h = row.offsetHeight;
        if (currentHeight + h > available && current.length > 0) {
          pagesData.push(current);
          current = [];
          currentHeight = 0;
          available = availableNext;
        }
        current.push(row);
        currentHeight += h;
      });
      pagesData.push(current);

      var totalPages = pagesData.length;
      pagesData.forEach(function(rowsArr, idx) {
        var pageDiv = document.createElement('div');
        pageDiv.className = 'print-page';

        var content = document.createElement('div');
        content.className = 'page-content';

        if (idx === 0) {
          if (headerEl) content.appendChild(headerEl);
          if (errEl)    content.appendChild(errEl);
        }

        var table = document.createElement('table');
        table.appendChild(srcTable.querySelector('colgroup').cloneNode(true));
        table.appendChild(thead.cloneNode(true));
        var tbody = document.createElement('tbody');
        rowsArr.forEach(function(r) { tbody.appendChild(r); });
        table.appendChild(tbody);
        content.appendChild(table);

        pageDiv.appendChild(content);

        var footer = document.createElement('div');
        footer.className = 'page-footer';
        footer.textContent = 'Page ' + (idx + 1) + ' / ' + totalPages;
        pageDiv.appendChild(footer);

        pagesEl.appendChild(pageDiv);
      });

      srcWrap.remove();
    }

    if (document.readyState === 'complete') {
      paginate();
    } else {
      window.addEventListener('load', paginate);
    }
  </script>
</body>
</html>
