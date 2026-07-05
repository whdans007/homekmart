<?php // Design Ref: §4 — 브랜드/카테고리 드롭다운 인라인 수정 공통 헬퍼 (window.LcInlineEdit) ?>
<script>
(function () {
    if (window.LcInlineEdit) return; // 중복 include 가드
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var _active = null; // 현재 편집중인 항목 { li, restore }

    function label(item) {
        return item.name_en + (item.name_ko ? ' (' + item.name_ko + ')' : '');
    }

    function cancelActive() {
        if (_active) { var r = _active.restore; _active = null; r(); }
    }

    // Design Ref: §4.1 — 편집 폼 밖을 클릭하면 편집 취소 (캡처 단계)
    document.addEventListener('mousedown', function (e) {
        if (_active && _active.li && !_active.li.contains(e.target)) cancelActive();
    }, true);

    window.LcInlineEdit = {
        // 다른 위젯의 blur 닫힘 타이머가 편집중에는 드롭다운을 닫지 않도록 참조
        get editing() { return !!_active; },

        // opts: { li, item:{id,name_en,name_ko}, type:'brand'|'category', csrf, onSaved(item) }
        attach: function (opts) {
            var li = opts.li, item = opts.item, type = opts.type, csrf = opts.csrf;
            var onSaved = opts.onSaved || function () {};

            renderView();

            function renderView() {
                li.innerHTML = '';
                li.style.display = 'flex';
                li.style.alignItems = 'center';
                li.style.justifyContent = 'space-between';
                li.style.gap = '6px';

                var lbl = document.createElement('span');
                lbl.className = 'lc-ie-label';
                lbl.style.cssText = 'flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                lbl.textContent = label(item);

                var edit = document.createElement('button');
                edit.type = 'button';
                edit.className = 'lc-ie-edit';
                edit.title = 'Edit';
                edit.innerHTML = '<i class="fas fa-pencil-alt"></i>';
                edit.style.cssText = 'flex:0 0 auto;color:#9ca3af;padding:2px 6px;font-size:11px;';
                edit.addEventListener('mouseover', function () { edit.style.color = '#0d9488'; });
                edit.addEventListener('mouseout',  function () { edit.style.color = '#9ca3af'; });
                // Plan SC-4 / §4.1: 항목 선택(li mousedown)·검색창 blur 닫힘 차단
                edit.addEventListener('mousedown', function (e) { e.preventDefault(); e.stopPropagation(); });
                edit.addEventListener('click',     function (e) { e.preventDefault(); e.stopPropagation(); enterEdit(); });

                li.appendChild(lbl);
                li.appendChild(edit);
            }

            function enterEdit() {
                cancelActive();
                _active = { li: li, restore: renderView };

                li.innerHTML = '';
                li.style.display = 'block';

                var wrap = document.createElement('div');
                wrap.style.cssText = 'display:flex;flex-direction:column;gap:4px;padding:2px 0;';
                var row = document.createElement('div');
                row.style.cssText = 'display:flex;gap:4px;align-items:center;';

                var en = document.createElement('input');
                en.type = 'text'; en.value = item.name_en || ''; en.placeholder = 'English *';
                en.style.cssText = 'flex:1;min-width:0;border:1px solid #d1d5db;border-radius:4px;padding:3px 6px;font-size:12px;';

                var ko = document.createElement('input');
                ko.type = 'text'; ko.value = item.name_ko || ''; ko.placeholder = '한글';
                ko.style.cssText = 'flex:1;min-width:0;border:1px solid #d1d5db;border-radius:4px;padding:3px 6px;font-size:12px;';

                var save = document.createElement('button');
                save.type = 'button'; save.innerHTML = '<i class="fas fa-check"></i>';
                save.style.cssText = 'flex:0 0 auto;background:#0d9488;color:#fff;border-radius:4px;padding:3px 8px;font-size:11px;cursor:pointer;';

                var cancel = document.createElement('button');
                cancel.type = 'button'; cancel.innerHTML = '<i class="fas fa-times"></i>';
                cancel.style.cssText = 'flex:0 0 auto;background:#e5e7eb;color:#374151;border-radius:4px;padding:3px 8px;font-size:11px;cursor:pointer;';

                var err = document.createElement('div');
                err.style.cssText = 'color:#dc2626;font-size:11px;display:none;';

                // 편집 폼 내부 클릭/포커스는 li 선택·드롭다운 닫힘으로 번지지 않도록 차단
                [wrap, row, en, ko, save, cancel].forEach(function (el) {
                    el.addEventListener('mousedown', function (e) { e.stopPropagation(); });
                    el.addEventListener('click',     function (e) { e.stopPropagation(); });
                });
                function onKey(e) {
                    if (e.key === 'Enter') { e.preventDefault(); doSave(); }
                    else if (e.key === 'Escape') { e.preventDefault(); cancelActive(); }
                }
                en.addEventListener('keydown', onKey);
                ko.addEventListener('keydown', onKey);
                cancel.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); cancelActive(); });
                save.addEventListener('click',   function (e) { e.preventDefault(); e.stopPropagation(); doSave(); });

                row.appendChild(en); row.appendChild(ko); row.appendChild(save); row.appendChild(cancel);
                wrap.appendChild(row); wrap.appendChild(err);
                li.appendChild(wrap);
                setTimeout(function () { en.focus(); en.select(); }, 0);

                function doSave() {
                    var ne = en.value.trim(), nk = ko.value.trim();
                    // Plan SC-3: 영문 필수
                    if (!ne) { err.textContent = 'English name is required.'; err.style.display = 'block'; en.focus(); return; }
                    save.disabled = true; save.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

                    var fd = new FormData();
                    fd.append('csrf_token', csrf);
                    fd.append('type', type);
                    fd.append('id', item.id);
                    fd.append('name_en', ne);
                    fd.append('name_ko', nk);

                    fetch(LC_BASE + '/ajax/quick_update.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (!data.success) {
                                err.textContent = data.message || 'Failed to save.';
                                err.style.display = 'block';
                                save.disabled = false; save.innerHTML = '<i class="fas fa-check"></i>';
                                return;
                            }
                            // Plan SC-1: 로컬 데이터 갱신
                            item.name_en = data.name_en;
                            item.name_ko = data.name_ko;
                            _active = null;
                            // Plan SC-2: 위젯이 라벨/검색칸 동기화 + 재렌더
                            onSaved(item);
                        })
                        .catch(function () {
                            err.textContent = 'Network error. Please try again.';
                            err.style.display = 'block';
                            save.disabled = false; save.innerHTML = '<i class="fas fa-check"></i>';
                        });
                }
            }
        }
    };
})();
</script>
