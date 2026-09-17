<?php
$page_title = t('logistics.product_add.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/unit_helper.php'; // unit 검증(BOX/PCS만 허용)
require_once __DIR__ . '/lib/image_helper.php'; // 상품 대표 이미지 업로드 처리
require_once __DIR__ . '/lib/barcode_helper.php'; // 바코드 중복 검증

lc_require_staff();

$errors = [];
$prefill_barcode = trim($_GET['barcode'] ?? '');
$form = ['name_en'=>'','name_ko'=>'','capacity'=>'','brand_id'=>'','category_id'=>'',
         'unit'=>'BOX','pieces_per_box'=>1,
         'barcode_unit'=>$prefill_barcode,'barcode_box'=>'','barcode_logistics'=>'',
         'min_stock'=>0,'requires_expiry'=>0];

try {
    $conn = get_lc_db();
    $brands     = $conn->query("SELECT id, name_en, name_ko FROM lc_brands ORDER BY name_en ASC")->fetch_all(MYSQLI_ASSOC);
    $categories = $conn->query("SELECT id, name_en, name_ko FROM lc_categories ORDER BY name_en ASC")->fetch_all(MYSQLI_ASSOC);
    $conn->close();
} catch (Exception $e) { $brands = []; $categories = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lc_verify_csrf();
    $form['name_en']          = trim($_POST['name_en'] ?? '');
    $form['name_ko']          = trim($_POST['name_ko'] ?? '') ?: null;
    $form['capacity']         = trim($_POST['capacity'] ?? '') ?: null;
    $form['brand_id']         = (int)($_POST['brand_id'] ?? 0) ?: null;
    $form['category_id']      = (int)($_POST['category_id'] ?? 0) ?: null;
    $form['unit']             = lc_valid_unit($_POST['unit'] ?? '', LC_UNIT_PCS); // BOX/PCS 외 값은 PCS로
    $form['pieces_per_box']   = max(1, (int)($_POST['pieces_per_box'] ?? 1));
    $form['barcode_unit']     = trim($_POST['barcode_unit'] ?? '') ?: null;
    $form['barcode_box']      = trim($_POST['barcode_box'] ?? '') ?: null;
    $form['barcode_logistics']= trim($_POST['barcode_logistics'] ?? '') ?: null;
    $form['min_stock']        = max(0, (int)($_POST['min_stock'] ?? 0));
    $form['requires_expiry']  = isset($_POST['requires_expiry']) ? 1 : 0;

    if ($form['name_en'] === '') $errors[] = t('logistics.product_add.name_required');

    // 바코드 중복 검증 (3개 컬럼 교차 검사) — 다른 상품과 겹치면 등록 차단
    if (empty($errors)) {
        try {
            $conn_chk = get_lc_db();
            $conflicts = lc_find_barcode_conflicts($conn_chk, [
                $form['barcode_unit'], $form['barcode_box'], $form['barcode_logistics'],
            ]);
            $conn_chk->close();
            if (!empty($conflicts)) $errors[] = lc_format_barcode_conflict_msg($conflicts);
        } catch (Exception $e) {
            $errors[] = t('logistics.product_add.barcode_check_error') . ': ' . $e->getMessage();
        }
    }

    // 대표 이미지 업로드 (선택)
    $image_path = null;
    if (empty($errors) && isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $img_err = '';
        $image_path = lc_handle_product_image_upload($_FILES['image'], $img_err);
        if ($image_path === null && $img_err !== '') $errors[] = $img_err;
    }

    if (empty($errors)) {
        try {
            $conn = get_lc_db();
            $st = $conn->prepare(
                "INSERT INTO lc_products
                 (name_en, name_ko, capacity, brand_id, category_id, unit, pieces_per_box,
                  barcode_unit, barcode_box, barcode_logistics, min_stock, requires_expiry, image_path, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $uid = lc_current_user_id();
            $st->bind_param('sssiisisssiisi',
                $form['name_en'], $form['name_ko'], $form['capacity'], $form['brand_id'], $form['category_id'],
                $form['unit'], $form['pieces_per_box'],
                $form['barcode_unit'], $form['barcode_box'], $form['barcode_logistics'],
                $form['min_stock'], $form['requires_expiry'], $image_path, $uid
            );
            $st->execute();
            $new_id = $conn->insert_id;

            // 등록 이력
            $uname = $_SESSION['name'] ?? ($_SESSION['username'] ?? 'Unknown');
            $sh = $conn->prepare(
                "INSERT INTO lc_product_history (product_id, user_id, user_name, action) VALUES (?,?,?,'create')"
            );
            $sh->bind_param('iis', $new_id, $uid, $uname);
            $sh->execute();
            $sh->close();

            $conn->close();
            lc_set_flash('success', t('logistics.product_add.register_success'));
            header('Location: ' . LC_BASE . '/products.php');
            exit;
        } catch (Exception $e) {
            $errors[] = t('logistics.product_add.db_error') . ': ' . $e->getMessage();
        }
    }
}
?>
<div class="flex items-center gap-3 mb-6">
    <a href="<?php echo LC_BASE; ?>/products.php" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars(t('logistics.product_add.title')); ?></h2>
</div>
<?php if (!empty($errors)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-sm text-red-700"><i class="fas fa-exclamation-circle mr-1"></i><?php echo htmlspecialchars($e); ?></p><?php endforeach; ?>
</div>
<?php endif; ?>
<div class="bg-white rounded-lg border border-gray-200 p-6" style="max-width:63rem;">
    <div class="border border-teal-200 bg-teal-50 rounded-lg p-4 mb-5">
        <h3 class="text-sm font-semibold text-teal-800 mb-1"><i class="fas fa-magic mr-1"></i><?php echo htmlspecialchars(t('logistics.product_add.import_title')); ?></h3>
        <p class="text-xs text-teal-700 mb-2"><?php echo htmlspecialchars(t('logistics.product_add.import_description')); ?></p>
        <div class="relative">
            <input type="text" id="shopProductSearch" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('logistics.product_add.import_placeholder')); ?>"
                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            <div id="shopProductDropdown" class="hidden absolute z-10 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-56 overflow-y-auto">
                <ul id="shopProductList" class="py-1"></ul>
            </div>
        </div>
    </div>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var searchEl = document.getElementById('shopProductSearch');
    var dropEl   = document.getElementById('shopProductDropdown');
    var listEl   = document.getElementById('shopProductList');
    var timer = null;

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function lbl(item) {
        return item.name_en + (item.name_ko ? ' (' + item.name_ko + ')' : '');
    }

    function render(products) {
        listEl.innerHTML = '';
        if (!products.length) {
            listEl.innerHTML = '<li class="px-3 py-2 text-sm text-gray-400"><?php echo htmlspecialchars(t('logistics.product_add.no_results')); ?></li>';
            return;
        }
        products.forEach(function(item) {
            var li = document.createElement('li');
            li.className = 'px-3 py-2 text-sm cursor-pointer hover:bg-teal-50 hover:text-teal-700';
            li.innerHTML = '<div class="font-medium">' + escapeHtml(item.name_en) +
                            (item.name_ko ? ' <span class="text-gray-500">(' + escapeHtml(item.name_ko) + ')</span>' : '') + '</div>' +
                            '<div class="text-xs text-gray-400 font-mono">' + escapeHtml(item.sku) +
                            (item.pieces_per_box ? ' &middot; ' + item.pieces_per_box + ' <?php echo htmlspecialchars(t('logistics.product_add.pcs_box')); ?>' : '') + '</div>';
            li.addEventListener('mousedown', function(e) { e.preventDefault(); applyProduct(item); });
            listEl.appendChild(li);
        });
    }

    function applyProduct(item) {
        var enInput      = document.getElementById('prodNameEn');
        var koInput      = document.getElementById('prodNameKo');
        var ppbInput     = document.getElementById('piecesPerBoxInput');
        var barcodeInput = document.getElementById('barcodeUnitInput');

        if (item.name_en) enInput.value = item.name_en;
        if (item.name_ko) koInput.value = item.name_ko;
        if (item.pieces_per_box) ppbInput.value = item.pieces_per_box;
        if (item.sku && !barcodeInput.value.trim()) {
            barcodeInput.value = item.sku;
            barcodeInput.dispatchEvent(new Event('input'));
        }

        searchEl.value = lbl(item);
        dropEl.classList.add('hidden');
    }

    function search(q) {
        fetch(LC_BASE + '/ajax/search_shop_product.php?q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    render(data.products);
                } else {
                    listEl.innerHTML = '<li class="px-3 py-2 text-sm text-gray-400">' + escapeHtml(data.message) + '</li>';
                }
                dropEl.classList.remove('hidden');
            })
            .catch(function() {
                listEl.innerHTML = '<li class="px-3 py-2 text-sm text-red-400"><?php echo htmlspecialchars(t('logistics.product_add.search_failed')); ?></li>';
                dropEl.classList.remove('hidden');
            });
    }

    searchEl.addEventListener('input', function() {
        var q = searchEl.value.trim();
        if (timer) clearTimeout(timer);
        if (!q) { dropEl.classList.add('hidden'); return; }
        timer = setTimeout(function() { search(q); }, 350);
    });

    searchEl.addEventListener('blur', function() {
        setTimeout(function() { dropEl.classList.add('hidden'); }, 150);
    });

    <?php if ($prefill_barcode !== ''): ?>
    searchEl.value = <?php echo json_encode($prefill_barcode, JSON_UNESCAPED_UNICODE); ?>;
    search(searchEl.value);
    <?php endif; ?>
})();
</script>

    <form method="post" class="space-y-5" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">

        <div class="border-b border-gray-100 pb-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-3"><?php echo htmlspecialchars(t('logistics.product_add.product_name')); ?></h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.product_add.english_name')); ?> <span class="text-red-500">*</span></label>
                    <div class="flex gap-2">
                        <input type="text" name="name_en" id="prodNameEn" value="<?php echo htmlspecialchars($form['name_en']); ?>" required
                           placeholder="<?php echo htmlspecialchars(t('logistics.product_add.name_placeholder')); ?>"
                               class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <button type="button" id="prodTranslateToKoBtn" onclick="translateToKorean()"
                                class="px-3 py-2 text-xs font-semibold rounded-md whitespace-nowrap transition-colors"
                                style="background:#dcfce7;color:#15803d;">
                            Translate ▶ Korean
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.product_add.korean_name')); ?> <span class="text-xs text-gray-400 font-normal"><?php echo htmlspecialchars(t('logistics.product_add.korean_only')); ?></span></label>
                    <div class="flex gap-2">
                        <input type="text" name="name_ko" id="prodNameKo" value="<?php echo htmlspecialchars($form['name_ko'] ?? ''); ?>"
                           placeholder="<?php echo htmlspecialchars(t('logistics.product_add.name_placeholder')); ?>"
                               class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <button type="button" id="prodRomanizeBtn" onclick="romanizeKoreanName()" title="<?php echo htmlspecialchars(t('logistics.brand.romanize')); ?>"
                                class="px-3 py-2 text-xs font-semibold rounded-md whitespace-nowrap transition-colors"
                                style="background:#f3e8ff;color:#7e22ce;">
                            발음 ▶ English
                        </button>
                        <button type="button" id="prodTranslateBtn" onclick="translateProdName()"
                                class="px-3 py-2 bg-blue-500 text-white text-xs font-semibold rounded-md hover:bg-blue-600 whitespace-nowrap transition-colors">
                            Translate ▶ English
                        </button>
                    </div>
                </div>
            </div>
        </div>

