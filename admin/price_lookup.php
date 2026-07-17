<?php
/**
 * 전 점포 가격 조회 (All-Store Price Lookup)
 * SKU/상품명으로 상품을 검색하면 전 점포의 원가/판매가를 한 화면에 표시합니다.
 * 시스템 관리(super_admin) 메뉴에서 진입.
 */
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';

ensure_logged_in();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('company.name'); ?> - 전 점포 가격 조회</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
          crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body {
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 50%, #0a0a0a 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #f1f5f9;
        }
        .wrap { max-width: 880px; margin: 0 auto; padding: 2.5rem 1rem 4rem; }
        .page-title { font-size: 1.75rem; font-weight: 800; color: #fff; letter-spacing: 0.04em; }
        .page-subtitle { font-size: 0.85rem; color: rgba(255,255,255,0.55); letter-spacing: 0.1em; text-transform: uppercase; }
        .panel {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 1rem;
            padding: 1.25rem 1.25rem;
            backdrop-filter: blur(8px);
        }
        .search-box { position: relative; }
        .search-input {
            background: #0f172a; border: 1px solid #334155; color: #f1f5f9;
            border-radius: 0.6rem; padding: 0.75rem 1rem; font-size: 1rem; width: 100%;
        }
        .search-input:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 2px rgba(14,165,233,0.25); }
        .suggest {
            position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 30;
            background: #0f172a; border: 1px solid #334155; border-radius: 0.6rem;
            max-height: 320px; overflow-y: auto; display: none;
        }
        .suggest-item { padding: 0.55rem 0.85rem; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .suggest-item:last-child { border-bottom: none; }
        .suggest-item:hover, .suggest-item.active { background: rgba(14,165,233,0.18); }
        .suggest-sku { color: #38bdf8; font-weight: 700; font-size: 0.8rem; }
        .suggest-name { color: #e2e8f0; font-size: 0.9rem; }
        .product-head { border-bottom: 1px solid rgba(255,255,255,0.12); padding-bottom: 0.85rem; margin-bottom: 0.85rem; }
        .prod-sku { color: #38bdf8; font-weight: 800; font-size: 1.05rem; }
        .prod-name { color: #fff; font-size: 1.1rem; font-weight: 700; }
        .prod-name-en { color: rgba(255,255,255,0.6); font-size: 0.9rem; }
        table.price-table { width: 100%; border-collapse: collapse; }
        table.price-table th, table.price-table td { padding: 0.6rem 0.8rem; text-align: right; }
        table.price-table th:first-child, table.price-table td:first-child { text-align: left; }
        table.price-table thead th {
            color: rgba(255,255,255,0.6); font-size: 0.78rem; text-transform: uppercase;
            letter-spacing: 0.05em; border-bottom: 1px solid rgba(255,255,255,0.18);
        }
        table.price-table tbody td { border-bottom: 1px solid rgba(255,255,255,0.07); font-size: 0.95rem; }
        table.price-table tbody tr:hover { background: rgba(255,255,255,0.04); }
        .store-name { color: #f1f5f9; font-weight: 600; }
        .cost-val { color: #fbbf24; font-variant-numeric: tabular-nums; }
        .whole-val { color: #a78bfa; font-weight: 600; font-variant-numeric: tabular-nums; }
        .sell-val { color: #4ade80; font-weight: 700; font-variant-numeric: tabular-nums; }
        .muted { color: rgba(255,255,255,0.35); }
        .badge-noinv { background: rgba(148,163,184,0.18); color: #94a3b8; font-size: 0.7rem; padding: 0.1rem 0.45rem; border-radius: 0.35rem; }
        .msg { padding: 1rem; text-align: center; color: rgba(255,255,255,0.55); }
        .back-link { color: rgba(255,255,255,0.6); font-size: 0.9rem; text-decoration: none; }
        .back-link:hover { color: #fff; }
        .main-btn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            background: rgba(14,165,233,0.14); border: 1px solid rgba(14,165,233,0.35);
            color: #7dd3fc; font-size: 0.85rem; font-weight: 600; text-decoration: none;
            padding: 0.4rem 0.85rem; border-radius: 0.5rem; transition: all 0.15s ease;
        }
        .main-btn:hover { background: rgba(14,165,233,0.28); color: #fff; }
        .pull-btn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            background: rgba(34,197,94,0.14); border: 1px solid rgba(34,197,94,0.35);
            color: #86efac; font-size: 0.85rem; font-weight: 600; text-decoration: none;
            padding: 0.4rem 0.85rem; border-radius: 0.5rem; transition: all 0.15s ease;
        }
        .pull-btn:hover { background: rgba(34,197,94,0.28); color: #fff; }
        .summary-chip {
            display:inline-block; background: rgba(14,165,233,0.12); border:1px solid rgba(14,165,233,0.3);
            color:#7dd3fc; font-size:0.78rem; padding:0.25rem 0.6rem; border-radius:0.4rem; margin-left:0.4rem;
        }
        .logi-title {
            font-size: 0.95rem; font-weight: 700; color: #fff;
            border-bottom: 1px solid rgba(255,255,255,0.12); padding-bottom: 0.6rem; margin-bottom: 0.75rem;
        }
        .logi-title i { color: #fb923c; margin-right: 0.4rem; }
        .logi-name-ko { font-size: 1.05rem; font-weight: 700; color: #fff; }
        .logi-capacity { color: rgba(255,255,255,0.5); font-weight: 500; font-size: 0.85rem; }
        .logi-name-en { color: rgba(255,255,255,0.6); font-size: 0.9rem; margin-top: 0.15rem; }
        .logi-stats { display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .logi-item { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); border-radius: 0.6rem; padding: 0.6rem 0.75rem; min-width: 7rem; }
        .logi-label { font-size: 0.7rem; color: rgba(255,255,255,0.45); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem; }
        .logi-value { font-size: 0.95rem; color: #f1f5f9; font-weight: 600; }
        .logi-cost { color: #fbbf24; font-variant-numeric: tabular-nums; }
        .logi-cost.copyable { cursor: pointer; }
        .logi-cost.copyable:hover { text-decoration: underline dotted; }
        .logi-estimated-badge {
            display:inline-block; background: rgba(251,146,60,0.15); border:1px solid rgba(251,146,60,0.4);
            color:#fdba74; font-size:0.68rem; padding:0.1rem 0.4rem; border-radius:0.3rem; margin-left:0.4rem;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="page-title"><i class="fas fa-magnifying-glass-dollar me-2"></i>전 점포 가격 조회</div>
            <div class="page-subtitle">All-Store Price Lookup</div>
        </div>
        <div class="d-flex align-items-center gap-3">
            <a href="pull_store_pricing.php" class="pull-btn"><i class="fas fa-cloud-arrow-down"></i>지점 가격 가져오기</a>
            <a href="/" class="main-btn"><i class="fas fa-globe"></i>MAIN</a>
            <a href="system_management.php" class="back-link"><i class="fas fa-arrow-left me-1"></i>시스템 관리</a>
        </div>
    </div>

    <div class="panel mb-3" style="position:relative; z-index:50;">
        <div class="search-box">
            <input type="text" id="searchInput" class="search-input" autocomplete="off"
                   placeholder="바코드(SKU) 또는 상품명을 입력하세요…">
            <div id="suggest" class="suggest"></div>
        </div>
        <div class="text-end mt-2" style="font-size:0.8rem;color:rgba(255,255,255,0.4)">
            바코드를 정확히 입력하고 Enter, 또는 목록에서 상품을 선택하세요.
        </div>
    </div>

    <div id="resultPanel" class="panel" style="display:none;">
        <div id="productHead" class="product-head"></div>
        <div id="storeResult"></div>
    </div>

    <div id="logisticsPanel" class="panel mt-3" style="display:none;"></div>

    <div id="emptyMsg" class="msg"><i class="fas fa-store-slash me-1"></i>상품을 검색하면 전 점포의 원가·판매가가 표시됩니다.</div>
</div>

<script>
const $ = (id) => document.getElementById(id);
const searchInput = $('searchInput');
const suggestBox  = $('suggest');
let suggestTimer = null;
let activeIdx = -1;
let suggestItems = [];

function debounce(fn, ms) {
    return (...a) => { clearTimeout(suggestTimer); suggestTimer = setTimeout(() => fn(...a), ms); };
}

const fetchSuggest = debounce(async (q) => {
    if (q.length < 1) { hideSuggest(); return; }
    try {
        const res = await fetch('ajax_all_store_prices.php?action=suggest&q=' + encodeURIComponent(q) + '&limit=15');
        const data = await res.json();
        if (!data.success || !data.products.length) { hideSuggest(); return; }
        suggestItems = data.products;
        renderSuggest(data.products);
    } catch (e) { hideSuggest(); }
}, 180);

function renderSuggest(products) {
    activeIdx = -1;
    suggestBox.innerHTML = products.map((p, i) => `
        <div class="suggest-item" data-idx="${i}" data-pid="${p.product_id}">
            <span class="suggest-sku">${escapeHtml(p.sku)}</span>
            <span class="suggest-name"> · ${escapeHtml(p.name_ko || p.name_en || '')}</span>
        </div>`).join('');
    suggestBox.style.display = 'block';
    suggestBox.querySelectorAll('.suggest-item').forEach(el => {
        el.addEventListener('click', () => {
            const idx = parseInt(el.dataset.idx);
            selectProduct(suggestItems[idx]);
        });
    });
}
function hideSuggest() { suggestBox.style.display = 'none'; activeIdx = -1; }

function selectProduct(p) {
    hideSuggest();
    searchInput.value = p.sku;
    loadStorePrices({ product_id: p.product_id });
}

async function loadStorePrices(params) {
    const qs = new URLSearchParams(params).toString();
    $('emptyMsg').style.display = 'none';
    $('resultPanel').style.display = 'block';
    $('productHead').innerHTML = '<div class="msg"><i class="fas fa-spinner fa-spin me-1"></i>조회 중…</div>';
    $('storeResult').innerHTML = '';
    $('logisticsPanel').style.display = 'none';
    try {
        const res = await fetch('ajax_all_store_prices.php?action=detail&' + qs);
        const data = await res.json();
        if (!data.success) {
            $('productHead').innerHTML = '<div class="msg" style="color:#f87171">' + escapeHtml(data.message || '조회 실패') + '</div>';
            return;
        }
        renderResult(data.product, data.stores);
        renderLogistics(data.logistics);
    } catch (e) {
        $('productHead').innerHTML = '<div class="msg" style="color:#f87171">네트워크 오류: ' + escapeHtml(e.message) + '</div>';
        $('logisticsPanel').style.display = 'none';
    }
}

function renderLogistics(logi) {
    const panel = $('logisticsPanel');
    panel.style.display = 'block';

    if (!logi || !logi.found) {
        panel.innerHTML = `
            <div class="logi-title"><i class="fas fa-warehouse"></i>물류센터 (Logistics Center)</div>
            <div class="msg">상품이 없음</div>`;
        return;
    }

    const estimatedBadge = logi.is_estimated
        ? '<span class="logi-estimated-badge">킴스몰 원가 × 박스포장수량 추정</span>' : '';
    const capacityHtml = logi.capacity ? ` <span class="logi-capacity">(${escapeHtml(logi.capacity)})</span>` : '';

    panel.innerHTML = `
        <div class="logi-title"><i class="fas fa-warehouse"></i>물류센터 (Logistics Center)</div>
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="logi-name-ko">${escapeHtml(logi.name_ko || '-')}${capacityHtml}</div>
                ${logi.name_en ? `<div class="logi-name-en">${escapeHtml(logi.name_en)}</div>` : ''}
            </div>
            <div class="logi-stats">
                <div class="logi-item">
                    <div class="logi-label">박스포장수량</div>
                    <div class="logi-value">${logi.pieces_per_box}</div>
                </div>
                <div class="logi-item">
                    <div class="logi-label">원가${estimatedBadge}</div>
                    <div class="logi-value logi-cost${logi.cost_price_raw > 0 ? ' copyable' : ''}"
                         ${logi.cost_price_raw > 0 ? `title="클릭하여 복사" onclick="copyCostValue(this, '${logi.cost_price_raw}')"` : ''}>${logi.cost_price}</div>
                </div>
            </div>
        </div>`;
    panel.style.display = 'block';
}

function renderResult(product, stores) {
    const withInv = stores.filter(s => s.has_inventory).length;
    $('productHead').innerHTML = `
        <div class="d-flex justify-content-between align-items-start flex-wrap">
            <div>
                <span class="prod-sku">${escapeHtml(product.sku)}</span>
                <span class="summary-chip">점포 ${stores.length}곳 · 가격등록 ${withInv}곳</span>
                <div class="prod-name mt-1">${escapeHtml(product.name_ko || product.name_en || '')}</div>
                ${product.name_en ? `<div class="prod-name-en">${escapeHtml(product.name_en)}</div>` : ''}
            </div>
        </div>`;

    if (!stores.length) {
        $('storeResult').innerHTML = '<div class="msg">등록된 점포가 없습니다.</div>';
        return;
    }

    const rows = stores.map(s => {
        const cost = s.cost_price_raw !== null
            ? `<span class="cost-val">${s.cost_price}</span>`
            : `<span class="muted">-</span>`;
        // 추천 도매가: 원가에서 15% 마진 (원가 × 1.15), 소숫점 이하 올림
        const wholesale = s.cost_price_raw !== null
            ? `<span class="whole-val">${Math.ceil(s.cost_price_raw * 1.15).toLocaleString('en-US')}</span>`
            : `<span class="muted">-</span>`;
        const sell = s.selling_price_raw !== null
            ? `<span class="sell-val">${s.selling_price}</span>`
            : `<span class="muted">-</span>`;
        const qty = s.quantity !== null ? s.quantity.toLocaleString() : '<span class="muted">-</span>';
        const noinv = s.has_inventory ? '' : ' <span class="badge-noinv">미등록</span>';
        return `<tr>
            <td><span class="store-name">${escapeHtml(s.store_name)}</span>${noinv}</td>
            <td>${cost}</td>
            <td>${wholesale}</td>
            <td>${sell}</td>
            <td>${qty}</td>
        </tr>`;
    }).join('');

    $('storeResult').innerHTML = `
        <table class="price-table">
            <thead>
                <tr><th>점포</th><th>원가</th><th>추천 도매가<br>(마진 15%)</th><th>판매가</th><th>재고수량</th></tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>`;
}

// 키보드 네비게이션 + 엔터 검색
searchInput.addEventListener('input', (e) => fetchSuggest(e.target.value.trim()));
searchInput.addEventListener('keydown', (e) => {
    const items = suggestBox.querySelectorAll('.suggest-item');
    if (suggestBox.style.display === 'block' && items.length) {
        if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, items.length - 1); highlight(items); return; }
        if (e.key === 'ArrowUp')   { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); highlight(items); return; }
        if (e.key === 'Enter' && activeIdx >= 0) { e.preventDefault(); selectProduct(suggestItems[activeIdx]); return; }
    }
    if (e.key === 'Enter') {
        e.preventDefault();
        const v = searchInput.value.trim();
        if (v) { hideSuggest(); loadStorePrices({ barcode: v }); }
    }
    if (e.key === 'Escape') hideSuggest();
});
function highlight(items) {
    items.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
    if (activeIdx >= 0 && items[activeIdx]) items[activeIdx].scrollIntoView({ block: 'nearest' });
}

document.addEventListener('click', (e) => {
    if (!suggestBox.contains(e.target) && e.target !== searchInput) hideSuggest();
});

function copyCostValue(el, value) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(String(value)).then(() => {
        const original = el.textContent;
        el.textContent = '복사됨!';
        setTimeout(() => { el.textContent = original; }, 900);
    });
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

searchInput.focus();
</script>
</body>
</html>
