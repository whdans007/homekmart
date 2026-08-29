<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';

$stores = [];
// 로그인한 사용자의 소속 점포 (헤더와 동일한 조회 패턴: users.store_id → stores)
$sessionStoreId = 0;
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // is_active 컬럼이 없는 환경도 안전하게 처리 (다른 admin 페이지와 동일한 쿼리 패턴)
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    if (is_logged_in()) {
        $st = $pdo->prepare("SELECT store_id FROM users WHERE id = ?");
        $st->execute([$_SESSION['user_id']]);
        $sessionStoreId = (int)($st->fetchColumn() ?: 0);
        if ($sessionStoreId) {
            // 세션 store_id를 DB 값으로 강제 동기화 (변조 방지, office 헤더와 동일 패턴)
            $_SESSION['store_id'] = $sessionStoreId;
        }
    }
} catch (Throwable $e) {
    error_log('pricing/index.php stores query error: ' . $e->getMessage());
}

// 기본 점포: 로그인 사용자의 소속 점포 우선, 없으면 id > 0 인 첫 번째 점포 (id=0 방어)
$defaultStore = 0;
if ($sessionStoreId) {
    foreach ($stores as $s) {
        if ((int)$s['id'] === $sessionStoreId) { $defaultStore = $sessionStoreId; break; }
    }
}
if (!$defaultStore) {
    foreach ($stores as $s) {
        if ((int)$s['id'] > 0) { $defaultStore = (int)$s['id']; break; }
    }
}
if (!$defaultStore) $defaultStore = 1;
// 로그인 사용자의 점포가 유효하게 반영된 경우, 클라이언트에서 저장된 점포 선택을 무시하고 항상 이 점포를 사용
$forceSessionStore = ($sessionStoreId > 0 && $defaultStore === $sessionStoreId);