<script>
(function() {
    // 한글 → 영문 발음(로마자) 변환: 음절을 초성/중성/종성으로 분해하여 변환
    var RR_CHO  = ['g','kk','n','d','tt','r','m','b','pp','s','ss','','j','jj','ch','k','t','p','h'];
    var RR_JUNG = ['a','ae','ya','yae','eo','e','yeo','ye','o','wa','wae','oe','yo','u','wo','we','wi','yu','eu','ui','i'];
    var RR_JONG = ['','k','k','k','n','n','n','t','l','k','m','l','l','l','p','l','m','p','p','t','t','ng','t','t','k','t','p','h'];

    function romanizeKorean(text) {
        var out = '';
        for (var i = 0; i < text.length; i++) {
            var code = text.charCodeAt(i);
            if (code >= 0xAC00 && code <= 0xD7A3) {
                var s = code - 0xAC00;
                var cho  = Math.floor(s / 588);
                var jung = Math.floor((s % 588) / 28);
                var jong = s % 28;
                out += RR_CHO[cho] + RR_JUNG[jung] + RR_JONG[jong];
            } else {
                out += text.charAt(i);
            }
        }
        return out;
    }

    window.romanizeKoreanName = function() {
        var koInput = document.getElementById('prodNameKo');
        var enInput = document.getElementById('prodNameEn');
        var text = koInput.value.trim();
        if (!text) { koInput.focus(); return; }
        enInput.value = romanizeKorean(text).toUpperCase();
        enInput.focus();
    };

    window.translateToKorean = function() {
        var enInput = document.getElementById('prodNameEn');
        var koInput = document.getElementById('prodNameKo');
        var btn = document.getElementById('prodTranslateToKoBtn');
        var text = enInput.value.trim();
        if (!text) { enInput.focus(); return; }

        btn.textContent = <?php echo json_encode(t('logistics.product_add.translating')); ?>;
        btn.disabled = true;

        fetch('https://api.mymemory.translated.net/get?q=' + encodeURIComponent(text) + '&langpair=en|ko')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.responseStatus === 200) {
                    koInput.value = data.responseData.translatedText;
                    koInput.focus();
                } else {
            alert(<?php echo json_encode(t('logistics.product_add.translation_failed')); ?>);
                }
            })
            .catch(function() {
            alert(<?php echo json_encode(t('logistics.product_add.translation_unavailable')); ?>);
            })
            .finally(function() {
        btn.textContent = <?php echo json_encode(t('logistics.product_add.translate_korean')); ?>;
                btn.disabled = false;
            });
    };

    window.translateProdName = function() {
        var koInput = document.getElementById('prodNameKo');
        var enInput = document.getElementById('prodNameEn');
        var btn = document.getElementById('prodTranslateBtn');
        var text = koInput.value.trim();
        if (!text) { koInput.focus(); return; }

        btn.textContent = <?php echo json_encode(t('logistics.product_add.translating')); ?>;
        btn.disabled = true;

        fetch('https://api.mymemory.translated.net/get?q=' + encodeURIComponent(text) + '&langpair=ko|en')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.responseStatus === 200) {
                    enInput.value = data.responseData.translatedText.toUpperCase();
                    enInput.focus();
                } else {
            alert(<?php echo json_encode(t('logistics.product_add.translation_failed')); ?>);
                }
            })
            .catch(function() {
            alert(<?php echo json_encode(t('logistics.product_add.translation_unavailable')); ?>);
            })
            .finally(function() {
        btn.textContent = <?php echo json_encode(t('logistics.product_add.translate_english')); ?>;
                btn.disabled = false;
            });
    };
})();
</script>

        <div class="border-b border-gray-100 pb-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-image text-gray-400 mr-1"></i><?php echo htmlspecialchars(t('logistics.product_add.product_image')); ?></h3>
            <div class="flex items-start gap-4">
                <div class="w-28 h-28 rounded-lg border border-gray-200 bg-gray-50 flex items-center justify-center overflow-hidden shrink-0">
                    <img id="imgPreview" src="" alt="" class="w-full h-full object-cover hidden">
                    <span id="imgPlaceholder" class="text-gray-300 text-3xl"><i class="fas fa-image"></i></span>
                </div>
                <div class="flex-1">
                    <input type="file" name="image" id="imageInput" accept="image/jpeg,image/png,image/webp,image/gif"
                           class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100">
                    <p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars(t('logistics.product_add.image_help')); ?></p>
                    <button type="button" id="removeImageBtn" onclick="clearProductImage()"
                            class="mt-2 text-xs text-red-600 hover:text-red-700 hidden">
                        <i class="fas fa-trash-alt mr-1"></i><?php echo htmlspecialchars(t('logistics.product_add.remove')); ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="border-b border-gray-100 pb-5">
            <div class="gap-4" style="display:grid; grid-template-columns:1fr 2fr 2fr; gap:1rem;">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.product_add.capacity')); ?></label>
                    <input type="text" name="capacity" value="<?php echo htmlspecialchars($form['capacity'] ?? ''); ?>"
                           placeholder="<?php echo htmlspecialchars(t('logistics.product_add.capacity_placeholder')); ?>"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.product_add.brand')); ?></label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="hidden" name="brand_id" id="brandIdHidden">
                            <input type="text" id="brandSearch" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('logistics.product_add.brand_placeholder')); ?>"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                            <div id="brandDropdown" class="hidden absolute z-10 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
                                <ul id="brandList" class="py-1"></ul>
                            </div>
                        </div>
                        <button type="button" onclick="openQuickCreate('brand')" title="<?php echo htmlspecialchars(t('logistics.product_add.add_brand')); ?>"
                                class="shrink-0 px-3 py-2 bg-teal-50 border border-teal-300 text-teal-700 rounded-md hover:bg-teal-100 transition-colors text-sm">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.product_add.category')); ?></label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="hidden" name="category_id" id="catIdHidden">
                            <input type="text" id="catSearch" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('logistics.product_add.category_placeholder')); ?>"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                            <div id="catDropdown" class="hidden absolute z-10 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
                                <ul id="catList" class="py-1"></ul>
                            </div>
                        </div>
                        <button type="button" onclick="openQuickCreate('category')" title="<?php echo htmlspecialchars(t('logistics.product_add.add_category')); ?>"
                                class="shrink-0 px-3 py-2 bg-teal-50 border border-teal-300 text-teal-700 rounded-md hover:bg-teal-100 transition-colors text-sm">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

