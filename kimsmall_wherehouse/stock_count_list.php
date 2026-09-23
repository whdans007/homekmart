<?php
$page_title = '재고조사 리스트';
require_once __DIR__ . '/lib/auth.php';
kw_require_staff();
require_once __DIR__ . '/partials/header.php';
?>
<div class="w-full max-w-none space-y-5 text-left">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div><h1 class="text-xl font-bold">재고조사 리스트</h1><p class="text-sm text-gray-500">상품별 기준 재고와 조사 재고를 비교합니다. 상품을 클릭하면 날짜별 입력 기록을 확인할 수 있습니다.</p></div>
    <a href="<?php echo LC_BASE; ?>/stock_count.php" class="text-sm font-semibold text-teal-700">+ 재고조사 입력</a>
  </div>
  <div id="notice" role="status" class="hidden rounded-lg px-4 py-3 text-sm"></div>
  <section class="bg-white border rounded-xl p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex flex-wrap gap-3">
        <div><label for="sessionSelect" class="text-xs text-gray-500">조사 건 선택</label><select id="sessionSelect" class="block border rounded-lg px-3 py-2 mt-1 text-sm min-w-56"><option value="">불러오는 중...</option></select></div>
        <?php if (kw_is_admin()): ?><div><label for="userFilter" class="text-xs text-gray-500">조사자 선택</label><select id="userFilter" class="block border rounded-lg px-3 py-2 mt-1 text-sm min-w-48"><option value="">전체 합산</option></select></div><?php endif; ?>
        <p id="sessionStatus" class="w-full font-semibold mt-1">확인 중...</p>
      </div>
      <div class="flex gap-2"><button id="previewButton" class="hidden bg-teal-600 text-white rounded-lg px-4 py-2 text-sm font-semibold">재고 확정 검토</button><button id="cancelButton" class="hidden border border-red-300 text-red-700 rounded-lg px-4 py-2 text-sm font-semibold">조사 취소</button></div>
    </div>
    <p id="uncounted" class="text-sm text-amber-700 mt-2"></p>
  </section>
  <section class="bg-white border rounded-xl p-5">
    <h2 class="font-bold">상품별 재고조사 결과</h2><p class="text-xs text-gray-500 mt-1 mb-3">상품 행을 클릭하면 해당 상품의 날짜별 입력 기록이 열립니다.</p>
    <div id="summary" class="text-sm text-gray-500">불러오는 중...</div>
  </section>
  <section id="preview" class="hidden border-2 border-teal-300 bg-teal-50 rounded-xl p-5 space-y-3">
    <h2 class="font-bold text-lg">재고 확정 검토</h2><p class="text-sm">전체 조사자의 유효 입력을 합산한 결과입니다. 수량과 차이를 확인한 후 확정하세요.</p>
    <div id="previewSummary"></div><p id="previewUncounted" class="text-sm text-amber-800"></p><button id="finalizeButton" class="bg-red-700 text-white rounded-lg px-5 py-2 font-bold">재고 확정</button>
  </section>
</div>
<div id="historyModal" class="hidden fixed inset-0 z-50 bg-black/50 p-4 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="historyTitle">
  <div class="bg-white rounded-xl shadow-2xl w-full max-w-5xl mx-auto my-8 overflow-hidden">
    <div class="flex items-start justify-between gap-4 border-b px-5 py-4"><div><h2 id="historyTitle" class="font-bold text-lg">상품 입력 기록</h2><p id="historyProduct" class="text-sm text-gray-500 mt-1"></p></div><button id="historyClose" type="button" class="text-gray-500 hover:text-gray-900 p-1" aria-label="닫기"><i class="fas fa-times text-xl"></i></button></div>
    <div id="historyBody" class="p-5 max-h-[70vh] overflow-y-auto"></div>
  </div>