// 마스터 파일 업로드 컬럼 매핑 프리셋 (기본값 하드코딩 → DB(settings)에 저장된 값이 있으면 그것으로 덮어씀)
// 이렇게 해야 코드 배포 없이도 프리셋 값을 즉시 수정/저장할 수 있음
define('COLMAP_PRESETS_SETTING_KEY', 'pricing_colmap_presets');
$colMapPresets = [
    'kimsmall' => ['label' => 'kimsmall (A/C/J/E)', 'col_sku' => 'A', 'col_name' => 'C', 'col_cost' => 'J', 'col_price' => 'E', 'header_row' => 1],
    'posco'    => ['label' => 'POSCO (SKU:B / 상품명:C / 원가:G / 판매가:H)', 'col_sku' => 'B', 'col_name' => 'C', 'col_cost' => 'G', 'col_price' => 'H', 'header_row' => 1],
    'village'  => ['label' => 'THE VILLAGE (SKU:A / 상품명:B / 원가:E / 판매가:C)', 'col_sku' => 'A', 'col_name' => 'B', 'col_cost' => 'E', 'col_price' => 'C', 'header_row' => 1],
    'default'  => ['label' => '기본형 (A/B/C/D)', 'col_sku' => 'A', 'col_name' => 'B', 'col_cost' => 'C', 'col_price' => 'D', 'header_row' => 1],
];
$colMapPresetsSource = 'fallback(하드코딩)'; // 진단용: DB 값이 실제로 반영됐는지 페이지 소스에서 확인 가능
try {
    if (isset($pdo)) {
        require_once __DIR__ . '/../lib/settings_helper.php';
        $stored = settings_get($pdo, COLMAP_PRESETS_SETTING_KEY);
        if ($stored) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) { $colMapPresets[$k] = $v; }
                $colMapPresetsSource = 'DB(settings.' . COLMAP_PRESETS_SETTING_KEY . ')';
            }
        } else {
            $colMapPresetsSource = 'fallback(DB에 저장된 값 없음)';
        }
    } else {
        $colMapPresetsSource = 'fallback(DB 연결 실패)';
    }
} catch (Throwable $e) {
    error_log('pricing/index.php colmap presets query error: ' . $e->getMessage());
    $colMapPresetsSource = 'fallback(오류: ' . $e->getMessage() . ')';
}
$colMapPresetsJson = json_encode($colMapPresets, JSON_UNESCAPED_UNICODE);
?><!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>가격 조회 / 라벨 출력</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/@zxing/browser@0.2.1/umd/zxing-browser.min.js"></script>
  <style>
    * { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; overflow: hidden; }
    body { display: flex; flex-direction: column; background: #0f172a; font-family: 'Segoe UI', Arial, 'Malgun Gothic', sans-serif; word-break: keep-all; overflow-wrap: break-word; }

    /* ── 헤더 ── */
    #appHeader {
      display: flex; align-items: center;
      padding: 0 20px; height: 80px;
      background: #1e293b; border-bottom: 1px solid #334155;
      flex-shrink: 0; position: relative;
    }
    .header-brand { font-weight: 700; font-size: 30px; letter-spacing: -0.3px; }
    .header-brand .brand-home { color: #ef4444; }
    .header-brand .brand-kmart { color: #3b82f6; }
    .header-center {
      position: absolute; left: 50%; transform: translateX(-50%);
      display: flex; align-items: center; gap: 16px;
    }
    .header-right { margin-left: auto; }
    .mode-btn {
      padding: 18px 44px; border-radius: 10px; border: 2px solid transparent;
      font-size: 18px; font-weight: 700; cursor: pointer; transition: all 0.15s;
      letter-spacing: -0.3px;
    }
    .mode-btn.lookup { background: #0ea5e9; color: #fff; border-color: #0ea5e9; }
    .mode-btn.lookup:not(.active) { background: transparent; color: #94a3b8; border-color: #334155; }
    .mode-btn.lookup:not(.active):hover { border-color: #0ea5e9; color: #0ea5e9; }
    .mode-btn.print-mode { background: #16a34a; color: #fff; border-color: #16a34a; }
    .mode-btn.print-mode:not(.active) { background: transparent; color: #94a3b8; border-color: #334155; }
    .mode-btn.print-mode:not(.active):hover { border-color: #16a34a; color: #16a34a; }
    /* ── 언어 토글 ── */
    .lang-toggle { display: flex; align-items: center; margin-right: 12px; }
    .lang-btn {
      padding: 8px 14px; border-radius: 8px; border: 1.5px solid #334155;
      font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.15s;
      background: transparent; color: #94a3b8;
    }
    .lang-btn:first-child { border-radius: 8px 0 0 8px; border-right: none; }
    .lang-btn:last-child { border-radius: 0 8px 8px 0; }
    .lang-btn.active { background: #7c3aed; color: #fff; border-color: #7c3aed; }
    .lang-btn:not(.active):hover { border-color: #7c3aed; color: #7c3aed; }
    /* ── 점포 표시 배지 ── */
    #storeDisplay { white-space: nowrap; }

    /* ── 환경설정 버튼 ── */
    #settingsBtn {
      display: flex; align-items: center; gap: 6px;
      background: #0f172a; border: 1.5px solid #334155; color: #94a3b8;
      border-radius: 8px; padding: 8px 14px; cursor: pointer;
      font-size: 13px; font-weight: 600; transition: all 0.15s; margin-left: 10px;
    }
    #settingsBtn:hover { border-color: #94a3b8; color: #f1f5f9; }

    /* ── 환경설정 모달 ── */
    #settingsOverlay {
      display: none; position: fixed; inset: 0; z-index: 9000;
      background: rgba(0,0,0,0.65); backdrop-filter: blur(4px);
    }
    #settingsOverlay.open { display: flex; align-items: center; justify-content: center; }
    #settingsModal {
      background: #1e293b; border: 1px solid #334155; border-radius: 20px;
      width: 640px; max-width: 96vw; max-height: 90vh;
      box-shadow: 0 30px 80px rgba(0,0,0,0.6); display: flex; flex-direction: column; overflow: hidden;
    }
    #settingsModal .sm-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 20px 24px 16px; border-bottom: 1px solid #334155; flex-shrink: 0;
    }
    #settingsModal .sm-title { font-size: 17px; font-weight: 700; color: #f1f5f9; }
    #settingsModal .sm-close {
      background: none; border: none; color: #64748b; cursor: pointer;
      font-size: 20px; padding: 4px 8px; border-radius: 6px; transition: color 0.15s;
    }
    #settingsModal .sm-close:hover { color: #f1f5f9; }
    /* 탭 */
    .sm-tabs { display: flex; gap: 0; padding: 12px 24px 0; border-bottom: 1px solid #334155; flex-shrink: 0; }
    .sm-tab {
      padding: 8px 16px 12px; font-size: 13px; font-weight: 600; cursor: pointer;
      color: #64748b; border-bottom: 2px solid transparent; margin-bottom: -1px;
      background: none; border-top: none; border-left: none; border-right: none;
      transition: all 0.15s;
    }
    .sm-tab:hover { color: #94a3b8; }
    .sm-tab.active { color: #38bdf8; border-bottom-color: #38bdf8; }
    /* 탭 컨텐츠 */
    .sm-body { flex: 1; overflow-y: auto; padding: 20px 24px 24px; }
    .sm-pane { display: none; }
    .sm-pane.active { display: block; }
    /* 폼 요소 */
    .sm-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
    .sm-input {
      width: 100%; background: #0f172a; border: 1.5px solid #334155; color: #f1f5f9;
      border-radius: 8px; padding: 10px 14px; font-size: 15px; letter-spacing: 0.04em;
      outline: none; transition: border-color 0.2s;
    }
    .sm-input:focus { border-color: #38bdf8; }
    .sm-input::placeholder { color: #475569; font-size: 13px; }
    .sm-input-group { display: flex; gap: 8px; align-items: center; }
    .sm-input-group .sm-input { flex: 1; }
    .sm-search-btn {
      background: #0ea5e9; color: #fff; border: none; border-radius: 8px;
      padding: 10px 16px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.15s;
      white-space: nowrap;
    }
    .sm-search-btn:hover { background: #0284c7; }
    /* 상품 정보 카드 */
    .sm-product-card {
      display: none; margin-top: 14px; background: #0f172a; border: 1px solid #334155;
      border-radius: 12px; padding: 14px 16px;
    }
    .sm-product-card.visible { display: block; }
    .sm-product-card .spc-sku { font-size: 11px; color: #475569; font-family: monospace; margin-bottom: 4px; }
    .sm-product-card .spc-name-en { font-size: 16px; font-weight: 700; color: #f1f5f9; }
    .sm-product-card .spc-name-ko { font-size: 13px; color: #94a3b8; margin-top: 2px; }
    .sm-product-card .spc-price { font-size: 22px; font-weight: 800; color: #38bdf8; margin-top: 6px; }
    /* 폼 필드 그룹 */
    .sm-field { margin-top: 14px; }
    .sm-save-btn {
      margin-top: 14px; width: 100%; background: #16a34a; color: #fff; border: none;
      border-radius: 8px; padding: 12px; font-size: 15px; font-weight: 700; cursor: pointer;
      transition: background 0.15s;
    }
    .sm-save-btn:hover { background: #15803d; }
    .sm-save-btn:disabled { background: #334155; color: #64748b; cursor: not-allowed; }
    .sm-msg { font-size: 13px; font-weight: 600; margin-top: 10px; padding: 8px 12px; border-radius: 8px; display: none; }
    .sm-msg.success { background: #052e16; color: #4ade80; display: block; }
    .sm-msg.error   { background: #2d0a0a; color: #f87171; display: block; }
    /* 점포 목록 (설정 내) */
    .sm-store-list { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
    .sm-store-item {
      display: flex; align-items: center; gap: 12px; padding: 12px 16px;
      border-radius: 10px; border: 1.5px solid #334155; cursor: pointer;
      font-size: 14px; font-weight: 600; color: #cbd5e1;
      transition: all 0.15s; background: #0f172a;
    }
    .sm-store-item:hover { border-color: #0ea5e9; color: #f1f5f9; background: #0c1a2e; }
    .sm-store-item.active { border-color: #0ea5e9; background: #0c2340; color: #38bdf8; }
    .sm-store-item .ssi-icon { color: #38bdf8; font-size: 15px; width: 20px; text-align: center; }
    .sm-store-item .ssi-check { margin-left: auto; color: #0ea5e9; font-size: 14px; display: none; }
    .sm-store-item.active .ssi-check { display: block; }
    /* 언어 선택 탭 */
    .lang-toggle { display: flex; gap: 6px; margin-top: 8px; }
    .lang-btn {
      flex: 1; padding: 8px; border: 1.5px solid #334155; border-radius: 8px;
      background: #0f172a; color: #64748b; font-size: 13px; font-weight: 600;
      cursor: pointer; transition: all 0.15s;
    }
    .lang-btn.active { border-color: #38bdf8; background: #0c2340; color: #38bdf8; }

    /* ── 자동완성 드롭다운 ── */
    .suggest-wrap { position: relative; flex: 1; }
    .suggest-wrap .sm-input,
    .suggest-wrap #lookupInput,
    .suggest-wrap #printInput { width: 100%; }
    .suggest-dropdown {
      display: none; position: absolute; left: 0; top: calc(100% + 4px);
      background: #1e293b; border: 1px solid #334155; border-radius: 12px;
      z-index: 500; width: 100%; max-height: 320px; overflow-y: auto;
      box-shadow: 0 16px 40px rgba(0,0,0,0.5);
    }
    .suggest-dropdown.open { display: block; }
    .suggest-item {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 14px; cursor: pointer; transition: background 0.1s;
      border-bottom: 1px solid #273449;
    }
    .suggest-item:last-child { border-bottom: none; }
    .suggest-item:hover, .suggest-item.focused { background: #334155; }
    .suggest-item .si-sku { font-family: monospace; font-size: 11px; color: #64748b; width: 100px; flex-shrink: 0; }
    .suggest-item .si-name { flex: 1; min-width: 0; }
    .suggest-item .si-name-en { font-size: 13px; font-weight: 600; color: #f1f5f9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .suggest-item .si-name-ko { font-size: 11px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .suggest-item .si-price { font-size: 13px; font-weight: 700; color: #38bdf8; flex-shrink: 0; }
    .suggest-empty { padding: 14px; text-align: center; color: #475569; font-size: 13px; }
    /* 프라이싱 화면 자동완성은 밝은 테마 */
    #printScreen .suggest-dropdown {
      background: #fff; border-color: #e2e8f0;
      box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    }
    #printScreen .suggest-item { border-bottom-color: #f1f5f9; }
    #printScreen .suggest-item:hover, #printScreen .suggest-item.focused { background: #f8fafc; }
    #printScreen .suggest-item .si-sku { color: #94a3b8; }
    #printScreen .suggest-item .si-name-en { color: #1f2937; }
    #printScreen .suggest-item .si-name-ko { color: #6b7280; }
    #printScreen .suggest-item .si-price { color: #2563eb; }
    #printScreen .suggest-empty { color: #9ca3af; }

    /* ── 가격 조회 화면 ── */
    #lookupScreen {
      flex: 1; display: flex; flex-direction: column;
      min-height: 0; overflow-y: scroll; scrollbar-gutter: stable;
    }
    /* 바코드 입력 영역 */
    #lookupInputBar {
      background: #1e293b; padding: 12px 20px;
      display: flex; align-items: center; gap: 10px;
      border-bottom: 1px solid #334155; flex-shrink: 0;
    }
    #lookupInput {
      flex: 1; background: #0f172a; border: 1.5px solid #334155; color: #f1f5f9;
      border-radius: 8px; padding: 10px 16px; font-size: 18px; letter-spacing: 0.05em;
      outline: none; transition: border-color 0.2s;
    }
    #lookupInput:focus { border-color: #38bdf8; }
    #lookupInput::placeholder { color: #475569; font-size: 14px; }
    .lookup-search-btn {
      background: #0ea5e9; color: #fff; border: none; border-radius: 8px;
      padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer;
      transition: background 0.15s;
    }
    .lookup-search-btn:hover { background: #0284c7; }
    /* 카메라 바코드 스캔 버튼 (모바일 전용) */
    .lookup-scan-btn {
      display: none; background: #16a34a; color: #fff; border: none; border-radius: 8px;
      width: 42px; height: 42px; align-items: center; justify-content: center;
      font-size: 17px; cursor: pointer; transition: background 0.15s; flex-shrink: 0;
    }
    .lookup-scan-btn:hover { background: #15803d; }

    /* ── 카메라 바코드 스캐너 모달 ── */
    #scannerOverlay {
      display: none; position: fixed; inset: 0; z-index: 6000; background: #000;
    }
    #scannerOverlay.open { display: block; }
    #scannerVideo {
      width: 100%; height: 100%; object-fit: cover; background: #000;
    }
    .scanner-frame {
      position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
      width: 78%; max-width: 420px; height: 130px;
      border: 3px solid #38bdf8; border-radius: 14px;
      box-shadow: 0 0 0 2000px rgba(0,0,0,0.45);
      pointer-events: none;
    }
    .scanner-close-btn {
      position: absolute; top: max(16px, env(safe-area-inset-top)); right: 16px;
      width: 42px; height: 42px; border-radius: 50%;
      background: rgba(15,23,42,0.75); border: 1px solid #334155; color: #f1f5f9;
      font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center;
      z-index: 2;
    }
    #scannerStatus {
      position: absolute; left: 0; right: 0; bottom: max(28px, env(safe-area-inset-bottom));
      text-align: center; color: #f1f5f9; font-size: 14px; font-weight: 600;
      padding: 0 24px; text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    }

    /* 가격 표시 영역 */
    #lookupDisplay {
      flex: 1; display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      padding: 20px; min-height: 0;
    }
    /* 대기 상태 */
    #lookupIdle {
      text-align: center;
    }
    #lookupIdle .idle-icon { font-size: 80px; color: #1e3a5f; margin-bottom: 16px; }
    #lookupIdle .idle-text { color: #334155; font-size: 22px; font-weight: 600; }
    #lookupIdle .idle-sub  { color: #1e293b; font-size: 14px; margin-top: 8px; }
    /* 상품 정보 카드 */
    #lookupResult {
      display: none; width: 100%; max-width: 900px;
      background: #1e293b; border-radius: 20px;
      border: 1px solid #334155; overflow: hidden;
      box-shadow: 0 25px 60px rgba(0,0,0,0.5);
    }
    #lookupResult .result-top {
      padding: 28px 36px 20px;
      border-bottom: 1px solid #334155;
    }
    #lookupResult .result-sku {
      font-size: 13px; color: #64748b; font-family: monospace; letter-spacing: 0.05em;
      margin-bottom: 8px;
    }
    #lookupResult .result-name-en {
      font-size: 32px; font-weight: 700; color: #f1f5f9; line-height: 1.2;
      margin-bottom: 4px;
    }
    #lookupResult .result-name-ko {
      font-size: 18px; color: #94a3b8; font-weight: 500;
    }
    #lookupResult .result-bottom {
      display: flex; align-items: center; justify-content: center; padding: 28px 36px;
    }
    #lookupResult .result-price-label {
      font-size: 13px; color: #64748b; margin-bottom: 6px; text-align: center;
    }
    #lookupResult .result-price {
      font-size: 80px; font-weight: 800; color: #38bdf8;
      line-height: 1; letter-spacing: -2px;
    }
    #lookupResult .result-price.no-price { color: #475569; font-size: 40px; }
    #lookupResult .result-currency {
      font-size: 28px; color: #64748b; margin-right: 6px; align-self: flex-end; padding-bottom: 12px;
    }

    /* ── 히든 원가조회 모달 ── */
    #staffModalOverlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.75); z-index: 2000;
      align-items: center; justify-content: center;
    }
    #staffModalOverlay.open { display: flex; }
    #staffModal {
      background: #1e293b; border: 1px solid #334155; border-radius: 20px;
      width: 90%; max-width: 640px; padding: 32px 36px;
      box-shadow: 0 30px 80px rgba(0,0,0,0.7);
      position: relative;
    }
    #staffModal .sm2-close {
      position: absolute; top: 16px; right: 16px;
      background: none; border: none; color: #475569; font-size: 20px; cursor: pointer;
    }
    #staffModal .sm2-close:hover { color: #f1f5f9; }
    #staffModal .sm2-title {
      font-size: 18px; font-weight: 700; color: #f1f5f9; margin-bottom: 20px;
      display: flex; align-items: center; gap: 8px;
    }
    #staffSearchWrap {
      display: flex; gap: 8px; margin-bottom: 20px; position: relative;
    }
    #staffInput {
      flex: 1; background: #0f172a; border: 1.5px solid #334155; color: #f1f5f9;
      border-radius: 8px; padding: 10px 16px; font-size: 16px; outline: none;
      transition: border-color 0.2s;
    }
    #staffInput:focus { border-color: #38bdf8; }
    #staffInput::placeholder { color: #475569; font-size: 13px; }
    #staffSearchBtn {
      background: #0ea5e9; color: #fff; border: none; border-radius: 8px;
      padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; flex-shrink: 0;
    }
    #staffSearchBtn:hover { background: #0284c7; }
    #staffResult { display: none; }
    #staffResult .sr-name {
      font-size: 20px; font-weight: 700; color: #f1f5f9; margin-bottom: 4px;
    }
    #staffResult .sr-sku {
      font-size: 12px; color: #475569; font-family: monospace; margin-bottom: 20px;
    }
    #staffResult .sr-prices {
      display: flex; gap: 0; border: 1px solid #334155; border-radius: 12px; overflow: hidden;
    }
    #staffResult .sr-price-cell {
      flex: 1; padding: 20px; text-align: center;
    }
    #staffResult .sr-price-cell:first-child { border-right: 1px solid #334155; }
    #staffResult .sr-price-cell .sr-label {
      font-size: 12px; color: #64748b; font-weight: 600; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em;
    }
    #staffResult .sr-price-cell .sr-val {
      font-size: 42px; font-weight: 800; line-height: 1; letter-spacing: -1px;
    }
    #staffResult .sr-price-cell.cost .sr-val { color: #fb923c; }
    #staffResult .sr-price-cell.sell .sr-val { color: #38bdf8; }
    #staffResult .sr-no-price { color: #475569; font-size: 22px; }
    #staffError { display: none; text-align: center; padding: 20px 0; }
    #staffError .se-icon { font-size: 40px; color: #ef4444; margin-bottom: 8px; }
    #staffError .se-text { color: #fca5a5; font-size: 16px; font-weight: 600; }
    .header-brand { cursor: pointer; user-select: none; }
    #staffSuggest {
      position: absolute; top: calc(100% + 4px); left: 0;
      width: calc(100% - 88px);
    }

    /* ── 핀번호 입력 모달 ── */
    #pinModalOverlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.85); z-index: 2500;
      align-items: center; justify-content: center;
    }
    #pinModalOverlay.open { display: flex; }
    #pinModal {
      background: #1e293b; border: 1px solid #334155; border-radius: 20px;
      width: 320px; padding: 28px 28px 24px;
      box-shadow: 0 30px 80px rgba(0,0,0,0.8);
      position: relative;
    }
    #pinModal .pm-close {
      position: absolute; top: 14px; right: 14px;
      background: none; border: none; color: #475569; font-size: 18px; cursor: pointer;
    }
    #pinModal .pm-close:hover { color: #f1f5f9; }
    #pinModal .pm-title {
      font-size: 16px; font-weight: 700; color: #f1f5f9;
      display: flex; align-items: center; gap: 8px; margin-bottom: 20px;
    }
    /* 4자리 도트 표시 */
    #pinDots {
      display: flex; justify-content: center; gap: 14px; margin-bottom: 20px;
    }
    .pin-dot {
      width: 18px; height: 18px; border-radius: 50%;
      border: 2px solid #475569; background: transparent;
      transition: all 0.15s;
    }
    .pin-dot.filled { background: #fb923c; border-color: #fb923c; }
    /* 오류 메시지 */
    #pinError {
      min-height: 20px; font-size: 13px; color: #f87171;
      text-align: center; margin-bottom: 14px; font-weight: 600;
    }
    /* 숫자 패드 */
    #pinPad {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
    }
    .pp-btn {
      background: #0f172a; border: 1.5px solid #334155; color: #f1f5f9;
      border-radius: 10px; font-size: 22px; font-weight: 700;
      padding: 14px 0; cursor: pointer; transition: all 0.12s; text-align: center;
    }
    .pp-btn:hover { background: #334155; border-color: #475569; }
    .pp-btn:active { transform: scale(0.93); }
    .pp-btn.del { font-size: 18px; color: #94a3b8; }
    .pp-btn.confirm {
      background: #fb923c; border-color: #fb923c; color: #fff; font-size: 18px;
    }
    .pp-btn.confirm:hover { background: #f97316; border-color: #f97316; }
    .pp-btn.confirm:disabled { background: #475569; border-color: #475569; cursor: not-allowed; }

    /* ── 핀번호 설정 (보안 탭) ── */
    .sm-pin-row {
      display: flex; gap: 8px; margin-bottom: 10px;
    }
    .sm-pin-input {
      flex: 1; background: #0f172a; border: 1.5px solid #334155; color: #f1f5f9;
      border-radius: 8px; padding: 10px 14px; font-size: 18px; letter-spacing: 8px;
      text-align: center; outline: none; transition: border-color 0.2s;
    }
    .sm-pin-input:focus { border-color: #38bdf8; }
    .sm-pin-msg { font-size: 13px; font-weight: 600; min-height: 18px; margin-top: 6px; }
    .sm-pin-msg.ok { color: #4ade80; }
    .sm-pin-msg.err { color: #f87171; }
    .sm-pin-status {
      display: flex; align-items: center; gap: 8px;
      background: #0f172a; border: 1px solid #334155; border-radius: 8px;
      padding: 10px 14px; margin-bottom: 16px; font-size: 13px;
    }
    .sm-pin-status .ps-badge {
      padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700;
    }
    .sm-pin-status .ps-badge.set { background: #14532d; color: #4ade80; }
    .sm-pin-status .ps-badge.unset { background: #450a0a; color: #fca5a5; }

    /* 오류 표시 */
    #lookupError {
      display: none; text-align: center;
    }
    #lookupError .err-icon { font-size: 60px; color: #ef4444; margin-bottom: 12px; }
    #lookupError .err-text { color: #fca5a5; font-size: 20px; font-weight: 600; }
    #lookupError .err-sub  { color: #64748b; font-size: 14px; margin-top: 8px; }

    /* ── 가격표 출력 화면 ── */
    #printScreen {
      flex: 1; display: none; flex-direction: column;
      background: #0f172a; min-height: 0; overflow-y: scroll;
      scrollbar-gutter: stable;
    }
    #printScreen .ps-inner { width: 100%; max-width: 1200px; margin: 0 auto; padding: 20px 28px; box-sizing: border-box; }
    .ps-card {
      width: 100%; box-sizing: border-box;
      background: #1e293b; border-radius: 14px; border: 1px solid #334155;
      padding: 18px 20px; margin-bottom: 16px;
    }
    /* 토글 그룹 */
    .toggle-group { display: inline-flex; background: #0f172a; border-radius: 8px; padding: 3px; gap: 3px; border: 1px solid #334155; }
    .toggle-group button {
      padding: 7px 18px; border: none; border-radius: 6px; font-size: 13px;
      font-weight: 600; cursor: pointer; transition: all 0.15s;
      background: transparent; color: #64748b; white-space: nowrap;
    }
    .toggle-group button:hover { color: #94a3b8; }
    .toggle-group button.active { background: #2563eb; color: #fff; }
    .toggle-group button.active.green { background: #16a34a; }
    /* 할인스티커 버튼 */
    .discount-sticker-btn {
      padding: 7px 14px; border: 1.5px solid #475569; border-radius: 8px;
      font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.15s;
      background: transparent; color: #94a3b8; white-space: nowrap;
    }
    .discount-sticker-btn:hover { border-color: #f1f5f9; color: #f1f5f9; }
    .discount-sticker-btn:active { transform: scale(0.95); }
    /* 입력 필드 */
    #printInput {
      width: 100%; background: #0f172a; border: 1.5px solid #334155; color: #f1f5f9;
      border-radius: 8px; padding: 10px 16px; font-size: 18px; letter-spacing: 0.05em;
      outline: none; transition: border-color 0.2s;
    }
    #printInput:focus { border-color: #38bdf8; }
    #printInput::placeholder { color: #475569; font-size: 14px; }
    /* 상태 표시줄 — 고정 높이로 폭 변화 방지 */
    #printStatusBar {
      display: flex; align-items: center; gap: 8px;
      font-size: 13px; font-weight: 600;
      padding: 10px 14px; border-radius: 10px;
      background: #172554; color: #93c5fd;
      min-height: 40px; width: 100%;
      margin-top: 12px; box-sizing: border-box;
    }
    /* 카드 제목 */
    .ps-card-title { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
    /* 섹션 레이블 */
    .ps-label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
    /* 수동 모드 테이블 */
    .ps-table th { background: #162032; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; padding: 10px 12px; }
    .ps-table td { font-size: 13px; padding: 9px 12px; vertical-align: middle; border-top: 1px solid #1e3045; color: #cbd5e1; }
    .qty-btn { width: 26px; height: 26px; border-radius: 5px; border: 1px solid #334155; background: #0f172a; color: #94a3b8; cursor: pointer; font-size: 14px; transition: all 0.1s; }
    .qty-btn:hover { background: #334155; color: #f1f5f9; }
    .qty-inp { width: 44px; text-align: center; border: 1px solid #334155; border-radius: 5px; padding: 3px; font-size: 13px; background: #0f172a; color: #f1f5f9; }
    /* 이력 행 */
    .ps-history-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid #1e3045; font-size: 13px; }
    .ps-history-row:last-child { border-bottom: none; }
    /* 자동완성 프라이싱 화면은 다크 테마 그대로 */
    #printScreen .suggest-dropdown { background: #1e293b; border-color: #334155; }
    #printScreen .suggest-item { border-bottom-color: #273449; }
    #printScreen .suggest-item:hover, #printScreen .suggest-item.focused { background: #334155; }
    #printScreen .suggest-item .si-sku { color: #64748b; }
    #printScreen .suggest-item .si-name-en { color: #f1f5f9; }
    #printScreen .suggest-item .si-name-ko { color: #94a3b8; }
    #printScreen .suggest-item .si-price { color: #38bdf8; }
    #printScreen .suggest-empty { color: #475569; }

    /* ══════════ 모바일 반응형 ══════════ */
    @media (max-width: 768px) {
      #appHeader {
        height: auto; min-height: 56px;
        flex-direction: column; align-items: stretch;
        padding: 10px 12px; gap: 8px;
      }
      .header-brand { font-size: 20px; text-align: center; order: 1; }
      .header-center {
        position: static !important; left: auto !important; transform: none !important;
        order: 2; width: 100%; justify-content: center; gap: 8px;
      }
      .header-right {
        order: 3; margin-left: 0; width: 100%;
        justify-content: center; flex-wrap: wrap; gap: 8px;
      }
      .mode-btn {
        flex: 1 1 0; min-width: 0; padding: 10px 8px; font-size: 13px;
        border-radius: 8px; white-space: nowrap; word-break: keep-all;
      }
      .lang-toggle { margin-right: 0; }
      .lang-btn { padding: 6px 10px; font-size: 11px; white-space: nowrap; }
      #storeDisplay { font-size: 11px; padding: 5px 8px !important; gap: 4px !important; white-space: nowrap; }
      #settingsBtn { padding: 6px 10px; font-size: 12px; margin-left: 0 !important; white-space: nowrap; }

      /* 가격 조회 화면 */
      #lookupInputBar { padding: 10px 12px; }
      #lookupInput { font-size: 16px; padding: 10px 12px; }
      .lookup-scan-btn { display: flex; }
      #lookupResult { max-width: 100%; border-radius: 14px; }
      #lookupResult .result-top { padding: 18px 20px 14px; }
      #lookupResult .result-name-en { font-size: 22px; }
      #lookupResult .result-name-ko { font-size: 14px; }
      #lookupResult .result-bottom { padding: 18px 20px; }
      #lookupResult .result-price { font-size: 44px; letter-spacing: -1px; }
      #lookupResult .result-price.no-price { font-size: 26px; }
      #lookupResult .result-currency { font-size: 18px; padding-bottom: 8px; }
      #lookupIdle .idle-icon { font-size: 56px; margin-bottom: 10px; }
      #lookupIdle .idle-text { font-size: 17px; }
      #lookupIdle .idle-sub { font-size: 12px; }

      /* 가격표 출력 화면 */
      #printScreen .ps-inner { padding: 12px 14px; }
      .ps-card { padding: 14px; border-radius: 12px; margin-bottom: 12px; }
      .toggle-group button { padding: 6px 12px; font-size: 12px; }
      .discount-sticker-btn { padding: 6px 10px; font-size: 12px; }
      #printInput { font-size: 16px; }
      .ps-table th, .ps-table td { padding: 6px 8px; font-size: 12px; }
      .qty-inp { width: 36px; }

      /* 환경설정 모달 */
      #settingsModal { width: 96vw; }
      .sm-tabs { flex-wrap: wrap; gap: 4px; padding: 10px 16px 0; }
      .sm-body { padding: 16px; }
      .sm-input { font-size: 16px; }

      /* 히든 원가조회 / 핀 모달 */
      #staffModal { width: 94%; padding: 24px 20px; }
      #staffInput { font-size: 16px; }
      #pinModal { width: 90vw; max-width: 320px; }
    }

    @media (max-width: 420px) {
      .header-brand { font-size: 17px; }
      .mode-btn { padding: 8px 14px; font-size: 12px; }
      .mode-btn span { display: none; }
      .mode-btn i { margin-right: 0 !important; font-size: 16px; }
      #settingsBtn span { display: none; }
      #lookupResult .result-price { font-size: 36px; }
      #lookupResult .result-name-en { font-size: 19px; }
    }
  </style>
</head>
<body>

<!-- ── 공통 헤더 ── -->
<div id="appHeader">
  <div class="header-brand"><span class="brand-home">Home</span> <span class="brand-kmart">k mart</span></div>
  <!-- 가운데 정렬 버튼 -->
  <div class="header-center">
    <button id="btnLookupMode" class="mode-btn lookup active" onclick="setScreen('lookup')">
      <i class="fas fa-search" style="margin-right:8px"></i><span data-i18n="nav.lookup">가격 조회</span>
    </button>
    <button id="btnPrintMode" class="mode-btn print-mode" onclick="setScreen('print')">
      <i class="fas fa-print" style="margin-right:8px"></i><span data-i18n="nav.print">가격표 출력</span>
    </button>
  </div>
  <!-- 오른쪽: 현재 점포 표시(읽기 전용) + 환경설정 -->
  <div class="header-right" style="display:flex;align-items:center;gap:0">
    <div class="lang-toggle">
      <button class="lang-btn active" id="btnLangKo" onclick="setLang('ko')">한국어</button>
      <button class="lang-btn" id="btnLangEn" onclick="setLang('en')">ENGLISH</button>
    </div>
    <div id="storeDisplay" style="display:flex;align-items:center;gap:6px;padding:6px 12px;background:#0c1a2e;border-radius:6px;color:#38bdf8;font-size:13px;font-weight:600;user-select:none;">
      <i class="fas fa-store" style="font-size:13px;opacity:0.8"></i>
      <span id="storeNameLabel"><?php echo !empty($stores) ? htmlspecialchars($stores[0]['name']) : '점포 없음'; ?></span>
    </div>
    <button id="settingsBtn" onclick="openSettings()">
      <i class="fas fa-cog"></i><span data-i18n="nav.settings">환경설정</span>
    </button>
  </div>
</div>

<!-- ════════════════════════════════════════
     환경설정 모달
════════════════════════════════════════ -->
<div id="settingsOverlay" onclick="handleOverlayClick(event)">
  <div id="settingsModal">
    <!-- 헤더 -->
    <div class="sm-header">
      <div class="sm-title"><i class="fas fa-cog" style="color:#38bdf8;margin-right:8px"></i><span data-i18n="settings.title">환경설정</span></div>
      <button class="sm-close" onclick="closeSettings()"><i class="fas fa-times"></i></button>
    </div>
    <!-- 탭 -->
    <div class="sm-tabs">
      <button class="sm-tab active" onclick="switchSettingsTab('store')"><i class="fas fa-store" style="margin-right:5px"></i><span data-i18n="settings.tab_store">점포 선택</span></button>
      <button class="sm-tab" onclick="switchSettingsTab('upload')"><i class="fas fa-file-upload" style="margin-right:5px"></i><span data-i18n="settings.tab_upload">마스터 파일 업로드</span></button>
      <button class="sm-tab" onclick="switchSettingsTab('security')"><i class="fas fa-shield-alt" style="margin-right:5px"></i>보안</button>
    </div>
    <!-- 본문 -->
    <div class="sm-body">

      <!-- ① 점포 선택 -->
      <div class="sm-pane active" id="paneStore">
        <div class="sm-label" data-i18n="settings.store_instruction">점포를 선택하면 해당 점포의 가격으로 조회합니다</div>
        <div class="sm-store-list">
          <?php foreach ($stores as $s): ?>
          <div class="sm-store-item" data-id="<?php echo (int)$s['id']; ?>"
               onclick="settingsSelectStore(<?php echo (int)$s['id']; ?>, <?php echo htmlspecialchars(json_encode($s['name']), ENT_QUOTES); ?>)">
            <span class="ssi-icon"><i class="fas fa-store"></i></span>
            <span><?php echo htmlspecialchars($s['name']); ?></span>
            <i class="fas fa-check-circle ssi-check"></i>
          </div>
          <?php endforeach; ?>
          <?php if (empty($stores)): ?>
          <div style="color:#475569;font-size:13px;text-align:center;padding:20px 0" data-i18n="settings.no_stores">등록된 점포가 없습니다</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ② 마스터 파일 업로드 -->
      <div class="sm-pane" id="paneUpload">
        <!-- 대상 점포 (조회용 점포 선택과 무관하게 독립적으로 유지됨) -->
        <div style="background:#162032;border:1px solid #1e3a5f;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#93c5fd;display:flex;align-items:center;gap:8px">
          <i class="fas fa-store" style="flex-shrink:0"></i>
          <span style="flex-shrink:0">업로드/컬럼매핑 대상 점포:</span>
          <select id="uploadStoreSelect" onchange="onUploadStoreChange(this)"
                  style="flex:1;background:#0f172a;border:1px solid #38bdf8;border-radius:6px;color:#38bdf8;font-weight:700;font-size:13px;padding:5px 8px">
            <?php foreach ($stores as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- 소스 선택 탭 -->
        <div style="display:flex;gap:0;margin-bottom:12px;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:3px">
          <button id="srcTabUpload" onclick="setUploadSource('upload')"
            style="flex:1;padding:7px 0;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;background:#0ea5e9;color:#fff;transition:all 0.15s">
            <i class="fas fa-upload" style="margin-right:5px"></i>파일 업로드
          </button>
          <button id="srcTabNas" onclick="setUploadSource('nas')"
            style="flex:1;padding:7px 0;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;background:transparent;color:#64748b;transition:all 0.15s">
            <i class="fas fa-network-wired" style="margin-right:5px"></i>NAS 폴더
          </button>
        </div>

        <!-- 파일 업로드 패널 -->
        <div id="srcPanelUpload">
          <div style="margin-bottom:12px">
            <label id="uploadDropZone" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;border:2px dashed #334155;border-radius:10px;padding:20px 16px;cursor:pointer;transition:border-color 0.2s;background:#0f172a" onmouseover="this.style.borderColor='#38bdf8'" onmouseout="this.style.borderColor='#334155'">
              <i class="fas fa-file-excel" style="font-size:28px;color:#22c55e"></i>
              <span style="font-size:13px;color:#94a3b8">클릭하여 파일 선택 또는 드래그 앤 드롭</span>
              <span style="font-size:11px;color:#475569">.xlsx, .xls, .csv 지원</span>
              <span id="uploadFileName" style="font-size:12px;color:#38bdf8;font-weight:600;display:none"></span>
              <input type="file" id="uploadFileInput" accept=".xlsx,.xls,.csv" style="display:none" onchange="onUploadFileChange(this)">
            </label>
          </div>
          <button id="uploadBtn" onclick="uploadMasterFile('upload')"
            style="width:100%;background:#0ea5e9;color:#fff;border:none;border-radius:8px;padding:11px 0;font-size:14px;font-weight:700;cursor:pointer;transition:background 0.15s;display:flex;align-items:center;justify-content:center;gap:8px"
            onmouseover="this.style.background='#0284c7'" onmouseout="this.style.background='#0ea5e9'">
            <i class="fas fa-upload"></i><span>업로드 시작</span>
          </button>
        </div>

        <!-- NAS 폴더 패널 -->
        <div id="srcPanelNas" style="display:none">
          <div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:12px;color:#94a3b8;line-height:1.7">
            <i class="fas fa-info-circle" style="color:#38bdf8;margin-right:5px"></i>
            파일을 SMB/NAS 공유 폴더 <strong style="color:#f1f5f9">uploads/pos_import/</strong>에 복사하거나, 아래에서 직접 업로드하세요.
          </div>

          <!-- NAS로 파일 업로드 -->
          <div style="margin-bottom:12px">
            <div style="font-size:12px;font-weight:600;color:#64748b;margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em">NAS 폴더에 파일 업로드</div>
            <label id="nasUploadZone" style="display:flex;align-items:center;gap:8px;border:1px dashed #334155;border-radius:8px;padding:9px 12px;cursor:pointer;background:#0f172a;transition:border-color 0.2s" onmouseover="this.style.borderColor='#7c3aed'" onmouseout="this.style.borderColor='#334155'">
              <i class="fas fa-upload" style="color:#7c3aed;font-size:15px;flex-shrink:0"></i>
              <span id="nasUploadFileName" style="font-size:12px;color:#64748b;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">클릭하여 파일 선택 (.xlsx, .xls, .csv)</span>
              <input type="file" id="nasUploadFileInput" accept=".xlsx,.xls,.csv" style="display:none" onchange="onNasUploadFileChange(this)">
            </label>
            <button id="nasUploadBtn" onclick="uploadToNas()"
              style="margin-top:6px;width:100%;background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:9px 0;font-size:13px;font-weight:700;cursor:pointer;transition:background 0.15s;display:flex;align-items:center;justify-content:center;gap:6px"
              onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='#7c3aed'">
              <i class="fas fa-upload"></i><span>NAS 폴더에 업로드</span>
            </button>
            <div id="nasUploadResult" style="display:none;margin-top:6px;font-size:12px;font-weight:600;line-height:1.5"></div>
          </div>

          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <div class="sm-label" style="margin:0;flex:1">NAS 폴더의 파일 목록</div>
            <button onclick="loadNasFiles()" style="background:none;border:1px solid #334155;border-radius:6px;color:#94a3b8;font-size:12px;padding:4px 10px;cursor:pointer;transition:all 0.15s" onmouseover="this.style.borderColor='#38bdf8';this.style.color='#38bdf8'" onmouseout="this.style.borderColor='#334155';this.style.color='#94a3b8'">
              <i class="fas fa-sync-alt"></i> 새로고침
            </button>
          </div>
          <div id="nasFileList" style="background:#0f172a;border:1px solid #334155;border-radius:8px;min-height:60px;max-height:140px;overflow-y:auto;margin-bottom:10px">
            <div style="padding:16px;text-align:center;color:#475569;font-size:13px">새로고침 버튼을 눌러 파일 목록을 불러오세요</div>
          </div>
          <button id="nasImportBtn" onclick="updateMasterFile()"
            style="width:100%;background:#16a34a;color:#fff;border:none;border-radius:8px;padding:11px 0;font-size:14px;font-weight:700;cursor:pointer;transition:background 0.15s;display:flex;align-items:center;justify-content:center;gap:8px"
            onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
            <i class="fas fa-rotate"></i><span>마스터파일 업데이트</span>
          </button>
        </div>

        <!-- 컬럼 매핑 설정 (접을 수 있는 섹션) -->
        <div style="margin-top:14px">
          <button onclick="toggleColMap()" style="width:100%;background:none;border:1px solid #334155;border-radius:8px;padding:8px 14px;color:#64748b;font-size:12px;font-weight:600;cursor:pointer;text-align:left;display:flex;align-items:center;gap:6px;transition:border-color 0.15s" onmouseover="this.style.borderColor='#94a3b8'" onmouseout="this.style.borderColor='#334155'">
            <i class="fas fa-table" style="color:#38bdf8"></i>
            <span>컬럼 매핑 설정 (점포별 파일 형식)</span>
            <i id="colMapChevron" class="fas fa-chevron-down" style="margin-left:auto;font-size:11px;transition:transform 0.2s"></i>
          </button>
          <div id="colMapPanel" style="display:none;background:#0f172a;border:1px solid #334155;border-top:none;border-radius:0 0 8px 8px;padding:12px 14px">
            <div style="font-size:11px;color:#64748b;margin-bottom:8px;line-height:1.6">
              각 데이터가 있는 <strong style="color:#94a3b8">엑셀 열 문자</strong>를 입력하세요 (예: A, B, C…). 헤더가 없으면 헤더행=0.
            </div>
            <div style="display:flex;gap:6px;margin-bottom:4px">
              <button class="cm-preset-btn" data-preset="kimsmall" onclick="applyPreset('kimsmall')" style="flex:1;background:#0f172a;border:1px solid #0ea5e9;border-radius:6px;color:#0ea5e9;font-size:12px;font-weight:700;padding:6px 0;cursor:pointer;transition:all 0.15s" onmouseover="this.style.background='#0ea5e9';this.style.color='#fff'" onmouseout="this.style.background='#0f172a';this.style.color='#0ea5e9'">
                <i class="fas fa-store" style="margin-right:4px"></i>kimsmall 프리셋
              </button>
              <button class="cm-preset-btn" data-preset="posco" onclick="applyPreset('posco')" style="flex:1;background:#0f172a;border:1px solid #f59e0b;border-radius:6px;color:#f59e0b;font-size:12px;font-weight:700;padding:6px 0;cursor:pointer;transition:all 0.15s" onmouseover="this.style.background='#f59e0b';this.style.color='#fff'" onmouseout="this.style.background='#0f172a';this.style.color='#f59e0b'">
                <i class="fas fa-store" style="margin-right:4px"></i>POSCO 프리셋
              </button>
              <button class="cm-preset-btn" data-preset="village" onclick="applyPreset('village')" style="flex:1;background:#0f172a;border:1px solid #22c55e;border-radius:6px;color:#22c55e;font-size:12px;font-weight:700;padding:6px 0;cursor:pointer;transition:all 0.15s" onmouseover="this.style.background='#22c55e';this.style.color='#fff'" onmouseout="this.style.background='#0f172a';this.style.color='#22c55e'">
                <i class="fas fa-store" style="margin-right:4px"></i>THE VILLAGE 프리셋
              </button>
              <button class="cm-preset-btn" data-preset="default" onclick="applyPreset('default')" style="flex:1;background:#0f172a;border:1px solid #334155;border-radius:6px;color:#64748b;font-size:12px;font-weight:700;padding:6px 0;cursor:pointer;transition:all 0.15s" onmouseover="this.style.background='#1e293b';this.style.color='#94a3b8'" onmouseout="this.style.background='#0f172a';this.style.color='#64748b'">
                <i class="fas fa-undo" style="margin-right:4px"></i>기본형
              </button>
            </div>
            <div id="presetEditHint" style="display:none;font-size:11px;color:#38bdf8;margin-bottom:10px"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
              <div>
                <div class="sm-label" style="margin-bottom:4px">SKU/바코드 열</div>
                <input id="cmColSku" type="text" maxlength="3" value="A" class="sm-input" style="text-transform:uppercase;font-size:18px;font-weight:700;text-align:center;padding:8px" oninput="this.value=this.value.toUpperCase();colMapTouched()">
              </div>
              <div>
                <div class="sm-label" style="margin-bottom:4px">상품명 열</div>
                <input id="cmColName" type="text" maxlength="3" value="B" class="sm-input" style="text-transform:uppercase;font-size:18px;font-weight:700;text-align:center;padding:8px" oninput="this.value=this.value.toUpperCase();colMapTouched()">
              </div>
              <div>
                <div class="sm-label" style="margin-bottom:4px">원가 열</div>
                <input id="cmColCost" type="text" maxlength="3" value="C" class="sm-input" style="text-transform:uppercase;font-size:18px;font-weight:700;text-align:center;padding:8px" oninput="this.value=this.value.toUpperCase();colMapTouched()">
              </div>
              <div>
                <div class="sm-label" style="margin-bottom:4px">판매가 열</div>
                <input id="cmColPrice" type="text" maxlength="3" value="D" class="sm-input" style="text-transform:uppercase;font-size:18px;font-weight:700;text-align:center;padding:8px" oninput="this.value=this.value.toUpperCase();colMapTouched()">
              </div>
            </div>
            <div style="margin-top:8px">
              <div class="sm-label" style="margin-bottom:4px">헤더 행 번호 <span style="color:#475569;font-weight:400">(데이터는 다음 행부터)</span></div>
              <input id="cmHeaderRow" type="number" min="0" max="10" value="1" oninput="colMapTouched()" class="sm-input" style="width:80px;font-size:16px;font-weight:700;text-align:center;padding:8px">
            </div>
            <button onclick="saveColMap()" style="margin-top:10px;width:100%;background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:9px 0;font-size:13px;font-weight:700;cursor:pointer;transition:background 0.15s" onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='#7c3aed'">
              <i class="fas fa-save" style="margin-right:5px"></i>저장 (점포 설정 + 선택한 프리셋)
            </button>
            <div id="colMapMsg" style="margin-top:6px;font-size:12px;font-weight:600;min-height:16px;color:#4ade80"></div>
          </div>
        </div>

        <div id="uploadProgress" style="display:none;margin-top:12px;text-align:center;color:#94a3b8;font-size:13px">
          <i class="fas fa-spinner fa-spin" style="color:#38bdf8;margin-right:6px"></i>파일 처리 중... 잠시 기다려 주세요
        </div>

        <div id="uploadResult" style="display:none;margin-top:12px;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:14px;font-size:12px;color:#cbd5e1;white-space:pre-wrap;line-height:1.7;max-height:220px;overflow-y:auto"></div>
      </div>

      <!-- ③ 보안 (핀번호 설정) -->
      <div class="sm-pane" id="paneSecurity">
        <div class="sm-label" style="margin-bottom:10px">원가 조회 핀번호</div>
        <div class="sm-pin-status" id="pinStatusBadge">
          <i class="fas fa-lock" style="color:#64748b"></i>
          <span>핀번호 상태:</span>
          <span class="ps-badge unset" id="pinStatusText">확인 중...</span>
        </div>

        <div class="sm-label" style="margin-bottom:6px">현재 핀번호 <span style="font-size:11px;color:#475569;font-weight:400;">(미설정 시 빈칸, 관리자는 백도어 사용 가능)</span></div>
        <div class="sm-pin-row">
          <input type="password" id="settingCurrentPin" class="sm-pin-input" maxlength="6" placeholder="현재 핀" inputmode="numeric" autocomplete="off">
        </div>

        <div class="sm-label" style="margin-bottom:6px;margin-top:12px">새 핀번호 <span style="font-size:11px;color:#475569;font-weight:400;">(4자리 숫자)</span></div>
        <div class="sm-pin-row">
          <input type="password" id="settingNewPin" class="sm-pin-input" maxlength="4" placeholder="새 핀" inputmode="numeric" autocomplete="off">
          <input type="password" id="settingNewPinConfirm" class="sm-pin-input" maxlength="4" placeholder="확인" inputmode="numeric" autocomplete="off">
        </div>
        <div class="sm-pin-msg" id="pinChangeMsg"></div>

        <button onclick="savePinSetting()" style="width:100%;background:#0ea5e9;color:#fff;border:none;border-radius:8px;padding:11px 0;font-size:14px;font-weight:700;cursor:pointer;margin-top:14px;transition:background 0.15s" onmouseover="this.style.background='#0284c7'" onmouseout="this.style.background='#0ea5e9'">
          <i class="fas fa-key" style="margin-right:6px"></i>핀번호 변경
        </button>
        <div style="margin-top:14px;background:#0f172a;border:1px solid #1e3a5f;border-radius:8px;padding:10px 14px;font-size:12px;color:#64748b;line-height:1.7">
          <i class="fas fa-info-circle" style="color:#38bdf8;margin-right:5px"></i>
          핀번호를 잊어버렸을 경우, <strong style="color:#94a3b8">관리자 백도어</strong>를 현재 핀번호 입력란에 입력하면 변경할 수 있습니다.
        </div>
      </div>

    </div><!-- /.sm-body -->
  </div>
</div>

<!-- ════════════════════════════════════════
     화면 1: 가격 조회
════════════════════════════════════════ -->
<div id="lookupScreen">
  <!-- 바코드 입력 바 -->
  <div id="lookupInputBar">
    <i class="fas fa-barcode" style="color:#38bdf8;font-size:20px;flex-shrink:0"></i>
    <div class="suggest-wrap">
      <input id="lookupInput" type="text" placeholder="바코드 스캔 또는 상품명(한글/영문) 검색" autocomplete="off">
      <div class="suggest-dropdown" id="lookupSuggest"></div>
    </div>
    <button class="lookup-search-btn" onclick="doLookup()" style="flex-shrink:0">
      <i class="fas fa-search"></i>
    </button>
    <button class="lookup-scan-btn" onclick="openBarcodeScanner()" title="카메라로 바코드 스캔">
      <i class="fas fa-camera"></i>
    </button>
  </div>

  <!-- 카메라 바코드 스캐너 -->
  <div id="scannerOverlay">
    <video id="scannerVideo" autoplay playsinline muted></video>
    <div class="scanner-frame"></div>
    <button class="scanner-close-btn" onclick="closeBarcodeScanner()"><i class="fas fa-times"></i></button>
    <div id="scannerStatus" data-i18n="scanner.status">바코드를 사각형 안에 비춰주세요</div>
  </div>

  <!-- 결과 표시 영역 -->
  <div id="lookupDisplay">
    <!-- 대기 상태 -->
    <div id="lookupIdle">
      <div class="idle-icon"><i class="fas fa-barcode"></i></div>
      <div class="idle-text" data-i18n="lookup.idle_text">바코드를 스캔하세요</div>
      <div class="idle-sub" data-i18n="lookup.idle_sub">상품 가격이 여기에 표시됩니다</div>
    </div>

    <!-- 상품 정보 -->
    <div id="lookupResult">
      <div class="result-top">
        <div class="result-sku" id="rSku"></div>
        <div class="result-name-en" id="rNameEn" onclick="copyText(this)" title="클릭하여 상품명 복사" style="cursor:pointer"></div>
        <div class="result-name-ko" id="rNameKo" onclick="copyText(this)" title="클릭하여 상품명 복사" style="cursor:pointer"></div>
      </div>
      <div class="result-bottom">
        <div style="text-align:center">
          <div class="result-price-label" data-i18n="lookup.price_label">판매가</div>
          <div style="display:flex;align-items:flex-end;justify-content:center">
            <span class="result-currency" id="rCurrencySymbol"></span>
            <span class="result-price" id="rPrice"></span>
          </div>
        </div>
      </div>
    </div>

    <!-- 오류 -->
    <div id="lookupError">
      <div class="err-icon"><i class="fas fa-exclamation-circle"></i></div>
      <div class="err-text" id="errText" data-i18n="lookup.error_not_found">상품을 찾을 수 없습니다</div>
      <div class="err-sub" id="errSub"></div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════
     화면 2: 가격표 출력
════════════════════════════════════════ -->
<div id="printScreen">
  <div class="ps-inner">

    <!-- 모드 + 타입 선택 -->
    <div class="ps-card">
      <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start">
        <div>
          <div class="ps-label" data-i18n="print.mode_label">출력 모드</div>
          <div class="toggle-group">
            <button id="btnAutoMode" class="active" onclick="setPrintMode('auto')"><i class="fas fa-bolt" style="margin-right:5px"></i><span data-i18n="print.mode_auto">자동</span></button>
            <button id="btnManualMode" onclick="setPrintMode('manual')"><i class="fas fa-list" style="margin-right:5px"></i><span data-i18n="print.mode_manual">수동</span></button>
          </div>
        </div>
        <div>
          <div class="ps-label" data-i18n="print.type_label">라벨 종류</div>
          <div class="toggle-group">
            <button id="btnTypePricing" class="active green" onclick="setPrintType('pricing')"><i class="fas fa-tag" style="margin-right:5px"></i><span data-i18n="print.type_pricing">프라이싱</span></button>
            <button id="btnType2p" onclick="setPrintType('2p')"><i class="fas fa-copy" style="margin-right:5px"></i><span data-i18n="print.type_barcode">바코드 2p</span></button>
          </div>
        </div>
        <div style="border-left:1px solid #334155;padding-left:20px">
          <div class="ps-label">할인스티커</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="discount-sticker-btn" onclick="printDiscountSticker('20')" title="20% OFF 스티커 2장 출력">20%</button>
            <button class="discount-sticker-btn" onclick="printDiscountSticker('30')" title="30% OFF 스티커 2장 출력">30%</button>
            <button class="discount-sticker-btn" onclick="printDiscountSticker('50')" title="50% OFF 스티커 2장 출력">50%</button>
            <button class="discount-sticker-btn" onclick="printDiscountSticker('75')" title="75% OFF 스티커 2장 출력">75%</button>
            <button class="discount-sticker-btn" onclick="printDiscountSticker('1+1')" title="1+1 스티커 2장 출력" style="font-size:11px">1+1</button>
            <button class="discount-sticker-btn" onclick="printDiscountSticker('P')" title="가격을 직접 손으로 쓰는 페소(P) 수기 스티커 2장 출력" style="font-size:11px">P 수기</button>
            <button class="discount-sticker-btn" onclick="printLogoSticker()" title="HOME K MART 로고 스티커 4개 출력 (좌2 우2)" style="font-size:10px">로고</button>
          </div>
        </div>
      </div>
      <!-- 상태 표시줄: 전체 너비 고정 행 → 폭 변화 없음 -->
      <div id="printStatusBar">
        <i class="fas fa-bolt"></i>
        <span id="printStatusText" data-i18n="print.status_auto">스캔하면 자동으로 출력됩니다</span>
      </div>
    </div>

    <!-- 바코드 / 상품명 입력 -->
    <div class="ps-card">
      <div class="ps-card-title">
        <i class="fas fa-barcode" style="color:#38bdf8"></i><span data-i18n="print.search_title">바코드 / 상품명 검색</span>
      </div>
      <div style="display:flex;gap:8px">
        <div class="suggest-wrap">
          <input id="printInput" type="text" placeholder="바코드 스캔 또는 상품명(한글/영문) 검색" autocomplete="off">
          <div class="suggest-dropdown" id="printSuggest"></div>
        </div>
        <button onclick="handlePrintBarcode()" style="background:#0ea5e9;color:#fff;border:none;border-radius:8px;padding:0 20px;font-size:14px;font-weight:600;cursor:pointer;flex-shrink:0;transition:background 0.15s" onmouseover="this.style.background='#0284c7'" onmouseout="this.style.background='#0ea5e9'">
          <i class="fas fa-search"></i>
        </button>
      </div>
    </div>

    <!-- 자동 모드: 이력 -->
    <div id="autoPanel" class="ps-card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div class="ps-card-title" style="margin-bottom:0">
          <i class="fas fa-history" style="color:#38bdf8"></i><span data-i18n="print.history_title">최근 출력 이력</span>
        </div>
        <button onclick="clearPrintHistory()" style="font-size:12px;color:#475569;background:none;border:none;cursor:pointer;transition:color 0.15s" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#475569'" data-i18n="print.clear_all">전체 삭제</button>
      </div>
      <div id="printHistoryList">
        <div style="text-align:center;color:#334155;font-size:13px;padding:24px 0" data-i18n="print.history_empty">출력 이력이 없습니다</div>
      </div>
    </div>

    <!-- 수동 모드: 리스트 -->
    <div id="manualPanel" class="ps-card" style="display:none;padding:0;overflow:hidden">
      <div style="display:flex;align-items:center;gap:8px;padding:14px 18px;border-bottom:1px solid #334155">
        <div class="ps-card-title" style="margin-bottom:0">
          <i class="fas fa-list" style="color:#38bdf8"></i><span data-i18n="print.list_title">출력 목록</span>
        </div>
        <span id="listCountBadge" style="font-size:11px;background:#1e3a5f;color:#38bdf8;font-weight:700;padding:2px 8px;border-radius:20px">0</span>
        <div style="flex:1"></div>
        <button onclick="selectAllItems()" style="font-size:12px;color:#94a3b8;border:1px solid #334155;background:#0f172a;border-radius:6px;padding:5px 12px;cursor:pointer;transition:all 0.15s" onmouseover="this.style.borderColor='#38bdf8';this.style.color='#38bdf8'" onmouseout="this.style.borderColor='#334155';this.style.color='#94a3b8'" data-i18n="print.select_all">전체 선택</button>
        <button onclick="printSelectedItems()" style="font-size:12px;background:#0ea5e9;color:#fff;border:none;border-radius:6px;padding:6px 14px;font-weight:600;cursor:pointer;transition:background 0.15s" onmouseover="this.style.background='#0284c7'" onmouseout="this.style.background='#0ea5e9'"><i class="fas fa-print" style="margin-right:5px"></i><span data-i18n="print.print_selected">선택 출력</span></button>
        <button onclick="clearItems()" style="font-size:12px;color:#f87171;border:1px solid #3d1515;background:#1a0a0a;border-radius:6px;padding:5px 12px;cursor:pointer;transition:all 0.15s" onmouseover="this.style.background='#2d1010'" onmouseout="this.style.background='#1a0a0a'" data-i18n="print.clear_all">전체 삭제</button>
      </div>
      <div style="overflow-x:auto">
        <table class="ps-table" style="width:100%;border-collapse:collapse">
          <thead>
            <tr>
              <th style="width:36px"><input type="checkbox" id="checkAllItems" onchange="toggleAllItems(this)" style="accent-color:#38bdf8"></th>
              <th style="width:120px">SKU</th><th data-i18n="print.th_name">상품명</th><th style="text-align:right;width:100px" data-i18n="print.th_price">가격</th>
              <th style="width:120px">위치</th>
              <th style="text-align:center;width:120px" data-i18n="print.th_qty">수량</th><th style="width:36px"></th>
            </tr>
          </thead>
          <tbody id="itemTableBody">
            <tr><td colspan="6" style="text-align:center;color:#334155;padding:28px" data-i18n="print.list_empty">상품을 스캔하면 목록에 추가됩니다</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<script>
let currentScreen = 'lookup';
let printMode = 'auto';
let printType = 'pricing';
let storeId = <?php echo $defaultStore; ?>;
// 업로드/컬럼매핑 대상 점포: 가격조회용 storeId(로그인 계정 소속 점포로 새로고침마다 강제 복원됨)와
// 완전히 분리된 값. 그렇지 않으면 관리자가 다른 점포를 골라 컬럼매핑을 저장해도
// 새로고침 시 storeId가 내 점포로 되돌아가면서 설정이 "초기화된 것처럼" 보이는 문제가 있었음.
let uploadStoreId = parseInt(localStorage.getItem('pricing_uploadStoreId') || '') || <?php echo $defaultStore; ?>;
let currentProduct = null;
let printItems = [];
let printHistory = [];

// ── 점포 선택 ──
function selectStore(id, name) {
  storeId = id;
  localStorage.setItem('pricing_storeId', id);
  const label = document.getElementById('storeNameLabel');
  if (label) label.textContent = name;
  // active 표시 갱신 (환경설정 모달 내)
  document.querySelectorAll('.sm-store-item').forEach(el => {
    el.classList.toggle('active', parseInt(el.dataset.id) === id);
  });
  // 현재 결과 초기화 (다른 점포 가격 방지)
  showLookupState('idle');
}

// ── 화면 전환 ──
function setScreen(screen) {
  currentScreen = screen;
  const isLookup = screen === 'lookup';
  document.getElementById('lookupScreen').style.display = isLookup ? 'flex' : 'none';
  document.getElementById('printScreen').style.display  = isLookup ? 'none' : 'flex';
  document.getElementById('btnLookupMode').classList.toggle('active', isLookup);
  document.getElementById('btnPrintMode').classList.toggle('active', !isLookup);
  setTimeout(() => {
    const inp = isLookup ? 'lookupInput' : 'printInput';
    document.getElementById(inp).focus();
  }, 50);
  localStorage.setItem('pricing_screen', screen);
}

// ── 가격 조회 ──
document.getElementById('lookupInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    const dd = document.getElementById('lookupSuggest');
    if (dd.classList.contains('open') && suggestFocusIdx['lookup'] >= 0) return;
    doLookup();
  }
});

function doLookup() {
  const input = document.getElementById('lookupInput');
  const barcode = input.value.trim().replace(/^,+/, '');
  input.value = '';
  closeSuggest('lookupSuggest', 'lookup');
  if (!barcode) return;
  showLookupState('loading');
  fetch('ajax_search.php?barcode=' + encodeURIComponent(barcode) + '&store_id=' + storeId)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        showLookupState('error', data.message, barcode);
        return;
      }
      currentProduct = data.product;
      showLookupResult(data.product);
    })
    .catch(() => showLookupState('error', tl('error.network')));
}

// ── 카메라 바코드 스캔 ──
let scannerReader = null;
let scannerControls = null;
let scannerActive = false;

function openBarcodeScanner() {
  const overlay = document.getElementById('scannerOverlay');
  const statusEl = document.getElementById('scannerStatus');
  overlay.classList.add('open');
  statusEl.removeAttribute('data-i18n');
  statusEl.textContent = tl('scanner.status');

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    statusEl.textContent = tl('scanner.unsupported');
    return;
  }
  if (!window.ZXingBrowser) {
    statusEl.textContent = tl('scanner.lib_error');
    return;
  }

  scannerActive = true;
  scannerReader = new ZXingBrowser.BrowserMultiFormatReader();
  scannerReader.decodeFromConstraints(
    {
      video: {
        facingMode: { ideal: 'environment' },
        // 저해상도 스트림은 1D 바코드(EAN/UPC)를 인식하지 못하는 주 원인이라 고해상도를 요청
        width:  { ideal: 1920 },
        height: { ideal: 1080 }
      }
    },
    'scannerVideo',
    (result, err) => {
      if (!scannerActive) return;
      if (result) {
        const text = result.getText();
        closeBarcodeScanner();
        const input = document.getElementById('lookupInput');
        input.value = text;
        doLookup();
      }
      // NotFoundException은 매 프레임 스캔 실패 시 계속 발생하는 정상 흐름이므로 무시
    }
  ).then(controls => {
    scannerControls = controls;
    // 연속 자동초점 시도 (지원 기기에서만 적용되며, 미지원 기기는 조용히 무시됨)
    if (scannerActive && controls.streamVideoConstraintsApply) {
      try { controls.streamVideoConstraintsApply({ advanced: [{ focusMode: 'continuous' }] }); } catch (e) {}
    }
  }).catch(err => {
    statusEl.textContent = tl('scanner.camera_error') + (err && err.message ? ' (' + err.message + ')' : '');
  });
}

function closeBarcodeScanner() {
  scannerActive = false;
  document.getElementById('scannerOverlay').classList.remove('open');
  if (scannerControls) {
    try { scannerControls.stop(); } catch (e) {}
    scannerControls = null;
  }
  if (scannerReader) {
    try { scannerReader.reset(); } catch (e) {}
    scannerReader = null;
  }
}

function showLookupState(state, msg, barcode) {
  document.getElementById('lookupIdle').style.display   = state === 'idle' ? 'block' : 'none';
  document.getElementById('lookupResult').style.display = state === 'result' ? 'block' : 'none';
  document.getElementById('lookupError').style.display  = state === 'error' ? 'block' : 'none';
  if (state === 'error') {
    document.getElementById('errText').textContent = msg || tl('lookup.error_not_found');
    document.getElementById('errSub').textContent  = barcode ? tl('lookup.scanned_code') + barcode : '';
  }
}

function showLookupResult(p) {
  document.getElementById('rSku').textContent    = p.sku;
  document.getElementById('rNameEn').textContent = p.name_en || '';
  const koEl = document.getElementById('rNameKo');
  koEl.textContent = (p.name_ko && p.name_ko !== p.name_en) ? p.name_ko : '';
  koEl.style.display = (p.name_ko && p.name_ko !== p.name_en) ? '' : 'none';
  const priceEl = document.getElementById('rPrice');
  const symEl   = document.getElementById('rCurrencySymbol');
  if (p.selling_price && p.selling_price !== '0') {
    priceEl.textContent = p.selling_price;
    priceEl.className = 'result-price';
    symEl.style.display = 'inline';
  } else {
    priceEl.textContent = tl('lookup.no_price');
    priceEl.className = 'result-price no-price';
    symEl.style.display = 'none';
  }
  showLookupState('result');
}

// ── 상품명 클립보드 복사 ──
function copyText(el) {
  const text = (el.textContent || '').trim();
  if (!text) return;
  const done = () => showCopyToast(el);
  if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
  } else {
    // LAN HTTP 등 비보안 컨텍스트 폴백
    fallbackCopy(text, done);
  }
}
function fallbackCopy(text, done) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.cssText = 'position:fixed;top:-1000px;left:-1000px;opacity:0';
  document.body.appendChild(ta);
  ta.focus(); ta.select();
  try { if (document.execCommand('copy') && done) done(); } catch (e) {}
  document.body.removeChild(ta);
}
function showCopyToast(anchor) {
  let t = document.getElementById('copyToast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'copyToast';
    t.style.cssText = 'position:fixed;z-index:99999;background:#22c55e;color:#fff;padding:6px 12px;border-radius:8px;font-size:13px;font-weight:600;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,0.35);transition:opacity 0.2s;opacity:0;transform:translateX(-50%)';
    document.body.appendChild(t);
  }
  t.innerHTML = '<i class="fas fa-check" style="margin-right:5px"></i>상품명 복사됨';
  const r = anchor.getBoundingClientRect();
  t.style.left = (r.left + r.width / 2) + 'px';
  t.style.top  = Math.max(8, r.top - 36) + 'px';
  t.style.opacity = '1';
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.style.opacity = '0'; }, 1200);
}

function quickPrintCurrent(type) {
  if (!currentProduct) return;
  const url = 'print.php?skus=' + encodeURIComponent(currentProduct.sku)
            + '&mode=' + type + '&store_id=' + storeId + '&autoprint=1';
  window.open(url, '_blank', 'width=900,height=600,toolbar=0,menubar=0,location=0,status=0');
}

// ── 가격표 출력 ──
document.getElementById('printInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    const dd = document.getElementById('printSuggest');
    if (dd.classList.contains('open') && suggestFocusIdx['print'] >= 0) return;
    handlePrintBarcode();
  }
});

function setPrintMode(m) {
  printMode = m;
  localStorage.setItem('pricing_printMode', m);
  document.getElementById('btnAutoMode').classList.toggle('active', m === 'auto');
  document.getElementById('btnManualMode').classList.toggle('active', m === 'manual');
  document.getElementById('autoPanel').style.display   = m === 'auto'   ? 'block' : 'none';
  document.getElementById('manualPanel').style.display = m === 'manual' ? 'block' : 'none';
  setPrintStatus(m === 'auto' ? 'blue' : 'gray',
    m === 'auto' ? '<i class="fas fa-bolt mr-1"></i>' + tl('print.status_auto')
                 : '<i class="fas fa-list mr-1"></i>' + tl('print.status_manual'));
  document.getElementById('printInput').focus();
}

function setPrintType(t) {
  printType = t;
  document.getElementById('btnTypePricing').classList.toggle('active', t === 'pricing');
  document.getElementById('btnType2p').classList.toggle('active', t === '2p');
  localStorage.setItem('pricing_printType', t);
  document.getElementById('printInput').focus();
}

function printDiscountSticker(rate) {
  const url = 'print.php?mode=discount&discount=' + encodeURIComponent(rate) + '&autoprint=1';
  window.open(url, '_blank', 'width=600,height=400');
}

// HOME K MART 로고 스티커: 70x30 라벨 3장(각 좌/우) = 로고 6개 출력
function printLogoSticker() {
  const url = 'print.php?mode=logo&autoprint=1';
  window.open(url, '_blank', 'width=600,height=400');
}

function setPrintStatus(color, html) {
  const bar  = document.getElementById('printStatusBar');
  const text = document.getElementById('printStatusText');
  const icon = bar.querySelector('i');
  // 색상 맵 (다크 테마)
  const bg   = { blue:'#172554', green:'#052e16', red:'#2d0a0a', gray:'#1e293b', yellow:'#1c1500' };
  const fg   = { blue:'#93c5fd', green:'#4ade80', red:'#f87171', gray:'#94a3b8', yellow:'#fbbf24' };
  const c    = color || 'blue';
  bar.style.background = bg[c] || bg.blue;
  bar.style.color      = fg[c] || fg.blue;
  // icon 클래스만 교체 (html에서 fa-* 추출)
  const iconMatch = html.match(/fa-[\w-]+/);
  if (icon && iconMatch) {
    icon.className = 'fas ' + iconMatch[0];
  }
  // 텍스트만 업데이트 (strip tags)
  if (text) text.innerHTML = html.replace(/<[^>]+>/g, ' ').trim();
}

function handlePrintBarcode() {
  const input = document.getElementById('printInput');
  const barcode = input.value.trim().replace(/^,+/, '');
  input.value = '';
  closeSuggest('printSuggest', 'print');
  input.focus();
  if (!barcode) return;

  setPrintStatus('yellow', '<i class="fas fa-spinner fa-spin mr-1"></i>' + tl('print.status_searching'));
  fetch('ajax_search.php?barcode=' + encodeURIComponent(barcode) + '&store_id=' + storeId)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        setPrintStatus('red', '<i class="fas fa-exclamation-circle mr-1"></i>' + escHtml(data.message));
        return;
      }
      if (printMode === 'auto') {
        silentPrint(data.product);
      } else {
        addItem(data.product);
      }
    })
    .catch(() => {
      setPrintStatus('red', '<i class="fas fa-exclamation-triangle mr-1"></i>' + tl('error.network_short'));
    });
}

// iframe 기반 자동 출력: 팝업 차단 우회 + 스캔 시 인쇄 대화상자 자동 표시
// - 일반 Chrome: print.php 내부 window.print()가 인쇄 대화상자를 자동으로 띄움
// - --kiosk-printing 모드: 대화상자 없이 기본 프린터로 자동 출력 (기존과 동일)
// ※ visibility:hidden 을 쓰면 일부 환경에서 대화상자가 안 뜨거나 빈 출력이 되므로
//   화면 밖으로만 이동(left:-10000px)시키고 visibility는 숨기지 않는다.
function silentPrint(p) {
  const url = 'print.php?skus=' + encodeURIComponent(p.sku) + '&mode=' + printType + '&store_id=' + storeId + '&autoprint=1';
  const iframe = document.createElement('iframe');
  iframe.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;height:600px;border:none;';
  const removeIframe = function() { if (iframe.parentNode) iframe.parentNode.removeChild(iframe); };
  iframe.onload = function() {
    try {
      iframe.contentWindow.focus(); // 인쇄 대화상자가 확실히 뜨도록 포커스
      // 인쇄/취소 완료 후 iframe 정리 (대화상자가 열려 있는 동안 제거되지 않도록)
      iframe.contentWindow.addEventListener('afterprint', function() { setTimeout(removeIframe, 500); });
    } catch (e) {}
  };
  document.body.appendChild(iframe);
  iframe.src = url;
  // 폴백 정리: afterprint가 안 와도 5분 뒤에는 제거
  setTimeout(removeIframe, 300000);
  setPrintStatus('green', '<i class="fas fa-check-circle mr-1"></i>' + tl('print.status_printed') + escHtml(p.name_en || p.sku));
  addPrintHistory(p);
}

// 팝업 창 출력: 사용자 클릭(user gesture) 컨텍스트에서만 사용 (이력 재출력, 수동 목록)
function doPrint(p) {
  const url = 'print.php?skus=' + encodeURIComponent(p.sku) + '&mode=' + printType + '&store_id=' + storeId + '&autoprint=1';
  const win = window.open(url, '_blank', 'width=900,height=600,toolbar=0,menubar=0,location=0,status=0');
  if (!win || win.closed || typeof win.closed === 'undefined') {
    window.open(url, '_blank');
    setPrintStatus('yellow', '<i class="fas fa-exclamation-triangle mr-1"></i>' + tl('error.popup_blocked'));
  } else {
    setPrintStatus('green', '<i class="fas fa-check-circle mr-1"></i>' + tl('print.status_printed') + escHtml(p.name_en || p.sku));
  }
  addPrintHistory(p);
}

function addPrintHistory(p) {
  const t = new Date();
  const ts = String(t.getHours()).padStart(2,'0')+':'+String(t.getMinutes()).padStart(2,'0')+':'+String(t.getSeconds()).padStart(2,'0');
  printHistory.unshift({...p, ts});
  if (printHistory.length > 30) printHistory.pop();
  renderPrintHistory();
}
function renderPrintHistory() {
  const el = document.getElementById('printHistoryList');
  if (!printHistory.length) { el.innerHTML = '<div style="text-align:center;color:#9ca3af;font-size:13px;padding:20px 0">' + tl('print.history_empty') + '</div>'; return; }
  el.innerHTML = printHistory.map((h,i) => `
    <div class="ps-history-row">
      <span style="color:#475569;width:56px;font-size:12px;font-family:monospace;flex-shrink:0">${escHtml(h.ts)}</span>
      <span style="font-family:monospace;font-size:11px;color:#64748b;width:108px;flex-shrink:0">${escHtml(h.sku)}</span>
      <span style="flex:1;color:#cbd5e1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(h.name_en || h.name_ko || '')}${(h.name_ko && h.name_ko !== h.name_en) ? ' <span style="color:#94a3b8;font-size:11px">' + escHtml(h.name_ko) + '</span>' : ''}</span>
      <span style="font-weight:700;color:#38bdf8;width:70px;text-align:right;flex-shrink:0">${escHtml(h.selling_price||'')}</span>
      <button onclick="doPrint(printHistory[${i}])" style="background:#0c2340;color:#38bdf8;border:1px solid #1e4a7a;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;margin-left:8px;flex-shrink:0;transition:background 0.15s" onmouseover="this.style.background='#1e3a5f'" onmouseout="this.style.background='#0c2340'"><i class="fas fa-redo"></i></button>
    </div>`).join('');
}
function clearPrintHistory() { printHistory = []; renderPrintHistory(); }

// 수동 모드
function addItem(p) {
  const idx = printItems.findIndex(x => x.sku === p.sku);
  if (idx >= 0) { printItems[idx].qty++; setPrintStatus('blue', '<i class="fas fa-plus-circle mr-1"></i>' + tl('print.status_qty_up') + escHtml(p.name_en||p.sku) + ' ×' + printItems[idx].qty); }
  else { printItems.push({...p, qty:1, checked:true, location: p.location||''}); setPrintStatus('blue', '<i class="fas fa-plus-circle mr-1"></i>' + tl('print.status_added') + escHtml(p.name_en||p.name_ko||p.sku)); }
  renderItems();
}
function renderItems() {
  document.getElementById('listCountBadge').textContent = printItems.length;
  const tbody = document.getElementById('itemTableBody');
  if (!printItems.length) { tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:24px">' + tl('print.list_empty') + '</td></tr>'; return; }
  tbody.innerHTML = printItems.map((p,i) => `
    <tr>
      <td><input type="checkbox" ${p.checked?'checked':''} onchange="printItems[${i}].checked=this.checked" style="accent-color:#38bdf8"></td>
      <td style="font-family:monospace;font-size:11px;color:#64748b">${escHtml(p.sku)}</td>
      <td style="min-width:0">
        <div style="display:flex;align-items:center;gap:4px">
          <span style="flex-shrink:0;font-size:9px;font-weight:700;color:#f59e0b;background:#1a1400;border:1px solid #78350f;border-radius:3px;padding:1px 4px;line-height:1.4">KOR</span>
          <input id="ni_ko_${i}" type="text" value="${escHtml(p.name_ko||'')}"
            placeholder="한글명"
            style="flex:1;min-width:0;background:#0f172a;border:1px solid #334155;border-radius:5px;color:#f1f5f9;font-size:12px;font-weight:600;padding:3px 6px;box-sizing:border-box;outline:none;transition:border-color 0.2s"
            onfocus="this.style.borderColor='#38bdf8'"
            onblur="this.style.borderColor='#334155';saveItemName(${i},'ko',this.value)"
            onkeydown="if(event.key==='Enter'){this.blur()}">
          <button onclick="openNameHistory(${p.product_id||0},'${escHtml(p.sku||'')}')" title="변경 이력" style="flex-shrink:0;background:none;border:none;color:#475569;cursor:pointer;font-size:11px;padding:2px 4px;border-radius:4px;transition:color 0.15s" onmouseover="this.style.color='#38bdf8'" onmouseout="this.style.color='#475569'"><i class="fas fa-history"></i></button>
        </div>
        <div style="display:flex;align-items:center;gap:4px;margin-top:2px">
          <span style="flex-shrink:0;font-size:9px;font-weight:700;color:#38bdf8;background:#0c2340;border:1px solid #1e4a7a;border-radius:3px;padding:1px 4px;line-height:1.4">ENG</span>
          <input id="ni_en_${i}" type="text" value="${escHtml(p.name_en||'')}"
            placeholder="영문명"
            style="flex:1;min-width:0;background:#0f172a;border:1px solid #334155;border-radius:5px;color:#94a3b8;font-size:11px;padding:3px 6px;box-sizing:border-box;outline:none;transition:border-color 0.2s"
            onfocus="this.style.borderColor='#38bdf8'"
            onblur="this.style.borderColor='#334155';saveItemName(${i},'en',this.value)"
            onkeydown="if(event.key==='Enter'){this.blur()}">
        </div>
      </td>
      <td style="text-align:right;font-weight:700;color:#38bdf8">${escHtml(p.selling_price||'-')}</td>
      <td>
        <input id="loc_${i}" type="text" value="${escHtml(p.location||'')}"
          placeholder="예: H2-1-5"
          style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:5px;color:#a78bfa;font-size:12px;font-weight:600;padding:3px 6px;box-sizing:border-box;outline:none;transition:border-color 0.2s;letter-spacing:0.03em"
          onfocus="this.style.borderColor='#a78bfa'"
          onblur="this.style.borderColor='#334155';saveItemLocation(${i},this.value)"
          onkeydown="if(event.key==='Enter'){this.blur()}">
      </td>
      <td style="text-align:center">
        <div style="display:flex;align-items:center;justify-content:center;gap:3px">
          <button class="qty-btn" onclick="chgQty(${i},-1)">−</button>
          <input class="qty-inp" type="number" value="${p.qty}" min="1" onchange="printItems[${i}].qty=Math.max(1,parseInt(this.value)||1);renderItems()">
          <button class="qty-btn" onclick="chgQty(${i},1)">+</button>
        </div>
      </td>
      <td><button onclick="removeItem(${i})" style="background:none;border:none;color:#475569;cursor:pointer;font-size:12px;transition:color 0.15s" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#475569'"><i class="fas fa-trash-alt"></i></button></td>
    </tr>`).join('');
}
function saveItemName(idx, lang, value) {
  const p = printItems[idx];
  if (!p || !p.product_id) return;
  const trimmed = value.trim();
  const prev = lang === 'en' ? p.name_en : p.name_ko;
  if (trimmed === (prev || '')) return; // 변경 없음
  if (lang === 'en') printItems[idx].name_en = trimmed;
  else               printItems[idx].name_ko = trimmed;
  const inputId = 'ni_' + lang + '_' + idx;
  const el = document.getElementById(inputId);
  const fd = new FormData();
  fd.append('product_id',   p.product_id);
  fd.append('language',     lang);
  fd.append('product_name', trimmed);
  fetch('ajax_update_name.php', { method:'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (el) {
        el.style.borderColor = data.success ? '#22c55e' : '#ef4444';
        setTimeout(() => { if (document.getElementById(inputId)) el.style.borderColor = '#334155'; }, 1200);
      }
    })
    .catch(() => {
      if (el) { el.style.borderColor = '#ef4444'; setTimeout(() => { if (document.getElementById(inputId)) el.style.borderColor = '#334155'; }, 1200); }
    });
}
function saveItemLocation(idx, value) {
  const p = printItems[idx];
  if (!p || !p.product_id) return;
  const trimmed = value.trim().toUpperCase();
  if (trimmed === (p.location || '').toUpperCase()) return;
  printItems[idx].location = trimmed;
  const inputId = 'loc_' + idx;
  const el = document.getElementById(inputId);
  if (el) el.value = trimmed;
  const fd = new FormData();
  fd.append('product_id', p.product_id);
  fd.append('store_id',   storeId);
  fd.append('location',   trimmed);
  fetch('ajax_update_location.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (el) {
        el.style.borderColor = data.success ? '#a855f7' : '#ef4444';
        setTimeout(() => { if (document.getElementById(inputId)) el.style.borderColor = '#334155'; }, 1200);
      }
    })
    .catch(() => {
      if (el) { el.style.borderColor = '#ef4444'; setTimeout(() => { if (document.getElementById(inputId)) el.style.borderColor = '#334155'; }, 1200); }
    });
}
function chgQty(i,d){ printItems[i].qty=Math.max(1,(printItems[i].qty||1)+d); renderItems(); }
function removeItem(i){ printItems.splice(i,1); renderItems(); }
function selectAllItems(){ printItems.forEach(p=>p.checked=true); renderItems(); }
function toggleAllItems(cb){ printItems.forEach(p=>p.checked=cb.checked); renderItems(); }
function clearItems(){ printItems=[]; renderItems(); }
function printSelectedItems(){
  const sel = printItems.filter(p=>p.checked);
  if (!sel.length){ setPrintStatus('red','<i class="fas fa-exclamation-circle mr-1"></i>' + tl('print.no_selection')); return; }
  const skus=[];
  sel.forEach(p=>{ for(let i=0;i<(p.qty||1);i++) skus.push(p.sku); });
  const url='print.php?skus='+skus.map(encodeURIComponent).join(',')+'&mode='+printType+'&store_id='+storeId;
  window.open(url,'_blank','width=900,height=600,toolbar=0,menubar=0,location=0,status=0');
  setPrintStatus('green','<i class="fas fa-print mr-1"></i>'+skus.length + tl('print.status_opened'));
}