<script>
(function() {
    var _brands = <?php echo json_encode(array_values($brands), JSON_UNESCAPED_UNICODE); ?>;
    var _cats   = <?php echo json_encode(array_values($categories), JSON_UNESCAPED_UNICODE); ?>;
    var CSRF    = (document.querySelector('input[name="csrf_token"]') || {}).value || ''; // 인라인 수정 AJAX용

    function makeSearchWidget(cfg) {
        var data = cfg.data;
        var searchEl = document.getElementById(cfg.searchId);
        var hiddenEl = document.getElementById(cfg.hiddenId);
        var dropEl   = document.getElementById(cfg.dropdownId);
        var listEl   = document.getElementById(cfg.listId);
        var _selId = '', _focusIdx = -1, _filtered = [];
        function lbl(item) { return item.name_en + (item.name_ko ? ' (' + item.name_ko + ')' : ''); }
        function highlight(idx) {
            var lis = listEl.querySelectorAll('li[data-s]');
            Array.prototype.forEach.call(lis, function(li, i) {
                li.style.backgroundColor = (i === idx) ? '#ccfbf1' : '';
                li.style.color           = (i === idx) ? '#0f766e' : '';
                li.style.fontWeight      = (i === idx) ? '600'     : '';
            });
            _focusIdx = idx;
            if (lis[idx]) lis[idx].scrollIntoView({ block: 'nearest' });
        }
        function render(filter) {
            listEl.innerHTML = ''; _focusIdx = -1;
            var q = (filter || '').toLowerCase();
            _filtered = data.filter(function(i) { return !q || lbl(i).toLowerCase().indexOf(q) !== -1; });
            if (!_filtered.length) { listEl.innerHTML = '<li class="px-3 py-2 text-sm text-gray-400"><?php echo htmlspecialchars(t('logistics.product_add.no_results')); ?></li>'; return; }
            _filtered.forEach(function(item) {
                var li = document.createElement('li');
                li.className = 'px-3 py-2 text-sm cursor-pointer hover:bg-teal-50 hover:text-teal-700';
                li.dataset.s = '1';
                li.addEventListener('mousedown', function(e) { e.preventDefault(); select(item.id, lbl(item)); });
                // Design Ref: §5.3 — 항목별 인라인 수정 (연필) 부착
                LcInlineEdit.attach({
                    li: li, item: item, type: cfg.type, csrf: CSRF,
                    onSaved: function(u) {
                        if (_selId === String(u.id)) { searchEl.value = lbl(u); hiddenEl.value = u.id; } // Plan SC-2
                        render(searchEl.value);
                    }
                });
                listEl.appendChild(li);
            });
        }
        function select(id, label) { _selId = String(id); hiddenEl.value = id; searchEl.value = label; dropEl.classList.add('hidden'); }
        searchEl.addEventListener('focus', function() { render(searchEl.value); dropEl.classList.remove('hidden'); });
        searchEl.addEventListener('input', function() { _selId = ''; hiddenEl.value = ''; render(searchEl.value); dropEl.classList.remove('hidden'); });
        searchEl.addEventListener('blur', function() { setTimeout(function() { if (window.LcInlineEdit && window.LcInlineEdit.editing) return; dropEl.classList.add('hidden'); if (!_selId) { searchEl.value = ''; hiddenEl.value = ''; } }, 150); });
        searchEl.addEventListener('keydown', function(e) {
            var open = !dropEl.classList.contains('hidden');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!open) { render(searchEl.value); dropEl.classList.remove('hidden'); }
                if (_filtered.length) highlight(Math.min(_focusIdx + 1, _filtered.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (_focusIdx > 0) highlight(_focusIdx - 1);
            } else if (e.key === 'Enter' && open) {
                e.preventDefault();
                if (_focusIdx >= 0 && _filtered[_focusIdx]) select(_filtered[_focusIdx].id, lbl(_filtered[_focusIdx]));
                else dropEl.classList.add('hidden');
            } else if (e.key === 'Escape') {
                dropEl.classList.add('hidden');
            }
        });
        return { reset: function() { _selId=''; _focusIdx=-1; _filtered=[]; hiddenEl.value=''; searchEl.value=''; dropEl.classList.add('hidden'); }, select: select, data: data };
    }

    window._brandWidget = makeSearchWidget({ data: _brands, type: 'brand',    searchId: 'brandSearch', hiddenId: 'brandIdHidden', dropdownId: 'brandDropdown', listId: 'brandList' });
    window._catWidget   = makeSearchWidget({ data: _cats,   type: 'category', searchId: 'catSearch',   hiddenId: 'catIdHidden',   dropdownId: 'catDropdown',   listId: 'catList'   });

    window.onQuickCreateSuccess = function(type, id, nameEn, nameKo) {
        var label = nameEn + (nameKo ? ' (' + nameKo + ')' : '');
        if (type === 'brand') { _brands.push({id:id,name_en:nameEn,name_ko:nameKo}); window._brandWidget.select(id, label); }
        else                  { _cats.push({id:id,name_en:nameEn,name_ko:nameKo});   window._catWidget.select(id, label); }
    };

    <?php if ($form['brand_id']): ?>
    (function() { var b = _brands.find(function(x){return x.id==<?php echo (int)$form['brand_id']; ?>;});
        if(b) window._brandWidget.select(b.id, b.name_en+(b.name_ko?' ('+b.name_ko+')':'')); })();
    <?php endif; ?>
    <?php if ($form['category_id']): ?>
    (function() { var c = _cats.find(function(x){return x.id==<?php echo (int)$form['category_id']; ?>;});
        if(c) window._catWidget.select(c.id, c.name_en+(c.name_ko?' ('+c.name_ko+')':'')); })();
    <?php endif; ?>
})();
</script>

        <div class="border-b border-gray-100 pb-5">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.product_add.unit')); ?></label>
                    <?php $cur_unit = $form['unit'] !== '' ? $form['unit'] : 'BOX'; ?>
                    <select name="unit"
                            class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <option value="BOX" <?php echo $cur_unit === 'BOX' ? 'selected' : ''; ?>>BOX</option>
                        <option value="PACK" <?php echo $cur_unit === 'PACK' ? 'selected' : ''; ?>>PACK</option>
                        <option value="PCS" <?php echo $cur_unit === 'PCS' ? 'selected' : ''; ?>>PCS</option>
                        <?php if (!in_array($cur_unit, ['BOX', 'PACK', 'PCS'], true)): ?>
                        <option value="<?php echo htmlspecialchars($cur_unit); ?>" selected><?php echo htmlspecialchars($cur_unit); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.product_add.units_per_box')); ?></label>
                    <input type="number" name="pieces_per_box" id="piecesPerBoxInput" value="<?php echo $form['pieces_per_box']; ?>" min="1"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
            </div>
        </div>

        <div class="border-b border-gray-100 pb-5">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-barcode text-gray-400 mr-1"></i><?php echo htmlspecialchars(t('logistics.product_add.barcode')); ?></label>
                    <input type="text" name="barcode_unit" id="barcodeUnitInput" value="<?php echo htmlspecialchars($form['barcode_unit'] ?? ''); ?>"
                           autocomplete="off"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-box text-gray-400 mr-1"></i><?php echo htmlspecialchars(t('logistics.product_add.box_code')); ?></label>
                    <input type="text" name="barcode_box" id="barcodeBoxInput" value="<?php echo htmlspecialchars($form['barcode_box'] ?? ''); ?>"
                           autocomplete="off"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-warehouse text-gray-400 mr-1"></i><?php echo htmlspecialchars(t('logistics.product_add.logistics_code')); ?></label>
                    <input type="text" name="barcode_logistics" id="barcodeLogisticsInput" value="<?php echo htmlspecialchars($form['barcode_logistics'] ?? ''); ?>"
                           autocomplete="off"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
            </div>
        </div>

        <div class="grid grid-cols-4 gap-4 items-start pb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.product_add.minimum_stock')); ?></label>
                <input type="number" name="min_stock" value="<?php echo $form['min_stock']; ?>" min="0"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div class="col-span-3 flex items-start gap-3 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                <input type="checkbox" name="requires_expiry" id="requiresExpiry" value="1"
                       <?php echo $form['requires_expiry'] ? 'checked' : ''; ?>
                       class="mt-0.5 w-4 h-4 text-orange-500 border-gray-300 rounded focus:ring-orange-400">
                <label for="requiresExpiry" class="cursor-pointer">
                    <span class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars(t('logistics.product_add.expiry_required')); ?></span>
                    <p class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars(t('logistics.product_add.expiry_help')); ?></p>
                </label>
            </div>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="px-6 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700">
                <i class="fas fa-save mr-2"></i><?php echo htmlspecialchars(t('logistics.product_add.register')); ?>
            </button>
            <a href="<?php echo LC_BASE; ?>/products.php" class="px-6 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200"><?php echo htmlspecialchars(t('logistics.product_add.cancel')); ?></a>
        </div>
    </form>
