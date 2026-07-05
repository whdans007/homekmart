<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

ensure_logged_in();

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin','super_admin'])) {
    http_response_code(403);
    echo '권한이 없습니다.';
    exit;
}

$passwords = [];
if (!empty($_GET['pw'])) {
    $rawPws = is_array($_GET['pw']) ? $_GET['pw'] : [$_GET['pw']];
    foreach ($rawPws as $p) {
        $p = trim($p);
        if ($p !== '') $passwords[] = $p;
    }
}

$autoPrint = ($_GET['autoprint'] ?? '0') === '1';
?><!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>비밀번호 QR 라벨 인쇄</title>
  <style>
    @page { size: auto; margin: 5mm; }
    html, body { margin: 0; padding: 0; font-family: Arial, 'Malgun Gothic', sans-serif; }
    body { display: flex; flex-direction: column; align-items: center; }

    .controls {
        display: flex; gap: 10px; align-items: center;
        margin: 12px; flex-wrap: wrap;
    }
    .controls button {
        padding: 6px 18px; background: #7c3aed; color: #fff;
        border: none; border-radius: 6px; cursor: pointer; font-size: 14px;
    }
    .controls button:hover { background: #6d28d9; }
    .controls .info { font-size: 12px; color: #555; }

    #labels { display: grid; grid-template-columns: 1fr; gap: 8mm; margin: 0 auto; }

    /* 70mm × 30mm 라벨, 좌우 동일 QR 2개 */
    .label-pw {
        width: 70mm; height: 30mm;
        border: 1px solid #000;
        display: flex;
        box-sizing: border-box;
        overflow: hidden;
    }
    .label-pw .half {
        flex: 1; width: 50%; max-width: 35mm;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        padding: 1mm 2mm; box-sizing: border-box; overflow: hidden;
    }
    .label-pw .divider {
        width: 0; border-left: 1px dashed #ccc;
        height: 100%; flex-shrink: 0;
    }
    .qr-wrap {
        width: 20mm; height: 20mm; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
    }
    .qr-wrap svg {
        width: 20mm !important; height: 20mm !important; display: block;
    }
    .pw-text {
        font-family: 'Courier New', monospace;
        font-size: 5pt; font-weight: 700;
        text-align: center; margin-top: 0.5mm;
        word-break: break-all; line-height: 1.2;
        max-width: 100%; color: #000;
    }

    @media print {
      @page { size: 70mm 30mm; margin: 0; }
      html, body { margin: 0; padding: 0; }
      .controls { display: none !important; }
      #labels { gap: 0 !important; }
      .label-pw {
        width: 70mm !important; height: 30mm !important;
        border: 1px solid #000;
        page-break-inside: avoid;
        box-sizing: border-box;
      }
      .label-pw .divider { border-left: 1px solid #ccc; }
    }
  </style>
</head>
<body>

<div class="controls">
  <button id="btnPrint">&#128438; 인쇄</button>
  <?php if (empty($passwords)): ?>
    <span class="info" style="color:#c00;">출력할 비밀번호가 없습니다.</span>
  <?php else: ?>
    <span class="info"><?php echo count($passwords); ?>개 비밀번호 QR 라벨 — 라벨 1장에 동일 QR 2개</span>
  <?php endif; ?>
</div>

<div id="labels">
<?php foreach ($passwords as $idx => $pw): ?>
<div class="label-pw">
  <div class="half">
    <div class="qr-wrap" data-value="<?php echo htmlspecialchars($pw, ENT_QUOTES); ?>"></div>
    <div class="pw-text"><?php echo htmlspecialchars($pw); ?></div>
  </div>
  <div class="divider"></div>
  <div class="half">
    <div class="qr-wrap" data-value="<?php echo htmlspecialchars($pw, ENT_QUOTES); ?>"></div>
    <div class="pw-text"><?php echo htmlspecialchars($pw); ?></div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- qrcode-generator: 동기식, SVG 출력, 외부 의존성 없는 순수 JS -->
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.qr-wrap').forEach(function (el) {
        var value = el.getAttribute('data-value');
        try {
            var qr = qrcode(0, 'M');
            qr.addData(value, 'Byte');
            qr.make();
            el.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
        } catch (e) {
            el.innerHTML = '<div style="color:#c00;font-size:6pt;">오류</div>';
            console.error('QR error:', value, e);
        }
    });

    document.getElementById('btnPrint').addEventListener('click', function () {
        window.print();
    });

    <?php if ($autoPrint): ?>
    setTimeout(function () {
        window.print();
        setTimeout(function () { window.close(); }, 500);
    }, 300);
    <?php endif; ?>
});
</script>
</body>
</html>