// ════════════════════════════════════════
// 자동완성 (실시간 검색)
// ════════════════════════════════════════
let suggestTimers = {};
let suggestFocusIdx = { lookup: -1, print: -1, staff: -1 };
let suggestSeq = {}; // 요청 순번 추적 — 오래된(out-of-order) 응답이 리스트/선택을 초기화하지 않도록

function initSuggest(inputId, dropdownId, key, onSelect) {
  const input = document.getElementById(inputId);
  const dd    = document.getElementById(dropdownId);

  input.addEventListener('input', function() {
    clearTimeout(suggestTimers[key]);
    const q = this.value.trim();
    if (q.length < 1) { closeSuggest(dropdownId, key); return; }
    suggestTimers[key] = setTimeout(() => fetchSuggest(q, dropdownId, key, onSelect), 250);
  });

  input.addEventListener('keydown', function(e) {
    const items = dd.querySelectorAll('.suggest-item');
    if (!items.length || !dd.classList.contains('open')) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      suggestFocusIdx[key] = Math.min(suggestFocusIdx[key] + 1, items.length - 1);
      updateSuggestFocus(items, key);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      suggestFocusIdx[key] = Math.max(suggestFocusIdx[key] - 1, 0);
      updateSuggestFocus(items, key);
    } else if (e.key === 'Enter') {
      if (suggestFocusIdx[key] >= 0 && items[suggestFocusIdx[key]]) {
        e.preventDefault();
        items[suggestFocusIdx[key]].click();
      }
    } else if (e.key === 'Escape') {
      closeSuggest(dropdownId, key);
    }
  });

  // 외부 클릭 시 닫기
  document.addEventListener('click', function(e) {
    if (!input.contains(e.target) && !dd.contains(e.target)) {
      closeSuggest(dropdownId, key);
    }
  });
}

