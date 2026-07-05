<?php
$page_title      = 'POS Sales Data — 상세 조회';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id  = get_office_store_id();
$upload_id = (int)($_GET['id'] ?? 0);
if (!$upload_id) { header('Location: index.php'); exit; }

$conn = get_db_connection();

// 업로드 메타 확인
$meta = $conn->prepare("SELECT * FROM pos_sales_uploads WHERE id=? AND store_id=?");
$meta->bind_param('ii', $upload_id, $store_id);
$meta->execute();
$upload = $meta->get_result()->fetch_assoc();
$meta->close();

if (!$upload) { $conn->close(); header('Location: index.php'); exit; }

// 페이지네이션
$per_page = 100;
$page     = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page - 1) * $per_page;

$total_rows = (int)$conn->query("SELECT COUNT(*) FROM pos_sales_data WHERE upload_id={$upload_id}")->fetch_row()[0];
$total_pages = (int)ceil($total_rows / $per_page);

$rows = $conn->query(
    "SELECT * FROM pos_sales_data WHERE upload_id={$upload_id}
     ORDER BY row_no ASC LIMIT {$per_page} OFFSET {$offset}"
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

<div class="flex items-center gap-3 mb-4">
  <a href="index.php" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
  <div>
    <h2 class="text-lg font-bold text-gray-800">
      <i class="fa-solid fa-table mr-2 text-indigo-600"></i><?php echo htmlspecialchars($upload['file_name']); ?>
    </h2>
    <p class="text-xs text-gray-400">
      <?php echo htmlspecialchars($upload['upload_date']); ?> · 슬롯 <?php echo $upload['file_slot']; ?> ·
      총 <?php echo number_format($upload['row_count']); ?>건
    </p>
  </div>
</div>

<?php if (empty($rows)): ?>
<div class="text-center py-12 text-gray-400">데이터가 없습니다.</div>
<?php else: ?>

<!-- 페이지 정보 -->
<div class="flex items-center justify-between mb-3 text-sm text-gray-500">
  <span><?php echo number_format(($page-1)*$per_page+1); ?>~<?php echo number_format(min($page*$per_page, $total_rows)); ?>건 / 전체 <?php echo number_format($total_rows); ?>건</span>
  <?php if ($total_pages > 1): ?>
  <div class="flex gap-1">
    <?php for ($i = max(1,$page-3); $i <= min($total_pages,$page+3); $i++): ?>
    <a href="?id=<?php echo $upload_id; ?>&p=<?php echo $i; ?>"
       class="px-2.5 py-1 rounded text-xs <?php echo $i===$page ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-200 hover:bg-gray-50'; ?>">
      <?php echo $i; ?>
    </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
  <table class="min-w-full text-xs divide-y divide-gray-100">
    <thead class="bg-gray-50 sticky top-0">
      <tr>
        <th class="px-2 py-2 text-left text-gray-500 font-semibold">#</th>
        <?php foreach ($headers as $h): ?>
        <th class="px-2 py-2 text-left text-gray-500 font-semibold whitespace-nowrap"><?php echo htmlspecialchars($h); ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php foreach ($rows as $row): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-2 py-1.5 text-gray-400"><?php echo $row['row_no']; ?></td>
        <?php foreach ($cols as $col): ?>
        <td class="px-2 py-1.5 whitespace-nowrap <?php echo in_array($col, $numeric) ? 'text-right font-mono text-gray-700' : 'text-gray-700'; ?>">
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
  <a href="?id=<?php echo $upload_id; ?>&p=<?php echo $page-1; ?>" class="px-3 py-1.5 rounded border border-gray-200 text-xs hover:bg-gray-50">이전</a>
  <?php endif; ?>
  <?php for ($i = max(1,$page-3); $i <= min($total_pages,$page+3); $i++): ?>
  <a href="?id=<?php echo $upload_id; ?>&p=<?php echo $i; ?>"
     class="px-2.5 py-1.5 rounded text-xs <?php echo $i===$page ? 'bg-indigo-600 text-white' : 'border border-gray-200 hover:bg-gray-50'; ?>">
    <?php echo $i; ?>
  </a>
  <?php endfor; ?>
  <?php if ($page < $total_pages): ?>
  <a href="?id=<?php echo $upload_id; ?>&p=<?php echo $page+1; ?>" class="px-3 py-1.5 rounded border border-gray-200 text-xs hover:bg-gray-50">다음</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
