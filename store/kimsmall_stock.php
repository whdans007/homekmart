<?php
// KIM'S MALL WHEREHOUSE 재고 확인 — store/order.php와 동일한 상품 리스트 UI를 재사용하되
// 수량 입력/장바구니/주문 제출 기능은 전부 제거한 읽기 전용 페이지
$page_title = "KIM'S MALL WHEREHOUSE Stock";
require_once __DIR__ . '/partials/header.php';

// 재고 있는 상품 목록 + 카테고리 (kw_* 테이블 — KIM'S MALL WHEREHOUSE)
try {
    $conn = get_store_db();

    $has_image_col = false;
    try {
        $rc = $conn->query("SHOW COLUMNS FROM kw_products LIKE 'image_path'");
        $has_image_col = $rc && $rc->num_rows > 0;
    } catch (Exception $e) { /* 감지 실패 시 미지원으로 처리 */ }
    $img_select = $has_image_col ? "p.image_path," : "NULL AS image_path,";

    $categories = $conn->query(
        "SELECT DISTINCT c.id, c.name_en, c.name_ko
         FROM kw_categories c
         JOIN kw_products p ON p.category_id = c.id
         JOIN kw_inventory i ON i.product_id = p.id
         WHERE i.quantity_remain > 0 AND p.is_active = 1
         ORDER BY c.name_en ASC"
    )->fetch_all(MYSQLI_ASSOC);

    $products = $conn->query(
        "SELECT p.id, p.name_en, p.name_ko, p.unit, p.pieces_per_box, p.capacity,
                {$img_select}
                COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                p.category_id, c.name_en AS cat_name,
                b.name_en AS brand_name, b.name_ko AS brand_name_ko,
                SUM(CASE WHEN i.unit = 'BOX' THEN i.quantity_remain ELSE 0 END) AS box_stock,
                SUM(CASE WHEN i.unit = 'PACK' THEN i.quantity_remain ELSE 0 END) AS pack_stock,
                SUM(CASE WHEN i.unit = 'PCS' THEN i.quantity_remain ELSE 0 END) AS pcs_stock,
                MIN(ib.expiry_date) AS earliest_expiry,
                COALESCE((
                    SELECT SUM(inv.quantity_remain * ib2.cost_price) / NULLIF(SUM(inv.quantity_remain), 0)
                    FROM kw_inventory inv JOIN kw_inbound ib2 ON inv.inbound_id = ib2.id
                    WHERE inv.product_id = p.id AND inv.unit = 'BOX' AND inv.quantity_remain > 0 AND ib2.cost_price > 0
                ), 0) AS box_price,
                COALESCE((
                    SELECT SUM(inv.quantity_remain * ib2.cost_price) / NULLIF(SUM(inv.quantity_remain), 0)
                    FROM kw_inventory inv JOIN kw_inbound ib2 ON inv.inbound_id = ib2.id
                    WHERE inv.product_id = p.id AND inv.unit = 'PACK' AND inv.quantity_remain > 0 AND ib2.cost_price > 0
                ), 0) AS pack_price,
                COALESCE((
                    SELECT SUM(inv.quantity_remain * IF(ib2.inbound_unit IN ('BOX','PACK'), ib2.cost_price_pcs, ib2.cost_price))
                           / NULLIF(SUM(inv.quantity_remain), 0)
                    FROM kw_inventory inv JOIN kw_inbound ib2 ON inv.inbound_id = ib2.id
                    WHERE inv.product_id = p.id AND inv.unit = 'PCS' AND inv.quantity_remain > 0
                ), 0) AS pcs_price,
                MAX(ib.created_at) AS latest_inbound_at
         FROM kw_inventory i
         JOIN kw_products p ON i.product_id = p.id
         JOIN kw_inbound ib ON i.inbound_id = ib.id
         LEFT JOIN kw_categories c ON p.category_id = c.id
         LEFT JOIN kw_brands b ON p.brand_id = b.id
         WHERE i.quantity_remain > 0 AND p.is_active = 1
         GROUP BY p.id
         ORDER BY (MIN(ib.expiry_date) IS NULL) ASC, MIN(ib.expiry_date) ASC, latest_inbound_at DESC, c.name_en ASC, p.name_en ASC"
    )->fetch_all(MYSQLI_ASSOC);

    $conn->close();
} catch (Exception $e) {
    error_log('store/kimsmall_stock.php product list query failed: ' . $e->getMessage());
    $products = []; $categories = [];
}
?>

<div class="flex items-center gap-2 mb-4">
    <i class="fas fa-warehouse text-pink-600"></i>
    <h2 class="text-lg font-bold text-gray-900">KIM'S MALL WHEREHOUSE 재고 확인</h2>
    <span class="text-xs px-2 py-1 rounded-full bg-pink-50 text-pink-700 border border-pink-200">조회 전용 — 이 페이지에서는 주문할 수 없습니다</span>
</div>

<?php if (empty($products)): ?>
<div class="bg-white rounded-xl border border-gray-200 p-10 text-center text-gray-400">
    <i class="fas fa-box-open text-4xl mb-3 block"></i>
    <p>No stock available.</p>