function fetchSuggest(q, dropdownId, key, onSelect) {
  const seq = (suggestSeq[key] = (suggestSeq[key] || 0) + 1);
  fetch('ajax_suggest.php?q=' + encodeURIComponent(q) + '&store_id=' + storeId + '&limit=50')
    .then(r => r.json())
    .then(data => {
      if (seq !== suggestSeq[key]) return; // 더 최신 요청이 있으면 이 응답은 무시 (선택 초기화 방지)
      const dd = document.getElementById(dropdownId);
      // 재렌더 직전 선택돼 있던 항목의 SKU 보존 (늦게 도착한 디바운스 응답이 첫 선택을 풀어버리는 문제 방지)
      let prevSku = null;
      if (suggestFocusIdx[key] >= 0 && dd._products && dd._products[suggestFocusIdx[key]]) {
        prevSku = dd._products[suggestFocusIdx[key]].sku;
      }
      if (!data.success || !data.products.length) {
        suggestFocusIdx[key] = -1;
        dd.innerHTML = '<div class="suggest-empty">' + tl('search.no_results') + '</div>';
        dd.classList.add('open');
        return;
      }
      dd.innerHTML = data.products.map((p, i) => `
        <div class="suggest-item" data-idx="${i}" onclick="pickSuggest('${dropdownId}','${key}',${i})">
          <span class="si-sku">${escHtml(p.sku)}</span>
          <span class="si-name">
            <div class="si-name-en">${escHtml(p.name_en || p.sku)}</div>
            ${(p.name_ko && p.name_ko !== p.name_en) ? '<div class="si-name-ko">' + escHtml(p.name_ko) + '</div>' : ''}
          </span>
          ${p.selling_price ? '<span class="si-price">' + escHtml(p.selling_price) + '</span>' : ''}
        </div>`).join('');
      // 상품 데이터를 dropdown에 저장
      dd._products = data.products;
      dd.classList.add('open');
      // 보존했던 선택 복원 — 같은 SKU가 새 목록에 있으면 하이라이트 유지, 없으면 초기화
      suggestFocusIdx[key] = prevSku ? data.products.findIndex(p => p.sku === prevSku) : -1;
      if (suggestFocusIdx[key] >= 0) updateSuggestFocus(dd.querySelectorAll('.suggest-item'), key);
    })
    .catch(() => {});
}

