// Design Ref: daily-report.design.md §5.3/§11.2 step 4 — 기타지출 드래그앤드롭 (Sortable.js)
// DOM이 단일 진실 공급원: 매 onAdd 후 전체 존을 다시 훑어 합계/미분류 배지를 재계산한다.
(function () {
  const board = document.getElementById('dr-board');
  if (!board) return;

  const DATE     = board.dataset.date;
  const STORE_ID = board.dataset.storeId;
  const SOURCE_KEY = '__source__';

  // ── 수수료 코너: 업체 등록/삭제 ──────────────────────────────
  // 아래 드래그앤드롭(Sortable.js, 외부 CDN) 초기화가 실패해도 이 핸들러는 영향받지 않도록 최상단에서 먼저 연결한다.
  const commissionForm = document.getElementById('dr-commission-form');
  if (commissionForm) {
    commissionForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const input = document.getElementById('dr-commission-input');
      const name = input.value.trim();
      if (!name) return;
      const fd = new FormData();
      fd.append('action', 'add');
      fd.append('supplier_name', name);
      if (STORE_ID) fd.append('store_id', STORE_ID);
      fetch('ajax_commission_company.php', { method: 'POST', body: fd })
        .then((r) => r.json())
        .then((res) => {
          if (res.success) location.reload();
          else alert(res.error || t('daily_report.register_failed'));
        })
        .catch(() => alert(t('daily_report.register_failed')));
    });
  }

  document.querySelectorAll('.dr-commission-del').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!confirm(t('daily_report.confirm_delete_company'))) return;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', btn.dataset.id);
      if (STORE_ID) fd.append('store_id', STORE_ID);
      fetch('ajax_commission_company.php', { method: 'POST', body: fd })
        .then((r) => r.json())
        .then((res) => {
          if (res.success) location.reload();
          else alert(res.error || t('daily_report.delete_failed'));
        })
        .catch(() => alert(t('daily_report.delete_failed')));
    });
  });

  function fmt2(n) {
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function buildCard(item) {
    const el = document.createElement('div');
    el.className = 'dr-card';
    el.draggable = true;
    el.dataset.itemId = item.source_item_id;
    el.dataset.amount = item.amount;
    const label = [item.supplier, item.details].filter(Boolean).join(' — ') || t('daily_report.no_detail');
    el.innerHTML = `<div class="dr-card-label">${escapeHtml(label)}</div><div class="dr-card-amount">₱ ${fmt2(item.amount)}</div>`;
    return el;
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function zoneEl(key) {
    return document.querySelector(`.dr-zone[data-category="${key}"]`);
  }

  function recalcAll() {
    let unplacedCount = 0;
    let totalPlaced = 0;

    document.querySelectorAll('.dr-zone').forEach((zone) => {
      const key = zone.dataset.category;
      let sum = 0;
      zone.querySelectorAll('.dr-card').forEach((card) => { sum += parseFloat(card.dataset.amount) || 0; });
      const totalEl = document.querySelector(`.dr-zone-total[data-category="${key}"]`);
      if (totalEl) totalEl.textContent = '₱ ' + fmt2(sum);
      if (key === SOURCE_KEY) {
        unplacedCount = zone.querySelectorAll('.dr-card').length;
      } else {
        totalPlaced += sum;
      }
    });

    const badge = document.getElementById('dr-unplaced-badge');
    if (badge) {
      if (unplacedCount > 0) {
        badge.textContent = t('daily_report.unclassified_count', { n: unplacedCount });
        badge.classList.remove('hidden');
      } else {
        badge.classList.add('hidden');
      }
    }
    const grandEl = document.getElementById('dr-total-placed');
    if (grandEl) grandEl.textContent = '₱ ' + fmt2(totalPlaced);
  }

  function saveOne(itemId, categoryKey) {
    const body = { date: DATE, store_id: STORE_ID, placements: [], removals: [] };
    if (categoryKey === SOURCE_KEY) {
      body.removals.push(itemId);
    } else {
      body.placements.push({ source_item_id: itemId, category_key: categoryKey });
    }
    fetch('ajax_save_categories.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
      .then((r) => r.json())
      .then((res) => { if (!res.success) console.error('daily_report save failed', res); })
      .catch((err) => console.error('daily_report save error', err));
  }

  function initSortable(container) {
    Sortable.create(container, {
      group: 'dr',
      animation: 150,
      ghostClass: 'dr-ghost',
      onAdd: (evt) => {
        const itemId = evt.item.dataset.itemId;
        const targetKey = evt.to.dataset.category;
        recalcAll();
        saveOne(itemId, targetKey);
      },
    });
  }

  // Sortable.js는 외부 CDN에서 로드됨 — 네트워크 문제로 로드 실패해도 나머지 기능(수수료 코너 등)은 정상 동작해야 함
  if (typeof Sortable === 'undefined') {
    console.error('Sortable.js를 불러오지 못했습니다. 기타지출 드래그앤드롭이 비활성화됩니다.');
  } else {
    document.querySelectorAll('.dr-zone').forEach(initSortable);
  }

  // ── 초기 로드 ──────────────────────────────────────────
  const qs = new URLSearchParams({ date: DATE });
  if (STORE_ID) qs.set('store_id', STORE_ID);

  fetch('ajax_load_expense_items.php?' + qs.toString())
    .then((r) => r.json())
    .then((res) => {
      if (!res.success) {
        board.innerHTML = '<div class="text-sm text-red-600 p-3">' + t('daily_report.expense_load_failed') + '</div>';
        return;
      }
      res.items.forEach((item) => {
        const key = item.category_key || SOURCE_KEY;
        const zone = zoneEl(key);
        if (zone) zone.appendChild(buildCard(item));
      });
      recalcAll();
    })
    .catch(() => {
      board.innerHTML = '<div class="text-sm text-red-600 p-3">' + t('daily_report.expense_load_failed') + '</div>';
    });
})();
