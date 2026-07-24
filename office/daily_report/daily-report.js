// Design Ref: daily-report.design.md §5.3/§11.2 step 4 — 기타지출 드래그앤드롭 (Sortable.js)
// DOM이 단일 진실 공급원: 매 onAdd 후 전체 존을 다시 훑어 합계/미분류 배지를 재계산한다.
(function () {
  const board = document.getElementById('dr-board');
  if (!board) return;

  const DATE     = board.dataset.date;
  const STORE_ID = board.dataset.storeId;
  const SOURCE_KEY = '__source__';

  function fmt2(n) {
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function buildCard(item) {
    const el = document.createElement('div');
    el.className = 'dr-card';
    el.draggable = true;
    el.dataset.itemId = item.source_item_id;
    el.dataset.amount = item.amount;
    const label = [item.supplier, item.details].filter(Boolean).join(' — ') || '(내역 없음)';
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
        badge.textContent = '미분류 ' + unplacedCount + '건';
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

  document.querySelectorAll('.dr-zone').forEach(initSortable);

  // ── 초기 로드 ──────────────────────────────────────────
  const qs = new URLSearchParams({ date: DATE });
  if (STORE_ID) qs.set('store_id', STORE_ID);

  fetch('ajax_load_expense_items.php?' + qs.toString())
    .then((r) => r.json())
    .then((res) => {
      if (!res.success) {
        board.innerHTML = '<div class="text-sm text-red-600 p-3">기타지출 데이터를 불러오지 못했습니다.</div>';
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
      board.innerHTML = '<div class="text-sm text-red-600 p-3">기타지출 데이터를 불러오지 못했습니다.</div>';
    });
})();
