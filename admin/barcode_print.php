<?php
// 간단한 바코드 2분할 라벨 인쇄 페이지
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();

// 권한: product_management 또는 admin/super_admin 허용
if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin','super_admin'])) {
    http_response_code(403);
    echo '권한이 없습니다.';
    exit;
}

$skusParam = $_GET['skus'] ?? '';
// 콤마/공백 구분 모두 허용
$parts = preg_split('/[\s,]+/u', $skusParam, -1, PREG_SPLIT_NO_EMPTY);
$skus = array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));

// 세션의 매장 ID (header.php에서 설정)
$storeId = isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : 1;

$items = [];
$dbError = null;
if (!empty($skus)) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // products.selling_price, inventory.selling_price 존재 여부 확인
        $hasProdPrice = false;
        $hasInventory = false;
        $hasInvPrice = false;
        try {
            $chkProd = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='products' AND column_name='selling_price'");
            $hasProdPrice = (bool)$chkProd->fetchColumn();
        } catch (Throwable $e) { $hasProdPrice = false; }
        try {
            $chkInvTbl = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name='inventory'");
            $hasInventory = (bool)$chkInvTbl->fetchColumn();
            if ($hasInventory) {
                $chkInv = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='inventory' AND column_name='selling_price'");
                $hasInvPrice = (bool)$chkInv->fetchColumn();
            }
        } catch (Throwable $e) { $hasInventory = false; $hasInvPrice = false; }

        $inPlaceholders = implode(',', array_fill(0, count($skus), '?'));
        // 최종 가격 표현식 결정
        if ($hasInventory && $hasInvPrice && $hasProdPrice) {
            $finalPriceExpr = 'COALESCE(inv.selling_price, p.selling_price) AS selling_price';
        } elseif ($hasInventory && $hasInvPrice) {
            $finalPriceExpr = 'inv.selling_price AS selling_price';
        } elseif ($hasProdPrice) {
            $finalPriceExpr = 'p.selling_price AS selling_price';
        } else {
            $finalPriceExpr = 'NULL AS selling_price';
        }

        $joinInventory = ($hasInventory && $hasInvPrice) ? 'LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.store_id = ?' : '';

        $sql = "
            SELECT p.id, p.sku, p.name_en, p.name_ko, $finalPriceExpr
            FROM products p
            $joinInventory
            WHERE p.sku IN ($inPlaceholders)
            ORDER BY p.sku DESC
        ";
        $stmt = $pdo->prepare($sql);
        $bind = [];
        if ($joinInventory) { $bind[] = $storeId; }
        foreach ($skus as $s) { $bind[] = $s; }
        $stmt->execute($bind);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// DB 조회 실패/미발견이어도 전달된 SKU는 그대로 출력되도록 보완
if (!empty($skus)) {
    $foundMap = [];
    foreach ($items as $it) { $foundMap[(string)($it['sku'] ?? '')] = true; }
    foreach ($skus as $s) {
        if ($s === '' || isset($foundMap[$s])) continue;
        $items[] = [
            'id' => null,
            'sku' => $s,
            'name_en' => '',
            'name_ko' => '',
            'selling_price' => null,
        ];
    }
}