</div>
<script>
(() => {
const api='<?php echo LC_BASE; ?>/ajax/stock_count.php', csrf=<?php echo json_encode(kw_csrf_token()); ?>, isAdmin=<?php echo kw_is_admin() ? 'true' : 'false'; ?>, userId=<?php echo (int)kw_current_user_id(); ?>;
const el=id=>document.getElementById(id), esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let session=null,sessions=[],selectedUserId='',currentLines=[],currentEntries=[];
function message(text,error=false){const n=el('notice');n.textContent=text;n.className='rounded-lg px-4 py-3 text-sm '+(error?'bg-red-50 text-red-700':'bg-green-50 text-green-800');}
async function request(action,params={},post=false){const body=new URLSearchParams({action,...params,...(post?{csrf_token:csrf}:{})});const response=await fetch(api+(post?'':'?'+body),post?{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}:{});const data=await response.json();if(!response.ok||!data.success)throw Error(data.message||data.error||'요청을 처리하지 못했습니다.');return data;}
function delta(value){const n=Number(value)||0;return '<span class="font-semibold '+(n<0?'text-red-700':n>0?'text-blue-700':'text-gray-500')+'">'+(n>0?'+':'')+esc(n)+'</span>';}
function statusLabel(s){return s==='finalized'?'확정 완료':s==='cancelled'?'취소됨':'진행 중';}
function table(lines,clickable=true){
 if(!lines.length)return '<p class="text-sm text-gray-500">조사된 상품이 없습니다.</p>';
 return '<div class="overflow-x-auto"><table class="w-full min-w-[1450px] text-sm text-left border-collapse"><thead class="bg-gray-50 text-gray-700"><tr><th class="p-3">SKU</th><th class="p-3 min-w-[260px]">상품명 한글 / 영문</th><th class="p-3">기준 박스</th><th class="p-3">조사 박스</th><th class="p-3">박스 차이</th><th class="p-3">기준 낱개</th><th class="p-3">조사 낱개</th><th class="p-3">낱개 차이</th><th class="p-3 whitespace-nowrap">총 낱개 기준 / 조사</th><th class="p-3">총 차이</th></tr></thead><tbody>'+
 lines.map(l=>'<tr class="border-b '+(clickable?'cursor-pointer hover:bg-teal-50 focus:bg-teal-50':'')+'" '+(clickable?'data-product-id="'+esc(l.product_id)+'" tabindex="0" role="button"':'')+'><td class="p-3 whitespace-nowrap font-medium">'+esc(l.sku||'-')+'</td><td class="p-3"><span class="block font-semibold text-gray-900">'+esc(l.product_name_ko||'-')+'</span><span class="block text-xs text-gray-500 mt-0.5">'+esc(l.product_name_en||'-')+'</span></td><td class="p-3">'+esc(l.book_box)+'</td><td class="p-3">'+esc(l.count_box)+'</td><td class="p-3">'+delta(l.delta_box)+'</td><td class="p-3">'+esc(l.book_pcs)+'</td><td class="p-3">'+esc(l.count_pcs)+'</td><td class="p-3">'+delta(l.delta_pcs)+'</td><td class="p-3">'+esc(l.book_total_pcs)+' / '+esc(l.count_total_pcs)+'</td><td class="p-3">'+delta(l.delta_total_pcs)+'</td></tr>').join('')+'</tbody></table></div>';
}
function flattenDates(dates){return (dates||[]).flatMap(g=>(g.entries||[]).map(e=>({...e,date:g.date})));}
function entryState(e){
 const voided=!!e.voided_at,canEdit=session?.status==='open'&&!voided&&(isAdmin||Number(e.entered_by)===userId);
 if(voided)return e.void_reason==='correction'?'수정됨':'취소됨';
 if(!canEdit)return e.corrected_from_entry_id?'수정 입력':'유효';
 return (e.corrected_from_entry_id?'<span class="mr-2 text-blue-700">수정 입력</span>':'')+'<button type="button" data-correct-entry="'+esc(e.id)+'" data-current-quantity="'+esc(e.quantity)+'" class="mr-3 text-blue-700 underline">수정</button><button type="button" data-void-entry="'+esc(e.id)+'" class="text-red-700 underline">삭제</button>';
}
function openHistory(productId){
 const line=currentLines.find(x=>Number(x.product_id)===Number(productId)),entries=currentEntries.filter(x=>Number(x.product_id)===Number(productId));if(!line)return;
 el('historyProduct').innerHTML='<b class="text-gray-800">'+esc(line.sku||'-')+'</b> · '+esc(line.product_name_ko||'-')+' / '+esc(line.product_name_en||'-');
 el('historyBody').innerHTML=entries.length?'<div class="overflow-x-auto"><table class="w-full min-w-[760px] text-sm text-left"><thead class="bg-gray-50"><tr><th class="p-3">날짜</th><th class="p-3">시간</th><th class="p-3">작성자</th><th class="p-3">단위</th><th class="p-3">수량</th><th class="p-3">상태 / 관리</th></tr></thead><tbody>'+entries.slice().sort((a,b)=>String(b.scanned_at).localeCompare(String(a.scanned_at))).map(e=>{const parts=String(e.scanned_at||'').split(' '),voided=!!e.voided_at;return '<tr class="border-b '+(voided?'bg-gray-50 text-gray-400':'')+'"><td class="p-3">'+esc(parts[0]||e.date||'-')+'</td><td class="p-3">'+esc(parts[1]||'-')+'</td><td class="p-3">'+esc(e.entered_by_name||('User #'+e.entered_by))+'</td><td class="p-3">'+(e.unit==='PCS'?'낱개':'박스')+'</td><td class="p-3 font-semibold">'+esc(e.quantity)+'</td><td class="p-3 whitespace-nowrap">'+entryState(e)+'</td></tr>';}).join('')+'</tbody></table></div>':'<p class="text-sm text-gray-500">이 상품의 입력 기록이 없습니다.</p>';
 el('historyModal').classList.remove('hidden');document.body.classList.add('overflow-hidden');
}
function closeHistory(){el('historyModal').classList.add('hidden');document.body.classList.remove('overflow-hidden');}
el('summary').addEventListener('click',e=>{const row=e.target.closest('[data-product-id]');if(row)openHistory(row.dataset.productId);});
el('summary').addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target.matches('[data-product-id]')){e.preventDefault();openHistory(e.target.dataset.productId);}});
el('historyClose').onclick=closeHistory;el('historyModal').addEventListener('click',e=>{if(e.target===el('historyModal'))closeHistory();});document.addEventListener('keydown',e=>{if(e.key==='Escape')closeHistory();});
el('historyBody').addEventListener('click',async e=>{
 if(!session||session.status!=='open')return;
 const edit=e.target.closest('[data-correct-entry]');
 if(edit){const old=edit.dataset.currentQuantity,value=prompt('수정할 조사 수량을 입력하세요.',old);if(value===null)return;if(!/^\d+$/.test(value)){message('0 이상의 정수를 입력하세요.',true);return;}if(value===old){message('기존 수량과 다른 값을 입력하세요.',true);return;}edit.disabled=true;try{await request('correct_entry',{session_id:session.id,entry_id:edit.dataset.correctEntry,quantity:value},true);message('입력 수량을 수정했습니다.');closeHistory();await load(session.id);}catch(err){message(err.message,true);edit.disabled=false;}return;}
 const remove=e.target.closest('[data-void-entry]');if(!remove||!confirm('이 입력 기록을 삭제하시겠습니까? 기록은 이력에 남고 조사 합계에서 제외됩니다.'))return;
 remove.disabled=true;try{await request('void_entry',{session_id:session.id,entry_id:remove.dataset.voidEntry},true);message('입력 기록을 삭제했습니다.');closeHistory();await load(session.id);}catch(err){message(err.message,true);remove.disabled=false;}
});
async function load(selectedId){
 try{const [status,history]=await Promise.all([request('status'),request('sessions')]);sessions=history.sessions||[];const chosen=sessions.find(x=>String(x.id)===String(selectedId))||status.session||sessions[0]||null,select=el('sessionSelect');
 select.innerHTML=sessions.length?sessions.map(x=>'<option value="'+esc(x.id)+'">#'+esc(x.id)+' · '+esc(x.opened_at||'날짜 없음')+' · '+statusLabel(x.status)+'</option>').join(''):'<option value="">조사 건 없음</option>';select.disabled=!sessions.length;session=chosen;
 if(!session){el('sessionStatus').textContent='조사 건이 없습니다.';el('summary').textContent='조사 건이 없습니다.';return;}
 select.value=String(session.id);const params={session_id:session.id};if(isAdmin&&selectedUserId)params.user_id=selectedUserId;const result=await request('list',params);session=result.session||session;currentLines=result.lines||[];currentEntries=flattenDates(result.dates||[]);
 if(isAdmin&&el('userFilter')){el('userFilter').innerHTML='<option value="">전체 합산</option>'+(result.contributors||[]).map(x=>'<option value="'+esc(x.entered_by)+'">'+esc(x.entered_by_name)+' ('+esc(x.entry_count)+'건)</option>').join('');el('userFilter').value=selectedUserId;}
 el('sessionStatus').textContent='#'+session.id+' · '+statusLabel(session.status)+(selectedUserId?' · 개인별 보기':' · 전체 합산')+(session.finalized_at?' · '+session.finalized_at:'');el('uncounted').textContent='조사되지 않은 상품 '+(result.uncounted_count||0)+'개는 확정 시 변경되지 않습니다.';el('summary').innerHTML=table(currentLines,true);el('previewButton').classList.toggle('hidden',!isAdmin||session.status!=='open'||!currentLines.length);el('cancelButton').classList.toggle('hidden',!isAdmin||session.status!=='open');el('preview').classList.add('hidden');
 }catch(err){message(err.message,true);}
}
el('sessionSelect').onchange=e=>{selectedUserId='';load(e.target.value);};if(el('userFilter'))el('userFilter').onchange=e=>{selectedUserId=e.target.value;load(session?.id);};
el('previewButton').onclick=async()=>{try{const r=await request('preview',{session_id:session.id});el('previewSummary').innerHTML=table(r.lines||[],false);el('previewUncounted').textContent='미조사 상품 '+(r.uncounted_count||0)+'개는 재고를 변경하지 않습니다.';el('preview').classList.remove('hidden');el('preview').scrollIntoView({behavior:'smooth'});}catch(err){message(err.message,true);}};
el('finalizeButton').onclick=async()=>{if(!session||!confirm('조사 수량으로 재고를 확정하시겠습니까? 확정 후 되돌릴 수 없습니다.'))return;el('finalizeButton').disabled=true;try{const id=session.id;await request('finalize',{session_id:id},true);message('재고가 확정되었습니다.');el('preview').classList.add('hidden');await load(id);}catch(err){message(err.message,true);}finally{el('finalizeButton').disabled=false;}};
el('cancelButton').onclick=async()=>{if(!session||session.status!=='open'||!confirm('이 재고조사를 취소하시겠습니까? 입력 기록은 보존됩니다.'))return;el('cancelButton').disabled=true;try{const id=session.id;await request('cancel',{session_id:id},true);message('재고조사가 취소되었습니다.');await load(id);}catch(err){message(err.message,true);}finally{el('cancelButton').disabled=false;}};
load(new URLSearchParams(location.search).get('session_id'));
})();
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
