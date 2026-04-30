<?php
// 바코드 라벨 인쇄 페이지 (프라이싱 1p / 바코드라벨 2p 모드 지원)
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin','super_admin'])) {
    http_response_code(403);
    echo '권한이 없습니다.';
    exit;
}

$skusParam = $_GET['skus'] ?? '';
$parts = preg_split('/[\s,]+/u', $skusParam, -1, PREG_SPLIT_NO_EMPTY);
$skus = array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));

// 출력 모드: 'pricing' = 1장 전체에 바코드 1개, '2p' = 좌우 분할 같은 바코드 2개 (기본)
$printMode = ($_GET['mode'] ?? '2p') === 'pricing' ? 'pricing' : '2p';
// 자동 출력 모드: autoprint=1 이면 렌더링 완료 후 자동 print() + 창 닫기
$autoPrint = ($_GET['autoprint'] ?? '0') === '1';

$storeId = isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : 1;

$items = [];
$dbError = null;
if (!empty($skus)) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

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
    @page { size: auto; margin: 5mm; }
    html, body { margin: 0; padding: 0; }
    body { font-family: Arial, 'Malgun Gothic', sans-serif; display:flex; flex-direction:column; align-items:center; }
    .controls { display: flex; gap: 8px; align-items: center; margin: 10px 0; flex-wrap: wrap; }
    .controls .status { font-size: 12px; color: #555; }
    .controls .spacer { flex: 1 1 auto; }
    .controls strong { display: none !important; }
    #scale, #scaleVal, #btnFit, #btn50, #btn100, #btnRefresh,
    .controls label { display: none !important; }
    .grid { display: grid; grid-template-columns: 1fr; gap: 8mm; }
    #labels { margin: 0 auto; }
    #status { display: none !important; }

    /* ===== 2p 모드: 좌우 분할 (기존) ===== */
    .label-2p { width: 70mm; height: 30mm; border: 1px solid #000; padding: 0; display: flex; box-sizing: border-box; }
    .label-2p .half { flex: 1; width: 50%; max-width: 35mm; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1mm 2mm 2mm; box-sizing: border-box; }
    .label-2p .divider { width: 0; border-left: 1px solid #000; margin: 0; height: 100%; }
    .label-2p .name { font-size: 7pt; font-weight: 700; text-align: center; width: 100%; margin: 0 0 0.4mm 0;
            padding-top: 0.2mm; white-space: normal; word-break: break-word; overflow: hidden;
            line-height: 1.2; min-height: 2.4em; max-height: 2.4em; display: block; }
    .label-2p .price { font-size: 7pt; font-weight: 700; line-height: 1; margin: 0.2mm 0 0.2mm; }
    .label-2p .barcode { width: 90%; max-width: 30mm; height: 5mm; margin: 0.5mm auto 0; }
    .label-2p .foot { font-size: 8pt; margin-top: 0.5mm; }

    /* ===== 프라이싱 모드: 상단 이름 + 하단 좌(바코드)/우(가격) ===== */
    .label-pricing { width: 70mm; height: 30mm; border: 1px solid #000; padding: 0; display: flex; flex-direction: column; box-sizing: border-box; overflow: hidden; }
    .label-pricing .pricing-header { text-align: center; padding: 1mm 1.5mm 0.5mm; border-bottom: 0.5pt solid #000; flex: 0 0 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; box-sizing: border-box; }
    .label-pricing .pricing-name-en { font-size: 12pt; font-weight: 700; line-height: 1.2; white-space: normal; word-break: break-word; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; max-height: 2.4em; }
    .label-pricing .pricing-name-ko { font-size: 9pt; font-weight: 700; line-height: 1.15; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 0.5mm; }
    .label-pricing .pricing-body { display: flex; flex: 1; min-height: 0; }
    .label-pricing .pricing-barcode { flex: 1; width: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1mm 3mm 0.5mm; min-width: 0; box-sizing: border-box; }
    .label-pricing .pricing-barcode svg { width: 100%; height: 7mm; }
    .label-pricing .pricing-foot { font-size: 7.5pt; font-weight: 700; text-align: center; line-height: 1; margin-top: 0.8mm; }
    .label-pricing .pricing-price { flex: 1; width: 50%; flex-shrink: 0; border-left: 0.5pt solid #000; display: flex; align-items: center; justify-content: center; font-size: 38pt; font-weight: 700; line-height: 1; box-sizing: border-box; }

    /* 인쇄 */
    @media print {
      @page { size: 70mm 30mm; margin: 0; }
      html, body { margin: 0; padding: 0; }
      .controls { display: none !important; }
      #labels { transform: none !important; transform-origin: 0 0 !important; gap: 0 !important; }
      .label-2p { width: 70mm !important; height: 30mm !important; border: 2px solid #000; padding: 0; page-break-inside: avoid; box-sizing: border-box; }
      .label-2p .half { padding: 2mm; }
      .label-2p .divider { border-left: 1px solid #000; margin: 0; }
      .label-pricing { width: 70mm !important; height: 30mm !important; border: none; padding: 0; page-break-inside: avoid; box-sizing: border-box; overflow: hidden; }
      .label-pricing .pricing-header { border-bottom: 0.5pt solid #000; }
      .label-pricing .pricing-price { border-left: none; }
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
      <span style="color:#c00">표시할 바코드가 없습니다.</span>
    <?php endif; ?>
    <?php if ($dbError): ?>
      <span class="status" style="color:#a00">(참고) DB 조회 오류: <?php echo htmlspecialchars($dbError); ?></span>
    <?php endif; ?>
  </div>

  <div class="grid" id="labels">
    <?php foreach ($items as $it):
      $sku = (string)($it['sku'] ?? '');
      $nameEn = trim((string)($it['name_en'] ?? ''));
      $nameKo = trim((string)($it['name_ko'] ?? ''));
      $baseName = $nameEn !== '' ? $nameEn : ($nameKo !== '' ? $nameKo : $sku);
      $price = $it['selling_price'];
      $priceText = is_null($price) || $price === '' ? '' : number_format((float)$price, 0);
      $barcodeFormat = strlen($sku) === 13 ? 'EAN13' : 'CODE128';
    ?>

    <?php if ($printMode === 'pricing'): ?>
    <!-- 프라이싱: 상단 이름 + 하단 좌(바코드)/우(가격) -->
    <div class="label-pricing">
      <div class="pricing-header">
        <div class="pricing-name-en" title="<?php echo htmlspecialchars($nameEn ?: $nameKo ?: $sku); ?>"><?php echo htmlspecialchars($nameEn ?: $nameKo ?: $sku); ?></div>
        <?php if ($nameEn !== '' && $nameKo !== ''): ?>
        <div class="pricing-name-ko" title="<?php echo htmlspecialchars($nameKo); ?>"><?php echo htmlspecialchars($nameKo); ?></div>
        <?php endif; ?>
      </div>
      <div class="pricing-body">
        <div class="pricing-barcode">
          <svg class="barcode" data-format="<?php echo $barcodeFormat; ?>" data-value="<?php echo htmlspecialchars($sku); ?>"></svg>
          <div class="pricing-foot"><?php echo htmlspecialchars($sku); ?></div>
        </div>
        <div class="pricing-price"><?php echo $priceText !== '' ? $priceText : ''; ?></div>
      </div>
    </div>

    <?php else: ?>
    <!-- 2p: 좌우 분할 같은 바코드 2개 -->
    <div class="label-2p">
      <div class="half">
        <div class="name" title="<?php echo htmlspecialchars($baseName); ?>"><?php echo htmlspecialchars($baseName); ?></div>
        <?php if ($priceText !== ''): ?><div class="price"><?php echo $priceText; ?></div><?php endif; ?>
        <svg class="barcode" data-format="<?php echo $barcodeFormat; ?>" data-value="<?php echo htmlspecialchars($sku); ?>"></svg>
        <div class="foot"><?php echo htmlspecialchars($sku); ?></div>
      </div>
      <div class="divider"></div>
      <div class="half">
        <div class="name" title="<?php echo htmlspecialchars($baseName); ?>"><?php echo htmlspecialchars($baseName); ?></div>
        <?php if ($priceText !== ''): ?><div class="price"><?php echo $priceText; ?></div><?php endif; ?>
        <svg class="barcode" data-format="<?php echo $barcodeFormat; ?>" data-value="<?php echo htmlspecialchars($sku); ?>"></svg>
        <div class="foot"><?php echo htmlspecialchars($sku); ?></div>
      </div>
    </div>
    <?php endif; ?>

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
            width: 1,
            height: 20,
            displayValue: false,
            margin: 0
          });
          try {
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
        if (statusEl) statusEl.textContent = ready ? '프리뷰 준비 완료' : '바코드 렌더링 중... (' + attempts + ')';
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

    document.addEventListener('DOMContentLoaded', function(){
      renderBarcodes();
      applyScale();
      var btnPrint = document.getElementById('btnPrint');
      if (btnPrint) btnPrint.addEventListener('click', function(){
        waitForBarcodes(function(ready){ window.print(); });
      });
      <?php if ($autoPrint): ?>
      // 자동 출력 모드: 바코드 렌더링 완료 후 자동 인쇄 후 창 닫기
      waitForBarcodes(function(ready){
        window.print();
        setTimeout(function(){ window.close(); }, 500);
      });
      <?php else: ?>
      waitForBarcodes(function(ready){});
      <?php endif; ?>
      setTimeout(applyScale, 0);
    });
  </script>
</body>
</html>
