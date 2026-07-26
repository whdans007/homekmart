<?php
$page_title = "Category Management - KIM'S MALL WAREHOUSE";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';

kw_require_staff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kw_verify_csrf();
    $action = $_POST['action'] ?? '';
    $conn = get_lc_db();
    if ($action === 'add') {
        $name_en = trim($_POST['name_en'] ?? '');
        $name_ko = trim($_POST['name_ko'] ?? '') ?: null;
        if ($name_en !== '') {
            $st = $conn->prepare("INSERT INTO kw_categories (name_en, name_ko) VALUES (?, ?)");
            $st->bind_param('ss', $name_en, $name_ko); $st->execute(); $st->close();
            kw_set_flash('success', "Category '{$name_en}' registered successfully");
        }
    } elseif ($action === 'edit') {
        $cid = (int)($_POST['cat_id'] ?? 0);
        $name_en = trim($_POST['name_en'] ?? '');
        $name_ko = trim($_POST['name_ko'] ?? '') ?: null;
        if ($cid > 0 && $name_en !== '') {
            $st = $conn->prepare("UPDATE kw_categories SET name_en = ?, name_ko = ? WHERE id = ?");
            $st->bind_param('ssi', $name_en, $name_ko, $cid); $st->execute(); $st->close();
            kw_set_flash('success', "Category '{$name_en}' updated successfully");
        }
    } elseif ($action === 'delete') {
        $cid = (int)($_POST['cat_id'] ?? 0);
        $st = $conn->prepare("DELETE FROM kw_categories WHERE id = ?");
        $st->bind_param('i', $cid); $st->execute(); $st->close();
        kw_set_flash('success', 'Deleted successfully.');
    }
    $conn->close();
    header('Location: ' . LC_BASE . '/category_manage.php?' . http_build_query(['search' => trim($_GET['search'] ?? ''), 'page' => (int)($_GET['page'] ?? 1)])); exit;
}

$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

try {
    $conn = get_lc_db();

    $where = ''; $params = []; $types = '';
    if ($search !== '') {
        $where = "WHERE (c.name_en LIKE ? OR c.name_ko LIKE ?)";
        $params = ["%$search%", "%$search%"]; $types = 'ss';
    }

    $cnt = $conn->prepare("SELECT COUNT(*) FROM kw_categories c $where");
    if ($params) { $cnt->bind_param($types, ...$params); }
    $cnt->execute();
    $total = (int)$cnt->get_result()->fetch_row()[0]; $cnt->close();
    $total_pages = max(1, (int)ceil($total / $limit));

    $sql = "SELECT c.id, c.name_en, c.name_ko, COUNT(p.id) AS product_count
            FROM kw_categories c LEFT JOIN kw_products p ON p.category_id = c.id
            $where
            GROUP BY c.id, c.name_en, c.name_ko
            ORDER BY c.name_en ASC
            LIMIT $limit OFFSET $offset";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $cats = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
    $cats = []; $total = 0; $total_pages = 1;
}
?>

<style>
/* category_manage 전용: main 스크롤 비활성화, flex 레이아웃으로 뷰포트 꽉 채움 */
main { overflow: hidden !important; }
</style>

<div class="flex flex-col h-full gap-3 overflow-hidden">

<div class="flex items-center gap-3 shrink-0">
    <a href="<?php echo LC_BASE; ?>/products.php" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-900">Category Management</h2>
</div>

<!-- 신규 등록 -->
<div class="bg-white rounded-lg border border-gray-200 px-3 py-2 shrink-0">
    <form method="post" id="catForm" onsubmit="return prepareCatSubmit()">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(kw_csrf_token()); ?>">
        <input type="hidden" name="action" value="add">
        <div class="flex gap-2 items-center flex-wrap">
            <span class="text-xs font-semibold text-gray-500 mr-1"><i class="fas fa-plus-circle text-teal-500 mr-1"></i>Register Category</span>
            <input type="text" name="name_en" id="catEnInput" placeholder="English name"
                   class="flex-1 min-w-0 border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            <input type="text" name="name_ko" id="catKoInput" placeholder="Korean name (optional)"
                   class="flex-1 min-w-0 border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
            <button type="button" onclick="romanizeCatName()" title="한글을 영문읽기(로마자)로 변환"
                    class="px-3 py-1.5 bg-blue-500 text-white text-xs font-semibold rounded-md hover:bg-blue-600 whitespace-nowrap transition-colors">한글→영문읽기</button>
            <button type="submit" class="px-4 py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700 whitespace-nowrap">Register</button>
        </div>
    </form>