</div>
<?php else: ?>

<style>
#productBody tr.product-row       { background-color: #ffffff; }
#productBody tr.product-row:hover { background-color: #fce7f3 !important; }
</style>

<!-- 검색 -->
<div class="mb-3">
    <div class="flex items-center w-full border border-gray-300 rounded-lg px-3 bg-white focus-within:ring-2 focus-within:ring-pink-400">
        <i class="fas fa-search text-gray-400 text-sm mr-2 flex-shrink-0"></i>
        <input type="text" id="searchInput" placeholder="Search product name..."
               class="flex-1 min-w-0 py-2.5 text-sm border-0 focus:outline-none focus:ring-0 bg-transparent">
    </div>
</div>

<!-- 카테고리 탭 -->
<div class="flex flex-wrap gap-2 pb-2 mb-4">
    <button type="button" data-cat="all"
            class="cat-tab flex-shrink-0 px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                   bg-pink-600 text-white border-pink-600">
        All
    </button>
    <?php foreach ($categories as $cat): ?>
    <button type="button" data-cat="<?php echo $cat['id']; ?>"
            class="cat-tab flex-shrink-0 px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                   bg-white text-gray-600 border-gray-300 hover:bg-pink-50 hover:border-pink-400">
        <?php echo htmlspecialchars($cat['name_ko'] ?: $cat['name_en']); ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- 상품 목록 -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
    <table class="w-full text-sm" id="productTable">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium w-16">Image</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium w-32">Barcode</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium w-28">Brand</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Product Name</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-16">PKG</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium w-24">Expiry</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-24">PCS Price</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-24">Box Price</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-24">Pack Price</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-28">Stock</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200" id="productBody">
        <?php foreach ($products as $p):
            $boxStock  = (int)$p['box_stock'];
            $packStock = (int)$p['pack_stock'];
            $pcsStock  = (int)$p['pcs_stock'];
        ?>
        <tr class="product-row transition-colors"
            data-cat="<?php echo $p['category_id'] ?? ''; ?>"
            data-name="<?php echo strtolower(($p['name_en'] ?? '') . ' ' . ($p['name_ko'] ?? '') . ' ' . ($p['brand_name'] ?? '') . ' ' . ($p['brand_name_ko'] ?? '') . ' ' . ($p['barcode'] ?? '')); ?>">
            <td class="px-4 py-3 text-center">
                <?php if (!empty($p['image_path'])): $img_url = STORE_WEB_ROOT . '/kimsmall_wherehouse/' . $p['image_path']; ?>
                <button type="button"
                        onclick="openImageLightbox('<?php echo htmlspecialchars(addslashes($img_url), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($p['name_en'] ?? ''), ENT_QUOTES); ?>')"
                        class="inline-block w-11 h-11 rounded border border-gray-200 overflow-hidden bg-gray-50 hover:ring-2 hover:ring-pink-400 align-middle" title="Click to enlarge">
                    <img src="<?php echo htmlspecialchars($img_url); ?>" alt="" class="w-full h-full object-cover" loading="lazy">
                </button>
                <?php else: ?>
                <span class="inline-flex items-center justify-center w-11 h-11 rounded border border-gray-100 bg-gray-50 text-gray-300 align-middle"><i class="fas fa-image text-xs"></i></span>
                <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-xs text-gray-400 font-mono whitespace-nowrap">
                <?php if ($p['barcode']): ?>
                <span><i class="fas fa-barcode mr-1 opacity-50"></i><?php echo htmlspecialchars($p['barcode']); ?></span>
                <?php else: ?>
                <span class="text-gray-300">-</span>
                <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-xs">
                <?php if ($p['brand_name'] || !empty($p['brand_name_ko'])): ?>
                <?php if (!empty($p['brand_name_ko'])): ?>
                <div class="text-gray-900 font-semibold leading-tight"><?php echo htmlspecialchars($p['brand_name_ko']); ?></div>
                <?php endif; ?>
                <?php if ($p['brand_name']): ?>
                <div class="text-gray-900 leading-tight"><?php echo htmlspecialchars($p['brand_name']); ?></div>
                <?php endif; ?>
                <?php else: ?>
                <span class="text-gray-300">-</span>
                <?php endif; ?>
            </td>
            <td class="px-4 py-3">
                <?php $cap = !empty($p['capacity']) ? ' ' . htmlspecialchars($p['capacity']) : ''; ?>
                <div class="leading-tight">
                    <?php if ($p['name_ko']): ?>
                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['name_ko']) . $cap; ?></div>
                    <div class="text-xs text-gray-900"><?php echo htmlspecialchars($p['name_en']); ?></div>
                    <?php else: ?>
                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['name_en']) . $cap; ?></div>
                    <?php endif; ?>
                </div>
            </td>
            <td class="px-4 py-3 text-right text-xs text-gray-500">
                <?php echo (int)$p['pieces_per_box'] > 1 ? number_format($p['pieces_per_box']) : '-'; ?>
            </td>
            <td class="px-4 py-3 text-center text-xs whitespace-nowrap">
                <?php if (!empty($p['earliest_expiry'])):
                    $daysLeft = (int)floor((strtotime($p['earliest_expiry']) - strtotime(date('Y-m-d'))) / 86400);
                    if ($daysLeft < 0)       $expClass = 'text-red-600 font-semibold';
                    elseif ($daysLeft <= 7)  $expClass = 'text-red-500 font-semibold';
                    elseif ($daysLeft <= 30) $expClass = 'text-amber-600';
                    else                     $expClass = 'text-gray-500';
                ?>
                <span class="<?php echo $expClass; ?>"><?php echo date('Y-m-d', strtotime($p['earliest_expiry'])); ?></span>
                <?php else: ?>
                <span class="text-gray-300">-</span>
                <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-right text-xs text-gray-500 font-mono">
                <?php echo $p['pcs_price'] > 0 ? number_format($p['pcs_price'], 2) : '-'; ?>
            </td>
            <td class="px-4 py-3 text-right text-xs text-gray-500 font-mono">
                <?php echo $p['box_price'] > 0 ? number_format($p['box_price'], 2) : '-'; ?>
            </td>
            <td class="px-4 py-3 text-right text-xs text-gray-500 font-mono">
                <?php echo $p['pack_price'] > 0 ? number_format($p['pack_price'], 2) : '-'; ?>
            </td>
            <td class="px-4 py-3 text-right text-xs">
                <?php if ($boxStock > 0): ?>
                <div><span class="font-semibold text-gray-700"><?php echo number_format($boxStock); ?></span> <span class="text-gray-400">BOX</span></div>
                <?php endif; ?>
                <?php if ($packStock > 0): ?>
                <div><span class="font-semibold text-gray-700"><?php echo number_format($packStock); ?></span> <span class="text-gray-400">PACK</span></div>
                <?php endif; ?>
                <?php if ($pcsStock > 0): ?>
                <div><span class="font-semibold text-gray-700"><?php echo number_format($pcsStock); ?></span> <span class="text-gray-400">PCS</span></div>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div id="noResult" class="hidden px-4 py-8 text-center text-gray-400 text-sm">
        <i class="fas fa-search mb-2 block text-2xl"></i>No search results.
    </div>
