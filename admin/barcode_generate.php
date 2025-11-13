<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('barcode_generate.page_title');
require_once __DIR__ . '/partials/header.php';
?>

<div class="flex-1 overflow-y-auto">
    <div class="max-w-4xl mx-auto p-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold mb-4"><?php echo t('barcode_generate.page_title'); ?></h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo t('barcode_generate.prefix_label'); ?></label>
                    <input id="prefix" type="text" value="2011223" class="w-full border rounded px-3 py-2" maxlength="7">
                    <p class="text-xs text-gray-500 mt-1"><?php echo t('barcode_generate.prefix_example'); ?></p>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo t('barcode_generate.next_barcode_preview'); ?></label>
                    <div id="preview" class="w-full border rounded px-3 py-2 bg-gray-50">-</div>
                </div>
            </div>

            <div class="flex items-center gap-2 mb-4">
                <button id="btnGenerate" class="btn btn-primary"><?php echo t('barcode_generate.generate_reserve'); ?></button>
                <button id="btnRegister" class="btn btn-success" disabled><?php echo t('barcode_generate.register_product'); ?></button>
            </div>

            <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo t('barcode_generate.generation_result'); ?></label>
                    <input id="result" type="text" class="w-full border rounded px-3 py-2" readonly>
                    <p class="text-xs text-gray-500 mt-1"><?php echo t('barcode_generate.result_help'); ?></p>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo t('barcode_generate.cost_price'); ?></label>
                    <input id="costPrice" type="number" step="0.01" min="0" class="w-full border rounded px-3 py-2" placeholder="0.00">
                    <p class="text-xs text-gray-500 mt-1"><?php echo t('barcode_generate.cost_price_help'); ?></p>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo t('barcode_generate.selling_price'); ?></label>
                    <input id="sellingPrice" type="number" step="0.01" min="0" class="w-full border rounded px-3 py-2" placeholder="0.00">
                    <p class="text-xs text-gray-500 mt-1"><?php echo t('barcode_generate.selling_price_help'); ?></p>
                </div>
            </div>

            <div id="msg" class="mt-4 text-sm"></div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mt-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold"><?php echo t('barcode_generate.generated_products_title'); ?></h3>
                <div class="flex items-center gap-2">
                    <select id="listLimit" class="border rounded px-2 py-1 text-sm">
                        <option value="20">20</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                    </select>
                    <button id="btnPrintSelected" class="btn btn-outline-primary btn-sm"><?php echo t('barcode_generate.print_selected_2copies'); ?></button>
                    <button id="btnRefresh" class="btn btn-outline-secondary btn-sm"><?php echo t('barcode_generate.refresh'); ?></button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="width:100px">ID</th>
                            <th style="width:180px"><?php echo t('barcode_generate.barcode_sku'); ?></th>
                            <th><?php echo t('barcode_generate.product_name_en'); ?></th>
                            <th><?php echo t('barcode_generate.product_name_ko'); ?></th>
                            <th style="width:180px"><?php echo t('barcode_generate.brand'); ?></th>
                            <th style="width:120px"><?php echo t('barcode_generate.manage'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="generatedList">
                        <tr><td colspan="6" class="text-center text-muted"><?php echo t('barcode_generate.loading'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// PHP에서 JavaScript로 번역 데이터 전달
const translations = {
    reserved_message: '<?php echo addslashes(t("barcode_generate.reserved_message")); ?>',
    generation_failed: '<?php echo addslashes(t("barcode_generate.generation_failed")); ?>',
    please_generate_first: '<?php echo addslashes(t("barcode_generate.please_generate_first")); ?>',
    please_select_barcode: '<?php echo addslashes(t("barcode_generate.please_select_barcode")); ?>',
    api_response_error: '<?php echo addslashes(t("barcode_generate.api_response_error")); ?>',
    query_failed: '<?php echo addslashes(t("barcode_generate.query_failed")); ?>',
    loading: '<?php echo addslashes(t("barcode_generate.loading")); ?>',
    no_data: '<?php echo addslashes(t("barcode_generate.no_data")); ?>',
    edit: '<?php echo addslashes(t("barcode_generate.edit")); ?>',
    select: '<?php echo addslashes(t("barcode_generate.select")); ?>',
    barcode_sku: '<?php echo addslashes(t("barcode_generate.barcode_sku")); ?>',
    product_name_en: '<?php echo addslashes(t("barcode_generate.product_name_en")); ?>',
    product_name_ko: '<?php echo addslashes(t("barcode_generate.product_name_ko")); ?>',
    selling_price: '<?php echo addslashes(t("barcode_generate.selling_price")); ?>',
    manage: '<?php echo addslashes(t("barcode_generate.manage")); ?>'
};

function computeCheckDigit(base12){
  if(!/^\d{12}$/.test(base12)) return null;
  let sum=0; for(let i=0;i<12;i++){const n=base12.charCodeAt(i)-48; sum += (i%2===0)?n:n*3;}
  return (10 - (sum % 10)) % 10;
}

async function generate(){
  const prefix = document.getElementById('prefix').value.replace(/\D/g,'');
  document.getElementById('msg').textContent='';
  try{
    const resp = await fetch('ajax_reserve_barcode.php',{
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({prefix})
    });
    const data = await resp.json();
    if(!data.ok){ throw new Error(data.error||translations.generation_failed); }
    document.getElementById('result').value = data.barcode;
    document.getElementById('preview').textContent = data.barcode;
    const message = translations.reserved_message
      .replace('{prefix}', data.prefix)
      .replace('{serial}', String(data.serial).padStart(5,'0'))
      .replace('{checkDigit}', data.checkDigit);
    document.getElementById('msg').textContent = message;
    document.getElementById('msg').className='mt-4 text-sm text-green-700';
    document.getElementById('btnRegister').disabled = false;
  }catch(e){
    document.getElementById('msg').textContent = e.message;
    document.getElementById('msg').className='mt-4 text-sm text-red-700';
    document.getElementById('btnRegister').disabled = true;
  }
}

document.getElementById('btnGenerate').addEventListener('click', generate);
document.getElementById('btnRegister').addEventListener('click', ()=>{
  const code = document.getElementById('result').value.trim();
  if(!code){ alert(translations.please_generate_first); return; }

  const costPrice = document.getElementById('costPrice').value.trim();
  const sellingPrice = document.getElementById('sellingPrice').value.trim();
  const storeId = '<?php echo $current_store_id ?? ''; ?>';

  let url = `add_product.php?sku=${encodeURIComponent(code)}`;
  if(costPrice) url += `&cost_price=${encodeURIComponent(costPrice)}`;
  if(sellingPrice) url += `&selling_price=${encodeURIComponent(sellingPrice)}`;
  if(storeId) url += `&store_id=${encodeURIComponent(storeId)}`;

  window.location.href = url;
});

async function loadGeneratedList(){
  const prefix = document.getElementById('prefix').value.replace(/\D/g,'');
  const limit = document.getElementById('listLimit').value;
  const tbody = document.getElementById('generatedList');
  tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted">${translations.loading}</td></tr>`;
  try{
    const res = await fetch(`ajax_list_generated_barcodes.php?prefix=${encodeURIComponent(prefix)}&limit=${encodeURIComponent(limit)}`);
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch(parseErr){
      throw new Error(translations.api_response_error.replace('{error}', text.slice(0,200)));
    }
    if(!data.ok) throw new Error(data.error||translations.query_failed);
    if(!data.items || data.items.length===0){
      tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted">${translations.no_data}</td></tr>`;
      return;
    }
    tbody.innerHTML = data.items.map(row=>{
      const nameEn = row.name_en ? row.name_en : '';
      const nameKo = row.name_ko ? row.name_ko : '';
      const brand = row.brand_name ? row.brand_name : '';
      return `<tr>
        <td>${row.id}</td>
        <td><code>${row.sku}</code></td>
        <td>${escapeHtml(nameEn)}</td>
        <td>${escapeHtml(nameKo)}</td>
        <td>${escapeHtml(brand)}</td>
        <td><a class="btn btn-sm btn-outline-primary" href="edit_product.php?id=${row.id}">${translations.edit}</a></td>
      </tr>`;
    }).join('');
  }catch(e){
    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">${e.message}</td></tr>`;
  }
}

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
}

document.getElementById('btnRefresh').addEventListener('click', loadGeneratedList);
document.getElementById('prefix').addEventListener('change', loadGeneratedList);
document.getElementById('listLimit').addEventListener('change', loadGeneratedList);

// 초기 로딩
loadGeneratedList();
</script>

<!-- Barcode Preview Modal -->
<div id="barcodeModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000;">
  <div id="barcodeModalDialog" style="position:absolute; top:5%; left:50%; transform:translateX(-50%); width:95%; max-width:1200px; height:90%; background:#fff; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.3); overflow:hidden;">
    <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #e5e7eb;">
      <strong><?php echo t('barcode_generate.barcode_preview_title'); ?></strong>
      <button id="barcodeModalClose" type="button" style="border:none; background:#f3f4f6; padding:6px 10px; border-radius:6px; cursor:pointer;"><?php echo t('barcode_generate.close'); ?></button>
    </div>
    <iframe id="barcodePreviewFrame" src="about:blank" style="width:100%; height:calc(100% - 46px); border:0;"></iframe>
  </div>
  <style>
    @media (max-width: 768px){
      #barcodeModalDialog { top:3%; height:94%; width:98%; }
    }
  </style>
</div>

<script>
  (function(){
    const modal = document.getElementById('barcodeModal');
    const dialog = document.getElementById('barcodeModalDialog');
    const iframe = document.getElementById('barcodePreviewFrame');
    const closeBtn = document.getElementById('barcodeModalClose');

    function openPreview(url){
      iframe.src = url;
      modal.style.display = 'block';
      document.body.style.overflow = 'hidden';
    }
    function closePreview(){
      modal.style.display = 'none';
      iframe.src = 'about:blank';
      document.body.style.overflow = '';
    }
    closeBtn.addEventListener('click', closePreview);
    // 모달 바깥(반투명 영역) 클릭 시에만 닫기. 다이얼로그 내부 클릭은 무시
    modal.addEventListener('click', function(e){ if (!dialog.contains(e.target)) closePreview(); });
    window.addEventListener('keydown', function(e){ if(e.key === 'Escape') closePreview(); });

    const btn = document.getElementById('btnPrintSelected');
    if (btn) {
      // 캡처 단계에서 기존 핸들러보다 먼저 가로채서 새 창 열림을 방지
      btn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopImmediatePropagation();
        const selected = Array.from(document.querySelectorAll('input.sku-check:checked')).map(el => el.value).filter(Boolean);
        if (selected.length === 0) { alert(translations.please_select_barcode); return; }
        const url = new URL('barcode_print.php', location.href);
        url.searchParams.set('skus', selected.join(','));
        openPreview(url.toString());
      }, true);
    }
  })();
