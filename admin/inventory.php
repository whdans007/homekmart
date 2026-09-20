<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/inventory_service.php';

$page_title = '재고 관리';
require_once __DIR__ . '/partials/header.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true)) {
    http_response_code(403);
    exit('권한이 없습니다.');
}

$conn = get_db_connection();
$stores = [];
$store_result = $conn->query('SELECT id, name FROM stores ORDER BY name');
if ($store_result) {
    $stores = $store_result->fetch_all(MYSQLI_ASSOC);
}
$store_id = (int)($_GET['store_id'] ?? ($_SESSION['store_id'] ?? 0));
if ($store_id <= 0 && $stores) {
    $store_id = (int)$stores[0]['id'];
}
$q = trim((string)($_GET['q'] ?? ''));
$rows = [];
if ($store_id > 0) {
    $sql = 'SELECT p.id AS product_id, p.name_ko, p.name_en, p.sku, COALESCE(p.pieces_per_box, 1) AS pieces_per_box,
                   COALESCE(i.quantity, 0) AS quantity,
                   MAX(pur.purchase_date) AS latest_purchase_date
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id AND i.store_id = ?
            LEFT JOIN purchase_items pi ON pi.product_id = p.id
            LEFT JOIN purchases pur ON pur.purchase_id = pi.purchase_id AND pur.store_id = ? AND pur.deleted_at IS NULL
            WHERE (? = "" OR p.name_ko LIKE CONCAT("%", ?, "%") OR p.sku LIKE CONCAT("%", ?, "%"))
            GROUP BY p.id, p.name_ko, p.name_en, p.sku, p.pieces_per_box, i.quantity
            ORDER BY latest_purchase_date IS NULL ASC, latest_purchase_date DESC, p.name_ko ASC
            LIMIT 500';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iisss', $store_id, $store_id, $q, $q, $q);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<main class="p-6 bg-gray-50 min-h-screen">
  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">재고 관리</h1>
      <p class="text-sm text-gray-500 mt-1">매입·POS·몰·도매·외상·이동·폐기 원장을 합산한 현재 재고입니다. 음수 재고도 그대로 표시됩니다.</p>
    </div>
  </div>
  <form method="get" class="bg-white border rounded-lg p-4 mb-4 flex flex-wrap gap-3 items-end">
    <label class="text-sm">점포
      <select name="store_id" class="block mt-1 border rounded px-3 py-2">
        <?php foreach ($stores as $store): ?>
          <option value="<?= (int)$store['id'] ?>" <?= (int)$store['id'] === $store_id ? 'selected' : '' ?>><?= htmlspecialchars($store['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-sm">상품/SKU 검색
      <input name="q" value="<?= htmlspecialchars($q) ?>" class="block mt-1 border rounded px-3 py-2" placeholder="상품명 또는 SKU">
    </label>
    <button class="px-4 py-2 rounded bg-teal-600 text-white">조회</button>
  </form>
  <div id="inventory-message" class="hidden mb-4 rounded p-3 text-sm"></div>
  <div class="bg-white border rounded-lg overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50 border-b"><tr>
        <th class="px-4 py-3 text-left">상품</th><th class="px-4 py-3 text-left">SKU</th>
        <th class="px-4 py-3 text-right">현재 재고(낱개)</th><th class="px-4 py-3 text-right">실사 수량</th><th class="px-4 py-3 text-left">사유</th><th class="px-4 py-3"></th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($rows as $row): $qty = (float)$row['quantity']; $negative = $qty < 0; ?>
        <tr class="inventory-row cursor-pointer hover:bg-teal-50" data-product-id="<?= (int)$row['product_id'] ?>" data-sku="<?= htmlspecialchars($row['sku'] ?? '-', ENT_QUOTES) ?>" data-name-ko="<?= htmlspecialchars($row['name_ko'], ENT_QUOTES) ?>" data-name-en="<?= htmlspecialchars($row['name_en'] ?? '', ENT_QUOTES) ?>" data-quantity="<?= htmlspecialchars(number_format($qty, 2, '.', ''), ENT_QUOTES) ?>">
          <td class="px-4 py-3 text-gray-500 font-mono"><?= htmlspecialchars($row['sku'] ?? '-') ?></td>
          <td class="px-4 py-3"><div class="font-medium text-gray-900"><?= htmlspecialchars($row['name_ko']) ?></div><div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($row['name_en'] ?? '') ?></div></td>
          <td class="px-4 py-3 text-right <?= $negative ? 'text-red-600 font-bold' : '' ?>"><?= number_format($qty, 2) ?></td>
          <td class="px-4 py-2"><input data-product-id="<?= (int)$row['product_id'] ?>" type="text" inputmode="decimal" autocomplete="off" class="counted-qty w-28 border rounded px-2 py-1 text-right" placeholder="실사"></td>
          <td class="px-4 py-2"><input data-reason-id="<?= (int)$row['product_id'] ?>" class="reason w-48 border rounded px-2 py-1" placeholder="조정 사유"></td>
          <td class="px-4 py-2"><button data-adjust-id="<?= (int)$row['product_id'] ?>" class="adjust-btn px-3 py-1.5 rounded bg-amber-500 text-white">조정</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">상품이 없습니다.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</main>
<div id="inventory-history-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-gray-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="history-title">
  <div class="w-full max-w-5xl max-h-[90vh] overflow-hidden rounded-lg bg-white shadow-xl flex flex-col">
    <div class="flex items-start justify-between border-b px-5 py-4">
      <div><h2 id="history-title" class="text-lg font-bold text-gray-900">재고 변동 이력</h2><p id="history-product" class="mt-1 text-sm text-gray-500"></p></div>
      <button id="history-close" type="button" class="rounded p-2 text-gray-500 hover:bg-gray-100" aria-label="닫기">&times;</button>
    </div>
    <div id="history-summary" class="border-b bg-gray-50 px-5 py-3 text-sm text-gray-700"></div>
    <div class="overflow-auto"><table class="min-w-full text-sm"><thead class="sticky top-0 bg-white shadow-sm"><tr>
      <th class="px-4 py-3 text-left">일시</th><th class="px-4 py-3 text-left">구분</th><th class="px-4 py-3 text-right">변동</th><th class="px-4 py-3 text-right">변동 후 재고</th><th class="px-4 py-3 text-left">처리자</th><th class="px-4 py-3 text-left">비고</th>
    </tr></thead><tbody id="history-body" class="divide-y"></tbody></table></div>
    <div class="flex items-center justify-between border-t px-5 py-3"><span id="history-page-info" class="text-xs text-gray-500"></span><div class="flex gap-2"><button id="history-prev" type="button" class="rounded border px-3 py-1 text-sm disabled:opacity-40">이전</button><button id="history-next" type="button" class="rounded border px-3 py-1 text-sm disabled:opacity-40">다음</button></div></div>
  </div>
</div>
<script>
const inventoryHeaders = document.querySelectorAll('table thead th');
if (inventoryHeaders.length >= 2) {
  inventoryHeaders[0].textContent = 'SKU';
  inventoryHeaders[1].textContent = '상품명 (한글 / English)';
}
const historyModal = document.getElementById('inventory-history-modal');
const historyBody = document.getElementById('history-body');
const historySummary = document.getElementById('history-summary');
const historyProduct = document.getElementById('history-product');
const historyPageInfo = document.getElementById('history-page-info');
let historyProductId = 0;
let historyPage = 1;
const historyLimit = 30;
const setCell = (text, className = '') => { const cell = document.createElement('td'); cell.className = `px-4 py-3 ${className}`; cell.textContent = text; return cell; };
async function loadInventoryHistory(page = 1) {
  historyBody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">이력을 불러오는 중입니다.</td></tr>';
  const response = await fetch(`ajax/get_inventory_history.php?product_id=${historyProductId}&store_id=<?= (int)$store_id ?>&page=${page}&limit=${historyLimit}`);
  const data = await response.json();
  if (!data.success) throw new Error(data.message || '이력 조회에 실패했습니다.');
  historyPage = data.pagination.page;
  historyBody.innerHTML = '';
  if (!data.items.length) { historyBody.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">재고 변동 이력이 없습니다.</td></tr>'; }
  data.items.forEach((item) => {
    const row = document.createElement('tr');
    const change = Number(item.quantity_change);
    row.append(setCell(item.occurred_at), setCell(item.event_label), setCell(`${change > 0 ? '+' : ''}${change.toFixed(2)}`, `text-right font-semibold ${change < 0 ? 'text-red-600' : 'text-green-600'}`), setCell(Number(item.quantity_after).toFixed(2), `text-right ${Number(item.quantity_after) < 0 ? 'font-bold text-red-600' : ''}`), setCell(item.operator), setCell(item.remarks || `${item.source_type} #${item.source_id}`, 'text-gray-600'));
    historyBody.appendChild(row);
  });
  const pages = data.pagination.pages;
  historyPageInfo.textContent = `${data.pagination.total}건 · ${historyPage}/${pages} 페이지`;
  document.getElementById('history-prev').disabled = historyPage <= 1;
  document.getElementById('history-next').disabled = historyPage >= pages;
}
document.querySelectorAll('.inventory-row').forEach((row) => row.addEventListener('click', async (event) => {
  if (event.target.closest('input, button')) return;
  historyProductId = Number(row.dataset.productId);
  historyProduct.textContent = `${row.dataset.sku} · ${row.dataset.nameKo}${row.dataset.nameEn ? ` / ${row.dataset.nameEn}` : ''}`;
  historySummary.textContent = `현재 재고: ${row.dataset.quantity}개 · 전체 변동 이력은 최신순으로 표시됩니다.`;
  historyModal.classList.remove('hidden'); historyModal.classList.add('flex');
  try { await loadInventoryHistory(1); } catch (error) { historyBody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-red-600">${error.message}</td></tr>`; }
}));
const closeHistory = () => { historyModal.classList.add('hidden'); historyModal.classList.remove('flex'); };
document.getElementById('history-close').addEventListener('click', closeHistory);
historyModal.addEventListener('click', (event) => { if (event.target === historyModal) closeHistory(); });
document.getElementById('history-prev').addEventListener('click', () => loadInventoryHistory(historyPage - 1));
document.getElementById('history-next').addEventListener('click', () => loadInventoryHistory(historyPage + 1));
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeHistory(); });
document.querySelectorAll('.adjust-btn').forEach((button) => button.addEventListener('click', async () => {
  const id = button.dataset.adjustId;
  const qty = document.querySelector(`.counted-qty[data-product-id="${id}"]`).value;
  const reason = document.querySelector(`[data-reason-id="${id}"]`).value.trim();
  const message = document.getElementById('inventory-message');
  if (qty === '' || !reason) { message.textContent = '실사 수량과 조정 사유를 입력해 주세요.'; message.className = 'mb-4 rounded p-3 text-sm bg-red-50 text-red-700'; return; }
  button.disabled = true;
  const body = new URLSearchParams({product_id: id, store_id: '<?= (int)$store_id ?>', counted_quantity: qty, reason});
  try {
    const response = await fetch('ajax/adjust_inventory.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body});
    const data = await response.json();
    message.textContent = data.message || (data.success ? '조정 완료' : '조정 실패');
    message.className = `mb-4 rounded p-3 text-sm ${data.success ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`;
    if (data.success) setTimeout(() => location.reload(), 500);
  } catch (error) { message.textContent = '조정 중 오류가 발생했습니다.'; message.className = 'mb-4 rounded p-3 text-sm bg-red-50 text-red-700'; }
  button.disabled = false;
}));
</script>
<?php $conn->close(); require_once __DIR__ . '/partials/footer.php'; ?>