</div>

<!-- 검색 -->
<form method="get" class="bg-white rounded-lg border border-gray-200 px-3 py-2 shrink-0">
    <div class="flex flex-wrap items-center gap-2">
        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Search by name (EN/KO)"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 w-56">
        <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700"><i class="fas fa-search mr-1"></i>Search</button>
        <a href="<?php echo LC_BASE; ?>/category_manage.php" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-sm rounded-md hover:bg-gray-200">Reset</a>
    </div>
</form>

<?php if (isset($db_error)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm shrink-0"><?php echo htmlspecialchars($db_error); ?></div>
<?php endif; ?>

<div class="bg-white rounded-lg border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0">
    <div class="px-4 py-3 border-b border-gray-100 text-sm text-gray-500 shrink-0">Total <?php echo number_format($total); ?> categories</div>
    <div class="overflow-auto flex-1 min-h-0">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">English Name</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Korean Name</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Products</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Manage</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($cats)): ?>
            <tr><td colspan="4" class="px-4 py-10 text-center text-gray-400">
                <?php if ($search !== ''): ?>
                <i class="fas fa-search text-3xl mb-3 block text-gray-300"></i>
                <span class="font-medium text-gray-600">"<?php echo htmlspecialchars($search); ?>"</span> No search results found.
                <?php else: ?>
                <i class="fas fa-sitemap text-3xl mb-2 block text-gray-300"></i>
                No categories registered.
                <?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <?php foreach ($cats as $c): ?>
            <tr class="hover:bg-gray-50 <?php echo $c['product_count'] > 0 ? 'cursor-pointer' : ''; ?>"
                <?php if ($c['product_count'] > 0): ?>onclick="openProductsModal(<?php echo $c['id']; ?>, <?php echo htmlspecialchars(json_encode($c['name_en']), ENT_QUOTES); ?>, <?php echo (int)$c['product_count']; ?>)"<?php endif; ?>>
                <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($c['name_en']); ?></td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo $c['name_ko'] ? htmlspecialchars($c['name_ko']) : '-'; ?></td>
                <td class="px-4 py-3 text-center text-gray-600 text-xs"><?php echo (int)$c['product_count']; ?></td>
                <td class="px-4 py-3 text-center">
                    <div class="flex items-center justify-center gap-3">
                        <button type="button"
                                onclick="event.stopPropagation(); openEditModal(<?php echo $c['id']; ?>, <?php echo htmlspecialchars(json_encode($c['name_en']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($c['name_ko'] ?? ''), ENT_QUOTES); ?>)"
                                class="text-teal-600 hover:text-teal-800 text-xs" title="Edit"><i class="fas fa-edit"></i></button>
                        <?php if ($c['product_count'] == 0): ?>
                        <form method="post" class="inline" onclick="event.stopPropagation()">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(kw_csrf_token()); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="cat_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" onclick="return confirm('Are you sure you want to delete?')" class="text-red-400 hover:text-red-600 text-xs" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-500" title="Click row to view products in use">In Use (<?php echo (int)$c['product_count']; ?>)</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <?php
        $window = 10;
        $block_start = (int)(floor(($page - 1) / $window) * $window) + 1;
        $block_end   = min($total_pages, $block_start + $window - 1);
        $qs = ['search' => $search];
    ?>
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-center gap-1 shrink-0">
        <?php if ($block_start > 1): ?>
        <a href="?page=<?php echo $block_start - $window; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="Previous 10 pages">
            <i class="fas fa-angle-double-left text-xs"></i>
        </a>
        <?php endif; ?>
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
            <i class="fas fa-chevron-left text-xs"></i>
        </a>
        <?php endif; ?>
        <?php for ($i = $block_start; $i <= $block_end; $i++): ?>
        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($qs); ?>"
           class="flex items-center justify-center rounded font-medium transition-colors
                  <?php echo $i === $page
                      ? 'w-9 h-9 bg-teal-600 text-white text-base shadow-md ring-2 ring-teal-300'
                      : 'w-8 h-8 text-sm text-gray-500 hover:bg-gray-100'; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
        <a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
            <i class="fas fa-chevron-right text-xs"></i>
        </a>
        <?php endif; ?>
        <?php if ($block_end < $total_pages): ?>
        <a href="?page=<?php echo $block_end + 1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="Next 10 pages">
            <i class="fas fa-angle-double-right text-xs"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black bg-opacity-40 px-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-800">Edit Category</h3>
            <button type="button" onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="post" class="p-5 space-y-4" onsubmit="return prepareEditSubmit()">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(kw_csrf_token()); ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="cat_id" id="editCatId">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Name (English) <span class="text-red-400">*</span></label>
                <input type="text" name="name_en" id="editNameEn" required
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Name (Korean)</label>
                <div class="flex gap-2">
                    <input type="text" name="name_ko" id="editNameKo"
                           class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                    <button type="button" onclick="romanizeEditName()" title="한글을 영문읽기(로마자)로 변환"
                            class="px-3 py-2 bg-blue-500 text-white text-xs font-semibold rounded-md hover:bg-blue-600 whitespace-nowrap">한글→영문읽기</button>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Products In Use Modal -->
<div id="productsModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black bg-opacity-40 px-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg flex flex-col" style="max-height:80vh;">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between shrink-0">
            <h3 class="text-sm font-semibold text-gray-800">Products using <span id="productsCatName" class="text-teal-600"></span></h3>
            <button type="button" onclick="closeProductsModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div id="productsModalBody" class="p-5 overflow-auto flex-1 min-h-0">
            <div class="text-center text-gray-400 py-8"><i class="fas fa-spinner fa-spin mr-2"></i>Loading...</div>
        </div>
        <div class="px-5 py-3 border-t border-gray-100 flex justify-end shrink-0">
            <button type="button" onclick="closeProductsModal()" class="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">Close</button>
        </div>
    </div>
</div>

<script>
(function() {
    // ─── 한글 → 영문읽기(국어의 로마자 표기법) ───────────────────────
    function romanizeKorean(str) {
        var CHO  = ['g','kk','n','d','tt','r','m','b','pp','s','ss','','j','jj','ch','k','t','p','h'];
        var JUNG = ['a','ae','ya','yae','eo','e','yeo','ye','o','wa','wae','oe','yo','u','wo','we','wi','yu','eu','ui','i'];
        var JONG = ['','k','k','k','n','n','n','t','l','k','m','p','l','l','p','l','m','p','p','t','t','ng','t','t','k','t','p','t'];
        var out = '';
        for (var i = 0; i < str.length; i++) {
            var code = str.charCodeAt(i);
            if (code >= 0xAC00 && code <= 0xD7A3) {
                var s = code - 0xAC00;
                out += CHO[Math.floor(s / 588)] + JUNG[Math.floor((s % 588) / 28)] + JONG[s % 28];
            } else {
                out += str[i];
            }
        }
        return out;
    }

    window.romanizeCatName = function() {
        var koInput = document.getElementById('catKoInput');
        var enInput = document.getElementById('catEnInput');
        var ko = koInput.value.trim();
        if (!ko) { koInput.focus(); return; }
        enInput.value = romanizeKorean(ko).toUpperCase();
        enInput.focus();
    };

    window.romanizeEditName = function() {
        var koInput = document.getElementById('editNameKo');
        var enInput = document.getElementById('editNameEn');
        var ko = koInput.value.trim();
        if (!ko) { koInput.focus(); return; }
        enInput.value = romanizeKorean(ko).toUpperCase();
        enInput.focus();
    };

    window.prepareCatSubmit = function() {
        var en = document.getElementById('catEnInput').value.trim();
        if (!en) { document.getElementById('catEnInput').focus(); return false; }
        return true;
    };

    // ─── Edit Modal ───────────────────────────────────────────────
    window.openEditModal = function(id, en, ko) {
        document.getElementById('editCatId').value = id;
        document.getElementById('editNameEn').value = en || '';
        document.getElementById('editNameKo').value = ko || '';
        var m = document.getElementById('editModal');
        m.classList.remove('hidden'); m.classList.add('flex');
        document.getElementById('editNameEn').focus();
    };
    window.closeEditModal = function() {
        var m = document.getElementById('editModal');
        m.classList.add('hidden'); m.classList.remove('flex');
    };

    // ─── Products In Use Modal ────────────────────────────────────
    var LC_BASE = <?php echo json_encode(LC_BASE); ?>;
    // 상품 수정 후 현재 페이지(검색/페이지 상태 포함)로 복귀시키기 위한 back 파라미터
    var BACK_PARAM = encodeURIComponent('category_manage.php' + window.location.search);
    window.openProductsModal = function(catId, catName, count) {
        document.getElementById('productsCatName').textContent = catName + ' (' + count + ')';
        var body = document.getElementById('productsModalBody');
        body.innerHTML = '<div class="text-center text-gray-400 py-8"><i class="fas fa-spinner fa-spin mr-2"></i>Loading...</div>';
        var m = document.getElementById('productsModal');
        m.classList.remove('hidden'); m.classList.add('flex');

        fetch(LC_BASE + '/ajax_inuse_products.php?type=category&id=' + encodeURIComponent(catId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) { body.innerHTML = '<div class="text-center text-red-500 py-8">Failed to load products.</div>'; return; }
                if (!data.products.length) { body.innerHTML = '<div class="text-center text-gray-400 py-8">No products found.</div>'; return; }
                var html = '<table class="w-full text-sm"><thead class="bg-gray-50"><tr>'
                    + '<th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Product</th>'
                    + '<th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Barcode</th>'
                    + '<th class="px-3 py-2 text-center text-xs text-gray-500 font-medium">Status</th>'
                    + '</tr></thead><tbody class="divide-y divide-gray-100">';
                data.products.forEach(function(p) {
                    var name = escapeHtml(p.name_en || '') + (p.name_ko ? ' <span class="text-gray-400 text-xs">(' + escapeHtml(p.name_ko) + ')</span>' : '');
                    var badge = (parseInt(p.is_active, 10) === 1)
                        ? '<span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">Active</span>'
                        : '<span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">Inactive</span>';
                    html += '<tr class="hover:bg-gray-50">'
                        + '<td class="px-3 py-2"><a href="' + LC_BASE + '/product_edit.php?id=' + p.id + '&back=' + BACK_PARAM + '" class="text-teal-600 hover:text-teal-800">' + name + '</a></td>'
                        + '<td class="px-3 py-2 text-gray-500 text-xs">' + escapeHtml(p.barcode_unit || '-') + '</td>'
                        + '<td class="px-3 py-2 text-center">' + badge + '</td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                body.innerHTML = html;
            })
            .catch(function() { body.innerHTML = '<div class="text-center text-red-500 py-8">Failed to load products.</div>'; });
    };
    window.closeProductsModal = function() {
        var m = document.getElementById('productsModal');
        m.classList.add('hidden'); m.classList.remove('flex');
    };
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    document.getElementById('productsModal').addEventListener('click', function(e) {
        if (e.target === this) closeProductsModal();
    });
    window.prepareEditSubmit = function() {
        return document.getElementById('editNameEn').value.trim() !== '';
    };
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeEditModal(); closeProductsModal(); }
    });
})();
</script>
</div><!-- /.flex.flex-col.h-full -->
<?php require_once __DIR__ . '/partials/footer.php'; ?>