function updateSuggestFocus(items, key) {
  items.forEach((el, i) => el.classList.toggle('focused', i === suggestFocusIdx[key]));
  if (suggestFocusIdx[key] >= 0) items[suggestFocusIdx[key]].scrollIntoView({ block: 'nearest' });
}

function closeSuggest(dropdownId, key) {
  const dd = document.getElementById(dropdownId);
  dd.classList.remove('open');
  suggestFocusIdx[key] = -1;
}

// 전역 맵: dropdown별 onSelect 콜백 저장
const suggestCallbacks = {};

function pickSuggest(dropdownId, key, idx) {
  const dd = document.getElementById(dropdownId);
  const p  = dd._products && dd._products[idx];
  if (!p) return;
  closeSuggest(dropdownId, key);
  if (suggestCallbacks[key]) suggestCallbacks[key](p);
}

function escHtml(s){ if(!s&&s!==0)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ════════════════════════════════════════
// 환경설정 모달
// ════════════════════════════════════════
let settingsTab = 'store';

function openSettings() {
  syncSettingsStoreHighlight();
  syncUploadStoreLabel();
  document.getElementById('settingsOverlay').classList.add('open');
}

function syncUploadStoreLabel() {
  const sel = document.getElementById('uploadStoreSelect');
  if (sel) sel.value = uploadStoreId;
}

// 업로드/컬럼매핑 대상 점포 변경 (가격조회용 storeId와 무관, 새로고침해도 유지됨)
function onUploadStoreChange(sel) {
  uploadStoreId = parseInt(sel.value) || uploadStoreId;
  localStorage.setItem('pricing_uploadStoreId', uploadStoreId);
  loadColMap();
}

function closeSettings() {
  document.getElementById('settingsOverlay').classList.remove('open');
}

function handleOverlayClick(e) {
  if (e.target === document.getElementById('settingsOverlay')) closeSettings();
}

function switchSettingsTab(tab) {
  settingsTab = tab;
  document.querySelectorAll('.sm-tab').forEach((el, i) => {
    el.classList.toggle('active', ['store','upload','security'][i] === tab);
  });
  document.querySelectorAll('.sm-pane').forEach(el => el.classList.remove('active'));
  const paneMap = { store: 'paneStore', upload: 'paneUpload', security: 'paneSecurity' };
  document.getElementById(paneMap[tab] || 'paneStore').classList.add('active');
  if (tab === 'upload') { syncUploadStoreLabel(); loadColMap(); }
  if (tab === 'security') loadPinStatus();
}

// 설정 모달 내 점포 선택
function syncSettingsStoreHighlight() {
  document.querySelectorAll('.sm-store-item').forEach(el => {
    el.classList.toggle('active', parseInt(el.dataset.id) === storeId);
  });
}

function settingsSelectStore(id, name) {
  // 기존 selectStore 재활용
  selectStore(id, name);
  syncSettingsStoreHighlight();
}

// ── 마스터 파일 업로드 ──
let uploadSource = 'upload'; // 'upload' | 'nas'
let nasSelectedFile = null;  // NAS에서 선택한 파일명

function setUploadSource(src) {
  uploadSource = src;
  document.getElementById('srcTabUpload').style.cssText =
    src === 'upload' ? 'flex:1;padding:7px 0;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;background:#0ea5e9;color:#fff;transition:all 0.15s'
                     : 'flex:1;padding:7px 0;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;background:transparent;color:#64748b;transition:all 0.15s';
  document.getElementById('srcTabNas').style.cssText =
    src === 'nas' ? 'flex:1;padding:7px 0;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;background:#16a34a;color:#fff;transition:all 0.15s'
                  : 'flex:1;padding:7px 0;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;background:transparent;color:#64748b;transition:all 0.15s';
  document.getElementById('srcPanelUpload').style.display = src === 'upload' ? '' : 'none';
  document.getElementById('srcPanelNas').style.display    = src === 'nas'    ? '' : 'none';
}

function onUploadFileChange(input) {
  const nameEl = document.getElementById('uploadFileName');
  if (input.files.length > 0) {
    nameEl.textContent = input.files[0].name;
    nameEl.style.display = 'block';
  } else {
    nameEl.style.display = 'none';
  }
  document.getElementById('uploadResult').style.display = 'none';
}

// ── NAS 파일 업로드 (브라우저 → NAS 폴더) ──
function onNasUploadFileChange(input) {
  const nameEl = document.getElementById('nasUploadFileName');
  const zone   = document.getElementById('nasUploadZone');
  if (input.files && input.files[0]) {
    nameEl.textContent = input.files[0].name;
    nameEl.style.color = '#a78bfa';
    zone.style.borderColor = '#7c3aed';
  } else {
    nameEl.textContent = '클릭하여 파일 선택 (.xlsx, .xls, .csv)';
    nameEl.style.color = '#64748b';
    zone.style.borderColor = '#334155';
  }
}

function uploadToNas() {
  const fileInput = document.getElementById('nasUploadFileInput');
  const btn       = document.getElementById('nasUploadBtn');
  const resultEl  = document.getElementById('nasUploadResult');

  if (!fileInput.files || !fileInput.files[0]) {
    alert('파일을 먼저 선택해주세요.');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>업로드 중...</span>';
  resultEl.style.display = 'none';

  const fd = new FormData();
  fd.append('action', 'upload_to_nas');
  fd.append('nas_upload_file', fileInput.files[0]);

  fetch('ajax_import_master.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      resultEl.style.display = 'block';
      resultEl.style.color   = data.success ? '#4ade80' : '#f87171';
      resultEl.textContent   = data.message;
      if (data.success) {
        fileInput.value = '';
        document.getElementById('nasUploadFileName').textContent = '클릭하여 파일 선택 (.xlsx, .xls, .csv)';
        document.getElementById('nasUploadFileName').style.color = '#64748b';
        document.getElementById('nasUploadZone').style.borderColor = '#334155';
        loadNasFiles(); // 목록 자동 새로고침
      }
    })
    .catch(() => {
      resultEl.style.display = 'block';
      resultEl.style.color   = '#f87171';
      resultEl.textContent   = '업로드 오류가 발생했습니다.';
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-upload"></i><span>NAS 폴더에 업로드</span>';
    });
}

// ── NAS 파일 목록 로드 ──
function loadNasFiles() {
  const listEl = document.getElementById('nasFileList');
  listEl.innerHTML = '<div style="padding:14px;text-align:center;color:#64748b;font-size:13px"><i class="fas fa-spinner fa-spin" style="margin-right:6px"></i>로딩 중...</div>';
  nasSelectedFile = null;

  const fd = new FormData();
  fd.append('action', 'list_nas');
  fetch('ajax_import_master.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (!data.success || !data.files.length) {
        listEl.innerHTML = '<div style="padding:14px;text-align:center;color:#475569;font-size:13px">' +
          (data.message || 'uploads/pos_import/ 폴더에 파일이 없습니다') + '</div>';
        return;
      }
      listEl.innerHTML = data.files.map((f, i) => `
        <div class="nas-file-item" data-name="${escHtml(f.name)}" onclick="selectNasFile(this, '${escHtml(f.name)}')"
          style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid #1e3045;transition:background 0.1s${i === 0 ? ';background:#0c2340' : ''}"
          onmouseover="this.style.background='#162032'" onmouseout="if(!this.classList.contains('selected'))this.style.background='transparent'">
          <i class="fas fa-file-excel" style="color:#22c55e;font-size:14px;flex-shrink:0"></i>
          <span style="flex:1;font-size:12px;color:#f1f5f9;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(f.name)}</span>
          ${i === 0 ? '<span style="font-size:10px;background:#16a34a;color:#fff;border-radius:4px;padding:1px 5px;flex-shrink:0">최신</span>' : ''}
          <span style="font-size:11px;color:#475569;flex-shrink:0">${f.size_kb} KB</span>
          <span style="font-size:11px;color:#475569;flex-shrink:0">${f.modified.slice(5,16)}</span>
          <button onclick="event.stopPropagation();deleteNasFile('${escHtml(f.name)}')"
            style="flex-shrink:0;background:none;border:1px solid #334155;border-radius:5px;color:#64748b;font-size:11px;padding:2px 7px;cursor:pointer;transition:all 0.15s"
            onmouseover="this.style.borderColor='#f87171';this.style.color='#f87171'" onmouseout="this.style.borderColor='#334155';this.style.color='#64748b'">
            <i class="fas fa-trash"></i>
          </button>
        </div>`).join('');
      // 가장 최근 파일 자동 선택
      const firstItem = listEl.querySelector('.nas-file-item');
      if (firstItem) {
        firstItem.classList.add('selected');
        nasSelectedFile = firstItem.dataset.name;
      }
    })
    .catch(err => {
      listEl.innerHTML = '<div style="padding:14px;text-align:center;color:#f87171;font-size:13px">오류: ' + escHtml(err.message) + '</div>';
    });
}