?><!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>바코드 인쇄</title>
  <style>
    /* 화면 기본 */
    @page { size: auto; margin: 5mm; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, 'Malgun Gothic', sans-serif; display:flex; flex-direction:column; align-items:center; }
    .controls { display: flex; gap: 8px; align-items: center; margin: 10px 0; flex-wrap: wrap; }
    .controls .status { font-size: 12px; color: #555; }
    .controls .spacer { flex: 1 1 auto; }
    /* 제목 텍스트 숨김 */
    .controls strong { display: none !important; }
    /* 요청: 배율/맞춤/50%/100%/미리보기 새로고침 컨트롤 숨김 */
    #scale, #scaleVal, #btnFit, #btn50, #btn100, #btnRefresh,
    .controls label { display: none !important; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 8mm; }
    #labels { margin: 0 auto; }
    #status { display: none !important; }
    /* 화면에서도 실제 용지 크기(70mm x 30mm)를 렌더하고, 상단 배율 슬라이더로 확대/축소 미리보기 */
    .label { width: 70mm; height: 30mm; border: 1px solid #000; padding: 0; display: flex; box-sizing: border-box; }
    .half { flex: 1; width: 50%; max-width: 35mm; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1mm 2mm 2mm; box-sizing: border-box; }
    .divider { width: 0; border-left: 1px solid #000; margin: 0; height: 100%; }
    /* 상품명: 스케일 변환시 잘림 방지. 정확히 2줄 영역을 확보 */
    .name { font-size: 7pt; font-weight: 700; text-align: center; width: 100%; margin: 0 0 0.4mm 0;
            padding-top: 0.2mm; white-space: normal; word-break: break-word; overflow: hidden;
            line-height: 1.2; min-height: 2.4em; max-height: 2.4em; display: block; }
    /* 가격: 높이(행간)와 여백을 최소화하여 상품명이 보이도록 함 */
    .price { font-size: 7pt; font-weight: 700; line-height: 1; margin: 0.2mm 0 0.2mm; }
    .barcode { width: 90%; max-width: 30mm; height: 5mm; margin: 0.5mm auto 0; }
    .foot { font-size: 8pt; margin-top: 0.5mm; }
    /* 인쇄: 실제 용지 크기 가로 70mm x 세로 30mm에 정확히 맞춤 (하나의 라벨에 좌/우 2개 바코드) */
    @media print {
      @page { size: 70mm 30mm; margin: 0; }
      html, body { margin: 0; padding: 0; }
      .controls { display: none !important; }
      #labels { transform: none !important; transform-origin: 0 0 !important; gap: 0 !important; }
      /* 인쇄 시 라벨을 용지 전체(70mm x 30mm)로 확장하고 내부를 좌/우 절반으로 분할 */
      .label { width: 70mm !important; height: 30mm !important; border: 2px solid #000; padding: 0; page-break-inside: avoid; box-sizing: border-box; }
      .half { padding: 2mm; }
      .divider { border-left: 1px solid #000; margin: 0; }
    }
  </style>
</head>
<body>
  <div class="controls">
    <strong>바코드 라벨 미리보기</strong>
    <div class="spacer"></div>
    <label>배율
      <input id="scale" type="range" min="10" max="200" step="5" value="50" style="vertical-align: middle;">
      <span id="scaleVal">50%</span>
    </label>
    <button id="btnFit">맞춤</button>
    <button id="btn50">50%</button>
    <button id="btn100">100%</button>
    <button id="btnRefresh">미리보기 새로고침</button>
    <button id="btnPrint">인쇄</button>
    <span id="status" class="status"></span>
    <?php if (empty($items)): ?>
      <span style="color:#c00">표시할 바코드가 없습니다. barcode_generate에서 선택하거나, URL에 ?skus=콤마구분 으로 전달하세요.</span>
    <?php endif; ?>
    <?php if ($dbError): ?>
      <span class="status" style="color:#a00">(참고) DB 조회 오류: <?php echo htmlspecialchars($dbError); ?></span>
    <?php endif; ?>
  </div>

  <div class="grid" id="labels">
    <?php 
    foreach ($items as $it): 
      $sku = (string)($it['sku'] ?? '');
      $nameEn = trim((string)($it['name_en'] ?? ''));
      $nameKo = trim((string)($it['name_ko'] ?? ''));
      $baseName = $nameEn !== '' ? $nameEn : ($nameKo !== '' ? $nameKo : $sku);
      $price = $it['selling_price'];
      $priceText = is_null($price) || $price === '' ? '' : number_format((float)$price, 0);
    ?>
    <div class="label">
      <div class="half">
        <div class="name" title="<?php echo htmlspecialchars($baseName); ?>"><?php echo htmlspecialchars($baseName); ?></div>
        <?php if ($priceText !== ''): ?><div class="price"><?php echo $priceText; ?></div><?php endif; ?>
        <svg class="barcode" data-format="<?php echo strlen($sku)===13 ? 'EAN13':'CODE128'; ?>" data-value="<?php echo htmlspecialchars($sku); ?>"></svg>
        <div class="foot"><?php echo htmlspecialchars($sku); ?></div>
      </div>
      <div class="divider"></div>
      <div class="half">
        <div class="name" title="<?php echo htmlspecialchars($baseName); ?>"><?php echo htmlspecialchars($baseName); ?></div>
        <?php if ($priceText !== ''): ?><div class="price"><?php echo $priceText; ?></div><?php endif; ?>
        <svg class="barcode" data-format="<?php echo strlen($sku)===13 ? 'EAN13':'CODE128'; ?>" data-value="<?php echo htmlspecialchars($sku); ?>"></svg>
        <div class="foot"><?php echo htmlspecialchars($sku); ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
  <script>
    function renderBarcodes(){
      document.querySelectorAll('svg.barcode').forEach(function(el){
        const value = el.getAttribute('data-value') || '';
        const format = el.getAttribute('data-format') || 'EAN13';
        try {
          JsBarcode(el, value, {
            format: format,
            lineColor: '#000',
            width: 1, // 막대 폭을 줄여 35mm 내에 적합
            height: 20,
            displayValue: false,
            margin: 0
          });
          // 컨테이너(35mm)에 가로가 정확히 맞도록 SVG를 강제 적응
          try {
            // 비율을 유지하며 컨테이너(최대 30mm, 폭 90%) 안에 맞춤
            el.setAttribute('preserveAspectRatio','xMidYMid meet');
            el.removeAttribute('width');
            el.removeAttribute('height');
            el.style.width = '100%';
            el.style.height = '100%';
          } catch(e){}
        } catch (e) {
          el.outerHTML = '<div style="color:#c00;font-size:8pt">바코드 오류</div>';
        }
      });
    }

    function checkBarcodesReady(container){
      const svgs = (container || document).querySelectorAll('svg.barcode');
      if (svgs.length === 0) return false;
      for (const svg of svgs) {
        if (!svg.querySelector('rect') && !svg.querySelector('g')) return false;
      }
      return true;
    }

    function waitForBarcodes(callback, maxAttempts = 50, interval = 100){
      let attempts = 0;
      const statusEl = document.getElementById('status');
      const timer = setInterval(() => {
        attempts++;
        const ready = checkBarcodesReady(document.getElementById('labels'));
        statusEl.textContent = ready ? '프리뷰 준비 완료' : `바코드 렌더링 중... (${attempts})`;
        if (ready || attempts >= maxAttempts) {
          clearInterval(timer);
          callback(ready);
        }
      }, interval);
    }

    function applyScale(){
      const container = document.getElementById('labels');
      container.style.transformOrigin = 'top center';
      container.style.transform = 'scale(2)';
    }

    function setScale(percent){
      const s = Math.max(10, Math.min(200, Math.round(percent)));
      document.getElementById('scale').value = s;
      applyScale();
    }

    function fitToWidth(){
      const container = document.getElementById('labels');
      // 현재 스케일
      const current = 2; // 고정 배율
      // 현재 렌더된 폭을 측정하고, 원래 폭을 역산하여 창 폭에 맞춤
      const rect = container.getBoundingClientRect();
      const originalWidth = rect.width / (current || 0.01);
      const padding = 24; // 좌우 여백 약간
      // 고정 200%로 유지
      setScale(200);
    }

    function fitToPage(){
      const container = document.getElementById('labels');
      const current = Number(document.getElementById('scale').value) / 100 || 0.01;
      const rect = container.getBoundingClientRect();
      const originalWidth = rect.width / current;
      const originalHeight = rect.height / current;
      const padding = 48; // 여백을 더 두어 화면에서 너무 크게 보이지 않도록
      const controls = document.querySelector('.controls');
      const controlsH = controls ? controls.getBoundingClientRect().height : 0;
      const availW = window.innerWidth - padding;
      const availH = window.innerHeight - controlsH - padding;
      const scaleW = availW / originalWidth;
      const scaleH = availH / originalHeight;
      const target = Math.max(0.1, Math.min(2.0, Math.floor(Math.min(scaleW, scaleH) * 100 * 0.80))); // 80%로 여유 확대
      setScale(target);
    }

    document.addEventListener('DOMContentLoaded', function(){
      renderBarcodes();
      applyScale();
      document.getElementById('scale').addEventListener('input', applyScale);
      document.getElementById('btnRefresh').addEventListener('click', function(){
        document.getElementById('status').textContent = '프리뷰 갱신 중...';
        renderBarcodes();
        waitForBarcodes(function(){ document.getElementById('status').textContent = '프리뷰 준비 완료'; });
      });
      document.getElementById('btnPrint').addEventListener('click', function(){
        waitForBarcodes(function(ready){ window.print(); });
      });
      var _btnFit = document.getElementById('btnFit'); if (_btnFit) _btnFit.addEventListener('click', fitToPage);
      var _btn50 = document.getElementById('btn50'); if (_btn50) _btn50.addEventListener('click', function(){ setScale(50); });
      var _btn100 = document.getElementById('btn100'); if (_btn100) _btn100.addEventListener('click', function(){ setScale(100); });
      waitForBarcodes(function(ready){ /* no-op, just update status */ });
      // 최초 로드시 화면(가로/세로)에 맞춰 보기 좋게 맞춤
      setTimeout(applyScale, 0);
    });
  </script>
</body>
</html>