</script>

<script>
// 선택 컬럼과 출력 버튼 기능을 사후 적용하는 보강 스크립트
(function(){
  const tbody = document.getElementById('generatedList');
  if (!tbody) return;

  function applySelectionColumn(){
    const theadRow = document.querySelector('table thead tr');
    if (theadRow) {
      const ths = Array.from(theadRow.children);
      const needHeader = !(ths.length >= 5 && (ths[0].textContent.includes('선택') || ths[0].textContent.includes('Select')));
      if (needHeader) {
        const skuTh = ths[0];
        const selTh = document.createElement('th');
        selTh.textContent = translations.select;
        selTh.style.width = '80px';
        theadRow.insertBefore(selTh, skuTh);
      }
    }

    Array.from(tbody.querySelectorAll('tr')).forEach(tr => {
      const firstCell = tr.querySelector('td');
      if (!firstCell) return;
      // 이미 선택 체크박스가 있으면 스킵
      if (firstCell.querySelector('input.sku-check')) return;
      // SKU는 다음 셀의 <code> 또는 현재 셀의 <code>
      let sku = '';
      let codeEl = firstCell.querySelector('code');
      if (codeEl && codeEl.textContent) {
        sku = codeEl.textContent.trim();
      } else if (firstCell.nextElementSibling) {
        const c2 = firstCell.nextElementSibling.querySelector('code');
        if (c2) sku = c2.textContent.trim();
      }
      const selTd = document.createElement('td');
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'sku-check';
      cb.value = sku;
      selTd.appendChild(cb);
      tr.insertBefore(selTd, firstCell);
    });
  }

  // 변화 감지하여 매번 적용
  const obs = new MutationObserver(() => applySelectionColumn());
  obs.observe(tbody, { childList: true, subtree: true });
  // 초기 1회 적용
  applySelectionColumn();

  // 인쇄 버튼 동작 연결
  const printBtn = document.getElementById('btnPrintSelected');
  if (printBtn) {
    printBtn.addEventListener('click', function(){
      const selected = Array.from(document.querySelectorAll('input.sku-check:checked')).map(el => el.value).filter(Boolean);
      if (selected.length === 0) { alert(translations.please_select_barcode); return; }
      const url = new URL('barcode_print.php', location.href);
      url.searchParams.set('skus', selected.join(','));
      window.open(url.toString(), '_blank');
    });
  }
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

<script>
// 기존 리스트 렌더링을 덮어쓰기: ID/브랜드 제거 + 판매가 열 추가
(function(){
  const theadSel = 'table thead tr';
  const tbodySel = '#generatedList';

  window.loadGeneratedList = async function(){
    const prefix = document.getElementById('prefix').value.replace(/\D/g,'');
    const limit = document.getElementById('listLimit').value;
    const tbody = document.querySelector(tbodySel);
    if (!tbody) return;

    // 헤더 구성: SKU, EN, KO, 판매가, 관리
    const theadRow = document.querySelector(theadSel);
    if (theadRow) {
      const headers = [translations.barcode_sku, translations.product_name_en, translations.product_name_ko, translations.selling_price, translations.manage];
      theadRow.innerHTML = headers.map((h,i)=>`<th${i===0? ' style="width:180px"':''}>${h}</th>`).join('');
    }

    const cols = document.querySelector(theadSel)?.querySelectorAll('th').length || 5;
    tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted">${translations.loading}</td></tr>`;

    try{
      const res = await fetch(`ajax_list_generated_barcodes.php?prefix=${encodeURIComponent(prefix)}&limit=${encodeURIComponent(limit)}`);
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); } catch(e) { throw new Error(translations.api_response_error.replace('{error}', text.slice(0,200))); }
      if(!data.ok) throw new Error(data.error||translations.query_failed);

      const items = Array.isArray(data.items) ? data.items : [];
      if(items.length === 0){
        tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted">${translations.no_data}</td></tr>`;
        return;
      }

      const rowsHtml = items.map(row => {
        const nameEn = row.name_en ? row.name_en : '';
        const nameKo = row.name_ko ? row.name_ko : '';
        const price = (row.selling_price===null||row.selling_price===undefined||row.selling_price==='') ? '-' : Number(row.selling_price).toLocaleString();
        return `<tr>
          <td><code>${row.sku}</code></td>
          <td>${escapeHtml(nameEn)}</td>
          <td>${escapeHtml(nameKo)}</td>
          <td>${price}</td>
          <td><a class="btn btn-sm btn-outline-primary" href="edit_product.php?id=${row.id}">${translations.edit}</a></td>
        </tr>`;
      }).join('');

      tbody.innerHTML = rowsHtml;
    }catch(err){
      tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-danger">${escapeHtml(String(err.message || err))}</td></tr>`;
    }
  };
  // 기존 이벤트 핸들러 덮어쓰기 및 즉시 1회 렌더링
  try { document.getElementById('btnRefresh').onclick = window.loadGeneratedList; } catch(e) {}
  try { document.getElementById('prefix').onchange = window.loadGeneratedList; } catch(e) {}
  try { document.getElementById('listLimit').onchange = window.loadGeneratedList; } catch(e) {}
  try { window.loadGeneratedList(); } catch(e) {}
})();
</script>
