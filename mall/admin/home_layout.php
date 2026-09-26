<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../lib/home_layout.php';

ensure_logged_in();
require_mall_permission('mall_management', '../../admin/index.php');

$current_page = 'home_layout.php';

$last_publish = mall_get_last_publish_info();

$conn = get_db_connection();
$categories = $conn->query('SELECT id, name, name_en, parent_id FROM categories ORDER BY parent_id IS NULL DESC, sort_order, name')->fetch_all(MYSQLI_ASSOC);
$conn->close();

function hl_decode_config($slot) {
    if (!$slot || empty($slot['config'])) {
        return [];
    }
    $decoded = json_decode($slot['config'], true);
    return is_array($decoded) ? $decoded : [];
}

// 디자인 목업 그대로: 배너/오늘의 특가/새로 들어온 한국 상품 딱 3개 고정 슬롯의 "현재 초안" 값을 불러온다.
// 기획전 상품(promo_products)은 홈에는 안 보이지만 같은 파이프라인으로 /mall/promo.php에 노출한다.
$slot_banner = mall_get_home_slot('promo_banner');
$slot_today = mall_get_home_slot('today_deals');
$slot_new = mall_get_home_slot('new_arrivals');
$slot_promo = mall_get_home_slot('promo_products');

$banner_config = hl_decode_config($slot_banner);
$today_config = hl_decode_config($slot_today);
$new_config = hl_decode_config($slot_new);
$promo_config = hl_decode_config($slot_promo);

