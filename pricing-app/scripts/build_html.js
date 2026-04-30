#!/usr/bin/env node
/**
 * PHP pricing 앱 → Electron renderer HTML 변환 스크립트
 * Usage: node scripts/build_html.js
 */
const fs = require('fs');
const path = require('path');

const SRC = path.join(__dirname, '../../pricing');
const DST = path.join(__dirname, '../renderer');
fs.mkdirSync(DST, { recursive: true });

// ── index.html 변환 ────────────────────────────────────────
let html = fs.readFileSync(path.join(SRC, 'index.php'), 'utf8');

// 1. PHP 헤더 (require, $stores 쿼리, $defaultStore) 제거
html = html.replace(/^<\?php[\s\S]*?\?>/m, '');

// 2. PHP 점포 목록 출력 → placeholder (JS로 동적 생성)
html = html.replace(
  /(<div id="storePicker">[\s\S]*?<\/div>)\s*<\?php else[\s\S]*?<\/span>\s*<\?php endif; \?>/m,
  `<div id="storePicker">
      <button id="storeBtn" onclick="toggleStoreDropdown()">
        <i class="fas fa-store" style="color:#38bdf8;font-size:14px"></i>
        <span class="store-name" id="storeNameLabel">로딩 중...</span>
        <i class="fas fa-chevron-down store-arrow"></i>
      </button>
      <div id="storeDropdown">
        <div class="sd-title"><i class="fas fa-store mr-1"></i>점포 선택</div>
        <div id="storeDropdownItems"></div>
      </div>
    </div>`
);

