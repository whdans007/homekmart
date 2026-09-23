<?php
$page_title = '재고조사';
require_once __DIR__ . '/lib/auth.php';
kw_require_staff();
require_once __DIR__ . '/partials/header.php';
?>
<div class="max-w-5xl mx-auto space-y-5">
  <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-xl font-bold text-gray-900">재고조사</h1><p class="text-sm text-gray-500">입출고가 마감된 상태에서 박스와 낱개를 각각 조사합니다. PACK은 박스로 집계합니다.</p></div><a class="text-sm text-teal-700 font-semibold" href="<?php echo LC_BASE; ?>/stock_count_list.php">재고조사 리스트 →</a></div>
  <div id="notice" role="status" class="hidden rounded-lg px-4 py-3 text-sm"></div>
  <div class="bg-white border rounded-xl p-5 flex flex-wrap items-center justify-between gap-3"><div><p class="text-xs text-gray-500">현재 조사 건</p><p id="sessionStatus" class="font-semibold text-gray-800">확인 중...</p></div><div id="startControls" class="hidden space-y-2"><label class="flex items-start gap-2 text-sm text-gray-700"><input id="inboundClosedAck" type="checkbox" class="mt-1"><span>입고 작업이 마감되었고, 재고조사 중 입고·출고를 진행하지 않겠습니다.</span></label><button id="startButton" type="button" disabled class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 disabled:cursor-not-allowed">재고조사 시작</button></div></div>
  <section id="entryPanel" class="hidden bg-white border rounded-xl p-5 space-y-4">
    <h2 class="font-bold">수량 입력</h2>
    <div class="flex gap-5"><label class="flex items-center gap-2"><input type="radio" name="unit" value="BOX" checked> 박스 (BOX/PACK)</label><label class="flex items-center gap-2"><input type="radio" name="unit" value="PCS"> 낱개 (PCS)</label></div>
    <div class="relative">
      <form id="lookupForm" class="flex gap-2"><input id="barcode" class="flex-1 min-w-0 border rounded-lg px-3 py-2" placeholder="상품명 또는 바코드를 입력하세요" autocomplete="off" required aria-autocomplete="list" aria-controls="liveSearchResults" aria-expanded="false"><button class="bg-slate-700 text-white rounded-lg px-4 py-2">조회</button></form>
      <div id="liveSearchResults" role="listbox" class="hidden absolute z-30 left-0 right-16 mt-1 max-h-80 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg"></div>
      <p class="mt-1 text-xs text-gray-500">상품명이나 바코드 일부를 입력하면 실시간으로 검색됩니다.</p>
    </div>
    <div id="productPanel" class="hidden rounded-lg border border-teal-200 bg-teal-50 p-4"><p id="productSku" class="text-xs font-mono text-gray-500"></p><p id="productNameKo" class="mt-1 font-semibold text-gray-900"></p><p id="productNameEn" class="text-sm text-gray-600"></p></div>
    <form id="saveForm" class="hidden flex flex-wrap items-end gap-3"><label class="text-sm font-medium">조사 수량<input id="quantity" type="number" min="0" step="1" required class="block border rounded-lg px-3 py-2 w-36 mt-1"></label><button id="saveButton" class="bg-teal-600 text-white rounded-lg px-5 py-2 font-semibold">저장</button><span class="text-xs text-gray-500">같은 상품을 다시 입력하면 수량이 합산됩니다. 0개도 저장할 수 있습니다.</span></form>
  </section>
  <section class="bg-white border rounded-xl p-5"><div class="flex justify-between items-center mb-3"><h2 class="font-bold">입력 내역</h2><button id="refreshButton" type="button" class="text-sm text-teal-700">새로고침</button></div><div id="lines" class="text-sm text-gray-500">조사 건을 확인 중입니다.</div></section>
