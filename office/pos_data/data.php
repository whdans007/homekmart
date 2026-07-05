<?php
$page_title      = 'POS Sales Data';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$conn     = get_db_connection();

// 페이지네이션
$per_page = 200;
$page     = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page - 1) * $per_page;

// 검색
$search   = trim($_GET['q']  ?? '');
$supplier = trim($_GET['s']  ?? '');

$where = "d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})";
if ($search !== '') {
    $like   = '%' . $conn->real_escape_string($search) . '%';
    $where .= " AND (d.sale_date LIKE '{$like}' OR d.item_name LIKE '{$like}' OR d.item_code LIKE '{$like}' OR d.supplier LIKE '{$like}' OR d.department LIKE '{$like}' OR d.si_no LIKE '{$like}' OR d.cashier LIKE '{$like}')";
}
if ($supplier !== '') {
    $slike  = '%' . $conn->real_escape_string($supplier) . '%';
    $where .= " AND d.supplier LIKE '{$slike}'";
}

$total_rows  = (int)$conn->query("SELECT COUNT(*) FROM pos_sales_data d WHERE {$where}")->fetch_row()[0];
$total_pages = (int)ceil($total_rows / $per_page);

$rows = $conn->query(
    "SELECT d.*, u.uploaded_at
     FROM pos_sales_data d
     JOIN pos_sales_uploads u ON u.id = d.upload_id
     WHERE {$where}
     ORDER BY u.uploaded_at DESC, d.row_no ASC
     LIMIT {$per_page} OFFSET {$offset}"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();

$headers = ['DATE','TIME','STORE ID','SI NO.','ITEMCODE','ITEMNAME','SUPPLIER','DEPARTMENT',
            'BOX','PCS','UNIT COST','TOTAL COST','SELLING PRICE','DISCOUNT','TOTAL SALES',
            'GROSS PROFIT','SC DISCOUNT','PWD DISCOUNT','LESS VAT','NET SALES','CASHIER','PAYMENT FORM'];
$cols    = ['sale_date','sale_time','pos_store_id','si_no','item_code','item_name','supplier','department',
            'box','pcs','unit_cost','total_cost','selling_price','discount','total_sales',
            'gross_profit','sc_discount','pwd_discount','less_vat','net_sales','cashier','payment_form'];
$numeric = ['box','pcs','unit_cost','total_cost','selling_price','discount','total_sales',
            'gross_profit','sc_discount','pwd_discount','less_vat','net_sales'];
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-table mr-2 text-blue-600"></i>POS Sales Data
  </h2>
  <div class="flex gap-2">
    <?php if ($total_rows > 0): ?>
    <a href="export.php?<?php echo http_build_query(['q' => $search, 's' => $supplier]); ?>"
       class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
      <i class="fa-solid fa-file-excel mr-1"></i>Excel (<?php echo number_format($total_rows); ?>건)
    </a>
    <?php endif; ?>
    <a href="upload.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
      <i class="fa-solid fa-upload mr-1"></i>Upload
    </a>
  </div>
</div>

<!-- 검색 (서버) -->
<form method="GET" class="flex flex-wrap gap-2 mb-2">
  <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
         placeholder="날짜, 상품명, 코드, 부서, SI No., 캐셔..."
         class="flex-1 min-w-48 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
  <input type="text" name="s" value="<?php echo htmlspecialchars($supplier); ?>"
         placeholder="SUPPLIER 검색..."
         class="w-52 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
  <button type="submit" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm">
    <i class="fa-solid fa-magnifying-glass mr-1"></i>검색
  </button>
  <?php if ($search || $supplier): ?>
  <a href="data.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-sm">초기화</a>
  <?php endif; ?>
</form>

<?php if (!empty($rows)): ?>
<!-- 검색 내 재검색 (클라이언트, 현재 페이지만) -->
<div class="flex items-center gap-2 mb-4 bg-blue-50 border border-blue-100 rounded-lg px-3 py-2">
  <i class="fa-solid fa-filter text-blue-400 text-xs"></i>
  <span class="text-xs text-blue-500 font-medium whitespace-nowrap">결과 내 재검색:</span>
  <input type="text" id="refine_input" oninput="refineRows(this.value)"
         placeholder="현재 결과에서 추가 필터..."
         class="flex-1 border border-blue-200 rounded px-2.5 py-1 text-xs focus:ring-2 focus:ring-blue-400 bg-white">
  <span id="refine_count" class="text-xs text-blue-400 whitespace-nowrap"></span>
  <button onclick="document.getElementById('refine_input').value=''; refineRows('');"
          class="text-xs text-blue-400 hover:text-blue-600 px-1">✕</button>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
<div class="text-center py-16 text-gray-400">
  <i class="fa-solid fa-table text-4xl mb-3"></i>
  <p>데이터가 없습니다.</p>
  <a href="upload.php" class="mt-3 inline-block text-blue-600 hover:underline text-sm">엑셀 파일 업로드하기</a>
</div>
<?php else: ?>

<!-- 페이지 정보 -->
<div class="flex items-center justify-between mb-2 text-sm text-gray-500">
  <span>
    <?php echo number_format(($page-1)*$per_page+1); ?>~<?php echo number_format(min($page*$per_page, $total_rows)); ?>건
    / 전체 <strong><?php echo number_format($total_rows); ?></strong>건
    <?php if ($search): ?>
    <span class="text-blue-600 ml-1">"<?php echo htmlspecialchars($search); ?>" 검색결과</span>
    <?php endif; ?>
  </span>
  <?php if ($total_pages > 1): ?>
  <div class="flex gap-1">
    <?php if ($page > 1): ?>
    <a href="?p=<?php echo $page-1; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?><?php echo $supplier ? '&s='.urlencode($supplier) : ''; ?>"
       class="px-2.5 py-1 rounded border border-gray-200 text-xs hover:bg-gray-50">이전</a>
    <?php endif; ?>
    <?php for ($i = max(1,$page-3); $i <= min($total_pages,$page+3); $i++): ?>
    <a href="?p=<?php echo $i; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?><?php echo $supplier ? '&s='.urlencode($supplier) : ''; ?>"
       class="px-2.5 py-1 rounded text-xs <?php echo $i===$page ? 'bg-blue-600 text-white' : 'border border-gray-200 hover:bg-gray-50'; ?>">
      <?php echo $i; ?>
    </a>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?>
    <a href="?p=<?php echo $page+1; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?><?php echo $supplier ? '&s='.urlencode($supplier) : ''; ?>"
       class="px-2.5 py-1 rounded border border-gray-200 text-xs hover:bg-gray-50">다음</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
  <table class="min-w-full text-xs divide-y divide-gray-100">
    <thead class="bg-gray-50 sticky top-0">
      <tr>
        <?php foreach ($headers as $h): ?>
        <th class="px-2 py-2 text-left text-gray-500 font-semibold whitespace-nowrap"><?php echo $h; ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($rows as $row): ?>
      <tr class="hover:bg-gray-50" data-row="<?php
          echo htmlspecialchars(strtolower(implode(' ', array_map(fn($c) => $row[$c] ?? '', $cols))), ENT_QUOTES);
      ?>">
        <?php foreach ($cols as $col): ?>
        <td class="px-2 py-1.5 whitespace-nowrap <?php echo in_array($col,$numeric) ? 'text-right font-mono text-gray-700' : 'text-gray-700'; ?>">
          <?php
            $v = $row[$col] ?? '';
            if (in_array($col, $numeric) && $v !== null && $v !== '') {
                echo number_format((float)$v, 2);
            } else {
                echo htmlspecialchars((string)$v);
            }
          ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- 하단 페이지네이션 -->
<?php if ($total_pages > 1): ?>
<div class="flex justify-center gap-1 mt-4">
  <?php if ($page > 1): ?>
  <a href="?p=<?php echo $page-1; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?><?php echo $supplier ? '&s='.urlencode($supplier) : ''; ?>"
     class="px-3 py-1.5 rounded border border-gray-200 text-xs hover:bg-gray-50">이전</a>
  <?php endif; ?>
  <?php for ($i = max(1,$page-3); $i <= min($total_pages,$page+3); $i++): ?>
  <a href="?p=<?php echo $i; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?><?php echo $supplier ? '&s='.urlencode($supplier) : ''; ?>"
     class="px-2.5 py-1.5 rounded text-xs <?php echo $i===$page ? 'bg-blue-600 text-white' : 'border border-gray-200 hover:bg-gray-50'; ?>">
    <?php echo $i; ?>
  </a>
  <?php endfor; ?>
  <?php if ($page < $total_pages): ?>
  <a href="?p=<?php echo $page+1; ?><?php echo $search ? '&q='.urlencode($search) : ''; ?><?php echo $supplier ? '&s='.urlencode($supplier) : ''; ?>"
     class="px-3 py-1.5 rounded border border-gray-200 text-xs hover:bg-gray-50">다음</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
function refineRows(q) {
    const kw   = q.trim().toLowerCase();
    const rows = document.querySelectorAll('tbody tr[data-row]');
    let visible = 0;
    rows.forEach(tr => {
        const match = !kw || tr.dataset.row.includes(kw);
        tr.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const el = document.getElementById('refine_count');
    if (el) el.textContent = kw ? visible + ' / ' + rows.length + '건' : '';
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
