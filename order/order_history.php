<?php
// Design Ref: §11.1 — 발주 이력 조회
$page_title = '발주 이력';
require_once __DIR__ . '/partials/header.php';

$storeId    = ord_current_store_id();
$vendorFilter = (int)($_GET['vendor_id'] ?? 0);
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 20;
$offset     = ($page - 1) * $limit;

$conn = get_ord_db();

// 업체 목록 (필터용)
$stmt = $conn->prepare("SELECT id, name FROM order_vendors ORDER BY name");
$stmt->execute();
$vendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$where = ord_is_admin() ? '' : "WHERE h.store_id = $storeId";
$vendorWhere = '';
if ($vendorFilter) $vendorWhere = ($where ? ' AND' : ' WHERE') . " h.vendor_id = $vendorFilter";

$countSql = "SELECT COUNT(*) FROM order_history h $where$vendorWhere";
$total = $conn->query($countSql)->fetch_row()[0] ?? 0;

$sql = "SELECT h.id, h.vendor_name, h.order_date, h.item_count, h.items_json,
               u.full_name AS ordered_by_name
        FROM order_history h
        LEFT JOIN users u ON h.ordered_by = u.id
        $where$vendorWhere
        ORDER BY h.order_date DESC
        LIMIT $limit OFFSET $offset";
$histories = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
$conn->close();
$pages = ceil($total / $limit);
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-xl font-bold text-gray-800"><i class="fas fa-history mr-2 text-indigo-600"></i>발주 이력</h1>
    <span class="text-sm text-gray-400">총 <?php echo number_format($total); ?>건</span>
</div>

<!-- 필터 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-4">
    <form method="get" class="flex gap-3 items-center">
        <select name="vendor_id" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
            <option value="0">업체 전체</option>
            <?php foreach ($vendors as $v): ?>
            <option value="<?php echo $v['id']; ?>" <?php echo $vendorFilter == $v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">조회</button>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-gray-500">#</th>
                <th class="px-4 py-3 text-left font-medium text-gray-500">업체</th>
                <th class="px-4 py-3 text-left font-medium text-gray-500">발주일시</th>
                <th class="px-4 py-3 text-center font-medium text-gray-500">상품 수</th>
                <th class="px-4 py-3 text-left font-medium text-gray-500">담당자</th>
                <th class="px-4 py-3 text-center font-medium text-gray-500">상세</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($histories)): ?>
            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">발주 이력이 없습니다.</td></tr>
            <?php else: foreach ($histories as $h): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-400"><?php echo $h['id']; ?></td>
                <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($h['vendor_name']); ?></td>
                <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($h['order_date']); ?></td>
                <td class="px-4 py-3 text-center">
                    <span class="inline-block px-2 py-0.5 bg-indigo-50 text-indigo-700 rounded-full text-xs font-medium"><?php echo $h['item_count']; ?>종</span>
                </td>
                <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($h['ordered_by_name'] ?? ''); ?></td>
                <td class="px-4 py-3 text-center">
                    <button onclick="showDetail(<?php echo $h['id']; ?>, this.dataset.items)"
                            data-items="<?php echo htmlspecialchars($h['items_json']); ?>"
                            class="text-xs text-indigo-600 hover:text-indigo-800">상세보기</button>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div class="px-4 py-3 border-t border-gray-100 flex justify-center gap-2">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="?vendor_id=<?php echo $vendorFilter; ?>&page=<?php echo $i; ?>"
           class="px-3 py-1 text-xs rounded <?php echo $i === $page ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 상세 모달 -->
<div id="detailModal" class="hidden fixed inset-0 bg-black bg-opacity-40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-screen overflow-y-auto">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">발주 상세</h3>
            <button onclick="document.getElementById('detailModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <div id="detailContent" class="p-5"></div>
    </div>
</div>

<script>
function showDetail(id, itemsJson) {
    const items = JSON.parse(itemsJson || '[]');
    const content = document.getElementById('detailContent');
    content.innerHTML = `
        <table class="min-w-full text-sm divide-y divide-gray-100">
            <thead><tr class="text-left text-xs text-gray-500">
                <th class="pb-2">상품명</th><th class="pb-2">코드</th>
                <th class="pb-2 text-right">단가</th><th class="pb-2 text-right">수량</th><th class="pb-2">단위</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
                ${items.map(i => `
                <tr>
                    <td class="py-2 font-medium text-gray-800">${escHtml(i.product_name || '')}</td>
                    <td class="py-2 text-gray-400">${escHtml(i.product_code || '')}</td>
                    <td class="py-2 text-right text-gray-600">${i.unit_price !== null ? Number(i.unit_price).toLocaleString('ko-KR', {minimumFractionDigits:2}) : ''}</td>
                    <td class="py-2 text-right font-semibold text-indigo-700">${i.quantity}</td>
                    <td class="py-2 text-gray-400">${escHtml(i.unit || '')}</td>
                </tr>`).join('')}
            </tbody>
        </table>`;
    document.getElementById('detailModal').classList.remove('hidden');
}
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