</div>
<script>
(() => {
const api = '<?php echo LC_BASE; ?>/ajax/stock_count.php';
const productSearchApi = '<?php echo LC_BASE; ?>/ajax/search_product_by_barcode.php';
const csrf = <?php echo json_encode(kw_csrf_token()); ?>;
const isAdmin = <?php echo kw_is_admin() ? 'true' : 'false'; ?>;
const userId = <?php echo kw_current_user_id(); ?>;
let session = null, product = null, searchTimer = null, searchController = null, searchItems = [], searchIndex = -1, searchVersion = 0, composing = false;
const el = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function message(text, error=false) { const n=el('notice'); n.textContent=text; n.className='rounded-lg px-4 py-3 text-sm '+(error?'bg-red-50 text-red-700':'bg-green-50 text-green-800'); }
async function request(action, params={}, post=false) { const body=new URLSearchParams({action,...params,...(post?{csrf_token:csrf}:{})}); const response=await fetch(api+(post?'':'?'+body), post?{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}:{}); const data=await response.json(); if(!response.ok||!data.success) throw Error(data.message||data.error||'요청을 처리하지 못했습니다.'); return data; }
function unit() { return document.querySelector('input[name="unit"]:checked').value; }
function resetProduct() { product=null; el('productPanel').classList.add('hidden'); el('saveForm').classList.add('hidden'); }
function closeSearch() { searchVersion++;if(searchController)searchController.abort();searchController=null;searchItems=[];searchIndex=-1;el('liveSearchResults').classList.add('hidden');el('liveSearchResults').innerHTML='';el('barcode').setAttribute('aria-expanded','false'); }
function productLabel(p) { return [p.name_ko,p.name_en].filter(Boolean).join(' / ') || ('상품번호 '+p.id); }
function searchBarcode(p) { return unit()==='PCS' ? (p.barcode_unit||p.barcode_logistics||p.barcode_box||'') : (p.barcode_box||p.barcode_logistics||p.barcode_unit||''); }
function renderSearch(items) {
  const selectedId=searchIndex>=0&&searchItems[searchIndex]?String(searchItems[searchIndex].id):null;
  searchItems=items;searchIndex=selectedId===null?-1:items.findIndex(p=>String(p.id)===selectedId);
  if(!items.length){el('liveSearchResults').innerHTML='<p class="px-3 py-3 text-sm text-gray-500">검색 결과가 없습니다.</p>';}
  else el('liveSearchResults').innerHTML=items.map((p,i)=>'<button type="button" role="option" aria-selected="'+(i===searchIndex?'true':'false')+'" data-search-index="'+i+'" class="block w-full border-b border-gray-100 px-3 py-2 text-left hover:bg-teal-50 focus:bg-teal-50 focus:outline-none '+(i===searchIndex?'bg-teal-50':'')+'"><span class="block text-xs font-mono text-gray-500">SKU '+esc(searchBarcode(p)||'-')+'</span><span class="block text-sm font-semibold text-gray-800">'+esc(p.name_ko||'-')+'</span><span class="block text-xs text-gray-500">'+esc(p.name_en||'-')+'</span></button>').join('');
  el('liveSearchResults').classList.remove('hidden');el('barcode').setAttribute('aria-expanded','true');
}
async function liveSearch(query) {
  if(searchController)searchController.abort();const controller=new AbortController();searchController=controller;const version=++searchVersion;
  try{const response=await fetch(productSearchApi+'?barcode='+encodeURIComponent(query),{signal:controller.signal});const data=await response.json();if(version!==searchVersion||query!==el('barcode').value.trim())return;renderSearch(data.success?(data.products||[]):[]);}catch(e){if(e.name!=='AbortError'&&version===searchVersion)closeSearch();}finally{if(searchController===controller)searchController=null;}
}
function scheduleSearch() { clearTimeout(searchTimer);if(searchController)searchController.abort();searchVersion++;const q=el('barcode').value.trim();if(q.length<2){closeSearch();return;}searchTimer=setTimeout(()=>liveSearch(q),250); }
async function selectSearchProduct(p) {
  const barcode=searchBarcode(p);closeSearch();
  if(!barcode){message('선택한 상품에 사용할 수 있는 바코드가 없습니다.',true);return;}
  el('barcode').value=barcode;await lookupProduct(barcode);
}
async function lookupProduct(barcode) {
  resetProduct();closeSearch();
  try{const result=await request('lookup',{barcode,unit:unit()});product=result.product;el('productSku').textContent='SKU '+barcode;el('productNameKo').textContent=product.name_ko||'-';el('productNameEn').textContent=product.name_en||'-';el('productPanel').classList.remove('hidden');el('saveForm').classList.remove('hidden');el('quantity').focus();}catch(e){message(e.message,true);}
}
function renderEntries(dates) {const entries=dates.flatMap(group=>group.entries||[]).filter(entry=>!entry.voided_at).reverse();el('lines').innerHTML=entries.length?'<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2">SKU</th><th>상품명</th><th class="text-right">수량</th><th class="text-right">관리</th></tr></thead><tbody>'+entries.map(entry=>{const canEdit=isAdmin||Number(entry.entered_by)===userId;return '<tr class="border-b"><td class="py-3 pr-3 font-mono text-xs text-gray-500">'+esc(entry.sku||entry.barcode||'-')+'</td><td class="py-3"><span class="block font-semibold text-gray-900">'+esc(entry.product_name_ko||'-')+'</span><span class="block text-xs text-gray-500">'+esc(entry.product_name_en||'-')+'</span></td><td class="py-3 text-right font-bold text-teal-700">'+esc(entry.quantity)+' '+(entry.unit==='PCS'?'낱개':'박스')+'</td><td class="py-3 text-right">'+(canEdit?'<button type="button" data-correct-entry="'+esc(entry.id)+'" data-current-quantity="'+esc(entry.quantity)+'" class="mr-3 text-blue-700 underline">수정</button><button type="button" data-delete-entry="'+esc(entry.id)+'" class="text-red-700 underline">삭제</button>':'-')+'</td></tr>';}).join('')+'</tbody></table></div>':'아직 저장한 조사 수량이 없습니다.'; }
async function refresh() { try { const result=await request('status'); session=result.session; const active=session&&session.status==='open'; el('sessionStatus').textContent=session?'#'+session.id+' · '+(active?'진행 중':'종료됨'):'진행 중인 조사 건이 없습니다.'; el('startControls').classList.toggle('hidden',!!session||!isAdmin); el('entryPanel').classList.toggle('hidden',!active); if(active){const list=await request('list',{session_id:session.id,mine:'1'});renderEntries(list.dates||[]);}else renderEntries([]); } catch(e){message(e.message,true);} }
el('inboundClosedAck').onchange=()=>{el('startButton').disabled=!el('inboundClosedAck').checked;};
el('startButton').onclick=async()=>{ if(!el('inboundClosedAck').checked)return; el('startButton').disabled=true; try { await request('start',{inbound_closed_ack:'1'},true); message('재고조사를 시작했습니다.'); await refresh(); el('barcode').focus(); }catch(e){message(e.message,true);}finally{el('startButton').disabled=!el('inboundClosedAck').checked;} };
document.querySelectorAll('input[name="unit"]').forEach(input=>input.onchange=()=>{resetProduct();closeSearch();const q=el('barcode').value.trim();if(q.length>=2)liveSearch(q);el('barcode').focus();});
el('barcode').addEventListener('compositionstart',()=>{composing=true;});
el('barcode').addEventListener('compositionend',()=>{composing=false;resetProduct();scheduleSearch();});
el('barcode').addEventListener('input',()=>{resetProduct();scheduleSearch();});
el('barcode').addEventListener('keydown',event=>{if(composing||event.isComposing||el('liveSearchResults').classList.contains('hidden')||!searchItems.length)return;if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();searchIndex=(searchIndex+(event.key==='ArrowDown'?1:-1)+searchItems.length)%searchItems.length;el('liveSearchResults').querySelectorAll('[data-search-index]').forEach((node,i)=>{const selected=i===searchIndex;node.classList.toggle('bg-teal-50',selected);node.setAttribute('aria-selected',selected?'true':'false');});el('liveSearchResults').querySelector('[data-search-index="'+searchIndex+'"]').scrollIntoView({block:'nearest'});}else if(event.key==='Enter'&&searchIndex>=0){event.preventDefault();selectSearchProduct(searchItems[searchIndex]);}else if(event.key==='Escape')closeSearch();});
el('liveSearchResults').addEventListener('click',event=>{const button=event.target.closest('[data-search-index]');if(button)selectSearchProduct(searchItems[Number(button.dataset.searchIndex)]);});
document.addEventListener('click',event=>{if(!event.target.closest('#lookupForm')&&!event.target.closest('#liveSearchResults'))closeSearch();});
el('lookupForm').onsubmit=async event=>{event.preventDefault();const query=el('barcode').value.trim();if(searchIndex>=0&&searchItems[searchIndex]){await selectSearchProduct(searchItems[searchIndex]);return;}const exact=searchItems.find(p=>[p.barcode_unit,p.barcode_box,p.barcode_logistics].includes(query));if(exact){await selectSearchProduct(exact);return;}if(searchItems.length===1){await selectSearchProduct(searchItems[0]);return;}if(searchItems.length>1){message('검색 결과에서 상품을 선택하세요.',true);return;}await lookupProduct(query);};
el('saveForm').onsubmit=async event=>{event.preventDefault();if(!session||!product)return;const quantity=el('quantity').value;if(!/^\d+$/.test(quantity)){message('0 이상의 정수를 입력하세요.',true);return;}el('saveButton').disabled=true;try{await request('save',{session_id:session.id,product_id:product.id,barcode:el('barcode').value.trim(),unit:unit(),quantity},true);message('조사 수량을 저장했습니다.');el('quantity').value='';resetProduct();el('barcode').value='';await refresh();el('barcode').focus();}catch(e){message(e.message,true);}finally{el('saveButton').disabled=false;}};
el('refreshButton').onclick=refresh;refresh();
el('lines').addEventListener('click',async event=>{if(!session||session.status!=='open')return;const correctButton=event.target.closest('[data-correct-entry]');if(correctButton){const current=correctButton.dataset.currentQuantity;const value=prompt('수정할 조사 수량을 입력하세요.',current);if(value===null)return;if(!/^\d+$/.test(value)){message('0 이상의 정수를 입력하세요.',true);return;}if(value===current){message('기존 수량과 다른 값을 입력하세요.',true);return;}correctButton.disabled=true;try{await request('correct_entry',{session_id:session.id,entry_id:correctButton.dataset.correctEntry,quantity:value},true);message('입력 수량을 수정했습니다.');await refresh();}catch(e){message(e.message,true);correctButton.disabled=false;}return;}const deleteButton=event.target.closest('[data-delete-entry]');if(!deleteButton)return;if(!confirm('이 입력 내용을 삭제하시겠습니까? 최종 조사 합계에서 제외됩니다.'))return;deleteButton.disabled=true;try{await request('void_entry',{session_id:session.id,entry_id:deleteButton.dataset.deleteEntry},true);message('입력 내용을 삭제했습니다.');await refresh();}catch(e){message(e.message,true);deleteButton.disabled=false;}});
})();
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
