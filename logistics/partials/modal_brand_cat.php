<!-- 브랜드/카테고리 빠른 등록 모달 -->
<div id="qcModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black bg-opacity-40" onclick="closeQuickCreate()"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 id="qcTitle" class="text-base font-semibold text-gray-900"></h3>
            <button type="button" onclick="closeQuickCreate()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.modal_brand_cat.english_name')); ?> <span class="text-red-500">*</span></label>
                <input type="text" id="qcNameEn" placeholder="<?php echo htmlspecialchars(t('logistics.modal_brand_cat.name_placeholder')); ?>"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();saveQuickCreate();}">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('logistics.modal_brand_cat.korean_name')); ?></label>
                <div class="flex gap-2">
                    <input type="text" id="qcNameKo" placeholder="<?php echo htmlspecialchars(t('logistics.modal_brand_cat.name_placeholder')); ?>"
                           class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();translateQcName();}">
                    <button type="button" id="qcTransBtn" onclick="translateQcName()"
                            class="px-3 py-2 text-xs font-semibold rounded-md whitespace-nowrap transition-colors"
                            style="background:#f3e8ff;color:#7e22ce;">
                        <?php echo htmlspecialchars(t('logistics.modal_brand_cat.romanize')); ?>
                    </button>
                </div>
            </div>
            <p id="qcError" class="text-xs text-red-600 hidden"></p>
        </div>

        <div class="flex gap-2 mt-5">
            <button type="button" id="qcSaveBtn" onclick="saveQuickCreate()"
                    class="flex-1 px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
                <i class="fas fa-plus mr-1"></i><?php echo htmlspecialchars(t('logistics.modal_brand_cat.register')); ?>
            </button>
            <button type="button" onclick="closeQuickCreate()"
                    class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">
                <?php echo htmlspecialchars(t('logistics.modal_brand_cat.cancel')); ?>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    var _qcType = '', _qcSelect = null;
    var LC_BASE = '<?php echo LC_BASE; ?>';

    window.openQuickCreate = function (type, targetEl) {
        _qcType = type;
        _qcSelect = targetEl || document.getElementById(type === 'brand' ? 'brandSelect' : 'catSelect');
        document.getElementById('qcTitle').textContent = type === 'brand' ? <?php echo json_encode(t('logistics.modal_brand_cat.register_new_brand'), JSON_UNESCAPED_UNICODE); ?> : <?php echo json_encode(t('logistics.modal_brand_cat.register_new_category'), JSON_UNESCAPED_UNICODE); ?>;
        document.getElementById('qcNameKo').value = '';
        document.getElementById('qcNameEn').value = '';
        var err = document.getElementById('qcError');
        err.textContent = ''; err.classList.add('hidden');
        document.getElementById('qcModal').classList.remove('hidden');
        setTimeout(function () { document.getElementById('qcNameEn').focus(); }, 50);
    };

    window.closeQuickCreate = function () {
        document.getElementById('qcModal').classList.add('hidden');
    };

    // 한글 → 영문 발음(로마자) 변환: 음절을 초성/중성/종성으로 분해 (product_add.php와 동일 로직)
    var RR_CHO  = ['g','kk','n','d','tt','r','m','b','pp','s','ss','','j','jj','ch','k','t','p','h'];
    var RR_JUNG = ['a','ae','ya','yae','eo','e','yeo','ye','o','wa','wae','oe','yo','u','wo','we','wi','yu','eu','ui','i'];
    var RR_JONG = ['','k','k','k','n','n','n','t','l','k','m','l','l','l','p','l','m','p','p','t','t','ng','t','t','k','t','p','h'];

    function romanizeKorean(text) {
        var out = '';
        for (var i = 0; i < text.length; i++) {
            var code = text.charCodeAt(i);
            if (code >= 0xAC00 && code <= 0xD7A3) {
                var s = code - 0xAC00;
                out += RR_CHO[Math.floor(s / 588)] + RR_JUNG[Math.floor((s % 588) / 28)] + RR_JONG[s % 28];
            } else {
                out += text.charAt(i);
            }
        }
        return out;
    }

    window.translateQcName = function () {
        var ko = document.getElementById('qcNameKo').value.trim();
        if (!ko) { document.getElementById('qcNameKo').focus(); return; }
        var enEl = document.getElementById('qcNameEn');
        enEl.value = romanizeKorean(ko).toUpperCase();
        enEl.focus();
    };

    window.saveQuickCreate = function () {
        var name_en = document.getElementById('qcNameEn').value.trim();
        var name_ko = document.getElementById('qcNameKo').value.trim();
        var errEl = document.getElementById('qcError');

        if (!name_en) {
            errEl.textContent = <?php echo json_encode(t('logistics.modal_brand_cat.english_name_required'), JSON_UNESCAPED_UNICODE); ?>;
            errEl.classList.remove('hidden');
            document.getElementById('qcNameEn').focus();
            return;
        }
        errEl.classList.add('hidden');

        var csrf = (document.querySelector('input[name="csrf_token"]') || {}).value || '';
        var btn = document.getElementById('qcSaveBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>' + <?php echo json_encode(t('logistics.modal_brand_cat.saving'), JSON_UNESCAPED_UNICODE); ?>;

        var fd = new FormData();
        fd.append('type', _qcType);
        fd.append('name_en', name_en);
        fd.append('name_ko', name_ko);
        fd.append('csrf_token', csrf);

        fetch(LC_BASE + '/ajax/quick_create.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var label = data.name_en + (data.name_ko ? ' (' + data.name_ko + ')' : '');
                    if (_qcSelect) {
                        var opt = new Option(label, data.id, true, true);
                        _qcSelect.appendChild(opt);
                        _qcSelect.value = data.id;
                    }
                    if (typeof window.onQuickCreateSuccess === 'function') {
                        window.onQuickCreateSuccess(_qcType, data.id, data.name_en, data.name_ko);
                    }
                    closeQuickCreate();
                } else {
                    errEl.textContent = data.message || <?php echo json_encode(t('logistics.modal_brand_cat.error'), JSON_UNESCAPED_UNICODE); ?>;
                    errEl.classList.remove('hidden');
                }
            })
            .catch(function () {
                errEl.textContent = <?php echo json_encode(t('logistics.modal_brand_cat.server_error'), JSON_UNESCAPED_UNICODE); ?>;
                errEl.classList.remove('hidden');
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-plus mr-1"></i>' + <?php echo json_encode(t('logistics.modal_brand_cat.register'), JSON_UNESCAPED_UNICODE); ?>;
            });
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeQuickCreate();
    });
})();
</script>