function selectNasFile(el, name) {
  document.querySelectorAll('.nas-file-item').forEach(x => {
    x.classList.remove('selected');
    x.style.background = 'transparent';
    x.style.color = '';
  });
  el.classList.add('selected');
  el.style.background = '#0c2340';
  nasSelectedFile = name;
}

function updateMasterFile() {
  const btn = document.getElementById('nasImportBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>파일 확인 중...</span>';

  const fd = new FormData();
  fd.append('action', 'list_nas');
  fetch('ajax_import_master.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-rotate"></i><span>마스터파일 업데이트</span>';

      if (!data.success || !data.files || !data.files.length) {
        alert('NAS 폴더에 파일이 없습니다.\n먼저 파일을 업로드하세요.');
        return;
      }
      const latest = data.files[0]; // 이미 최신순 정렬
      if (!confirm(`가장 최근 파일로 마스터파일을 업데이트합니다.\n\n파일: ${latest.name}\n크기: ${latest.size_kb} KB\n수정일: ${latest.modified}\n\n계속하시겠습니까?`)) return;

      nasSelectedFile = latest.name;
      uploadMasterFile('nas');
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-rotate"></i><span>마스터파일 업데이트</span>';
      alert('파일 목록을 불러오는 중 오류가 발생했습니다.');
    });
}

function deleteNasFile(name) {
  if (!confirm(`"${name}" 파일을 삭제하시겠습니까?`)) return;

  const fd = new FormData();
  fd.append('action', 'delete_nas');
  fd.append('nas_filename', name);

  fetch('ajax_import_master.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        if (nasSelectedFile === name) nasSelectedFile = null;
        loadNasFiles();
      } else {
        alert('삭제 실패: ' + data.message);
      }
    })
    .catch(() => alert('삭제 중 오류가 발생했습니다.'));
}

// ── 컬럼 매핑 설정 ──
// 프리셋 값은 DB(settings 테이블)에서 로드됨 — 코드 배포 없이 값 수정/저장 가능 (아래 savePresetDefinition 참고)
// 프리셋 출처: <?php echo htmlspecialchars($colMapPresetsSource, ENT_QUOTES); ?> (진단용 — 페이지 소스에서 이 줄로 확인)
let PRESETS = <?php echo $colMapPresetsJson; ?>;
const PRESETS_SOURCE = <?php echo json_encode($colMapPresetsSource, JSON_UNESCAPED_UNICODE); ?>;
let lastAppliedPreset = null;

let presetLoadSeq = 0;

// 프리셋 버튼 클릭 → 항상 DB에서 최신 프리셋을 직접 읽어와 적용한다.
// (페이지 로드 시 PHP가 주입한 PRESETS 값에만 의존하면, 서버 캐시나 배포 시점 차이로
//  예전 값이 그대로 적용되는 문제가 있어 클릭 시점에 서버에서 다시 확인한다)
function applyPreset(name) {
  colMapTouched();            // 진행 중인 loadColMap() 응답이 덮어쓰지 못하게 무효화
  lastAppliedPreset = name;

  const msg = document.getElementById('colMapMsg');
  msg.style.color = '#38bdf8';
  msg.textContent = 'DB에서 프리셋 불러오는 중...';

  const mySeq = ++presetLoadSeq;
  fetch('ajax_colmap.php?action=load_presets', { cache: 'no-store' })
    .then(r => r.json())
    .then(data => {
      if (mySeq !== presetLoadSeq) return;   // 더 최근 클릭이 있으면 무시
      if (data.success && data.presets) {
        PRESETS = Object.assign({}, PRESETS, data.presets);
        applyPresetValues(name, 'DB');
      } else {
        applyPresetValues(name, '기본값(DB에 저장된 프리셋 없음)');
      }
    })
    .catch(() => {
      if (mySeq !== presetLoadSeq) return;
      applyPresetValues(name, '기본값(서버 통신 실패)');
    });
}

function applyPresetValues(name, source) {
  const p = PRESETS[name];
  const msg = document.getElementById('colMapMsg');
  if (!p) {
    msg.style.color = '#f87171';
    msg.textContent = '❌ "' + name + '" 프리셋을 찾을 수 없습니다.';
    return;
  }
  colMapTouched();
  applyColMapToInputs(p);
  refreshPresetButtonLabels();
  updatePresetEditHint();
  msg.style.color = '#38bdf8';
  msg.textContent = '✔ ' + (p.label || name) + ' 적용됨 [' + source + '] — 저장하려면 아래 버튼을 누르세요';
  setTimeout(() => { msg.textContent = ''; msg.style.color = '#4ade80'; }, 4000);
}

// 프리셋 정의 자체를 DB에 저장 (프리셋 버튼을 누르면 이 값이 적용됨)
function savePresetDefinition(name, map) {
  const baseLabel = (PRESETS[name] && PRESETS[name].label ? PRESETS[name].label.split(' (')[0] : name);
  const label = baseLabel + ' (' + map.col_sku + '/' + map.col_name + '/' + map.col_cost + '/' + map.col_price + ')';

  const fd = new FormData();
  fd.append('action', 'save_preset');
  fd.append('preset_name', name);
  fd.append('label', label);
  Object.keys(map).forEach(k => fd.append(k, map[k]));

  return fetch('ajax_colmap.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success && data.presets) {
        PRESETS = data.presets;           // 서버가 저장 직후 재조회한 값 = 실제 DB 내용
        refreshPresetButtonLabels();
        updatePresetEditHint();
      }
      return data;
    });
}

function refreshPresetButtonLabels() {
  document.querySelectorAll('.cm-preset-btn').forEach(btn => {
    const name = btn.dataset.preset;
    if (PRESETS[name]) btn.title = PRESETS[name].label;
  });
}

function updatePresetEditHint() {
  const hint = document.getElementById('presetEditHint');
  if (!hint) return;
  if (lastAppliedPreset && PRESETS[lastAppliedPreset]) {
    hint.textContent = '"' + (PRESETS[lastAppliedPreset].label || lastAppliedPreset)
                     + '" 편집 중 — 저장하면 이 프리셋에도 함께 반영됩니다';
    hint.style.display = '';
  } else {
    hint.style.display = 'none';
  }
}

function toggleColMap() {
  const panel = document.getElementById('colMapPanel');
  const chev  = document.getElementById('colMapChevron');
  const isOpen = panel.style.display !== 'none';
  panel.style.display = isOpen ? 'none' : '';
  chev.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
  if (!isOpen) loadColMap(); // 열 때마다 현재 점포 설정 불러오기
}

function colMapKey(sid) { return 'pricing_colmap_' + sid; }

function applyColMapToInputs(map) {
  document.getElementById('cmColSku').value   = map.col_sku    || 'A';
  document.getElementById('cmColName').value  = map.col_name   || 'B';
  document.getElementById('cmColCost').value  = map.col_cost   || 'C';
  document.getElementById('cmColPrice').value = map.col_price  || 'D';
  document.getElementById('cmHeaderRow').value = map.header_row != null ? map.header_row : 1;
}

// loadColMap()의 비동기 응답이 "사용자가 그 사이에 한 조작"을 덮어쓰지 않도록 하는 세대 번호.
// (프리셋 버튼 클릭/직접 입력이 있으면 진행 중이던 불러오기 결과는 버린다)
let colMapLoadSeq = 0;

