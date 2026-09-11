<?php
/**
 * Foodpang(배달 판매채널) 상품 큐레이션 관리 화면.
 * mall/admin/products.php의 상품 검색/추가/편집/정렬 UX를 모델로 하되,
 * 완전히 별도 테이블(foodpang_products)만 사용해 Mall 큐레이션(mall_products)과는
 * 서로 영향을 주지 않는다.
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
$page_title = t('foodpang_admin.title') . ' - ' . t('company.name');
ensure_logged_in();
require_permission('foodpang_management', '/index.php');
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../mall/lib/csrf.php';
require_once __DIR__ . '/../lib/store_context.php';

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$conn = get_db_connection();
$foodpang_csrf_token = mall_csrf_token();

foreach (['foodpang_categories', 'foodpang_products'] as $required_table) {
    $safe_table = $conn->real_escape_string($required_table);
    $table_check = $conn->query("SHOW TABLES LIKE '{$safe_table}'");
    if (!$table_check || $table_check->num_rows === 0) {
        $conn->close();
        header('Location: /sql/run_create_foodpang_products_migration.php');
        exit;
    }
}

// 큐레이션 기준 점포: foodpang/lib/store_context.php의 단일 판정 로직을 사용해
// ajax_products.php(저장 처리)와 항상 같은 store_id를 쓰도록 보장한다.
$foodpang_store_id = foodpang_resolve_store_id($conn);

$selected_category_id = isset($_GET['cat_id']) && $_GET['cat_id'] !== '' ? (int)$_GET['cat_id'] : null;

// 대분류 목록 + Foodpang에 큐레이션된 상품 수 (대분류 + 하위 소분류 포함)
$cat_stmt = $conn->prepare(
    "SELECT c.id, c.name, c.name_en,
            COUNT(DISTINCT fp.id) AS product_count
     FROM foodpang_categories c
     LEFT JOIN foodpang_products fp ON fp.category_id = c.id AND fp.store_id = ?
     WHERE c.parent_id IS NULL AND c.store_id = ?
     GROUP BY c.id, c.name, c.name_en
     ORDER BY c.sort_order, c.name"
);
$cat_stmt->bind_param('ii', $foodpang_store_id, $foodpang_store_id);
$cat_stmt->execute();
$categories = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$cat_stmt->close();

// 선택한 대분류 + 하위 소분류 id (없으면 필터 없음 = 전체)
$category_filter_ids = $selected_category_id ? [$selected_category_id] : [];

// 큐레이션된 상품 목록
$where = ['fp.store_id = ?'];
$params = [$foodpang_store_id, $foodpang_store_id];
$types = 'ii';
if (!empty($category_filter_ids)) {
    $placeholders = implode(',', array_fill(0, count($category_filter_ids), '?'));
    $where[] = "fp.category_id IN ({$placeholders})";
    foreach ($category_filter_ids as $cid) {
        $params[] = (int)$cid;
        $types .= 'i';
    }
}

$curated_stmt = $conn->prepare(
    "SELECT fp.id, fp.product_id, fp.display_name, fp.display_name_en, fp.selling_price_override,
            fp.is_active, fp.is_sold_out, fp.display_order, fp.category_id,
            p.name_ko, p.name_en AS product_name_en, p.sku,
            inv.selling_price AS original_selling_price, inv.quantity AS real_stock_quantity
     FROM foodpang_products fp
     INNER JOIN products p ON p.id = fp.product_id
     LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.store_id = ?
     WHERE " . implode(' AND ', $where) . "
     ORDER BY fp.display_order, fp.id DESC"
);
$curated_stmt->bind_param($types, ...$params);
$curated_stmt->execute();
$curated = $curated_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$curated_stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
<?php $current_page = 'products.php'; include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">

<div>
    <div class="mb-4 flex items-center justify-between flex-wrap gap-2">
        <div>
            <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-box mr-2"></i><?php echo t('foodpang_admin.title'); ?></h1>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="mb-4 rounded-md p-3 text-sm <?php echo $flash['type'] === 'error' ? 'bg-red-50 text-red-800 border border-red-200' : 'bg-green-50 text-green-800 border border-green-200'; ?>">
        <?php echo htmlspecialchars($flash['message']); ?>
    </div>
    <?php endif; ?>

    <div id="foodpang-flash" class="mb-4 hidden rounded-md p-3 text-sm"></div>

    <div class="foodpang-products-layout">
        <!-- 좌측 카테고리 사이드바 -->
        <aside class="w-52 flex-shrink-0 bg-white rounded-lg border border-gray-200 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-bold text-gray-700"><?php echo t('mall_admin.products.category'); ?></h2>
                <button type="button" id="foodpang-category-manage-inline" class="text-xs text-blue-600 hover:text-blue-800 font-semibold"><i class="fas fa-pen mr-1"></i><?php echo t('common.edit'); ?></button>
            </div>
            <div>
                <a href="?"
                   class="flex items-center justify-between px-2 py-1.5 rounded text-xs font-medium <?php echo !$selected_category_id ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                    <span><?php echo t('foodpang_admin.all_categories'); ?></span>
                </a>
                <?php foreach ($categories as $cat): ?>
                <?php
                    // 영어 화면에서는 name_en이 있으면 그것을, 없으면 한국어 name으로 폴백해서 보여준다.
                    // (카테고리 관리 모달의 두 입력창은 언어와 무관하게 항상 둘 다 채워서 보여준다 - 편집용이므로 유지)
                    $cat_nav_label = (get_language() === 'en' && !empty($cat['name_en'])) ? $cat['name_en'] : $cat['name'];
                ?>
                <a href="?cat_id=<?php echo (int)$cat['id']; ?>" data-category-id="<?php echo (int)$cat['id']; ?>" data-product-count="<?php echo (int)$cat['product_count']; ?>"
                   class="flex items-center justify-between px-2 py-1.5 rounded text-xs font-medium <?php echo $selected_category_id === (int)$cat['id'] ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                    <span><?php echo htmlspecialchars($cat_nav_label); ?></span>
                    <span class="text-xs text-gray-400"><?php echo (int)$cat['product_count']; ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <!-- 우측 메인 -->
        <div class="foodpang-products-content">
            <!-- 상품 검색 → Foodpang 추가 -->
            <section class="foodpang-search-panel bg-white rounded-lg border border-gray-200 p-4">
                <h2 class="text-sm font-semibold text-gray-700 mb-2"><?php echo t('foodpang_admin.search_title'); ?></h2>
                <div class="flex gap-2">
                    <input type="text" id="foodpang-search-input" placeholder="<?php echo htmlspecialchars(t('foodpang_admin.search_placeholder')); ?>"
                           class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-pink-500 focus:border-pink-500">
                    <button type="button" id="foodpang-search-btn" class="px-4 py-2 bg-pink-600 hover:bg-pink-700 text-white text-sm font-medium rounded-md">
                        <i class="fas fa-search mr-1"></i><?php echo t('common.search'); ?>
                    </button>
                </div>
                <?php if (!$selected_category_id): ?>
                <p class="mt-2 text-xs text-amber-600"><i class="fas fa-circle-info mr-1"></i><?php echo t('foodpang_admin.select_category_first'); ?></p>
                <?php endif; ?>
                <div id="foodpang-search-results" class="mt-3 divide-y divide-gray-100 hidden border border-gray-100 rounded-md"></div>
            </section>

            <!-- 큐레이션된 상품 목록 -->
            <section class="foodpang-curated-panel bg-white rounded-lg border border-gray-200 p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-700"><?php echo t('foodpang_admin.curated_title'); ?></h2>
                    <span class="text-xs text-gray-400"><?php echo count($curated); ?><?php echo t('foodpang_admin.count_suffix'); ?></span>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-100 text-gray-600">
                        <tr>
                            <th class="px-2 py-2 w-6"></th>
                            <th class="px-3 py-2 text-left"><?php echo t('foodpang_admin.col_product'); ?></th>
                            <th class="px-3 py-2 text-left"><?php echo t('foodpang_admin.col_display_name'); ?></th>
                            <th class="px-3 py-2 text-left"><?php echo t('foodpang_admin.col_price'); ?></th>
                            <th class="px-3 py-2 text-center"><?php echo t('foodpang_admin.col_active'); ?></th>
                            <th class="px-3 py-2 text-center"><?php echo t('foodpang_admin.col_sold_out'); ?></th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody id="foodpang-curated-body" class="divide-y divide-gray-100">
                        <?php if (empty($curated)): ?>
                        <tr><td colspan="7" class="px-3 py-8 text-center text-gray-400"><?php echo t('foodpang_admin.empty_curated'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($curated as $c): ?>
                        <tr class="foodpang-row" data-foodpang-id="<?php echo (int)$c['id']; ?>" data-category-id="<?php echo (int)$c['category_id']; ?>">
                            <td class="foodpang-drag-handle px-2 py-2 text-gray-300 cursor-move text-center" draggable="true" title="카테고리로 끌어서 이동하거나 위아래로 순서를 변경하세요"><i class="fas fa-grip-vertical"></i></td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-gray-800"><?php echo htmlspecialchars($c['name_ko']); ?></div>
                                <div class="text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($c['sku'] ?? '-'); ?></div>
                            </td>
                            <td class="px-3 py-2">
                                <input type="text" class="foodpang-input w-32 border border-gray-200 rounded px-2 py-1 text-xs mb-1" data-field="display_name" value="<?php echo htmlspecialchars($c['display_name'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars($c['name_ko']); ?>">
                                <input type="text" class="foodpang-input w-32 border border-gray-200 rounded px-2 py-1 text-xs" data-field="display_name_en" value="<?php echo htmlspecialchars($c['display_name_en'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars($c['product_name_en'] ?? ''); ?>">
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" step="0.01" min="0" class="foodpang-input w-24 border border-gray-200 rounded px-2 py-1 text-xs" data-field="selling_price" value="<?php echo $c['selling_price_override'] !== null ? htmlspecialchars($c['selling_price_override']) : ''; ?>" placeholder="<?php echo $c['original_selling_price'] !== null ? number_format((float)$c['original_selling_price'], 2) : '-'; ?>">
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input type="checkbox" class="foodpang-input" data-field="is_active" <?php echo $c['is_active'] ? 'checked' : ''; ?>>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input type="checkbox" class="foodpang-input" data-field="is_sold_out" <?php echo $c['is_sold_out'] ? 'checked' : ''; ?>>
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <button type="button" class="foodpang-save-btn text-xs text-pink-600 hover:text-pink-800 font-medium mr-2"><?php echo t('common.save'); ?></button>
                                <button type="button" class="foodpang-remove-btn text-xs text-red-500 hover:text-red-700"><?php echo t('common.delete'); ?></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </section>
        </div>
    </div>
</div>

<div id="foodpang-category-modal" class="fixed inset-0 hidden items-center justify-center z-50 p-4" style="background:rgba(17,24,39,.65)">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[85vh] flex flex-col">
        <div class="px-5 py-4 border-b flex items-center justify-between flex-shrink-0">
            <h2 class="font-bold text-gray-800"><i class="fas fa-sitemap mr-1.5"></i><?php echo t('foodpang_admin.manage_categories'); ?></h2>
            <button type="button" id="foodpang-category-close" class="text-gray-400 hover:text-gray-700"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-4 overflow-y-auto flex-1">
            <ul id="foodpang-category-list" class="space-y-1">
                <?php foreach ($categories as $cat): ?>
                <li class="foodpang-category-item flex items-center gap-1 border border-gray-100 rounded-md px-2 py-1.5" data-category-id="<?php echo (int)$cat['id']; ?>" draggable="true">
                    <i class="fas fa-grip-vertical text-gray-300 cursor-grab" title="<?php echo htmlspecialchars(t('mall_admin.products.drag_to_reorder')); ?>"></i>
                    <input type="text" class="edit-category-name border border-gray-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" data-category-id="<?php echo (int)$cat['id']; ?>" value="<?php echo htmlspecialchars($cat['name']); ?>">
                    <input type="text" class="edit-category-name-en border border-gray-300 rounded px-1.5 py-1 text-xs w-16 flex-shrink-0" value="<?php echo htmlspecialchars($cat['name_en'] ?? ''); ?>" placeholder="EN">
                    <span class="text-gray-400 text-[10px] flex-shrink-0">(<?php echo (int)$cat['product_count']; ?>)</span>
                    <button type="button" class="delete-category-btn text-gray-300 hover:text-red-500 px-1" data-category-id="<?php echo (int)$cat['id']; ?>" data-category-name="<?php echo htmlspecialchars($cat['name']); ?>" title="<?php echo htmlspecialchars(t('common.delete')); ?>"><i class="fas fa-xmark"></i></button>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="p-4 border-t border-gray-100 space-y-2 flex-shrink-0">
            <p class="text-[11px] text-gray-400"><?php echo t('mall_admin.products.new_badge_hint'); ?></p>
            <button type="button" id="foodpang-apply-category-edits-btn" class="w-full px-3 py-1.5 text-xs font-semibold bg-pink-600 text-white rounded-md">
                <i class="fas fa-check mr-1"></i><?php echo t('mall_admin.products.apply_all_changes'); ?>
            </button>
            <form id="foodpang-add-category-form" class="flex gap-1">
                <input type="text" name="name" placeholder="<?php echo htmlspecialchars(t('mall_admin.products.new_category_name_placeholder')); ?>" required class="border border-gray-300 rounded-md px-2 py-1 text-xs flex-1 min-w-0">
                <input type="text" name="name_en" placeholder="EN" class="border border-gray-300 rounded-md px-2 py-1 text-xs w-20">
                <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md flex-shrink-0"><?php echo t('common.add'); ?></button>
            </form>
        </div>
    </div>
</div>

<style>
.foodpang-row.drag-over { background: #fdf2f8; }
.foodpang-row.is-dragging { opacity: .45; }
.foodpang-category-drop-target { outline: 2px dashed #db2777; outline-offset: 2px; background:#fdf2f8 !important; color:#9d174d !important; }
.foodpang-category-item.drag-over { border-top: 2px solid #db2777; }
.foodpang-category-item .edit-category-name, .foodpang-category-item .pending-name { -webkit-user-drag: none; }
.foodpang-products-layout { display:grid; grid-template-columns:13rem minmax(0,1fr) 24rem; gap:1rem; align-items:start; }
.foodpang-products-content { display:contents; }
.foodpang-curated-panel { grid-column:2; grid-row:1; min-width:0; overflow-x:auto; }
.foodpang-search-panel { grid-column:3; grid-row:1; }
@media (max-width:1100px) {
    .foodpang-products-layout { grid-template-columns:13rem minmax(0,1fr); }
    .foodpang-products-content { display:block; min-width:0; }
    .foodpang-search-panel { margin-bottom:1rem; }
}
@media (max-width:767px) {
    .foodpang-products-layout { display:flex; flex-direction:column; }
    .foodpang-products-layout aside { width:100%; }
    .foodpang-products-content { width:100%; }
}
</style>
<script>
(function () {
    const AJAX_URL = 'ajax_products.php';
    const flashBox = document.getElementById('foodpang-flash');
    const selectedCategoryId = <?php echo $selected_category_id ? (int)$selected_category_id : 'null'; ?>;
    const storeId = <?php echo (int)$foodpang_store_id; ?>;
    const csrfToken = <?php echo json_encode($foodpang_csrf_token); ?>;

    function showFlash(message, type) {
        flashBox.textContent = message;
        flashBox.className = 'mb-4 rounded-md p-3 text-sm ' + (type === 'error' ? 'bg-red-50 text-red-800 border border-red-200' : 'bg-green-50 text-green-800 border border-green-200');
        flashBox.classList.remove('hidden');
        setTimeout(() => flashBox.classList.add('hidden'), 3000);
    }

    function postForm(action, data) {
        const params = new URLSearchParams(data);
        params.set('action', action);
        params.set('csrf_token', csrfToken);
        return fetch(AJAX_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(r => r.json());
    }

    // ---- 카테고리 관리 (mall/admin/products.php의 카테고리 관리 모달과 동일한 동작을 재현) ----
    // 이름 수정/신규 추가는 즉시 저장하지 않고 모달 안에서만 쌓아두다가 "한번에 적용" 버튼으로 일괄 반영한다.
    // 삭제/순서변경(드래그앤드롭)만 클릭/드롭 즉시 서버에 반영된다.
    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    const categoryModal = document.getElementById('foodpang-category-modal');
    const categoryCloseBtn = document.getElementById('foodpang-category-close');
    document.getElementById('foodpang-category-manage-inline').addEventListener('click', function () {
        categoryModal.classList.remove('hidden'); categoryModal.classList.add('flex');
    });
    categoryCloseBtn.addEventListener('click', function () {
        categoryModal.classList.add('hidden'); categoryModal.classList.remove('flex');
    });
    categoryModal.addEventListener('click', function (e) {
        if (e.target === categoryModal) { categoryCloseBtn.click(); }
    });

    // "추가"를 누르면 즉시 서버로 보내지 않고 목록에 NEW 행으로만 쌓아둔다(실제 저장은 "한번에 적용" 클릭 시).
    function addPendingCategoryRow(name, nameEn) {
        const li = document.createElement('li');
        li.className = 'pending-category-item foodpang-category-item flex items-center gap-1 border border-dashed border-pink-300 rounded-md px-2 py-1.5';
        li.innerHTML =
            '<span class="text-pink-500 text-[10px] font-bold flex-shrink-0" title="<?php echo addslashes(t('mall_admin.products.not_saved_yet')); ?>">NEW</span>' +
            '<input type="text" class="pending-name border border-pink-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" value="' + escHtml(name) + '">' +
            '<input type="text" class="pending-name-en border border-pink-300 rounded px-1.5 py-1 text-xs w-16 flex-shrink-0" value="' + escHtml(nameEn) + '" placeholder="EN">' +
            '<button type="button" class="remove-pending-btn text-gray-300 hover:text-red-500 px-1" title="<?php echo addslashes(t('common.cancel')); ?>"><i class="fas fa-xmark"></i></button>';
        li.querySelector('.remove-pending-btn').addEventListener('click', function () { li.remove(); });
        document.getElementById('foodpang-category-list').appendChild(li);
    }

    const addCategoryForm = document.getElementById('foodpang-add-category-form');
    addCategoryForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = addCategoryForm.name.value.trim();
        if (!name) return;
        addPendingCategoryRow(name, addCategoryForm.name_en.value.trim());
        addCategoryForm.reset();
        addCategoryForm.name.focus();
    });

    // 카테고리 이름 수정 + "추가"로 쌓아둔 신규(NEW) 항목을 한번에 서버로 보낸다.
    const applyCategoryEditsBtn = document.getElementById('foodpang-apply-category-edits-btn');
    applyCategoryEditsBtn.addEventListener('click', function () {
        const params = new URLSearchParams();
        params.set('action', 'category_bulk_edit');

        document.querySelectorAll('#foodpang-category-list .edit-category-name').forEach(function (nameInput) {
            const nameEnInput = nameInput.parentElement.querySelector('.edit-category-name-en');
            params.append('category_id[]', nameInput.dataset.categoryId);
            params.append('name[]', nameInput.value.trim());
            params.append('name_en[]', nameEnInput ? nameEnInput.value.trim() : '');
        });

        let hasEmptyPending = false;
        document.querySelectorAll('#foodpang-category-list .pending-category-item').forEach(function (li) {
            const name = li.querySelector('.pending-name').value.trim();
            const nameEn = li.querySelector('.pending-name-en').value.trim();
            if (!name) { hasEmptyPending = true; return; }
            params.append('new_name[]', name);
            params.append('new_name_en[]', nameEn);
        });
        if (hasEmptyPending) {
            showFlash('<?php echo addslashes(t('mall_admin.products.empty_category_name_error')); ?>', 'error');
            return;
        }

        params.set('csrf_token', csrfToken);
        applyCategoryEditsBtn.disabled = true;
        fetch(AJAX_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    location.reload();
                } else {
                    showFlash((res.error && res.error.message) || '<?php echo addslashes(t('mall_admin.products.apply_changes_failed')); ?>', 'error');
                    applyCategoryEditsBtn.disabled = false;
                }
            });
    });

    // 카테고리 삭제 후, 방금 지운 카테고리가 현재 선택된 필터였다면 필터를 해제하고 새로고침한다.
    function goToProductsAfterCategoryDelete(deletedId) {
        const url = new URL(window.location.href);
        if (url.searchParams.get('cat_id') === String(deletedId)) {
            url.searchParams.delete('cat_id');
        }
        window.location.href = url.pathname + url.search;
    }

    document.querySelectorAll('.delete-category-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm(btn.dataset.categoryName + '<?php echo addslashes(t('mall_admin.products.delete_category_confirm')); ?>')) return;
            const params = new URLSearchParams();
            params.set('action', 'category_delete');
            params.set('category_id', btn.dataset.categoryId);
            params.set('csrf_token', csrfToken);
            fetch(AJAX_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        const details = res.error && res.error.details;
                        if (details && details.products && details.products.length) {
                            const list = '<ul style="margin:0.3rem 0 0;padding-left:1.1rem;">' +
                                details.products.map(p => '<li>' + escHtml(p.name) + (p.sku ? ' (' + escHtml(p.sku) + ')' : '') + '</li>').join('') +
                                (details.truncated ? '<li>' + '<?php echo addslashes(t('mall_admin.products.and_more_count')); ?>'.replace('{count}', details.product_count - details.products.length) + '</li>' : '') +
                                '</ul>';
                            showFlash((res.error.message || '<?php echo addslashes(t('mall_admin.products.delete_category_failed')); ?>') + list, 'error');
                        } else {
                            showFlash((res.error && res.error.message) || '<?php echo addslashes(t('mall_admin.products.delete_category_failed')); ?>', 'error');
                        }
                        return;
                    }
                    goToProductsAfterCategoryDelete(btn.dataset.categoryId);
                });
        });
    });

    // 네이티브 HTML5 Drag & Drop으로 카테고리 순서 변경 — mall/admin과 동일하게 드롭 즉시 저장된다.
    (function () {
        const listEl = document.getElementById('foodpang-category-list');
        let dragSrc = null;
        Array.from(listEl.children).forEach(function (item) {
            if (!item.classList.contains('foodpang-category-item') || item.getAttribute('draggable') !== 'true') return;
            item.addEventListener('dragstart', function () { dragSrc = item; });
            item.addEventListener('dragover', function (e) { e.preventDefault(); item.classList.add('drag-over'); });
            item.addEventListener('dragleave', function () { item.classList.remove('drag-over'); });
            item.addEventListener('drop', function (e) {
                e.preventDefault();
                item.classList.remove('drag-over');
                if (dragSrc && dragSrc !== item && dragSrc.parentElement === listEl) {
                    const items = Array.from(listEl.children);
                    const srcIndex = items.indexOf(dragSrc);
                    const dstIndex = items.indexOf(item);
                    if (srcIndex < dstIndex) { item.after(dragSrc); } else { item.before(dragSrc); }
                    const ids = Array.from(listEl.children).filter(el => el.classList.contains('foodpang-category-item')).map(el => el.dataset.categoryId);
                    postForm('category_reorder', { order: ids.join(',') }).then(function (res) {
                        if (!res.success) {
                            showFlash((res.error && res.error.message) || '<?php echo addslashes(t('foodpang_admin.order_save_failed')); ?>', 'error');
                        }
                    });
                }
            });
        });
    })();

    // ---- 검색 ----
    const searchInput = document.getElementById('foodpang-search-input');
    const searchBtn = document.getElementById('foodpang-search-btn');
    const resultsBox = document.getElementById('foodpang-search-results');

    function runSearch() {
        const q = searchInput.value.trim();
        if (!q) { resultsBox.classList.add('hidden'); resultsBox.innerHTML = ''; return; }
        fetch(AJAX_URL + '?action=search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(res => {
                resultsBox.innerHTML = '';
                if (!res.success || !res.data.length) {
                    resultsBox.innerHTML = '<div class="p-3 text-xs text-gray-400"><?php echo addslashes(t('foodpang_admin.no_search_results')); ?></div>';
                    resultsBox.classList.remove('hidden');
                    return;
                }
                res.data.forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'flex items-center justify-between px-3 py-2 text-sm';
                    const label = document.createElement('span');
                    label.textContent = item.name_ko + (item.sku ? ' (' + item.sku + ')' : '');
                    row.appendChild(label);
                    if (item.already_added) {
                        const badge = document.createElement('span');
                        badge.className = 'text-xs text-gray-400';
                        badge.textContent = '<?php echo addslashes(t('foodpang_admin.already_added')); ?>';
                        row.appendChild(badge);
                    } else {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'text-xs px-2 py-1 bg-pink-600 hover:bg-pink-700 text-white rounded';
                        btn.textContent = '<?php echo addslashes(t('foodpang_admin.add_button')); ?>';
                        btn.addEventListener('click', function () {
                            if (!selectedCategoryId) {
                                showFlash('<?php echo addslashes(t('foodpang_admin.select_category_first')); ?>', 'error');
                                return;
                            }
                            postForm('add', { product_id: item.product_id, store_id: storeId, category_id: selectedCategoryId })
                                .then(res2 => {
                                    if (res2.success) {
                                        location.reload();
                                    } else {
                                        showFlash((res2.error && res2.error.message) || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error');
                                    }
                                });
                        });
                        row.appendChild(btn);
                    }
                    resultsBox.appendChild(row);
                });
                resultsBox.classList.remove('hidden');
            });
    }
    searchBtn.addEventListener('click', runSearch);
    searchInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } });

    // ---- 행별 저장/삭제 ----
    document.querySelectorAll('.foodpang-row').forEach(function (row) {
        const id = row.dataset.foodpangId;

        row.querySelector('.foodpang-save-btn').addEventListener('click', function () {
            const data = { foodpang_product_id: id };
            row.querySelectorAll('.foodpang-input').forEach(function (input) {
                const field = input.dataset.field;
                if (input.type === 'checkbox') {
                    data[field] = input.checked ? '1' : '0';
                } else {
                    data[field] = input.value;
                }
            });
            postForm('update', data).then(res => {
                if (res.success) {
                    showFlash('<?php echo addslashes(t('common.save_success')); ?>', 'success');
                } else {
                    showFlash((res.error && res.error.message) || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error');
                }
            });
        });

        row.querySelector('.foodpang-remove-btn').addEventListener('click', function () {
            if (!confirm('<?php echo addslashes(t('foodpang_admin.confirm_remove')); ?>')) return;
            postForm('delete', { foodpang_product_id: id }).then(res => {
                if (res.success) {
                    row.remove();
                } else {
                    showFlash((res.error && res.error.message) || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error');
                }
            });
        });
    });

    // ---- 드래그앤드롭 순서 변경 ----
    let dragSrc = null;
    document.querySelectorAll('.foodpang-row').forEach(function (row) {
        const handle = row.querySelector('.foodpang-drag-handle');
        handle.addEventListener('dragstart', function (e) {
            dragSrc = row;
            row.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', row.dataset.foodpangId);
        });
        handle.addEventListener('dragend', function () {
            row.classList.remove('is-dragging');
            document.querySelectorAll('.foodpang-category-drop-target').forEach(el => el.classList.remove('foodpang-category-drop-target'));
            dragSrc = null;
        });
        row.addEventListener('dragover', function (e) { e.preventDefault(); row.classList.add('drag-over'); });
        row.addEventListener('dragleave', function () { row.classList.remove('drag-over'); });
        row.addEventListener('drop', function (e) {
            e.preventDefault();
            row.classList.remove('drag-over');
            if (dragSrc && dragSrc !== row) {
                const isAfter = dragSrc.compareDocumentPosition(row) & Node.DOCUMENT_POSITION_FOLLOWING;
                if (isAfter) { row.after(dragSrc); } else { row.before(dragSrc); }
                saveOrder();
            }
        });
    });

    // 상품의 그립을 좌측 카테고리에 놓으면 Foodpang 카테고리를 즉시 변경한다.
    document.querySelectorAll('aside a[data-category-id]').forEach(function (categoryLink) {
        categoryLink.addEventListener('dragover', function (e) {
            if (!dragSrc) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            categoryLink.classList.add('foodpang-category-drop-target');
        });
        categoryLink.addEventListener('dragleave', function () {
            categoryLink.classList.remove('foodpang-category-drop-target');
        });
        categoryLink.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            categoryLink.classList.remove('foodpang-category-drop-target');
            if (!dragSrc) return;

            const movedRow = dragSrc;
            const oldCategoryId = Number(movedRow.dataset.categoryId || 0);
            const newCategoryId = Number(categoryLink.dataset.categoryId || 0);
            if (!newCategoryId || oldCategoryId === newCategoryId) return;

            postForm('move_category', {
                foodpang_product_id: movedRow.dataset.foodpangId,
                category_id: newCategoryId
            }).then(function (res) {
                if (!res.success) {
                    showFlash((res.error && res.error.message) || '카테고리 변경에 실패했습니다.', 'error');
                    return;
                }
                movedRow.dataset.categoryId = String(newCategoryId);
                document.querySelectorAll('aside a[data-category-id]').forEach(function (link) {
                    const linkCategoryId = Number(link.dataset.categoryId);
                    let count = Number(link.dataset.productCount || 0);
                    if (linkCategoryId === oldCategoryId) count = Math.max(0, count - 1);
                    if (linkCategoryId === newCategoryId) count++;
                    link.dataset.productCount = String(count);
                    const badge = link.querySelector('span:last-child');
                    if (badge) badge.textContent = String(count);
                });
                if (selectedCategoryId && selectedCategoryId !== newCategoryId) {
                    movedRow.remove();
                }
                showFlash('상품 카테고리를 변경했습니다.', 'success');
            });
        });
    });

    function saveOrder() {
        const ids = Array.from(document.querySelectorAll('#foodpang-curated-body .foodpang-row')).map(r => r.dataset.foodpangId);
        postForm('reorder', { order: ids.join(',') }).then(res => {
            if (!res.success) {
                showFlash((res.error && res.error.message) || '<?php echo addslashes(t('foodpang_admin.order_save_failed')); ?>', 'error');
            }
        });
    }
})();
</script>

</main>
</body>
</html>