</div>

<!-- 코드 중복 알림 모달 -->
<div id="duplicateCodeModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-red-50 border-2 border-red-300 rounded-xl shadow-xl p-6 w-full max-w-sm mx-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-500 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-white"></i>
            </div>
            <div>
        <h3 class="text-sm font-semibold text-red-700"><?php echo htmlspecialchars(t('logistics.product_add.duplicate_code')); ?></h3>
                <p id="duplicateCodeMessage" class="text-xs text-red-600 mt-0.5"></p>
            </div>
        </div>
        <div class="flex gap-3">
            <a id="duplicateCodeViewLink" href="#" target="_blank"
               class="flex-1 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors text-center">
                <?php echo htmlspecialchars(t('logistics.product_add.view_product')); ?>
            </a>
            <button type="button" onclick="closeDuplicateCodeModal()"
                    class="flex-1 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                <?php echo htmlspecialchars(t('logistics.product_add.close')); ?>
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';

    window.closeDuplicateCodeModal = function() {
        document.getElementById('duplicateCodeModal').classList.add('hidden');
    };

    function showDuplicateCodeModal(product) {
        document.getElementById('duplicateCodeMessage').textContent =
            '"' + product.name + '" (' + product.fields.join(', ') + ')';
        document.getElementById('duplicateCodeViewLink').href = LC_BASE + '/product_edit.php?id=' + product.id;
        document.getElementById('duplicateCodeModal').classList.remove('hidden');
    }

    function setupDuplicateCheck(inputId) {
        var input = document.getElementById(inputId);
        var timer = null;

        function check(showModal) {
            var value = input.value.trim();
            if (!value) {
                input.classList.remove('border-red-400');
                return;
            }
            fetch(LC_BASE + '/ajax/check_product_code.php?value=' + encodeURIComponent(value))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.duplicate) {
                        input.classList.add('border-red-400');
                        if (showModal) showDuplicateCodeModal(data.product);
                    } else {
                        input.classList.remove('border-red-400');
                    }
                })
                .catch(function() { /* 네트워크 오류 시 검사 무시 */ });
        }

        input.addEventListener('blur', function() { check(true); });
        input.addEventListener('input', function() {
            if (timer) clearTimeout(timer);
            timer = setTimeout(function() { check(false); }, 400);
        });
    }

    setupDuplicateCheck('barcodeUnitInput');
    setupDuplicateCheck('barcodeBoxInput');
    setupDuplicateCheck('barcodeLogisticsInput');
})();
</script>
<script>
(function() {
    var fileInput   = document.getElementById('imageInput');
    var preview     = document.getElementById('imgPreview');
    var placeholder = document.getElementById('imgPlaceholder');
    var removeBtn   = document.getElementById('removeImageBtn');
    if (!fileInput) return;

    fileInput.addEventListener('change', function() {
        var f = this.files && this.files[0];
        if (!f) return;
        preview.src = URL.createObjectURL(f);
        preview.classList.remove('hidden');
        placeholder.classList.add('hidden');
        removeBtn.classList.remove('hidden');
    });

    window.clearProductImage = function() {
        fileInput.value = '';
        preview.src = '';
        preview.classList.add('hidden');
        placeholder.classList.remove('hidden');
        removeBtn.classList.add('hidden');
    };
})();
</script>
<?php require __DIR__ . '/partials/inline_edit_widget.php'; // Design Ref: §5.4 ?>
<?php require_once __DIR__ . '/partials/modal_brand_cat.php'; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
