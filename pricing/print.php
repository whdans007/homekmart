<?php
// 바코드 라벨 인쇄 페이지 - 독립형 (인증 불필요)
require_once __DIR__ . '/../config/db_config.php';

$skusParam = $_GET['skus'] ?? '';
$parts = preg_split('/[\s,]+/u', $skusParam, -1, PREG_SPLIT_NO_EMPTY);
$skus = array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));

$rawMode   = $_GET['mode'] ?? '2p';
$printMode = in_array($rawMode, ['pricing', 'discount', 'logo'], true) ? $rawMode : '2p';
// 바코드를 렌더링하지 않는 모드 (할인스티커 / 로고스티커)
$noBarcode = in_array($printMode, ['discount', 'logo'], true);

// 로고 모드: 로고 이미지를 base64 data URI로 임베드(출력 PC 경로/도메인 문제 방지)
$logoSrc = '../logo/homekmart_logo.png';
if ($printMode === 'logo') {
    $logoFile = __DIR__ . '/../logo/homekmart_logo.png';
    if (is_file($logoFile)) {
        $logoSrc = 'data:image/png;base64,' . base64_encode(file_get_contents($logoFile));
    }
}
$rawDiscount = trim($_GET['discount'] ?? '');
$isPeso    = (strtoupper($rawDiscount) === 'P');
$discount  = $isPeso ? 'P' : preg_replace('/[^0-9+%]/', '', $rawDiscount);
$autoPrint = ($_GET['autoprint'] ?? '0') === '1';
$storeId   = (int)($_GET['store_id'] ?? 1);

$items = [];
$dbError = null;
if (!empty($skus)) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $hasProdPrice = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='selling_price'")->fetchColumn();
        $hasInvPrice  = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory' AND column_name='selling_price'")->fetchColumn();

        if ($hasProdPrice && $hasInvPrice) {
            $priceExpr = 'COALESCE(inv.selling_price, p.selling_price)';
        } elseif ($hasInvPrice) {
            $priceExpr = 'inv.selling_price';
        } elseif ($hasProdPrice) {
            $priceExpr = 'p.selling_price';
        } else {
            $priceExpr = 'NULL';
        }

        $hasLocTable = (bool)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'product_locations'"
        )->fetchColumn();
        $locJoin  = $hasLocTable ? 'LEFT JOIN product_locations pl ON pl.product_id = p.id AND pl.store_id = ?' : '';
        $locField = $hasLocTable ? ', COALESCE(pl.location, \'\') AS location' : ', \'\' AS location';

        $inPlaceholders = implode(',', array_fill(0, count($skus), '?'));
        $sql = "
            SELECT p.sku, p.name_en, p.name_ko, {$priceExpr} AS selling_price {$locField}
            FROM products p
            LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.store_id = ?
            {$locJoin}
            WHERE p.sku IN ({$inPlaceholders})
        ";
        $stmt = $pdo->prepare($sql);
        $params = $hasLocTable ? array_merge([$storeId, $storeId], $skus) : array_merge([$storeId], $skus);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // SKU 순서 유지 (수동 모드 수량 반영용)
        $rowMap = [];
        foreach ($rows as $r) { $rowMap[$r['sku']] = $r; }
        foreach ($skus as $s) {
            if (isset($rowMap[$s])) {
                $items[] = $rowMap[$s];
            } else {
                $items[] = ['sku' => $s, 'name_en' => '', 'name_ko' => '', 'selling_price' => null, 'location' => ''];
            }
        }

    } catch (Throwable $e) {
        $dbError = $e->getMessage();
        foreach ($skus as $s) {
            $items[] = ['sku' => $s, 'name_en' => '', 'name_ko' => '', 'selling_price' => null];
        }
    }
}