</div>

<script>
(function() {
    var activeCat = 'all';
    var searchVal = '';

    document.querySelectorAll('.cat-tab').forEach(function(btn) {
        btn.addEventListener('click', function() {
            activeCat = this.dataset.cat;
            document.querySelectorAll('.cat-tab').forEach(function(b) {
                b.classList.remove('bg-pink-600', 'text-white', 'border-pink-600');
                b.classList.add('bg-white', 'text-gray-600', 'border-gray-300');
            });
            this.classList.add('bg-pink-600', 'text-white', 'border-pink-600');
            this.classList.remove('bg-white', 'text-gray-600', 'border-gray-300');
            filterRows();
        });
    });

    document.getElementById('searchInput').addEventListener('input', function() {
        searchVal = this.value.trim().toLowerCase();
        filterRows();
    });

    function filterRows() {
        var rows = document.querySelectorAll('.product-row');
        var matched = 0;
        rows.forEach(function(row) {
            var catMatch  = activeCat === 'all' || row.dataset.cat == activeCat;
            var nameMatch = !searchVal || row.dataset.name.includes(searchVal);
            var show = catMatch && nameMatch;
            row.classList.toggle('hidden', !show);
            if (show) matched++;
        });
        document.getElementById('noResult').classList.toggle('hidden', matched > 0);
    }
})();
</script>

<!-- 이미지 확대 라이트박스 -->
<div id="imageLightbox" class="hidden fixed inset-0 flex items-center justify-center bg-black/60 p-4" style="z-index:10001;" onclick="closeImageLightbox()">
    <div class="bg-white rounded-xl shadow-2xl overflow-hidden flex flex-col border-2 border-gray-300 ring-1 ring-black/5" style="width:26rem;max-width:92vw;" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100">
            <span id="lightboxTitle" class="text-sm font-semibold text-gray-800 truncate pr-2"></span>
            <button type="button" onclick="closeImageLightbox()" title="Close"
                    class="text-gray-400 hover:text-gray-700 text-lg flex-shrink-0 w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="bg-gray-50 flex items-center justify-center" style="height:22rem;">
            <img id="lightboxImg" src="" alt="" class="max-w-full max-h-full object-contain">
        </div>
        <div class="px-4 py-3 border-t border-gray-100 flex justify-end">
            <button type="button" onclick="closeImageLightbox()"
                    class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">
                Close
            </button>
        </div>
    </div>
</div>
<script>
    window.openImageLightbox = function(src, alt) {
        if (!src) return;
        var img = document.getElementById('lightboxImg');
        img.src = src; img.alt = alt || '';
        document.getElementById('lightboxTitle').textContent = alt || '';
        document.getElementById('imageLightbox').classList.remove('hidden');
    };
    window.closeImageLightbox = function() {
        document.getElementById('imageLightbox').classList.add('hidden');
        document.getElementById('lightboxImg').src = '';
    };
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeImageLightbox();
    });
</script>

<?php endif; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
