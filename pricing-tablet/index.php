<?php
// Tablet price lookup — barcode scanner only, read-only, English, kimsmall store 1
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <title>Price Lookup</title>
  <!-- PWA -->
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#1e293b">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="가격조회">
  <link rel="apple-touch-icon" href="./homekmart_logo.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body { height: 100%; margin: 0; overflow: hidden; touch-action: manipulation; }
    body {
      display: flex; flex-direction: column;
      background: #0f172a;
      font-family: 'Segoe UI', Arial, sans-serif;
    }

    /* ── Header ── */
    #appHeader {
      display: flex; flex-direction: column;
      align-items: center; justify-content: center; gap: 6px;
      padding: 12px 28px; height: auto;
      background: #1e293b; border-bottom: 1px solid #334155;
      flex-shrink: 0;
    }
    .brand-logo { height: 52px; width: auto; display: block; }
    .header-title { font-size: 26px; font-weight: 700; color: #ffffff;
                    letter-spacing: 0.04em; white-space: nowrap; text-align: center; }

    /* ── Scan zone ── */
    #scanZone {
      background: #1e293b; border-bottom: 1px solid #334155;
      padding: 14px 28px; flex-shrink: 0;
      display: flex; align-items: center; gap: 16px; cursor: text;
    }
    #scanZone .sz-icon { font-size: 28px; color: #334155; flex-shrink: 0; transition: color 0.2s; }
    #scanZone.active .sz-icon { color: #38bdf8; }
    #scanZone .sz-label {
      font-size: 15px; font-weight: 600; color: #334155; flex-shrink: 0;
      text-transform: uppercase; letter-spacing: 0.06em;
    }
    #scanZone.active .sz-label { color: #38bdf8; }
    #scanBuffer {
      flex: 1; font-family: monospace; font-size: 20px; font-weight: 700;
      color: #f1f5f9; letter-spacing: 0.1em;
      overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
    }
    #scanBuffer:empty::before { content: 'Scan barcode to look up price'; color: #334155;
                                font-family: 'Segoe UI', Arial, sans-serif; font-size: 15px;
                                font-weight: 400; letter-spacing: 0; }
    /* Blinking cursor when active */
    #scanBuffer.typing::after { content: '|'; animation: blink 0.7s step-end infinite; color: #38bdf8; }
    @keyframes blink { 50% { opacity: 0; } }

    /* Hidden capture input */
    #barcodeCapture {
      position: fixed; left: -9999px; top: 0;
      width: 1px; height: 1px; opacity: 0;
      pointer-events: none;
    }

    /* ── Main display area ── */
    #displayArea {
      flex: 1; display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      padding: 32px 28px; min-height: 0;
    }

    /* Idle state */
    #idleState { text-align: center; user-select: none; }
    #idleState .idle-icon { font-size: 90px; color: #1e293b; margin-bottom: 24px; }
    #idleState .idle-label { font-size: 20px; color: #1e3a5f; font-weight: 600; }

    /* Loading state */
    #loadingState { display: none; text-align: center; }
    .spinner {
      width: 64px; height: 64px; border: 5px solid #1e293b;
      border-top-color: #38bdf8; border-radius: 50%;
      animation: spin 0.7s linear infinite; margin: 0 auto 20px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    #loadingState .loading-label { font-size: 17px; color: #475569; }

    /* Error state */
    #errorState {
      display: none; text-align: center; padding: 44px 48px;
      background: #1a0505; border: 1px solid #7f1d1d;
      border-radius: 24px; max-width: 580px; width: 100%;
    }
    #errorState .error-icon { font-size: 56px; color: #ef4444; margin-bottom: 18px; }
    #errorState .error-code { font-family: monospace; font-size: 15px; color: #7f1d1d;
                              letter-spacing: 0.1em; margin-bottom: 10px; }
    #errorState .error-msg { font-size: 22px; color: #fca5a5; font-weight: 700; }

    /* Result card — two-section layout matching pricing/index.php */
    #resultCard {
      display: none; width: 100%; max-width: 900px;
      background: #1e293b; border-radius: 20px;
      border: 1px solid #334155; overflow: hidden;
      box-shadow: 0 25px 60px rgba(0,0,0,0.5);
    }
    #resultCard .result-top {
      padding: 32px 40px 24px;
      border-bottom: 1px solid #334155;
    }
    #resultCard .result-sku {
      font-size: 14px; color: #64748b; font-family: monospace;
      letter-spacing: 0.06em; margin-bottom: 10px;
    }
    #resultCard .result-name {
      font-size: 36px; font-weight: 700; color: #f1f5f9; line-height: 1.2;
    }
    #resultCard .result-bottom {
      display: flex; align-items: center; justify-content: center;
      padding: 32px 40px;
    }
    #resultCard .result-price-label {
      font-size: 13px; color: #64748b; margin-bottom: 8px; text-align: center;
      text-transform: uppercase; letter-spacing: 0.08em;
    }
    #resultCard .result-price {
      font-size: 96px; font-weight: 800; color: #38bdf8;
      line-height: 1; letter-spacing: -3px;
    }
    #resultCard .result-price.no-price { color: #475569; font-size: 44px; letter-spacing: 0; }
    #resultCard .result-currency {
      font-size: 32px; color: #64748b; margin-right: 8px;
      align-self: flex-end; padding-bottom: 14px;
    }
  </style>