// 사용자가 입력칸을 건드렸음을 표시 — 진행 중인 불러오기 응답을 무효화한다.
function colMapTouched() { colMapLoadSeq++; }

function loadColMap() {
  const sid = uploadStoreId;
  const mySeq = ++colMapLoadSeq;

  // 1) 즉시 표시: 로컬 캐시(localStorage)로 우선 채움
  const cached = JSON.parse(localStorage.getItem(colMapKey(sid)) || '{}');
  applyColMapToInputs(cached);

  // 2) 서버(DB)의 저장값으로 동기화 — 다른 기기/브라우저에서 저장한 설정도 반영
  fetch('ajax_colmap.php?action=load&store_id=' + sid)
    .then(r => r.json())
    .then(data => {
      // 응답이 늦게 도착한 사이 사용자가 프리셋을 누르거나 값을 고쳤다면 덮어쓰지 않는다
      if (mySeq !== colMapLoadSeq) return;
      if (data.success && data.colmap) {
        applyColMapToInputs(data.colmap);
        localStorage.setItem(colMapKey(sid), JSON.stringify(data.colmap));
      }
    })
    .catch(() => { /* 서버 조회 실패 시 로컬 캐시 값 유지 */ });
}

// 저장 = ① 이 점포의 매핑 + ② (프리셋 버튼을 눌러 편집 중이면) 그 프리셋 정의 를 함께 DB에 저장.
// 프리셋 버튼을 눌러 값을 고친 뒤 저장했는데 프리셋을 다시 누르면 예전 값이 나오던 문제를 없애기 위해
// 두 곳을 한 번에 갱신한다.
function saveColMap() {
  const sid  = uploadStoreId;
  const map  = getColMap();
  const name = lastAppliedPreset;               // 프리셋 버튼을 눌렀다면 그 프리셋도 같이 갱신
  const msg  = document.getElementById('colMapMsg');
  msg.style.color = '#4ade80';
  msg.textContent = '저장 중...';

  const fd = new FormData();
  fd.append('action', 'save');
  fd.append('store_id', sid);
  Object.keys(map).forEach(k => fd.append(k, map[k]));

  fetch('ajax_colmap.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(storeRes => {
      if (!storeRes.success) throw new Error(storeRes.message || '알 수 없는 오류');
      localStorage.setItem(colMapKey(sid), JSON.stringify(map));

      if (!name) return { storeRes, presetRes: null };
      return savePresetDefinition(name, map).then(presetRes => ({ storeRes, presetRes }));
    })
    .then(({ presetRes }) => {
      const storeLabel = uploadStoreName();
      if (!name) {
        msg.style.color = '#4ade80';
        msg.textContent = '✅ 저장됨 — 점포: ' + storeLabel;
      } else if (presetRes && presetRes.success) {
        msg.style.color = '#4ade80';
        msg.textContent = '✅ 저장됨 — 점포: ' + storeLabel + ' / 프리셋: ' + name;
      } else {
        msg.style.color = '#fbbf24';
        msg.textContent = '⚠ 점포 설정은 저장됨. 프리셋 저장 실패: '
                        + ((presetRes && presetRes.message) || '알 수 없는 오류');
      }
      setTimeout(() => { msg.textContent = ''; }, 4000);
    })
    .catch(err => {
      msg.style.color = '#f87171';
      msg.textContent = '❌ 저장 실패: ' + (err && err.message ? err.message : '서버와 통신할 수 없습니다.');
      setTimeout(() => { msg.textContent = ''; }, 4000);
    });
}

function uploadStoreName() {
  const sel = document.getElementById('uploadStoreSelect');
  if (sel && sel.selectedIndex >= 0) return sel.options[sel.selectedIndex].text;
  return '점포 ' + uploadStoreId;
}

// 업로드 시 사용할 컬럼 매핑 — 화면 입력칸 값을 그대로 사용한다.
// (입력칸은 loadColMap()이 DB에서 불러온 값으로 채워둠. 예전엔 localStorage만 읽어서
//  다른 PC/브라우저에서는 DB에 저장해둔 매핑이 무시되고 하드코딩 기본값 A/B/C/D가 쓰이던 문제가 있었음)
// 서버(ajax_import_master.php)도 DB 값을 우선 적용하므로 최종적으로는 DB가 기준이 됨.
function getColMap() {
  return {
    col_sku:    document.getElementById('cmColSku').value.toUpperCase().trim()   || 'A',
    col_name:   document.getElementById('cmColName').value.toUpperCase().trim()  || 'B',
    col_cost:   document.getElementById('cmColCost').value.toUpperCase().trim()  || 'C',
    col_price:  document.getElementById('cmColPrice').value.toUpperCase().trim() || 'D',
    header_row: parseInt(document.getElementById('cmHeaderRow').value) || 1,
  };
}

function uploadMasterFile(src) {
  if (src) uploadSource = src;
  if (!uploadStoreId || uploadStoreId <= 0) {
    alert(tl('error.store_not_selected'));
    return;
  }

  const progress = document.getElementById('uploadProgress');
  const result   = document.getElementById('uploadResult');
  const colMap   = getColMap();

  const fd = new FormData();
  fd.append('store_id',   uploadStoreId);
  fd.append('action',     'import');
  fd.append('col_sku',    colMap.col_sku);
  fd.append('col_name',   colMap.col_name);
  fd.append('col_cost',   colMap.col_cost);
  fd.append('col_price',  colMap.col_price);
  fd.append('header_row', colMap.header_row);

  if (uploadSource === 'nas') {
    if (!nasSelectedFile) {
      alert('NAS 폴더에서 파일을 선택해주세요.');
      return;
    }
    fd.append('source', 'nas');
    fd.append('nas_filename', nasSelectedFile);
    const btn = document.getElementById('nasImportBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 처리 중...';
    progress.style.display = 'block';
    result.style.display = 'none';

    fetch('ajax_import_master.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-folder-open"></i> NAS 파일 가져오기';
        progress.style.display = 'none';
        result.style.display = 'block';
        result.style.borderColor = data.success ? '#22c55e' : '#ef4444';
        result.style.color = data.success ? '#4ade80' : '#f87171';
        result.textContent = data.message || (data.success ? '완료' : '오류 발생');
      })
      .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-folder-open"></i> NAS 파일 가져오기';
        progress.style.display = 'none';
        result.style.display = 'block';
        result.style.borderColor = '#ef4444';
        result.style.color = '#f87171';
        result.textContent = '네트워크 오류: ' + err.message;
      });
  } else {
    // HTTP 파일 업로드
    const fileInput = document.getElementById('uploadFileInput');
    if (!fileInput.files.length) {
      alert(tl('error.file_not_selected'));
      return;
    }
    fd.append('source', 'upload');
    fd.append('excel_file', fileInput.files[0]);
    const btn = document.getElementById('uploadBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + tl('upload.processing');
    progress.style.display = 'block';
    result.style.display = 'none';

    fetch('ajax_import_master.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload"></i> 업로드 시작';
        progress.style.display = 'none';
        result.style.display = 'block';
        result.style.borderColor = data.success ? '#22c55e' : '#ef4444';
        result.style.color = data.success ? '#4ade80' : '#f87171';
        result.textContent = data.message || (data.success ? tl('upload.complete') : tl('upload.error'));
      })
      .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload"></i> 업로드 시작';
        progress.style.display = 'none';
        result.style.display = 'block';
        result.style.borderColor = '#ef4444';
        result.style.color = '#f87171';
        result.textContent = tl('error.network_short') + ': ' + err.message;
      });
  }
}

function showSettingsMsg(id, type, msg) {
  const el = document.getElementById(id);
  el.className = 'sm-msg ' + type;
  el.textContent = msg;
}
function hideSettingsMsg(id) {
  const el = document.getElementById(id);
  el.className = 'sm-msg';
  el.textContent = '';
}

// ── 초기화 ──
(function init(){
  const savedType   = localStorage.getItem('pricing_printType') || 'pricing';
  const savedScreen = localStorage.getItem('pricing_screen')    || 'lookup';
  // 로그인 사용자의 소속 점포가 확인된 경우, 저장된(다른) 점포 선택은 무시하고 항상 내 점포를 사용
  const forceSessionStore = <?php echo $forceSessionStore ? 'true' : 'false'; ?>;
  const savedStore  = forceSessionStore
                      ? <?php echo $defaultStore; ?>
                      : ((parseInt(localStorage.getItem('pricing_storeId') || '') || 0) > 0
                        ? parseInt(localStorage.getItem('pricing_storeId'))
                        : <?php echo $defaultStore; ?>);
  if (forceSessionStore) localStorage.setItem('pricing_storeId', savedStore);

  // 점포 복원
  const matchedItem = document.querySelector(`.sm-store-item[data-id="${savedStore}"]`);
  if (matchedItem) {
    storeId = savedStore;
    const label   = document.getElementById('storeNameLabel');
    const nameSpan = matchedItem.querySelector('span:not(.ssi-icon)');
    if (label && nameSpan) label.textContent = nameSpan.textContent.trim();
    matchedItem.classList.add('active');
  } else {
    // 저장된 점포가 없거나 삭제된 경우: 첫 번째 항목을 기본값으로
    const firstItem = document.querySelector('.sm-store-item');
    if (firstItem) {
      firstItem.classList.add('active');
      const firstId = parseInt(firstItem.dataset.id) || <?php echo $defaultStore; ?>;
      storeId = firstId;
      localStorage.setItem('pricing_storeId', firstId);
    }
  }

  setPrintType(savedType);
  setScreen(savedScreen);
  syncSettingsStoreHighlight();
  // 업로드 대상 점포 드롭다운 + 컬럼 매핑을 DB에서 미리 불러와 입력칸에 채워둠
  // (환경설정 탭을 열지 않고 바로 업로드해도 DB에 저장된 매핑이 적용되도록)
  syncUploadStoreLabel();
  loadColMap();

  // ── 포커스 자동 복귀 (클릭해도 입력란에 포커스 유지) ──
  document.addEventListener('mousedown', function(e) {
    // 자동완성 드롭다운 클릭은 무시
    if (e.target.closest('.suggest-dropdown')) return;
    // 버튼, 링크, 입력란, 설정 패널 등 인터랙티브 요소 클릭은 무시
    if (e.target.closest('button, a, input, select, textarea, .sm-body, .sm-store-item')) return;

    const isLookup = currentScreen === 'lookup';
    const isPrintAuto = !isLookup && printMode === 'auto';

    if (isLookup || isPrintAuto) {
      const targetInput = document.getElementById(isLookup ? 'lookupInput' : 'printInput');
      setTimeout(function() { targetInput.focus(); }, 0);
    }
  });

  // ── 자동완성 초기화 ──
  // 가격 조회 화면
  suggestCallbacks['lookup'] = function(p) {
    currentProduct = p;
    showLookupResult(p);
    document.getElementById('lookupInput').value = '';
  };
  initSuggest('lookupInput', 'lookupSuggest', 'lookup', suggestCallbacks['lookup']);

  // 가격표 출력 화면
  suggestCallbacks['print'] = function(p) {
    document.getElementById('printInput').value = '';
    closeSuggest('printSuggest', 'print');
    if (printMode === 'auto') silentPrint(p);
    else addItem(p);
  };
  initSuggest('printInput', 'printSuggest', 'print', suggestCallbacks['print']);
})();

// ── 다국어 (i18n) ──
const i18n = {
  ko: {
    'nav.lookup': '가격 조회',
    'nav.print': '가격표 출력',
    'nav.settings': '환경설정',
    'nav.store_select': '점포 선택',
    'nav.no_stores': '점포 없음',
    'settings.title': '환경설정',
    'settings.tab_store': '점포 선택',
    'settings.tab_upload': '마스터 파일 업로드',
    'settings.store_instruction': '점포를 선택하면 해당 점포의 가격으로 조회합니다',
    'settings.no_stores': '등록된 점포가 없습니다',
    'settings.excel_format': '엑셀 컬럼 형식',
    'settings.excel_cols': 'A열: 바코드(SKU) \u00a0·\u00a0 B열: 상품명 \u00a0·\u00a0 C열: 원가 \u00a0·\u00a0 D열: 판매가',
    'settings.upload_target': '업로드 대상 점포:',
    'settings.file_select': '엑셀 / CSV 파일 선택',
    'settings.file_hint': '클릭하여 파일 선택 또는 드래그 앤 드롭',
    'settings.file_formats': '.xlsx, .xls, .csv 지원',
    'settings.upload_start': '업로드 시작',
    'settings.processing': '파일 처리 중... 잠시 기다려 주세요',
    'lookup.placeholder': '바코드 스캔 또는 상품명(한글/영문) 검색',
    'lookup.idle_text': '바코드를 스캔하세요',
    'lookup.idle_sub': '상품 가격이 여기에 표시됩니다',
    'lookup.cost_label': '원가',
    'lookup.price_label': '판매가',
    'lookup.error_not_found': '상품을 찾을 수 없습니다',
    'lookup.scanned_code': '스캔한 코드: ',
    'lookup.no_price': '가격 없음',
    'scanner.status': '바코드를 사각형 안에 비춰주세요',
    'scanner.unsupported': '이 브라우저에서는 카메라 스캔을 지원하지 않습니다',
    'scanner.lib_error': '스캐너 라이브러리를 불러오지 못했습니다',
    'scanner.camera_error': '카메라를 사용할 수 없습니다',
    'print.mode_label': '출력 모드',
    'print.mode_auto': '자동',
    'print.mode_manual': '수동',
    'print.type_label': '라벨 종류',
    'print.type_pricing': '프라이싱',
    'print.type_barcode': '바코드 2p',
    'print.status_auto': '스캔하면 자동으로 출력됩니다',
    'print.status_manual': '스캔 후 목록에서 선택 출력',
    'print.search_title': '바코드 / 상품명 검색',
    'print.input_placeholder': '바코드 스캔 또는 상품명(한글/영문) 검색',
    'print.history_title': '최근 출력 이력',
    'print.clear_all': '전체 삭제',
    'print.history_empty': '출력 이력이 없습니다',
    'print.list_title': '출력 목록',
    'print.select_all': '전체 선택',
    'print.print_selected': '선택 출력',
    'print.th_name': '상품명',
    'print.th_price': '가격',
    'print.th_qty': '수량',
    'print.list_empty': '상품을 스캔하면 목록에 추가됩니다',
    'print.status_searching': '검색 중...',
    'print.status_printed': '출력: ',
    'print.status_qty_up': '수량 증가: ',
    'print.status_added': '추가: ',
    'print.status_opened': '장 출력 창을 열었습니다',
    'print.no_selection': '선택된 상품이 없습니다',
    'print.placeholder_en': '영문명',
    'print.placeholder_ko': '한글명',
    'error.network': '네트워크 오류가 발생했습니다',
    'error.network_short': '네트워크 오류',
    'error.popup_blocked': '팝업 차단됨 — 새 탭으로 열었습니다',
    'error.file_not_selected': '파일을 선택해주세요.',
    'error.store_not_selected': '점포를 먼저 선택해주세요. (환경설정 → 점포 선택)',
    'upload.processing': '처리 중...',
    'upload.complete': '완료',
    'upload.error': '오류 발생',
    'search.no_results': '검색 결과가 없습니다'
  },
  en: {
    'nav.lookup': 'Price Lookup',
    'nav.print': 'Label Printing',
    'nav.settings': 'Settings',
    'nav.store_select': 'Select Store',
    'nav.no_stores': 'No Stores',
    'settings.title': 'Settings',
    'settings.tab_store': 'Store Selection',
    'settings.tab_upload': 'Master File Upload',
    'settings.store_instruction': 'Select a store to view its prices',
    'settings.no_stores': 'No registered stores',
    'settings.excel_format': 'Excel Column Format',
    'settings.excel_cols': 'Col A: Barcode(SKU) \u00a0·\u00a0 Col B: Item Name \u00a0·\u00a0 Col C: Cost Price \u00a0·\u00a0 Col D: Selling Price',
    'settings.upload_target': 'Upload Target Store:',
    'settings.file_select': 'Select Excel / CSV File',
    'settings.file_hint': 'Click to select file or drag and drop',
    'settings.file_formats': 'Supports .xlsx, .xls, .csv',
    'settings.upload_start': 'Start Upload',
    'settings.processing': 'Processing file... Please wait',
    'lookup.placeholder': 'Scan barcode or search product name',
    'lookup.idle_text': 'Scan a barcode',
    'lookup.idle_sub': 'Product price will be displayed here',
    'lookup.cost_label': 'Cost Price',
    'lookup.price_label': 'Selling Price',
    'lookup.error_not_found': 'Product not found',
    'lookup.scanned_code': 'Scanned code: ',
    'lookup.no_price': 'No Price',
    'scanner.status': 'Point the camera at a barcode',
    'scanner.unsupported': 'Camera scanning is not supported on this browser',
    'scanner.lib_error': 'Failed to load the scanner library',
    'scanner.camera_error': 'Camera unavailable',
    'print.mode_label': 'Print Mode',
    'print.mode_auto': 'Auto',
    'print.mode_manual': 'Manual',
    'print.type_label': 'Label Type',
    'print.type_pricing': 'Pricing',
    'print.type_barcode': 'Barcode 2p',
    'print.status_auto': 'Scans will print automatically',
    'print.status_manual': 'Select items from list to print',
    'print.search_title': 'Barcode / Product Search',
    'print.input_placeholder': 'Scan barcode or search product name',
    'print.history_title': 'Recent Print History',
    'print.clear_all': 'Clear All',
    'print.history_empty': 'No print history',
    'print.list_title': 'Print List',
    'print.select_all': 'Select All',
    'print.print_selected': 'Print Selected',
    'print.th_name': 'Product',
    'print.th_price': 'Price',
    'print.th_qty': 'Qty',
    'print.list_empty': 'Scanned products will be added here',
    'print.status_searching': 'Searching...',
    'print.status_printed': 'Printed: ',
    'print.status_qty_up': 'Qty increased: ',
    'print.status_added': 'Added: ',
    'print.status_opened': ' items sent to print',
    'print.no_selection': 'No products selected',
    'print.placeholder_en': 'English name',
    'print.placeholder_ko': 'Korean name',
    'error.network': 'A network error occurred',
    'error.network_short': 'Network Error',
    'error.popup_blocked': 'Popup blocked — opened in new tab',
    'error.file_not_selected': 'Please select a file.',
    'error.store_not_selected': 'Please select a store first. (Settings → Store Selection)',
    'upload.processing': 'Processing...',
    'upload.complete': 'Completed',
    'upload.error': 'Error occurred',
    'search.no_results': 'No search results'
  }
};