// ── EAN/UPC 체크 디지트 검증 ──
// 마지막 자리가 올바른 체크 디지트이면 true 반환
function validateEanCheckDigit(string $code): bool {
    $digits = str_split($code);
    $last   = (int)array_pop($digits);
    $sum    = 0;
    $len    = count($digits);       // 남은 자리 수
    foreach ($digits as $i => $d) {
        // EAN-13(12자리 남음): 홀수 인덱스(1,3,5...) × 3
        // EAN-8 (7자리 남음): 짝수 인덱스(0,2,4...) × 3
        $weight = ($i % 2 === ($len % 2 === 0 ? 1 : 0)) ? 3 : 1;
        $sum += (int)$d * $weight;
    }
    return (10 - ($sum % 10)) % 10 === $last;
}
?><!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>바코드 라벨 인쇄</title>
  <style>
    <?php if ($printMode === 'logo'): ?>
    @page { size: 140mm 60mm; margin: 0; }
    <?php else: ?>
    @page { size: 70mm 30mm; margin: 0; }
    <?php endif; ?>
    html, body { margin: 0; padding: 0; font-family: Arial, 'Malgun Gothic', sans-serif; }
    body { display: flex; flex-direction: column; align-items: center; }

    .controls { display: flex; gap: 8px; align-items: center; margin: 12px 0; padding: 0 12px; flex-wrap: wrap; }
    .controls button { padding: 6px 14px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; font-size: 13px; background: #f5f5f5; }
    .controls button:hover { background: #e8e8e8; }
    .controls button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
    .controls button.primary:hover { background: #1d4ed8; }
    .error-msg { color: #c00; font-size: 12px; }
    .spacer { flex: 1; }

    .grid { display: grid; grid-template-columns: 1fr; gap: 8mm; }
    #labels { margin: 0 auto; }

    /* ===== 2p 모드 ===== */
    .label-2p { width: 70mm; height: 30mm; border: 1px solid #000; padding: 0; display: flex; box-sizing: border-box; }
    .label-2p .half { flex: 1; width: 50%; max-width: 35mm; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1mm 2mm 2mm; box-sizing: border-box; }
    .label-2p .divider { width: 0; border-left: 1px solid #000; height: 100%; }
    .label-2p .name { font-size: 7pt; font-weight: 700; text-align: center; width: 100%; margin: 0 0 0.4mm; white-space: normal; word-break: break-word; overflow: hidden; line-height: 1.2; max-height: 2.4em; display: block; }
    .label-2p .price { font-size: 7pt; font-weight: 700; line-height: 1; margin: 0.2mm 0; }
    .label-2p .barcode { width: 90%; max-width: 30mm; height: 5mm; margin: 0.5mm auto 0; }
    .label-2p .foot { font-size: 6.5pt; margin-top: 0.5mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }

    /* ===== 프라이싱 모드 ===== */
    .label-pricing { width: 70mm; height: 30mm; border: 1px solid #000; padding: 0; display: flex; flex-direction: column; box-sizing: border-box; overflow: hidden; }
    .label-pricing .pricing-header { text-align: center; padding: 1mm 1.5mm 0.5mm; border-bottom: 0.5pt solid #000; flex: 0 0 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; box-sizing: border-box; }
    .label-pricing .pricing-name-en { font-size: 12pt; font-weight: 700; line-height: 1.2; white-space: normal; word-break: break-word; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; max-height: 2.4em; }
    .label-pricing .pricing-name-ko { font-size: 9pt; font-weight: 700; line-height: 1.15; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 0.5mm; }
    .label-pricing .pricing-body { display: flex; flex: 1; min-height: 0; }
    .label-pricing .pricing-barcode { flex: 1; width: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1mm 3mm 0.5mm; min-width: 0; box-sizing: border-box; }
    .label-pricing .pricing-barcode svg { width: 100%; height: 7mm; }
    .label-pricing .pricing-foot { font-size: 6.5pt; font-weight: 700; text-align: center; line-height: 1; margin-top: 0.8mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
    .label-pricing .pricing-price { flex: 1; width: 50%; flex-shrink: 0; border-left: 0.5pt solid #000; display: flex; align-items: center; justify-content: center; font-size: 38pt; font-weight: 700; line-height: 1; box-sizing: border-box; }

    /* ===== 할인스티커 모드 ===== */
    .label-discount { width: 70mm; height: 30mm; border: 1px solid #000; padding: 0; display: flex; box-sizing: border-box; background: #fff; }
    .label-discount .disc-half {
      flex: 1; width: 50%; display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      background: #fff; box-sizing: border-box;
    }
    .label-discount .disc-half:first-child { border-right: 1px solid #000; }
    .label-discount .disc-rate {
      font-size: 32pt; font-weight: 900; color: #000; line-height: 1;
      letter-spacing: -1px;
    }
    .label-discount .disc-off {
      font-size: 12pt; font-weight: 700; color: #000; margin-top: 1mm;
      letter-spacing: 1px; text-transform: uppercase;
    }
    .label-discount .disc-1plus1 {
      font-size: 26pt; font-weight: 900; color: #000; line-height: 1;
    }
    .label-discount .disc-peso {
      display: flex; align-items: baseline; gap: 2mm;
    }
    .label-discount .disc-peso-symbol {
      font-size: 26pt; font-weight: 900; color: #000; line-height: 1;
    }
    .label-discount .disc-peso-line {
      display: inline-block; width: 20mm; height: 0; border-bottom: 2px solid #000;
      margin-bottom: 1mm;
    }

    /* ===== 로고스티커 모드 (1장에 2x2 = 로고 4개, 각 칸 70x30) ===== */
    .logo-sheet {
      width: 140mm; height: 60mm; box-sizing: border-box;
      display: grid; grid-template-columns: 70mm 70mm; grid-template-rows: 30mm 30mm;
    }
    .logo-sheet .logo-cell {
      border: 1px solid #000; display: flex; align-items: center; justify-content: center;
      padding: 2mm; box-sizing: border-box; overflow: hidden; background: #fff;
    }
    .logo-sheet .logo-cell img { max-width: 100%; max-height: 100%; object-fit: contain; }

    /* ===== 인쇄 ===== */
    @media print {
      <?php if ($printMode === 'logo'): ?>
      @page { size: 140mm 60mm; margin: 0; }
      <?php else: ?>
      @page { size: 70mm 30mm; margin: 0; }
      <?php endif; ?>
      html, body { margin: 0; padding: 0; }
      .controls { display: none !important; }
      #labels { transform: none !important; gap: 0 !important; }
      .label-2p { width: 70mm !important; height: 30mm !important; border: 2px solid #000; page-break-inside: avoid; box-sizing: border-box; }
      .label-2p .divider { border-left: 1px solid #000; }
      .label-pricing { width: 70mm !important; height: 30mm !important; border: none; page-break-inside: avoid; box-sizing: border-box; overflow: hidden; }
      .label-pricing .pricing-header { border-bottom: 0.5pt solid #000; }
      .label-pricing .pricing-price { border-left: none; }
      .label-discount { width: 70mm !important; height: 30mm !important; border: 1px solid #000; page-break-inside: avoid; box-sizing: border-box; }
      .logo-sheet { width: 140mm !important; height: 60mm !important; page-break-inside: avoid; box-sizing: border-box; }
    }
  </style>
</head>
<body>
  <div class="controls">
    <button class="primary" id="btnPrint">인쇄</button>
    <button onclick="window.close()">닫기</button>
    <div class="spacer"></div>
    <?php if (!$noBarcode && empty($items)): ?><span class="error-msg">표시할 바코드가 없습니다.</span><?php endif; ?>
    <?php if ($dbError && !$noBarcode): ?><span class="error-msg">DB 오류: <?php echo htmlspecialchars($dbError); ?></span><?php endif; ?>
    <?php if ($printMode === 'discount' && $discount === ''): ?><span class="error-msg">할인율이 지정되지 않았습니다.</span><?php endif; ?>
  </div>

  <div class="grid" id="labels">
    <?php if ($printMode === 'discount'): ?>
    <?php $is1plus1 = ($discount === '1+1'); ?>
    <div class="label-discount">
      <div class="disc-half">
        <?php if ($isPeso): ?>
        <div class="disc-peso"><span class="disc-peso-symbol">₱</span><span class="disc-peso-line"></span></div>
        <?php elseif ($is1plus1): ?>
        <div class="disc-1plus1">1+1</div>
        <?php else: ?>
        <div class="disc-rate"><?php echo htmlspecialchars($discount); ?>%</div>
        <div class="disc-off">OFF</div>
        <?php endif; ?>
      </div>
      <div class="disc-half">
        <?php if ($isPeso): ?>
        <div class="disc-peso"><span class="disc-peso-symbol">₱</span><span class="disc-peso-line"></span></div>
        <?php elseif ($is1plus1): ?>
        <div class="disc-1plus1">1+1</div>
        <?php else: ?>
        <div class="disc-rate"><?php echo htmlspecialchars($discount); ?>%</div>
        <div class="disc-off">OFF</div>
        <?php endif; ?>
      </div>
    </div>
    <?php elseif ($printMode === 'logo'): ?>
    <div class="logo-sheet">
      <?php for ($i = 0; $i < 4; $i++): ?>
      <div class="logo-cell"><img src="<?php echo $logoSrc; ?>" alt="HOME K MART"></div>
      <?php endfor; ?>
    </div>
    <?php else: ?>
    <?php foreach ($items as $it):
      $sku = (string)($it['sku'] ?? '');
      $nameEn = trim((string)($it['name_en'] ?? ''));
      $nameKo = trim((string)($it['name_ko'] ?? ''));
      $baseName = $nameEn !== '' ? $nameEn : ($nameKo !== '' ? $nameKo : $sku);
      $price = $it['selling_price'];
      $priceText = ($price !== null && $price !== '') ? number_format((float)$price, 0) : '';
      $location = trim((string)($it['location'] ?? ''));
      $footText = $location !== '' ? $sku . ' / ' . $location : $sku;
      // 바코드 타입 자동 감지
      $barcodeFormat = 'CODE128'; // 기본값
      $len = strlen($sku);
      if (ctype_digit($sku)) {
        if ($len === 8  && validateEanCheckDigit($sku)) $barcodeFormat = 'EAN8';
        elseif ($len === 12 && validateEanCheckDigit($sku)) $barcodeFormat = 'UPC';
        elseif ($len === 13 && validateEanCheckDigit($sku)) $barcodeFormat = 'EAN13';
        elseif ($len === 14 && validateEanCheckDigit($sku)) $barcodeFormat = 'ITF14';
        // 체크 디지트 불일치 → 임의 생성 코드로 판단, CODE128 그대로 사용
      } elseif (preg_match('/^[A-Z0-9 \-\.\/\+\%\*]+$/', $sku)) {
        $barcodeFormat = 'CODE39'; // 대문자·숫자·일부 특수문자만 → CODE39
      }
    ?>
    <?php if ($printMode === 'pricing'): ?>
    <div class="label-pricing">
      <div class="pricing-header">
        <div class="pricing-name-en"><?php echo htmlspecialchars($nameEn ?: $nameKo ?: $sku); ?></div>
        <?php if ($nameEn !== '' && $nameKo !== '' && $nameKo !== $nameEn): ?>
        <div class="pricing-name-ko"><?php echo htmlspecialchars($nameKo); ?></div>
        <?php endif; ?>
      </div>
      <div class="pricing-body">
        <div class="pricing-barcode">
          <svg class="barcode" data-format="<?php echo $barcodeFormat; ?>" data-value="<?php echo htmlspecialchars($sku); ?>"></svg>
          <div class="pricing-foot"><?php echo htmlspecialchars($footText); ?></div>
        </div>
        <div class="pricing-price"><?php echo htmlspecialchars($priceText); ?></div>
      </div>
    </div>
    <?php else: ?>
    <div class="label-2p">
      <div class="half">
        <div class="name"><?php echo htmlspecialchars($baseName); ?></div>
        <?php if ($priceText !== ''): ?><div class="price"><?php echo htmlspecialchars($priceText); ?></div><?php endif; ?>
        <svg class="barcode" data-format="<?php echo $barcodeFormat; ?>" data-value="<?php echo htmlspecialchars($sku); ?>"></svg>
        <div class="foot"><?php echo htmlspecialchars($footText); ?></div>
      </div>
      <div class="divider"></div>
      <div class="half">
        <div class="name"><?php echo htmlspecialchars($baseName); ?></div>
        <?php if ($priceText !== ''): ?><div class="price"><?php echo htmlspecialchars($priceText); ?></div><?php endif; ?>
        <svg class="barcode" data-format="<?php echo $barcodeFormat; ?>" data-value="<?php echo htmlspecialchars($sku); ?>"></svg>
        <div class="foot"><?php echo htmlspecialchars($footText); ?></div>
      </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
  <script>
    function renderBarcodes() {
      document.querySelectorAll('svg.barcode').forEach(function(el) {
        const value = el.getAttribute('data-value') || '';
        const format = el.getAttribute('data-format') || 'CODE128';
        try {
          JsBarcode(el, value, { format: format, lineColor: '#000', width: 1, height: 20, displayValue: false, margin: 0 });
          el.setAttribute('preserveAspectRatio', 'xMidYMid meet');
          el.removeAttribute('width');
          el.removeAttribute('height');
          el.style.width = '100%';
          el.style.height = '100%';
        } catch(e) {
          el.outerHTML = '<div style="color:#c00;font-size:8pt">바코드 오류</div>';
        }
      });
    }

    function barcodesReady() {
      const svgs = document.querySelectorAll('svg.barcode');
      // SVG가 모두 div로 교체됐거나 처음부터 없으면 → 렌더 완료로 간주
      if (!svgs.length) return true;
      for (const svg of svgs) {
        if (!svg.querySelector('rect,g')) return false;
      }
      return true;
    }

    function waitAndRun(fn, max, interval) {
      max = max || 30; interval = interval || 80;
      let n = 0;
      const t = setInterval(function() {
        n++;
        if (barcodesReady() || n >= max) { clearInterval(t); fn(); }
      }, interval);
    }

    // 모든 이미지(로고)가 로드 완료됐는지 확인 — 출력 PC에서 이미지 깨짐/누락 방지
    function imagesReady() {
      const imgs = document.querySelectorAll('img');
      if (!imgs.length) return true;
      for (const im of imgs) {
        if (!im.complete || im.naturalWidth === 0) return false;
      }
      return true;
    }
    function waitImagesAndRun(fn, max, interval) {
      max = max || 50; interval = interval || 80;
      let n = 0;
      const t = setInterval(function() {
        n++;
        if (imagesReady() || n >= max) { clearInterval(t); fn(); }
      }, interval);
    }

    document.addEventListener('DOMContentLoaded', function() {
      <?php if (!$noBarcode): ?>
      renderBarcodes();
      <?php endif; ?>
      document.getElementById('btnPrint').addEventListener('click', function() {
        <?php if ($printMode === 'discount'): ?>
        window.print();
        <?php elseif ($printMode === 'logo'): ?>
        waitImagesAndRun(function() { window.print(); });
        <?php else: ?>
        waitAndRun(function() { window.print(); });
        <?php endif; ?>
      });
      <?php if ($autoPrint): ?>
      var isInIframe = (window.self !== window.top);
      <?php if ($printMode === 'discount'): ?>
      // 할인스티커: 즉시 출력
      window.print();
      if (!isInIframe) setTimeout(function() { window.close(); }, 600);
      <?php elseif ($printMode === 'logo'): ?>
      // 로고스티커: 로고 이미지 로드 완료 후 출력 (출력 PC 깨짐 방지)
      waitImagesAndRun(function() {
        window.print();
        if (!isInIframe) setTimeout(function() { window.close(); }, 800);
      });
      <?php else: ?>
      // 바코드 렌더 완료 즉시 출력 시도 (최대 2.4초 대기)
      waitAndRun(function() {
        window.print();
        if (!isInIframe) setTimeout(function() { window.close(); }, 600);
      });
      // 강제 폴백: 3초 후에도 실행 안 됐으면 강제 출력
      var fallbackFired = false;
      var origPrint = window.print.bind(window);
      window.print = function() { fallbackFired = true; origPrint(); };
      setTimeout(function() {
        if (!fallbackFired) { origPrint(); if (!isInIframe) setTimeout(function(){ window.close(); }, 600); }
      }, 3000);
      <?php endif; ?>
      <?php endif; ?>
    });
  </script>
</body>
</html>