</head>
<body>

  <!-- Header -->
  <header id="appHeader">
    <img src="./homekmart_logo.png" alt="Home K Mart" class="brand-logo">
    <div class="header-title">Price Lookup &nbsp;/&nbsp; 가격조회</div>
  </header>

  <!-- Scan zone (visual only — click to re-focus) -->
  <div id="scanZone">
    <div class="sz-icon"><i class="fa fa-barcode"></i></div>
    <div class="sz-label">Scan</div>
    <div id="scanBuffer"></div>
  </div>

  <!-- Hidden capture input: always focused, captures barcode scanner keystrokes -->
  <input
    id="barcodeCapture"
    type="text"
    autocomplete="off"
    autocorrect="off"
    autocapitalize="off"
    spellcheck="false"
    inputmode="none"
    tabindex="0"
    aria-label="barcode scanner input"
  >

  <!-- Main display -->
  <div id="displayArea">

    <div id="idleState">
      <div class="idle-icon"><i class="fa fa-tag"></i></div>
      <div class="idle-label">Ready to scan</div>
    </div>

    <div id="loadingState">
      <div class="spinner"></div>
      <div class="loading-label">Looking up price...</div>
    </div>

    <div id="errorState">
      <div class="error-icon"><i class="fa fa-circle-exclamation"></i></div>
      <div class="error-code" id="errorCode"></div>
      <div class="error-msg" id="errorMsg">Product not found</div>
    </div>

    <div id="resultCard">
      <div class="result-top">
        <div class="result-sku" id="rSku"></div>
        <div class="result-name" id="rNameEn"></div>
      </div>
      <div class="result-bottom">
        <div style="text-align:center">
          <div class="result-price-label">Selling Price</div>
          <div style="display:flex;align-items:flex-end;justify-content:center">
            <span class="result-currency" id="rCurrencySymbol" style="display:none"></span>
            <span class="result-price" id="rPrice"></span>
          </div>
        </div>
      </div>
    </div>

  </div>

<script>
const captureInput = document.getElementById('barcodeCapture');
const scanZone     = document.getElementById('scanZone');
const scanBuffer   = document.getElementById('scanBuffer');

// ── Focus management ──────────────────────────────────────────
function ensureFocus() { captureInput.focus(); }

// Re-focus immediately whenever the input loses focus (most important handler)
captureInput.addEventListener('blur', () => { setTimeout(ensureFocus, 0); });

// Re-focus on any user interaction and on tab visibility restore
document.addEventListener('click', ensureFocus);
document.addEventListener('touchend', ensureFocus);
document.addEventListener('visibilitychange', () => { if (!document.hidden) ensureFocus(); });

ensureFocus();

// ── State helpers ────────────────────────────────────────────
// Use explicit 'block' — setting '' would revert to CSS display:none
function showState(name) {
  document.getElementById('idleState').style.display    = name === 'idle'    ? 'block' : 'none';
  document.getElementById('loadingState').style.display = name === 'loading' ? 'block' : 'none';
  document.getElementById('errorState').style.display   = name === 'error'   ? 'block' : 'none';
  document.getElementById('resultCard').style.display   = name === 'result'  ? 'block' : 'none';
}

function showResult(product) {
  document.getElementById('rSku').textContent    = product.sku;
  document.getElementById('rNameEn').textContent = product.name_en || '—';

  const priceEl = document.getElementById('rPrice');
  const symEl   = document.getElementById('rCurrencySymbol');

  if (product.selling_price && product.selling_price !== 'N/A' && product.selling_price !== '0') {
    priceEl.textContent  = product.selling_price;
    priceEl.className    = 'result-price';
  } else {
    priceEl.textContent  = 'Price not set';
    priceEl.className    = 'result-price no-price';
  }
  showState('result');
}

function showError(code, msg) {
  document.getElementById('errorCode').textContent = code ? 'Scanned: ' + code : '';
  document.getElementById('errorMsg').textContent  = msg || 'Product not found';
  showState('error');
}

// ── Barcode capture ──────────────────────────────────────────
captureInput.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    e.preventDefault();
    const raw    = captureInput.value;
    const barcode = raw.trim().replace(/^,+/, ''); // strip leading commas (scanner artifact)
    captureInput.value = '';
    clearBuffer();
    if (!barcode) return;
    doSearch(barcode);
  }
});

captureInput.addEventListener('input', () => {
  // Show scanned characters in the scan zone buffer (visual feedback)
  const val = captureInput.value;
  scanBuffer.textContent = val;
  scanBuffer.classList.toggle('typing', val.length > 0);
  scanZone.classList.toggle('active', val.length > 0);
});

function clearBuffer() {
  scanBuffer.textContent = '';
  scanBuffer.classList.remove('typing');
  scanZone.classList.remove('active');
}

// ── Search ────────────────────────────────────────────────────
function doSearch(barcode) {
  showState('loading');
  fetch('ajax_search.php?barcode=' + encodeURIComponent(barcode))
    .then(r => r.json())
    .then(data => {
      if (data.success) showResult(data.product);
      else showError(barcode, data.message || 'Product not found.');
    })
    .catch(() => showError(barcode, 'Connection error. Please try again.'));
}

function escHtml(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('./sw.js').catch(() => {});
}
</script>
</body>
</html>