// 3. 설정 모달 내 점포 목록 → placeholder
html = html.replace(
  /<div class="sm-store-list">[\s\S]*?<\/div>\s*<\?php if \(empty[\s\S]*?<\/div>\s*<\/div>\s*(?=<\/div><!--\s*\/.sm-body)/m,
  `<div class="sm-store-list" id="settingsStoreList"></div>\n      </div>`
);

// 4. 업로드 점포 라벨 PHP → JS (이미 JS로 동작)

// 5. PHP storeId 인라인 출력
html = html.replace(/let storeId = <\?php echo \$defaultStore; \?>;/, 'let storeId = 1;');
html = html.replace(/parseInt\(localStorage\.getItem\('pricing_storeId'\)\) \|\| <\?php echo \$defaultStore; \?>;/, "parseInt(localStorage.getItem('pricing_storeId')) || 1;");

// 6. fetch → window.api 교체 (검색)
html = html.replace(
  /fetch\('ajax_search\.php\?barcode='\s*\+\s*encodeURIComponent\(barcode\)\s*\+\s*'&store_id='\s*\+\s*storeId\)\s*\n\s*\.then\(r => r\.json\(\)\)\s*\n\s*\.then\(data => \{/g,
  'window.api.search(barcode, storeId).then(data => {'
);
html = html.replace(
  /fetch\('ajax_search\.php\?barcode='\s*\+\s*encodeURIComponent\(barcode\)\s*\+\s*'&store_id='\s*\+\s*storeId\)\s*\n\s*\.then\(r => r\.json\(\)\)\s*\n\s*\.then\(data => \{/g,
  'window.api.search(barcode, storeId).then(data => {'
);

// 7. fetch suggest
html = html.replace(
  /fetch\('ajax_suggest\.php\?q='\s*\+\s*encodeURIComponent\(q\)\s*\+\s*'&store_id='\s*\+\s*storeId\s*\+\s*'&limit=15'\)\s*\n\s*\.then\(r => r\.json\(\)\)\s*\n\s*\.then\(data => \{/g,
  'window.api.suggest(q, storeId).then(data => {'
);

// 8. fetch update_name → window.api
html = html.replace(
  /const fd = new FormData\(\);\s*fd\.append\('product_id',\s*p\.product_id\);\s*fd\.append\('language',\s*lang\);\s*fd\.append\('product_name',\s*trimmed\);\s*fetch\('ajax_update_name\.php', \{ method:'POST', body: fd \}\)\s*\.then\(r => r\.json\(\)\)\s*\.then\(data => \{/,
  `window.api.updateName(p.product_id,
      lang === 'en' ? trimmed : (printItems[idx].name_en || ''),
      lang === 'ko' ? trimmed : (printItems[idx].name_ko || '')
    ).then(data => {`
);

// 9. silentPrint: iframe → window.api.openPrint
html = html.replace(
  /function silentPrint\(p\) \{[\s\S]*?\n\}/m,
  `function silentPrint(p) {
  window.api.openPrint({ skus: [p.sku], mode: printType, storeId, autoprint: true });
  setPrintStatus('green', '<i class="fas fa-check-circle mr-1"></i>출력: ' + escHtml(p.name_en || p.sku));
  addPrintHistory(p);
}`
);

// 10. doPrint: window.open → window.api.openPrint
html = html.replace(
  /function doPrint\(p\) \{[\s\S]*?\n\}/m,
  `function doPrint(p) {
  window.api.openPrint({ skus: [p.sku], mode: printType, storeId, autoprint: true });
  setPrintStatus('green', '<i class="fas fa-check-circle mr-1"></i>출력: ' + escHtml(p.name_en || p.sku));
  addPrintHistory(p);
}`
);

// 11. printSelectedItems window.open → window.api.openPrint
html = html.replace(
  /function printSelectedItems\(\)\{[\s\S]*?\n\}/m,
  `function printSelectedItems(){
  const sel = printItems.filter(p=>p.checked);
  if (!sel.length){ setPrintStatus('red','<i class="fas fa-exclamation-circle mr-1"></i>선택된 상품이 없습니다'); return; }
  const skus=[];
  sel.forEach(p=>{ for(let i=0;i<(p.qty||1);i++) skus.push(p.sku); });
  window.api.openPrint({ skus, mode: printType, storeId, autoprint: false });
  setPrintStatus('green','<i class="fas fa-print mr-1"></i>'+skus.length+'장 출력 창을 열었습니다');
}`
);

// 12. uploadMasterFile → window.api.importMaster (file dialog)
html = html.replace(
  /function uploadMasterFile\(\) \{[\s\S]*?\n\}/m,
  `function uploadMasterFile() {
  const btn = document.getElementById('uploadBtn');
  const progress = document.getElementById('uploadProgress');
  const result = document.getElementById('uploadResult');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 처리 중...';
  progress.style.display = 'block';
  result.style.display = 'none';
  window.api.importMaster(window._uploadFilePath, storeId).then(data => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-upload"></i>업로드 시작';
    progress.style.display = 'none';
    result.style.display = 'block';
    result.style.borderColor = data.success ? '#22c55e' : '#ef4444';
    result.style.color = data.success ? '#4ade80' : '#f87171';
    result.textContent = data.message || (data.success ? '완료' : '오류 발생');
  });
}`
);

// 13. onUploadFileChange → 파일 경로 저장 (Electron은 File API path 제한)
html = html.replace(
  /function onUploadFileChange\(input\) \{[\s\S]*?\n\}/m,
  `function onUploadFileChange(input) {
  // Electron: 파일 선택 버튼은 dialog API로 대체
}`
);

// 14. 파일 선택 버튼 → Electron dialog
html = html.replace(
  /<label id="uploadDropZone"[\s\S]*?<\/label>/m,
  `<div id="uploadDropZone" onclick="pickUploadFile()" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;border:2px dashed #334155;border-radius:10px;padding:24px 16px;cursor:pointer;transition:border-color 0.2s;background:#0f172a" onmouseover="this.style.borderColor='#38bdf8'" onmouseout="this.style.borderColor='#334155'">
            <i class="fas fa-file-excel" style="font-size:28px;color:#22c55e"></i>
            <span style="font-size:13px;color:#94a3b8">클릭하여 파일 선택</span>
            <span style="font-size:11px;color:#475569">.xlsx, .xls, .csv 지원</span>
            <span id="uploadFileName" style="font-size:12px;color:#38bdf8;font-weight:600;display:none"></span>
          </div>`
);

// 15. 환경설정 탭에 DB 설정 탭 추가
html = html.replace(
  /<button class="sm-tab" onclick="switchSettingsTab\('upload'\)"><i class="fas fa-file-upload" style="margin-right:5px"><\/i>마스터 파일 업로드<\/button>/,
  `<button class="sm-tab" onclick="switchSettingsTab('upload')"><i class="fas fa-file-upload" style="margin-right:5px"></i>마스터 파일 업로드</button>
      <button class="sm-tab" onclick="switchSettingsTab('dbconfig')"><i class="fas fa-database" style="margin-right:5px"></i>DB 설정</button>`
);

// 16. DB 설정 탭 패널 추가
html = html.replace(
  /(<\/div><!--\s*\/.sm-body\s*-->)/,
  `      <!-- ③ DB 설정 -->
      <div class="sm-pane" id="paneDbconfig">
        <div class="sm-field">
          <div class="sm-label">DB 서버 호스트</div>
          <input class="sm-input" id="dbHost" type="text" placeholder="예: localhost 또는 123.456.789.0">
        </div>
        <div class="sm-field">
          <div class="sm-label">포트</div>
          <input class="sm-input" id="dbPort" type="number" placeholder="3306" style="width:120px">
        </div>
        <div class="sm-field">
          <div class="sm-label">데이터베이스명</div>
          <input class="sm-input" id="dbName" type="text" placeholder="예: homekmart">
        </div>
        <div class="sm-field">
          <div class="sm-label">사용자명</div>
          <input class="sm-input" id="dbUser" type="text" placeholder="예: homekmart_user">
        </div>
        <div class="sm-field">
          <div class="sm-label">비밀번호</div>
          <input class="sm-input" id="dbPass" type="password" placeholder="비밀번호">
        </div>
        <div style="display:flex;gap:8px;margin-top:14px">
          <button onclick="testDbConnection()" style="flex:1;background:#0ea5e9;color:#fff;border:none;border-radius:8px;padding:10px;font-size:14px;font-weight:700;cursor:pointer">연결 테스트</button>
          <button onclick="saveDbConfig()" style="flex:1;background:#16a34a;color:#fff;border:none;border-radius:8px;padding:10px;font-size:14px;font-weight:700;cursor:pointer">저장</button>
        </div>
        <div id="dbConfigMsg" class="sm-msg"></div>
      </div>
$1`
);

// 17. switchSettingsTab 함수 확장 (dbconfig 추가)
html = html.replace(
  /document\.querySelectorAll\('\.sm-tab'\)\.forEach\(\(el, i\) => \{[\s\S]*?\}\);/m,
  `document.querySelectorAll('.sm-tab').forEach((el, i) => {
    el.classList.toggle('active', ['store','upload','dbconfig'][i] === tab);
  });`
);
html = html.replace(
  "const paneId = tab === 'upload' ? 'paneUpload' : 'paneStore';",
  "const paneId = tab === 'upload' ? 'paneUpload' : tab === 'dbconfig' ? 'paneDbconfig' : 'paneStore';"
);

// 18. quickPrintCurrent → window.api.openPrint
html = html.replace(
  /function quickPrintCurrent\(type\) \{[\s\S]*?\n\}/m,
  `function quickPrintCurrent(type) {
  if (!currentProduct) return;
  window.api.openPrint({ skus: [currentProduct.sku], mode: type, storeId, autoprint: true });
}`
);

// 19. .catch 네트워크 오류 남은 것들 처리 (fetch 잔여)
html = html.replace(/\.catch\(\(\) => showLookupState\('error', '네트워크 오류가 발생했습니다'\)\)/g, '');
html = html.replace(/\.catch\(\(\) => \{\s*setPrintStatus\('red', '<i class="fas fa-exclamation-triangle mr-1"><\/i>네트워크 오류'\);\s*\}\)/g, '');

// 20. init() 내 savedStore 복원 코드에 JavaScript 로직 추가
// (stores가 동적 로딩이므로 init에 loadStores 추가)
html = html.replace(
  /\(function init\(\)\{/,
  `async function loadStores() {
  const { stores } = await window.api.getStores().catch(() => ({ stores: [] }));
  const ddItems = document.getElementById('storeDropdownItems');
  const settingsList = document.getElementById('settingsStoreList');
  const savedStore = parseInt(localStorage.getItem('pricing_storeId')) || (stores[0]?.id || 1);
  storeId = savedStore;
  if (!stores.length) {
    document.getElementById('storeNameLabel').textContent = '점포 없음';
    return;
  }
  ddItems.innerHTML = stores.map(s =>
    \`<button class="sd-item\${s.id==savedStore?' active':''}" data-id="\${s.id}" data-name="\${s.name}" onclick="selectStore(\${s.id}, '\${s.name.replace(/'/g,\\"\\\\\\\\'\\")}')">
      <span class="sd-icon"><i class="fas fa-store"></i></span>\${s.name}
    </button>\`
  ).join('');
  settingsList.innerHTML = stores.map(s =>
    \`<div class="sm-store-item\${s.id==savedStore?' active':''}" data-id="\${s.id}"
         onclick="settingsSelectStore(\${s.id}, '\${s.name.replace(/'/g,\\"\\\\\\\\'\\")}')">
      <span class="ssi-icon"><i class="fas fa-store"></i></span>
      <span>\${s.name}</span>
      <i class="fas fa-check-circle ssi-check"></i>
    </div>\`
  ).join('');
  const matched = stores.find(s => s.id == savedStore) || stores[0];
  document.getElementById('storeNameLabel').textContent = matched.name;
}

async function pickUploadFile() {
  const fp = await window.api.openFile();
  if (!fp) return;
  window._uploadFilePath = fp;
  const nameEl = document.getElementById('uploadFileName');
  nameEl.textContent = fp.split(/[\\\\/]/).pop();
  nameEl.style.display = 'block';
  document.getElementById('uploadResult').style.display = 'none';
}

async function loadDbConfig() {
  const cfg = await window.api.getConfig();
  document.getElementById('dbHost').value = cfg.host || '';
  document.getElementById('dbPort').value = cfg.port || 3306;
  document.getElementById('dbName').value = cfg.database || '';
  document.getElementById('dbUser').value = cfg.user || '';
  document.getElementById('dbPass').value = cfg.password || '';
}

async function testDbConnection() {
  const cfg = getDbFormValues();
  const msg = document.getElementById('dbConfigMsg');
  msg.className = 'sm-msg'; msg.textContent = '연결 테스트 중...'; msg.style.display='block';
  const r = await window.api.testConnection(cfg);
  msg.className = 'sm-msg ' + (r.success ? 'success' : 'error');
  msg.textContent = r.success ? '✅ 연결 성공!' : ('❌ ' + r.message);
}

async function saveDbConfig() {
  const cfg = getDbFormValues();
  const msg = document.getElementById('dbConfigMsg');
  msg.className = 'sm-msg'; msg.textContent = '저장 중...'; msg.style.display='block';
  const r = await window.api.setConfig(cfg);
  msg.className = 'sm-msg ' + (r.success ? 'success' : 'error');
  msg.textContent = r.success ? '✅ 저장 완료! DB 연결됨.' : ('❌ ' + r.message);
  if (r.success) { loadStores(); }
}

function getDbFormValues() {
  return {
    host: document.getElementById('dbHost').value.trim(),
    port: parseInt(document.getElementById('dbPort').value) || 3306,
    database: document.getElementById('dbName').value.trim(),
    user: document.getElementById('dbUser').value.trim(),
    password: document.getElementById('dbPass').value,
    charset: 'utf8mb4',
  };
}

(function init(){`
);

// 21. init 내 store 복원 코드 제거 (loadStores로 대체)
html = html.replace(
  /\/\/ 점포 복원\s*const matchedItem[\s\S]*?firstItem\.classList\.add\('active'\);\s*\}\s*}/m,
  ''
);

// 22. init 끝부분에 loadStores, loadDbConfig 호출 추가
html = html.replace(
  /initSuggest\('printInput', 'printSuggest', 'print', suggestCallbacks\['print'\]\);/,
  `initSuggest('printInput', 'printSuggest', 'print', suggestCallbacks['print']);
  loadStores();
  loadDbConfig();`
);

// 23. 퀵프린트 버튼 (가격조회 화면) - 남아있다면 처리
html = html.replace(/window\.open\(url, '_blank',[\s\S]*?\);/g, '');

// 24. switchSettingsTab에 dbconfig일때 loadDbConfig 호출
html = html.replace(
  "if (tab === 'upload') syncUploadStoreLabel();",
  "if (tab === 'upload') syncUploadStoreLabel();\n  if (tab === 'dbconfig') loadDbConfig();"
);

fs.writeFileSync(path.join(DST, 'index.html'), html, 'utf8');
console.log('✅ renderer/index.html 생성 완료');

// ── print.html 변환 ────────────────────────────────────────
let pHtml = fs.readFileSync(path.join(SRC, 'print.php'), 'utf8');

// PHP 전체 블록 제거 후 JS로 대체
pHtml = pHtml.replace(/^<\?php[\s\S]*?\?>/m, '');

// PHP foreach labels 블록 제거 → JS로 생성
pHtml = pHtml.replace(
  /<div class="grid" id="labels">[\s\S]*?<\/div>\s*\n\s*<script/m,
  `<div class="grid" id="labels"></div>\n  <script`
);

// 기존 JS 스크립트를 완전히 교체
pHtml = pHtml.replace(
  /<script>[\s\S]*?<\/script>\s*<\/body>/,
  `<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
  <script>
    function validateEanCheckDigit(code) {
      const digits = code.split('').map(Number);
      const last = digits.pop();
      const len = digits.length;
      const sum = digits.reduce((acc,d,i) => acc + d*((i%2===(len%2===0?1:0))?3:1), 0);
      return (10-(sum%10))%10 === last;
    }
    function detectFormat(sku) {
      if (/^\\d+$/.test(sku)) {
        const l = sku.length;
        if (l===8  && validateEanCheckDigit(sku)) return 'EAN8';
        if (l===12 && validateEanCheckDigit(sku)) return 'UPC';
        if (l===13 && validateEanCheckDigit(sku)) return 'EAN13';
        if (l===14 && validateEanCheckDigit(sku)) return 'ITF14';
      } else if (/^[A-Z0-9 \\-\\.\\/\\+\\%\\*]+$/.test(sku)) return 'CODE39';
      return 'CODE128';
    }
    function fmtPrice(v) {
      if (!v && v!==0) return '';
      return parseFloat(v).toLocaleString('ko-KR');
    }
    function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function renderLabel2p(it) {
      const name = it.name_en || it.name_ko || it.sku;
      const price = it.selling_price ? fmtPrice(it.selling_price) : '';
      const fmt = detectFormat(it.sku);
      return \`<div class="label-2p">
        <div class="half">
          <div class="name">\${esc(name)}</div>
          \${price?'<div class="price">'+esc(price)+'</div>':''}
          <svg class="barcode" data-format="\${fmt}" data-value="\${esc(it.sku)}"></svg>
          <div class="foot">\${esc(it.sku)}</div>
        </div>
        <div class="divider"></div>
        <div class="half">
          <div class="name">\${esc(name)}</div>
          \${price?'<div class="price">'+esc(price)+'</div>':''}
          <svg class="barcode" data-format="\${fmt}" data-value="\${esc(it.sku)}"></svg>
          <div class="foot">\${esc(it.sku)}</div>
        </div>
      </div>\`;
    }

    function renderLabelPricing(it) {
      const nameEn = it.name_en || '';
      const nameKo = it.name_ko || '';
      const price = it.selling_price ? fmtPrice(it.selling_price) : '';
      const fmt = detectFormat(it.sku);
      return \`<div class="label-pricing">
        <div class="pricing-header">
          <div class="pricing-name-en">\${esc(nameEn||nameKo||it.sku)}</div>
          \${nameEn&&nameKo?'<div class="pricing-name-ko">'+esc(nameKo)+'</div>':''}
        </div>
        <div class="pricing-body">
          <div class="pricing-barcode">
            <svg class="barcode" data-format="\${fmt}" data-value="\${esc(it.sku)}"></svg>
            <div class="pricing-foot">\${esc(it.sku)}</div>
          </div>
          <div class="pricing-price">\${esc(price)}</div>
        </div>
      </div>\`;
    }

    function renderBarcodes() {
      document.querySelectorAll('svg.barcode').forEach(el => {
        const value = el.getAttribute('data-value') || '';
        const format = el.getAttribute('data-format') || 'CODE128';
        try {
          JsBarcode(el, value, { format, lineColor:'#000', width:1, height:20, displayValue:false, margin:0 });
          el.setAttribute('preserveAspectRatio','xMidYMid meet');
          el.removeAttribute('width'); el.removeAttribute('height');
          el.style.width='100%'; el.style.height='100%';
        } catch(e) { el.outerHTML='<div style="color:#c00;font-size:8pt">바코드 오류</div>'; }
      });
    }

    function barcodesReady() {
      const svgs = document.querySelectorAll('svg.barcode');
      if (!svgs.length) return true;
      for (const svg of svgs) { if (!svg.querySelector('rect,g')) return false; }
      return true;
    }

    function waitAndRun(fn, max, interval) {
      max=max||30; interval=interval||80; let n=0;
      const t=setInterval(()=>{ n++; if(barcodesReady()||n>=max){clearInterval(t);fn();} },interval);
    }

    async function init() {
      const params = new URLSearchParams(location.search);
      const skusRaw = params.get('skus') || '';
      const skus = skusRaw.split(',').map(s=>s.trim()).filter(Boolean);
      const mode = params.get('mode') || '2p';
      const storeId = parseInt(params.get('store_id')) || 1;
      const autoprint = params.get('autoprint') === '1';

      const labels = document.getElementById('labels');
      if (!skus.length) {
        document.querySelector('.controls').insertAdjacentHTML('beforeend','<span class="error-msg">표시할 바코드가 없습니다.</span>');
        return;
      }

      const { success, items, message } = await window.api.printItems(skus, storeId);
      if (!success) {
        document.querySelector('.controls').insertAdjacentHTML('beforeend','<span class="error-msg">DB 오류: '+message+'</span>');
        return;
      }

      labels.innerHTML = items.map(it => mode==='pricing' ? renderLabelPricing(it) : renderLabel2p(it)).join('');
      renderBarcodes();

      document.getElementById('btnPrint').addEventListener('click', () => waitAndRun(() => window.print()));
      if (autoprint) {
        waitAndRun(() => {
          window.print();
          setTimeout(() => window.close(), 600);
        });
      }
    }

    document.addEventListener('DOMContentLoaded', init);
  </script>
</body>`
);

// JsBarcode script 태그 잔여 제거
pHtml = pHtml.replace(/<script src="https:\/\/cdn\.jsdelivr\.net\/npm\/jsbarcode[\s\S]*?<\/script>\s*\n\s*(?=<script>)/m, '');

fs.writeFileSync(path.join(DST, 'print.html'), pHtml, 'utf8');
console.log('✅ renderer/print.html 생성 완료');
console.log('Done!');