let currentLang = localStorage.getItem('pricing_lang') || 'ko';

function tl(key) { return (i18n[currentLang] && i18n[currentLang][key]) || (i18n['ko'][key]) || key; }

function applyLang() {
  // data-i18n 속성이 있는 모든 요소
  document.querySelectorAll('[data-i18n]').forEach(el => {
    const key = el.getAttribute('data-i18n');
    el.textContent = tl(key);
  });
  // data-i18n-placeholder 속성이 있는 input
  document.querySelectorAll('[data-i18n-ph]').forEach(el => {
    el.placeholder = tl(el.getAttribute('data-i18n-ph'));
  });
  // 페이지 제목
  document.title = tl('nav.lookup') + ' / ' + tl('nav.print');
  // 토글 버튼 활성화 상태
  document.getElementById('btnLangKo').classList.toggle('active', currentLang === 'ko');
  document.getElementById('btnLangEn').classList.toggle('active', currentLang === 'en');
}

function setLang(lang) {
  currentLang = lang;
  localStorage.setItem('pricing_lang', lang);
  applyLang();
}

// 초기 적용
document.addEventListener('DOMContentLoaded', function() {
  // placeholder용 data-i18n-ph 세팅
  const lookupInput = document.getElementById('lookupInput');
  if (lookupInput) lookupInput.setAttribute('data-i18n-ph', 'lookup.placeholder');
  const printInput = document.getElementById('printInput');
  if (printInput) printInput.setAttribute('data-i18n-ph', 'print.input_placeholder');
  applyLang();
  // 출력 모드(자동/수동) 복원 — tl() 사용하므로 i18n 초기화 후 실행
  const savedPrintMode = localStorage.getItem('pricing_printMode') || 'auto';
  setPrintMode(savedPrintMode);
});
</script>

<!-- ════════════════════════════════════════
     핀번호 입력 모달
════════════════════════════════════════ -->
<div id="pinModalOverlay" onclick="handlePinOverlayClick(event)">
  <div id="pinModal">
    <button class="pm-close" onclick="closePinModal()"><i class="fas fa-times"></i></button>
    <div class="pm-title">
      <i class="fas fa-lock" style="color:#fb923c;font-size:15px"></i>
      원가 조회 인증
    </div>
    <div id="pinDots">
      <div class="pin-dot" id="pd0"></div>
      <div class="pin-dot" id="pd1"></div>
      <div class="pin-dot" id="pd2"></div>
      <div class="pin-dot" id="pd3"></div>
    </div>
    <div id="pinError"></div>
    <div id="pinPad">
      <button class="pp-btn" onclick="pinInput('1')">1</button>
      <button class="pp-btn" onclick="pinInput('2')">2</button>
      <button class="pp-btn" onclick="pinInput('3')">3</button>
      <button class="pp-btn" onclick="pinInput('4')">4</button>
      <button class="pp-btn" onclick="pinInput('5')">5</button>
      <button class="pp-btn" onclick="pinInput('6')">6</button>
      <button class="pp-btn" onclick="pinInput('7')">7</button>
      <button class="pp-btn" onclick="pinInput('8')">8</button>
      <button class="pp-btn" onclick="pinInput('9')">9</button>
      <button class="pp-btn del" onclick="pinDelete()"><i class="fas fa-backspace"></i></button>
      <button class="pp-btn" onclick="pinInput('0')">0</button>
      <button class="pp-btn confirm" id="pinConfirmBtn" onclick="pinConfirm()" disabled><i class="fas fa-check"></i></button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════
     히든 원가조회 모달 (로고 클릭)
════════════════════════════════════════ -->
<div id="staffModalOverlay" onclick="handleStaffOverlayClick(event)">
  <div id="staffModal">
    <button class="sm2-close" onclick="closeStaffModal()"><i class="fas fa-times"></i></button>
    <div class="sm2-title">
      <i class="fas fa-lock" style="color:#fb923c;font-size:16px"></i>
      원가 조회
    </div>
    <div id="staffSearchWrap">
      <input id="staffInput" type="text" placeholder="바코드 스캔 또는 상품명 검색" autocomplete="off">
      <div class="suggest-dropdown" id="staffSuggest" style="width:calc(100% - 88px)"></div>
      <button id="staffSearchBtn" onclick="doStaffLookup()"><i class="fas fa-search"></i></button>
    </div>
    <div id="staffResult">
      <div class="sr-sku" id="srSku"></div>
      <div class="sr-name" id="srName" onclick="copyText(this)" title="클릭하여 상품명 복사" style="cursor:pointer"></div>
      <div class="sr-prices">
        <div class="sr-price-cell cost">
          <div class="sr-label">원가</div>
          <div class="sr-val" id="srCost"></div>
        </div>
        <div class="sr-price-cell sell">
          <div class="sr-label">판매가</div>
          <div class="sr-val" id="srSell"></div>
        </div>
      </div>
    </div>
    <div id="staffError">
      <div class="se-icon"><i class="fas fa-exclamation-circle"></i></div>
      <div class="se-text" id="seText"></div>
    </div>
  </div>
</div>

<div id="nameHistoryOverlay" onclick="if(event.target===this)closeNameHistory()" style="display:none;position:fixed;inset:0;z-index:9500;background:rgba(0,0,0,0.65);backdrop-filter:blur(4px);align-items:center;justify-content:center">
  <div style="background:#1e293b;border:1px solid #334155;border-radius:16px;width:560px;max-width:96vw;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.6)">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px 14px;border-bottom:1px solid #334155;flex-shrink:0">
      <div style="display:flex;align-items:center;gap:8px">
        <i class="fas fa-history" style="color:#38bdf8"></i>
        <span style="font-size:16px;font-weight:700;color:#f1f5f9">상품명 변경 이력</span>
        <span id="nhSku" style="font-size:12px;color:#64748b;margin-left:4px"></span>
      </div>
      <button onclick="closeNameHistory()" style="background:none;border:none;color:#64748b;cursor:pointer;font-size:18px;padding:4px 8px;border-radius:6px;transition:color 0.15s" onmouseover="this.style.color='#f1f5f9'" onmouseout="this.style.color='#64748b'"><i class="fas fa-times"></i></button>
    </div>
    <div id="nhBody" style="overflow-y:auto;flex:1;padding:16px 22px">
      <div style="text-align:center;color:#64748b;padding:32px 0"><i class="fas fa-spinner fa-spin"></i> 불러오는 중...</div>
    </div>
  </div>
</div>

<script>
// ── 히든 모달 ──
document.querySelector('.header-brand').addEventListener('click', openStaffModal);

function openStaffModal() {
  // PIN 모달을 먼저 띄워 인증 후 원가조회 모달 열기
  openPinModal();
}
function closeStaffModal() {
  document.getElementById('staffModalOverlay').classList.remove('open');
  document.getElementById('staffInput').value = '';
  closeSuggest('staffSuggest', 'staff');
  resetStaffModal();
}
function handleStaffOverlayClick(e) {
  if (e.target === document.getElementById('staffModalOverlay')) closeStaffModal();
}

// ── 상품명 변경 이력 모달 ──
function openNameHistory(productId, sku) {
  const overlay = document.getElementById('nameHistoryOverlay');
  document.getElementById('nhSku').textContent = sku ? '(' + sku + ')' : '';
  document.getElementById('nhBody').innerHTML = '<div style="text-align:center;color:#64748b;padding:32px 0"><i class="fas fa-spinner fa-spin"></i> 불러오는 중...</div>';
  overlay.style.display = 'flex';
  fetch('ajax_name_history.php?product_id=' + encodeURIComponent(productId))
    .then(r => r.json())
    .then(res => {
      if (!res.success) { document.getElementById('nhBody').innerHTML = '<div style="color:#ef4444;text-align:center;padding:24px">불러오기 실패</div>'; return; }
      const rows = res.data;
      if (!rows.length) { document.getElementById('nhBody').innerHTML = '<div style="color:#64748b;text-align:center;padding:32px">변경 이력이 없습니다.</div>'; return; }
      const langLabel = l => l === 'en' ? '<span style="font-size:10px;font-weight:700;color:#38bdf8;background:#0c2340;border:1px solid #1e4a7a;border-radius:3px;padding:1px 5px">ENG</span>' : '<span style="font-size:10px;font-weight:700;color:#f59e0b;background:#1a1400;border:1px solid #78350f;border-radius:3px;padding:1px 5px">KOR</span>';
      document.getElementById('nhBody').innerHTML = '<table style="width:100%;border-collapse:collapse;font-size:12px">'
        + '<thead><tr style="color:#64748b;border-bottom:1px solid #334155"><th style="text-align:left;padding:6px 8px;width:100px">변경 일시</th><th style="padding:6px 4px;width:40px">언어</th><th style="text-align:left;padding:6px 8px">이전 이름</th><th style="padding:4px 4px;width:16px"></th><th style="text-align:left;padding:6px 8px">변경 후</th></tr></thead>'
        + '<tbody>' + rows.map(r => `<tr style="border-bottom:1px solid #1e293b">
          <td style="padding:7px 8px;color:#94a3b8;white-space:nowrap">${escHtml(r.changed_at.slice(0,16).replace('T',' '))}</td>
          <td style="padding:7px 4px;text-align:center">${langLabel(r.language)}</td>
          <td style="padding:7px 8px;color:#94a3b8">${escHtml(r.old_name || '(없음)')}</td>
          <td style="padding:7px 4px;color:#475569;text-align:center">→</td>
          <td style="padding:7px 8px;color:#f1f5f9;font-weight:600">${escHtml(r.new_name)}</td>
        </tr>`).join('') + '</tbody></table>';
    })
    .catch(() => { document.getElementById('nhBody').innerHTML = '<div style="color:#ef4444;text-align:center;padding:24px">네트워크 오류</div>'; });
}
function closeNameHistory() {
  document.getElementById('nameHistoryOverlay').style.display = 'none';
}
function resetStaffModal() {
  document.getElementById('staffResult').style.display = 'none';
  document.getElementById('staffError').style.display  = 'none';
}

document.getElementById('staffInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    // 드롭다운에 포커스된 항목이 있으면 initSuggest 핸들러에 맡김
    const dd = document.getElementById('staffSuggest');
    if (dd.classList.contains('open') && suggestFocusIdx['staff'] >= 0) return;
    doStaffLookup();
  }
});

function doStaffLookup() {
  const input = document.getElementById('staffInput');
  const barcode = input.value.trim().replace(/^,+/, '');
  input.value = '';
  closeSuggest('staffSuggest', 'staff');
  if (!barcode) return;
  resetStaffModal();
  fetch('ajax_search.php?barcode=' + encodeURIComponent(barcode) + '&store_id=' + storeId)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        document.getElementById('seText').textContent = data.message || '상품을 찾을 수 없습니다';
        document.getElementById('staffError').style.display = 'block';
        return;
      }
      const p = data.product;
      document.getElementById('srSku').textContent  = p.sku;
      document.getElementById('srName').textContent = p.name_en || p.name_ko || '';
      document.getElementById('srCost').textContent = (p.cost_price && p.cost_price !== '0.00')
        ? p.cost_price : '-';
      document.getElementById('srSell').textContent = (p.selling_price && p.selling_price !== '0')
        ? p.selling_price : '-';
      document.getElementById('staffResult').style.display = 'block';
    })
    .catch(() => {
      document.getElementById('seText').textContent = '네트워크 오류가 발생했습니다';
      document.getElementById('staffError').style.display = 'block';
    });
}

// 자동완성 연결 (suggestCallbacks에 등록해야 pickSuggest에서 호출됨)
suggestCallbacks['staff'] = function(p) {
  document.getElementById('staffInput').value = p.sku;
  doStaffLookup();
};
initSuggest('staffInput', 'staffSuggest', 'staff', suggestCallbacks['staff']);
</script>

<script>
// ════════════════════════════════════════
// 핀번호 입력 모달
// ════════════════════════════════════════
let pinBuffer = [];

function openPinModal() {
  pinBuffer = [];
  updatePinDots();
  document.getElementById('pinError').textContent = '';
  document.getElementById('pinConfirmBtn').disabled = true;
  document.getElementById('pinModalOverlay').classList.add('open');
}

function closePinModal() {
  document.getElementById('pinModalOverlay').classList.remove('open');
  pinBuffer = [];
}

function handlePinOverlayClick(e) {
  if (e.target === document.getElementById('pinModalOverlay')) closePinModal();
}

function updatePinDots() {
  for (let i = 0; i < 4; i++) {
    const dot = document.getElementById('pd' + i);
    if (dot) dot.classList.toggle('filled', i < pinBuffer.length);
  }
  document.getElementById('pinConfirmBtn').disabled = (pinBuffer.length < 4);
}

function pinInput(digit) {
  if (pinBuffer.length >= 6) return; // 백도어 최대 6자리
  pinBuffer.push(digit);
  updatePinDots();
  document.getElementById('pinError').textContent = '';
}

function pinDelete() {
  if (pinBuffer.length > 0) pinBuffer.pop();
  updatePinDots();
  document.getElementById('pinError').textContent = '';
}

function pinConfirm() {
  if (pinBuffer.length < 4) return;
  const pin = pinBuffer.join('');
  const btn = document.getElementById('pinConfirmBtn');
  btn.disabled = true;

  const fd = new FormData();
  fd.append('action', 'verify');
  fd.append('pin', pin);

  fetch('ajax_pin.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        closePinModal();
        // 원가조회 모달 열기
        document.getElementById('staffModalOverlay').classList.add('open');
        document.getElementById('staffInput').focus();
        resetStaffModal();
      } else {
        document.getElementById('pinError').textContent = data.message || '핀번호가 올바르지 않습니다.';
        pinBuffer = [];
        updatePinDots();
        btn.disabled = true;
        // 도트 흔들기 애니메이션
        const dotsEl = document.getElementById('pinDots');
        dotsEl.style.animation = 'none';
        void dotsEl.offsetWidth;
        dotsEl.style.animation = 'pinShake 0.4s ease';
      }
    })
    .catch(() => {
      document.getElementById('pinError').textContent = '네트워크 오류가 발생했습니다.';
      btn.disabled = false;
    });
}

// 키보드 입력 지원 (document 레벨에서 캡처 — div는 포커스 불가)
document.addEventListener('keydown', function(e) {
  if (!document.getElementById('pinModalOverlay').classList.contains('open')) return;
  if (e.key >= '0' && e.key <= '9') { e.preventDefault(); pinInput(e.key); }
  else if (e.key === 'Backspace')    { e.preventDefault(); pinDelete(); }
  else if (e.key === 'Escape')       { e.preventDefault(); closePinModal(); }
  else if (e.key === 'Enter' && pinBuffer.length >= 4) { e.preventDefault(); pinConfirm(); }
});

// 흔들기 애니메이션
const pinStyle = document.createElement('style');
pinStyle.textContent = `@keyframes pinShake {
  0%,100%{transform:translateX(0)}
  20%{transform:translateX(-8px)}
  40%{transform:translateX(8px)}
  60%{transform:translateX(-6px)}
  80%{transform:translateX(6px)}
}`;
document.head.appendChild(pinStyle);

// ════════════════════════════════════════
// 보안 탭 — PIN 설정
// ════════════════════════════════════════
function loadPinStatus() {
  const badge = document.getElementById('pinStatusText');
  if (!badge) return;
  const fd = new FormData();
  fd.append('action', 'status');
  fetch('ajax_pin.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        badge.textContent = data.has_pin ? '설정됨' : '미설정';
        badge.className = 'ps-badge ' + (data.has_pin ? 'set' : 'unset');
      }
    })
    .catch(() => {});
}

function savePinSetting() {
  const currentPin = document.getElementById('settingCurrentPin').value.trim();
  const newPin     = document.getElementById('settingNewPin').value.trim();
  const newPinCfm  = document.getElementById('settingNewPinConfirm').value.trim();
  const msgEl      = document.getElementById('pinChangeMsg');

  if (!/^\d{4}$/.test(newPin)) {
    msgEl.textContent = '새 핀번호는 4자리 숫자여야 합니다.';
    msgEl.className = 'sm-pin-msg err';
    return;
  }
  if (newPin !== newPinCfm) {
    msgEl.textContent = '새 핀번호와 확인 핀번호가 일치하지 않습니다.';
    msgEl.className = 'sm-pin-msg err';
    return;
  }

  const fd = new FormData();
  fd.append('action', 'update');
  fd.append('current_pin', currentPin);
  fd.append('new_pin', newPin);

  fetch('ajax_pin.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      msgEl.textContent = data.message || (data.success ? '변경되었습니다.' : '오류가 발생했습니다.');
      msgEl.className = 'sm-pin-msg ' + (data.success ? 'ok' : 'err');
      if (data.success) {
        document.getElementById('settingCurrentPin').value = '';
        document.getElementById('settingNewPin').value = '';
        document.getElementById('settingNewPinConfirm').value = '';
        loadPinStatus();
      }
    })
    .catch(() => {
      msgEl.textContent = '네트워크 오류가 발생했습니다.';
      msgEl.className = 'sm-pin-msg err';
    });
}
</script>
</body>
</html>