// 표시용 이름은 캐시하지 않고 그때그때 다시 조회한다(상품명이 바뀌어도 항상 최신으로 보이도록).
$banner_link_product = null;
if (($banner_config['link_type'] ?? '') === 'product' && !empty($banner_config['link_value'])) {
    $rows = mall_get_products_by_ids([(int)$banner_config['link_value']]);
    $banner_link_product = $rows[0] ?? null;
}
$today_products = !empty($today_config['product_ids']) ? mall_get_products_by_ids($today_config['product_ids']) : [];
$new_products = !empty($new_config['product_ids']) ? mall_get_products_by_ids($new_config['product_ids']) : [];
$promo_products = !empty($promo_config['product_ids']) ? mall_get_products_by_ids($promo_config['product_ids']) : [];
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.nav.home_layout'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .product-chip { display:inline-flex; align-items:center; gap:0.3rem; background:#eef2ff; color:#3730a3; font-size:0.72rem; padding:0.2rem 0.5rem; border-radius:999px; margin:0.2rem 0.2rem 0 0; }
        .product-chip button { background:none; border:none; color:#3730a3; cursor:pointer; }
        .publish-banner { display:flex; align-items:center; justify-content:space-between; background:#f8fafc; border:1px solid #e2e8f0; border-radius:0.5rem; padding:0.6rem 1rem; margin-bottom:1rem; font-size:0.78rem; color:#475569; }
        .layout-columns { display:flex; gap:1.25rem; align-items:flex-start; }
        .editor-column { width:400px; flex-shrink:0; }
        .preview-column { flex-grow:1; min-width:0; }
        .preview-panes { display:flex; flex-wrap:wrap; gap:0.75rem; }
        .preview-pane { width:402px; flex-shrink:0; }
        .preview-pane-label { display:flex; align-items:center; gap:0.4rem; font-size:0.75rem; font-weight:700; padding:0.5rem 0.75rem; border-radius:0.4rem 0.4rem 0 0; }
        .preview-pane-label.published { background:#065f46; color:#fff; }
        .preview-pane-label.draft { background:#111827; color:#fff; }
        .preview-pane iframe { width:100%; height:75vh; border:1px solid #e5e7eb; border-top:none; border-radius:0 0 0.4rem 0.4rem; display:block; background:#fff; }
        .preview-refresh-btn { margin-left:auto; background:none; border:none; color:inherit; cursor:pointer; font-size:0.75rem; }
        .hl-panel { background:#fff; border:1px solid #e5e7eb; border-radius:0.6rem; padding:1rem; margin-bottom:1rem; }
        .hl-panel h2 { font-size:0.9rem; font-weight:700; color:#374151; margin:0 0 0.75rem; display:flex; align-items:center; gap:0.4rem; }
        .hl-link-group { display:none; }
        .hl-link-group.active { display:block; }
        @media (max-width: 1400px) {
            .layout-columns { flex-direction:column; }
            .editor-column { width:100%; }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-swatchbook mr-2"></i><?php echo t('mall_admin.nav.home_layout'); ?></h1>
            <div class="flex gap-2">
                <a href="preview_home.php?view=draft" target="_blank" class="px-3 py-1.5 text-xs font-semibold bg-gray-700 text-white rounded-md"><i class="fas fa-up-right-from-square mr-1"></i><?php echo t('mall_admin.home_layout.open_new_window'); ?></a>
                <button id="publish-btn" class="px-3 py-1.5 text-xs font-semibold bg-green-600 text-white rounded-md"><i class="fas fa-upload mr-1"></i><?php echo t('mall_admin.home_layout.publish'); ?></button>
            </div>
        </div>
        <p class="text-xs text-gray-400 mb-4"><?php echo t('mall_admin.home_layout.layout_hint'); ?></p>

        <div class="publish-banner">
            <span>
                <i class="fas fa-circle-info mr-1"></i>
                <?php if ($last_publish): ?>
                    <?php echo t('mall_admin.home_layout.last_published'); ?>: <?php echo htmlspecialchars(substr($last_publish['published_at'] ?? '', 0, 16)); ?>
                    <?php if (!empty($last_publish['published_by_name'])): ?> · <?php echo htmlspecialchars($last_publish['published_by_name']); ?><?php endif; ?>
                <?php else: ?>
                    <?php echo t('mall_admin.home_layout.never_published'); ?>
                <?php endif; ?>
            </span>
            <span><?php echo t('mall_admin.home_layout.draft_notice'); ?></span>
        </div>

        <div id="flash-area"></div>

        <div class="layout-columns">
            <div class="preview-column">
                <div class="preview-panes">
                    <div class="preview-pane">
                        <div class="preview-pane-label published">
                            <i class="fas fa-globe"></i> <?php echo t('mall_admin.home_layout.currently_live'); ?>
                            <button type="button" class="preview-refresh-btn" onclick="document.getElementById('preview-published').src = document.getElementById('preview-published').src;" title="<?php echo htmlspecialchars(t('mall_admin.home_layout.refresh')); ?>"><i class="fas fa-rotate-right"></i></button>
                        </div>
                        <iframe id="preview-published" src="preview_home.php?view=published"></iframe>
                    </div>
                    <div class="preview-pane">
                        <div class="preview-pane-label draft">
                            <i class="fas fa-pen"></i> <?php echo t('mall_admin.home_layout.designing_draft'); ?>
                            <button type="button" class="preview-refresh-btn" onclick="document.getElementById('preview-draft').src = document.getElementById('preview-draft').src;" title="<?php echo htmlspecialchars(t('mall_admin.home_layout.refresh')); ?>"><i class="fas fa-rotate-right"></i></button>
                        </div>
                        <iframe id="preview-draft" src="preview_home.php?view=draft"></iframe>
                    </div>
                </div>
            </div>

            <div class="editor-column">
                <!-- 배너 -->
                <div class="hl-panel" data-slot="promo_banner">
                    <h2><i class="fas fa-image text-gray-400"></i><?php echo t('mall_admin.home_layout.promo_banner'); ?></h2>
                    <label class="flex items-center gap-2 mb-2 text-xs">
                        <input type="checkbox" class="hl-active" <?php echo (!$slot_banner || $slot_banner['is_active']) ? 'checked' : ''; ?>> <?php echo t('mall_admin.home_layout.visible'); ?>
                    </label>
                    <div class="mb-2">
                        <label class="block text-xs font-semibold mb-1"><?php echo t('mall_admin.home_layout.title_label'); ?></label>
                        <input type="text" class="hl-title w-full border border-gray-300 rounded px-2 py-1.5 text-xs" value="<?php echo htmlspecialchars($slot_banner['title'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(t('mall_admin.home_layout.banner_title_placeholder')); ?>">
                    </div>
                    <div class="mb-2">
                        <label class="block text-xs font-semibold mb-1"><?php echo t('mall_admin.home_layout.subtitle_label'); ?></label>
                        <input type="text" class="hl-subtitle w-full border border-gray-300 rounded px-2 py-1.5 text-xs" value="<?php echo htmlspecialchars($slot_banner['subtitle'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(t('mall_admin.home_layout.banner_subtitle_placeholder')); ?>">
                    </div>
                    <div class="mb-2">
                        <label class="block text-xs font-semibold mb-1"><?php echo t('mall_admin.home_layout.banner_image_label'); ?></label>
                        <label class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold text-blue-600 border border-blue-600 rounded cursor-pointer hover:bg-blue-50">
                            <i class="fas fa-upload"></i> <?php echo t('mall_admin.home_layout.choose_image'); ?>
                            <input type="file" class="hl-banner-image hidden" accept="image/jpeg,image/png,image/webp">
                        </label>
                        <span class="hl-banner-filename text-xs text-gray-500 ml-1"><?php echo t('mall_admin.home_layout.no_file_chosen'); ?></span>
                        <img class="hl-banner-preview" src="<?php echo !empty($banner_config['image_path']) ? '/mall/' . htmlspecialchars($banner_config['image_path']) : ''; ?>" style="<?php echo !empty($banner_config['image_path']) ? '' : 'display:none;'; ?>max-width:100%;margin-top:0.4rem;border-radius:0.4rem;">
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1"><?php echo t('mall_admin.home_layout.link_on_click'); ?></label>
                        <select class="hl-link-type w-full border border-gray-300 rounded px-2 py-1.5 text-xs mb-1">
                            <option value="url" <?php echo ($banner_config['link_type'] ?? 'url') === 'url' ? 'selected' : ''; ?>><?php echo t('mall_admin.home_layout.link_type_url'); ?></option>
                            <option value="category" <?php echo ($banner_config['link_type'] ?? '') === 'category' ? 'selected' : ''; ?>><?php echo t('mall_admin.home_layout.link_type_category'); ?></option>
                            <option value="product" <?php echo ($banner_config['link_type'] ?? '') === 'product' ? 'selected' : ''; ?>><?php echo t('mall_admin.home_layout.link_type_product'); ?></option>
                            <option value="promo" <?php echo ($banner_config['link_type'] ?? '') === 'promo' ? 'selected' : ''; ?>><?php echo t('mall_admin.home_layout.link_type_promo'); ?></option>
                        </select>

                        <div class="hl-link-group <?php echo ($banner_config['link_type'] ?? 'url') === 'url' ? 'active' : ''; ?>" data-link-type="url">
                            <input type="text" class="hl-link-value w-full border border-gray-300 rounded px-2 py-1.5 text-xs" placeholder="<?php echo htmlspecialchars(t('mall_admin.home_layout.url_placeholder')); ?>" value="<?php echo ($banner_config['link_type'] ?? 'url') === 'url' ? htmlspecialchars($banner_config['link_value'] ?? '') : ''; ?>">
                        </div>
                        <div class="hl-link-group <?php echo ($banner_config['link_type'] ?? '') === 'promo' ? 'active' : ''; ?>" data-link-type="promo">
                            <div class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded px-2 py-1.5"><?php echo t('mall_admin.home_layout.promo_link_hint'); ?></div>
                        </div>
                        <div class="hl-link-group <?php echo ($banner_config['link_type'] ?? '') === 'category' ? 'active' : ''; ?>" data-link-type="category">
                            <select class="hl-link-category-id w-full border-2 rounded px-2 py-1.5 text-xs" style="border-color:#93c5fd;">
                                <option value=""><?php echo t('mall_admin.home_layout.select_placeholder'); ?></option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ($banner_config['link_type'] ?? '') === 'category' && (int)($banner_config['link_value'] ?? 0) === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(($c['parent_id'] ? '　└ ' : '') . $c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="hl-link-group <?php echo ($banner_config['link_type'] ?? '') === 'product' ? 'active' : ''; ?>" data-link-type="product">
                            <input type="text" class="hl-link-product-search w-full border border-gray-300 rounded px-2 py-1.5 text-xs" placeholder="<?php echo htmlspecialchars(t('mall_admin.home_layout.product_search_placeholder')); ?>">
                            <div class="hl-link-product-search-results text-xs mt-1"></div>
                            <div class="hl-link-product-selected mt-1">
                                <?php if ($banner_link_product): ?>
                                <span class="product-chip" data-product-id="<?php echo (int)$banner_link_product['product_id']; ?>">
                                    <?php echo htmlspecialchars($banner_link_product['display_name']); ?>
                                    <button type="button" class="remove-chip-btn">×</button>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="hl-save-btn w-full px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo t('mall_admin.home_layout.save_banner'); ?></button>
                </div>

                <?php
                // 상품 목록(어떤 상품이 들어갈지)은 이제 상품 큐레이션(products.php)의 "홈 노출 위치"
                // 체크박스에서 관리한다. 여기서는 노출 여부/제목 같은 "설정"만 다루고,
                // 지금 몇 개가 선택되어 있는지만 읽기 전용으로 보여준다.
                function hl_render_product_panel($slot_key, $icon, $label, $title_placeholder, $slot, $products) {
                    ob_start();
                    ?>
                    <div class="hl-panel" data-slot="<?php echo htmlspecialchars($slot_key); ?>">
                        <h2><i class="fas <?php echo $icon; ?> text-gray-400"></i><?php echo htmlspecialchars($label); ?></h2>
                        <label class="flex items-center gap-2 mb-2 text-xs">
                            <input type="checkbox" class="hl-active" <?php echo (!$slot || $slot['is_active']) ? 'checked' : ''; ?>> <?php echo t('mall_admin.home_layout.visible'); ?>
                        </label>
                        <div class="mb-2">
                            <label class="block text-xs font-semibold mb-1"><?php echo t('mall_admin.home_layout.title_label'); ?></label>
                            <input type="text" class="hl-title w-full border border-gray-300 rounded px-2 py-1.5 text-xs" value="<?php echo htmlspecialchars($slot['title'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars($title_placeholder); ?>">
                        </div>
                        <div class="mb-3 px-3 py-2 text-xs rounded border bg-gray-50 text-gray-600 flex items-center justify-between">
                            <span><?php echo t('mall_admin.home_layout.selected_products', ['count' => count($products)]); ?></span>
                            <a href="products.php" class="text-blue-600 font-semibold hover:underline"><i class="fas fa-box mr-1"></i><?php echo t('mall_admin.home_layout.manage_in_curation'); ?></a>
                        </div>
                        <button type="button" class="hl-save-btn w-full px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo htmlspecialchars($label); ?> <?php echo t('common.save'); ?></button>
                    </div>
                    <?php
                    return ob_get_clean();
                }
                echo hl_render_product_panel('today_deals', 'fa-fire', t('mall_admin.home_layout.today_deals'), t('mall_admin.home_layout.today_deals'), $slot_today, $today_products);
                echo hl_render_product_panel('new_arrivals', 'fa-box-open', t('mall_admin.home_layout.new_arrivals'), t('mall_admin.home_layout.new_arrivals'), $slot_new, $new_products);
                echo hl_render_product_panel('promo_products', 'fa-bullhorn', t('mall_admin.home_layout.promo_products'), t('mall_admin.home_layout.promo_products_placeholder'), $slot_promo, $promo_products);
                ?>
                <a href="/mall/promo.php" target="_blank" class="block text-center text-xs text-blue-600 hover:underline mb-4">
                    <i class="fas fa-up-right-from-square mr-1"></i><?php echo t('mall_admin.home_layout.view_promo_page'); ?>
                </a>
            </div>
        </div>
    </main>

<script>
function showFlash(message, type) {
    const area = document.getElementById('flash-area');
    const color = type === 'error' ? 'bg-red-100 text-red-700 border-red-300' : 'bg-green-100 text-green-700 border-green-300';
    area.innerHTML = '<div class="mb-3 px-3 py-2 text-xs rounded border ' + color + '">' + message + '</div>';
    setTimeout(() => { area.innerHTML = ''; }, 4000);
}

function makeProductChip(container, productId, displayName) {
    const chip = document.createElement('span');
    chip.className = 'product-chip';
    chip.dataset.productId = productId;
    chip.textContent = displayName;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'remove-chip-btn';
    btn.textContent = '×';
    btn.addEventListener('click', function () { chip.remove(); });
    chip.appendChild(btn);
    container.appendChild(chip);
}
document.querySelectorAll('.remove-chip-btn').forEach(function (btn) {
    btn.addEventListener('click', function () { btn.closest('.product-chip').remove(); });
});

// ── 배너 패널: 클릭 시 이동 타입 전환 + 상품 검색(단일 선택) ─────────────
const bannerPanel = document.querySelector('.hl-panel[data-slot="promo_banner"]');
if (bannerPanel) {
    const bannerFileInput = bannerPanel.querySelector('.hl-banner-image');
    const bannerFilenameLabel = bannerPanel.querySelector('.hl-banner-filename');
    const noFileChosenText = '<?php echo addslashes(t('mall_admin.home_layout.no_file_chosen')); ?>';
    bannerFileInput.addEventListener('change', function () {
        bannerFilenameLabel.textContent = this.files[0] ? this.files[0].name : noFileChosenText;
    });

    const linkTypeSelect = bannerPanel.querySelector('.hl-link-type');
    linkTypeSelect.addEventListener('change', function () {
        bannerPanel.querySelectorAll('.hl-link-group').forEach(function (g) {
            g.classList.toggle('active', g.dataset.linkType === linkTypeSelect.value);
        });
    });

    const linkProductSearch = bannerPanel.querySelector('.hl-link-product-search');
    const linkProductResults = bannerPanel.querySelector('.hl-link-product-search-results');
    const linkProductSelected = bannerPanel.querySelector('.hl-link-product-selected');
    let bannerSearchTimer = null;
    linkProductSearch.addEventListener('input', function () {
        const q = this.value.trim();
        clearTimeout(bannerSearchTimer);
        if (q.length < 1) { linkProductResults.innerHTML = ''; return; }
        bannerSearchTimer = setTimeout(function () {
            fetch('ajax/search_products.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    linkProductResults.innerHTML = '';
                    (data.data || []).forEach(function (p) {
                        const row = document.createElement('div');
                        row.style.cssText = 'display:flex;justify-content:space-between;padding:0.25rem 0;border-bottom:1px solid #f3f4f6;';
                        const nameSpan = document.createElement('span');
                        nameSpan.textContent = p.display_name;
                        row.appendChild(nameSpan);
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.textContent = '<?php echo addslashes(t('common.select')); ?>';
                        btn.className = 'text-blue-600';
                        btn.addEventListener('click', function () {
                            linkProductSelected.innerHTML = '';
                            makeProductChip(linkProductSelected, p.product_id, p.display_name);
                            linkProductResults.innerHTML = '';
                            linkProductSearch.value = '';
                        });
                        row.appendChild(btn);
                        linkProductResults.appendChild(row);
                    });
                });
        }, 300);
    });

    bannerPanel.querySelector('.hl-save-btn').addEventListener('click', function () {
        const btn = this;
        const formData = new FormData();
        formData.append('slot_key', 'promo_banner');
        formData.append('title', bannerPanel.querySelector('.hl-title').value);
        formData.append('subtitle', bannerPanel.querySelector('.hl-subtitle').value);
        formData.append('is_active', bannerPanel.querySelector('.hl-active').checked ? '1' : '0');
        const file = bannerPanel.querySelector('.hl-banner-image').files[0];
        if (file) formData.append('image', file);
        const linkType = linkTypeSelect.value;
        formData.append('link_type', linkType);
        let linkValue = '';
        if (linkType === 'category') {
            linkValue = bannerPanel.querySelector('.hl-link-category-id').value;
        } else if (linkType === 'product') {
            const chip = linkProductSelected.querySelector('.product-chip');
            linkValue = chip ? chip.dataset.productId : '';
        } else {
            linkValue = bannerPanel.querySelector('.hl-link-value').value;
        }
        formData.append('link_value', linkValue);
        formData.append('csrf_token', window.MALL_CSRF_TOKEN);

        btn.disabled = true;
        fetch('ajax/save_home_section.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (data.success) { showFlash('<?php echo addslashes(t('mall_admin.home_layout.banner_saved_msg')); ?>', 'success'); document.getElementById('preview-draft').src = document.getElementById('preview-draft').src; }
                else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.home_layout.save_failed')); ?>', 'error'); }
            });
    });
}

// ── 오늘의 특가 / 새로 들어온 한국 상품 / 기획전 상품 패널: 노출/제목만 저장 ──
// 어떤 상품이 들어갈지는 상품 큐레이션(products.php)의 "홈 노출 위치" 체크박스에서 관리한다.
document.querySelectorAll('.hl-panel[data-slot="today_deals"], .hl-panel[data-slot="new_arrivals"], .hl-panel[data-slot="promo_products"]').forEach(function (panel) {
    const slotKey = panel.dataset.slot;

    panel.querySelector('.hl-save-btn').addEventListener('click', function () {
        const btn = this;
        const params = new URLSearchParams();
        params.set('slot_key', slotKey);
        params.set('title', panel.querySelector('.hl-title').value);
        params.set('is_active', panel.querySelector('.hl-active').checked ? '1' : '0');
        params.set('csrf_token', window.MALL_CSRF_TOKEN);

        btn.disabled = true;
        fetch('ajax/save_home_section.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (data.success) { showFlash('<?php echo addslashes(t('mall_admin.home_layout.saved_msg')); ?>', 'success'); document.getElementById('preview-draft').src = document.getElementById('preview-draft').src; }
                else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.home_layout.save_failed')); ?>', 'error'); }
            });
    });
});

document.getElementById('publish-btn').addEventListener('click', function () {
    if (!confirm('<?php echo addslashes(t('mall_admin.home_layout.publish_confirm')); ?>')) return;
    const btn = this;
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/publish_home_layout.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                showFlash('<?php echo addslashes(t('mall_admin.home_layout.published_msg')); ?>'.replace('{count}', data.data.published_count), 'success');
                document.getElementById('preview-published').src = document.getElementById('preview-published').src;
            } else {
                showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.home_layout.publish_failed')); ?>', 'error');
            }
        })
        .catch(() => { btn.disabled = false; });
});
</script>
</body>
</html>
