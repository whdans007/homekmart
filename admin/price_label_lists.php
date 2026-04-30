<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/mobile_detect.php';

if (!isset($_GET['force_desktop'])) {
    redirect_if_mobile('mobile_main.php', true);
}
$page_title = t('navigation.price_label_lists') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!has_permission('product_management')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}
?>

<style>
/* 토글 스위치 공통 */
.toggle-group {
    display: inline-flex;
    background: #f3f4f6;
    border-radius: 0.5rem;
    padding: 4px;
    gap: 4px;
}
.toggle-group button {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    background: transparent;
    color: #6b7280;
    white-space: nowrap;
}
.toggle-group button.active {
    color: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.toggle-group.purple button.active { background: #7c3aed; }
.toggle-group.blue button.active { background: #2563eb; }

/* 자동 모드 상태 표시 */
.auto-status {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    border-radius: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 1rem;
}
.auto-status.ready { background: #ecfdf5; color: #065f46; border: 2px solid #a7f3d0; }
.auto-status.printing { background: #eff6ff; color: #1e40af; border: 2px solid #93c5fd; }
.auto-status.error { background: #fef2f2; color: #991b1b; border: 2px solid #fca5a5; }

/* 수량 입력 */
.qty-input {
    width: 60px;
    text-align: center;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    padding: 0.25rem;
    font-size: 0.875rem;
}
.qty-btn {
    width: 28px; height: 28px;
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid #d1d5db; border-radius: 0.375rem;
    background: #fff; cursor: pointer; font-weight: 700; font-size: 1rem; color: #374151;
}
.qty-btn:hover { background: #f3f4f6; }

/* 최근 출력 이력 */
.history-item {
    display: flex; align-items: center;
    padding: 0.5rem 0.75rem;
    border-bottom: 1px solid #f3f4f6;
    font-size: 0.875rem;
}
.history-item:last-child { border-bottom: none; }
.history-time { color: #9ca3af; font-size: 0.75rem; min-width: 60px; }

/* 숨겨진 인쇄 iframe */
#printFrame { position: fixed; left: -9999px; top: -9999px; width: 0; height: 0; border: none; }

/* 검색 결과 드롭다운 */
.search-dropdown {
    position: absolute; left: 0; right: 0; top: 100%;
    background: #fff; border: 1px solid #d1d5db; border-top: none;
    border-radius: 0 0 0.5rem 0.5rem;
    max-height: 320px; overflow-y: auto;
    z-index: 50; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.search-dropdown .search-item {
    padding: 0.625rem 1rem; cursor: pointer; border-bottom: 1px solid #f3f4f6;
    transition: background 0.1s;
}
.search-dropdown .search-item:last-child { border-bottom: none; }
.search-dropdown .search-item:hover,
.search-dropdown .search-item.selected { background: #ede9fe; }
.search-dropdown .search-item .item-name { font-weight: 600; font-size: 0.875rem; }
.search-dropdown .search-item .item-meta { font-size: 0.75rem; color: #6b7280; margin-top: 2px; }
.search-dropdown .search-empty {
    padding: 1rem; text-align: center; color: #9ca3af; font-size: 0.875rem;
}
</style>

<div class="w-full px-2 sm:px-3 md:px-4 py-2 md:py-8">
    <!-- 브레드크럼 -->
    <div class="mb-6 hidden md:block">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li>
                    <a href="index.php" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-home mr-1"></i><?php echo t('common.dashboard'); ?>
                    </a>
                </li>
                <li>
                    <div class="flex items-center">
                        <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                        <span class="text-gray-600"><?php echo t('navigation.price_label_lists'); ?></span>
                    </div>
                </li>
            </ol>
        </nav>
    </div>

    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <!-- 헤더: 출력유형 + 출력모드 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white">
            <div class="flex flex-col gap-3">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <h3 class="text-lg leading-6 font-semibold text-gray-900">
                        <i class="fas fa-barcode mr-2 text-purple-500"></i>
                        바코드 라벨 출력
                    </h3>
                </div>
                <!-- 토글 영역 -->
                <div class="flex flex-wrap items-center gap-4">
                    <!-- 출력 유형 선택 -->
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-600">출력유형</span>
                        <div class="toggle-group blue">
                            <button id="btnTypePricing" class="active" onclick="setPrintType('pricing')">
                                <i class="fas fa-tag mr-1"></i>프라이싱
                            </button>
                            <button id="btnType2p" onclick="setPrintType('2p')">
                                <i class="fas fa-th-large mr-1"></i>바코드라벨(2p)
                            </button>
                        </div>
                    </div>
                    <!-- 출력 모드 선택 -->
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-600">출력모드</span>
                        <div class="toggle-group purple">
                            <button id="btnAutoMode" class="active" onclick="setMode('auto')">
                                <i class="fas fa-bolt mr-1"></i>자동출력
                            </button>
                            <button id="btnManualMode" onclick="setMode('manual')">
                                <i class="fas fa-list mr-1"></i>수동출력
                            </button>
                        </div>
                    </div>
                    <!-- 현재 설정 설명 -->
                    <div id="settingDesc" class="text-xs text-gray-400 ml-auto">
                        프라이싱: 라벨 1장에 바코드 1개 (전체 크기)
                    </div>
                </div>
            </div>
        </div>

        <div class="p-6">
            <!-- 바코드/상품명 검색 -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">바코드 스캔 / 상품명 검색</label>
                <div class="flex gap-3">
                    <div class="flex-1 relative" id="searchWrapper">
                        <input id="barcodeInput" type="text"
                               placeholder="바코드 스캔, SKU, 상품명(한글/영문) 입력..."
                               class="w-full border border-gray-300 rounded-lg px-4 py-3 text-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                               autocomplete="off" autofocus>
                        <div id="inputSpinner" class="hidden absolute right-3 top-1/2 -translate-y-1/2">
                            <i class="fas fa-spinner fa-spin text-purple-500"></i>
                        </div>
                        <div id="searchDropdown" class="search-dropdown hidden"></div>
                    </div>
                    <button id="btnSearch" onclick="handleSearch()"
                            class="px-6 py-3 bg-purple-600 text-white rounded-lg font-medium hover:bg-purple-700 transition-colors"
                            style="color: white !important;">
                        <i class="fas fa-search mr-1" style="color: white !important;"></i>검색
                    </button>
                </div>
                <div id="searchStatus" class="mt-2 text-sm min-h-[1.5rem]"></div>
            </div>

            <!-- ===== 자동 모드 영역 ===== -->
            <div id="autoModeArea">
                <div id="autoStatus" class="auto-status ready">
                    <i class="fas fa-barcode mr-3 text-2xl"></i>
                    바코드를 스캔하면 자동으로 출력됩니다
                </div>

                <div class="mt-4">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-sm font-semibold text-gray-700">
                            <i class="fas fa-history mr-1"></i>최근 출력 이력
                        </h4>
                        <button onclick="clearHistory()" class="text-xs text-gray-400 hover:text-gray-600">이력 삭제</button>
                    </div>
                    <div id="historyList" class="border border-gray-200 rounded-lg overflow-hidden">
                        <div class="text-center text-sm text-gray-400 py-8">
                            <i class="fas fa-inbox text-2xl mb-2 block"></i>출력 이력이 없습니다
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== 수동 모드 영역 ===== -->
            <div id="manualModeArea" class="hidden">
                <div class="flex flex-wrap items-center gap-3 mb-4">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="selectAllCheckbox" class="w-4 h-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <span class="text-sm text-gray-700">전체 선택</span>
                    </label>
                    <button onclick="printSelected()"
                            class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-md text-sm font-medium hover:bg-purple-700 transition-colors"
                            style="color: white !important;">
                        <i class="fas fa-print mr-2" style="color: white !important;"></i>선택 출력
                    </button>
                    <button onclick="clearManualList()"
                            class="inline-flex items-center px-4 py-2 border border-gray-300 text-gray-700 rounded-md text-sm font-medium hover:bg-gray-50 transition-colors">
                        <i class="fas fa-trash mr-2"></i>전체 삭제
                    </button>
                    <div class="ml-auto text-sm text-gray-600">
                        총 <span id="totalItems" class="font-semibold">0</span>개 상품 /
                        선택 <span id="selectedItems" class="font-semibold text-purple-600">0</span>개
                    </div>
                </div>

                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <table class="min-w-full" id="manualTable">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase w-10"></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">SKU</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">상품명</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase hidden sm:table-cell">가격</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase" style="width:140px">수량(장)</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase w-12"></th>
                            </tr>
                        </thead>
                        <tbody id="manualTableBody">
                            <tr id="emptyRow">
                                <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400">
                                    <i class="fas fa-barcode text-3xl mb-3 block"></i>
                                    바코드를 스캔하여 상품을 추가하세요
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 출력용 숨겨진 iframe -->
<iframe id="printFrame" name="printFrame"></iframe>

<!-- 출력 미리보기 모달 (수동 모드) -->
<div id="printModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50">
    <div class="bg-white w-full h-full flex flex-col">
        <div class="flex items-center justify-between p-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold">
                <i class="fas fa-print mr-2 text-purple-500"></i>출력 미리보기
            </h3>
            <button onclick="closePrintModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div id="printIframeContainer" class="flex-1 overflow-auto"></div>
        <div class="flex items-center justify-end gap-2 p-4 border-t border-gray-200">
            <button onclick="confirmPrint()"
                    class="px-6 py-2 bg-purple-600 text-white rounded-md font-medium hover:bg-purple-700"
                    style="color: white !important;">
                <i class="fas fa-print mr-2" style="color: white !important;"></i>인쇄
            </button>
            <button onclick="closePrintModal()"
                    class="px-6 py-2 border border-gray-300 text-gray-700 rounded-md font-medium hover:bg-gray-50">
                취소
            </button>
        </div>
    </div>
</div>

<script>
// ========== State ==========
let printType = 'pricing';   // 'pricing' = 전체 1개, '2p' = 좌우 분할 2개
let currentMode = 'auto';    // 'auto' | 'manual'
let manualList = [];
let printHistory = [];
const MAX_HISTORY = 20;

// ========== Print Type Toggle ==========
function setPrintType(type) {
    printType = type;
    document.getElementById('btnTypePricing').classList.toggle('active', type === 'pricing');
    document.getElementById('btnType2p').classList.toggle('active', type === '2p');
    // 바코드라벨(2p) 선택 시 자동으로 수동출력 모드로 전환
    if (type === '2p' && currentMode === 'auto') {
        setMode('manual');
    }
    updateSettingDesc();
    document.getElementById('barcodeInput').focus();
}

function updateSettingDesc() {
    const desc = document.getElementById('settingDesc');
    if (printType === 'pricing') {
        desc.textContent = '프라이싱: 라벨 1장에 바코드 1개 (전체 크기)';
    } else {
        desc.textContent = '바코드라벨(2p): 라벨 1장에 같은 바코드 2개 (좌우 분할)';
    }
}

// ========== Mode Toggle ==========
function setMode(mode) {
    currentMode = mode;
    document.getElementById('btnAutoMode').classList.toggle('active', mode === 'auto');
    document.getElementById('btnManualMode').classList.toggle('active', mode === 'manual');
    document.getElementById('autoModeArea').classList.toggle('hidden', mode !== 'auto');
    document.getElementById('manualModeArea').classList.toggle('hidden', mode !== 'manual');
    if (mode === 'auto') {
        setAutoStatus('ready', '바코드를 스캔하면 자동으로 출력됩니다');
    }
    document.getElementById('barcodeInput').focus();
}

// ========== Search ==========
let searchTimeout = null;
let currentSearchResults = [];
let selectedDropdownIndex = -1;

function handleSearch() {
    const term = document.getElementById('barcodeInput').value.trim();
    if (!term) {
        showStatus('검색어를 입력하세요.', 'warning');
        return;
    }
    closeDropdown();
    searchExact(term);
}

// 정확 매칭 (바코드/SKU) → 실패 시 상품명 검색
async function searchExact(term) {
    const spinner = document.getElementById('inputSpinner');
    spinner.classList.remove('hidden');
    showStatus('<i class="fas fa-spinner fa-spin mr-1"></i>검색 중...', 'info');

    try {
        // 1단계: 바코드/SKU 정확 매칭 시도
        const barcodeResp = await fetch('ajax_get_product_by_barcode.php?barcode=' + encodeURIComponent(term));
        const barcodeData = await barcodeResp.json();

        if (barcodeData.success) {
            spinner.classList.add('hidden');
            handleProductFound(barcodeData.product);
            return;
        }

        // 2단계: 정확 매칭 실패 → 상품명 검색
        const searchResp = await fetch('ajax_search_products.php?term=' + encodeURIComponent(term) + '&limit=20');
        const searchData = await searchResp.json();
        spinner.classList.add('hidden');

        if (Array.isArray(searchData) && searchData.length > 0) {
            if (searchData.length === 1 && searchData[0].exact_match) {
                handleProductFound(normalizeProduct(searchData[0]));
            } else {
                showDropdown(searchData);
                showStatus('<i class="fas fa-list mr-1"></i>' + searchData.length + '개 상품 검색됨 — 선택하세요', 'info');
            }
        } else {
            showStatus('<i class="fas fa-exclamation-circle mr-1"></i>상품을 찾을 수 없습니다: ' + escapeHtml(term), 'error');
            if (currentMode === 'auto') {
                setAutoStatus('error', '상품을 찾을 수 없습니다: ' + term);
            }
        }

    } catch (error) {
        spinner.classList.add('hidden');
        console.error('Search error:', error);
        showStatus('<i class="fas fa-exclamation-triangle mr-1"></i>네트워크 오류가 발생했습니다.', 'error');
        if (currentMode === 'auto') setAutoStatus('error', '네트워크 오류');
    }
}

// 실시간 검색 (keyup, 2글자 이상)
async function liveSearch(term) {
    try {
        const resp = await fetch('ajax_search_products.php?term=' + encodeURIComponent(term) + '&limit=15');
        const data = await resp.json();
        document.getElementById('inputSpinner').classList.add('hidden');

        if (Array.isArray(data) && data.length > 0) {
            showDropdown(data);
        } else {
            showDropdownEmpty(term);
        }
    } catch (e) {
        document.getElementById('inputSpinner').classList.add('hidden');
        closeDropdown();
    }
}

// ajax_search_products.php 결과를 barcode_print용 형식으로 변환
function normalizeProduct(item) {
    return {
        product_id: item.id || item.product_id,
        sku: item.sku,
        name_en: item.name_en || '',
        name_ko: item.name_ko || '',
        selling_price_raw: item.selling_price || item.selling_price_raw || 0
    };
}

// 상품 찾음 → 모드에 따라 처리
function handleProductFound(product) {
    if (currentMode === 'auto') {
        autoPrint(product);
    } else {
        addToManualList(product);
    }
    document.getElementById('barcodeInput').value = '';
    document.getElementById('barcodeInput').focus();
    showStatus('<i class="fas fa-check-circle mr-1"></i>' + escapeHtml(product.name_en || product.name_ko || product.sku) + ' 선택됨', 'success');
}

// ========== Dropdown ==========
function showDropdown(items) {
    currentSearchResults = items;
    selectedDropdownIndex = 0;
    const dropdown = document.getElementById('searchDropdown');
    dropdown.innerHTML = items.map((item, idx) => {
        const name = item.name_ko || item.name_en || item.sku;
        const nameEn = item.name_en ? ' <span class="text-gray-400">(' + escapeHtml(item.name_en) + ')</span>' : '';
        const brand = item.brand_name ? '<span class="text-purple-600">' + escapeHtml(item.brand_name) + '</span> · ' : '';
        const price = item.selling_price ? formatPrice(item.selling_price) : '';
        return '<div class="search-item' + (idx === 0 ? ' selected' : '') + '" data-index="' + idx + '">' +
            '<div class="item-name">' + escapeHtml(item.name_ko || '') + nameEn + '</div>' +
            '<div class="item-meta">' + brand + 'SKU: ' + escapeHtml(item.sku) + (price ? ' · ' + price : '') + '</div>' +
        '</div>';
    }).join('');

    dropdown.classList.remove('hidden');

    dropdown.querySelectorAll('.search-item').forEach(el => {
        el.addEventListener('click', function() {
            const idx = parseInt(this.dataset.index);
            selectDropdownItem(idx);
        });
    });
}

function showDropdownEmpty(term) {
    const dropdown = document.getElementById('searchDropdown');
    dropdown.innerHTML = '<div class="search-empty"><i class="fas fa-search mr-1"></i>"' + escapeHtml(term) + '" 검색 결과 없음</div>';
    dropdown.classList.remove('hidden');
    currentSearchResults = [];
    selectedDropdownIndex = -1;
}

function closeDropdown() {
    document.getElementById('searchDropdown').classList.add('hidden');
    currentSearchResults = [];
    selectedDropdownIndex = -1;
}

function selectDropdownItem(idx) {
    if (idx < 0 || idx >= currentSearchResults.length) return;
    const item = currentSearchResults[idx];
    closeDropdown();
    handleProductFound(normalizeProduct(item));
}

function navigateDropdown(direction) {
    if (currentSearchResults.length === 0) return;
    const items = document.querySelectorAll('#searchDropdown .search-item');
    if (items.length === 0) return;

    items[selectedDropdownIndex]?.classList.remove('selected');
    selectedDropdownIndex += direction;
    if (selectedDropdownIndex < 0) selectedDropdownIndex = items.length - 1;
    if (selectedDropdownIndex >= items.length) selectedDropdownIndex = 0;
    items[selectedDropdownIndex]?.classList.add('selected');
    items[selectedDropdownIndex]?.scrollIntoView({ block: 'nearest' });
}

function showStatus(html, type) {
    const el = document.getElementById('searchStatus');
    const colors = { info: 'text-blue-600', success: 'text-green-600', error: 'text-red-600', warning: 'text-orange-600' };
    el.className = 'mt-2 text-sm min-h-[1.5rem] ' + (colors[type] || '');
    el.innerHTML = html;
}

// ========== Auto Mode ==========
function setAutoStatus(type, message) {
    const el = document.getElementById('autoStatus');
    el.className = 'auto-status ' + type;
    const icons = { ready: 'fa-barcode', printing: 'fa-spinner fa-spin', error: 'fa-exclamation-triangle' };
    el.innerHTML = '<i class="fas ' + (icons[type] || 'fa-barcode') + ' mr-3 text-2xl"></i>' + escapeHtml(message);
}

function autoPrint(product) {
    const typeName = printType === 'pricing' ? '프라이싱' : '바코드라벨(2p)';
    setAutoStatus('printing', typeName + ' 출력 중: ' + (product.name_en || product.name_ko || product.sku));

    const sku = product.sku;
    const printUrl = 'barcode_print.php?skus=' + encodeURIComponent(sku) + '&mode=' + printType + '&autoprint=1';

    // 팝업 창으로 열기 - barcode_print.php가 자체적으로 window.print() 호출 후 닫힘
    const popup = window.open(printUrl, '_blank', 'width=900,height=600,toolbar=0,menubar=0,location=0,status=0');

    setAutoStatus('ready', '바코드를 스캔하면 자동으로 출력됩니다');
    showStatus('<i class="fas fa-check-circle mr-1"></i>출력 완료: ' + escapeHtml(product.name_en || product.sku), 'success');
    addToHistory(product);
}

function addToHistory(product) {
    const now = new Date();
    const timeStr = now.getHours().toString().padStart(2, '0') + ':' +
                    now.getMinutes().toString().padStart(2, '0') + ':' +
                    now.getSeconds().toString().padStart(2, '0');
    printHistory.unshift({
        sku: product.sku,
        name: product.name_en || product.name_ko || product.sku,
        price: product.selling_price_raw,
        time: timeStr,
        type: printType
    });
    if (printHistory.length > MAX_HISTORY) printHistory = printHistory.slice(0, MAX_HISTORY);
    renderHistory();
}

function renderHistory() {
    const container = document.getElementById('historyList');
    if (printHistory.length === 0) {
        container.innerHTML = '<div class="text-center text-sm text-gray-400 py-8"><i class="fas fa-inbox text-2xl mb-2 block"></i>출력 이력이 없습니다</div>';
        return;
    }
    container.innerHTML = printHistory.map(item => {
        const typeLabel = item.type === 'pricing'
            ? '<span class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded mr-2">프라이싱</span>'
            : '<span class="text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded mr-2">2p</span>';
        return '<div class="history-item">' +
            '<span class="history-time">' + escapeHtml(item.time) + '</span>' +
            typeLabel +
            '<span class="flex-1 mx-2 truncate font-medium">' + escapeHtml(item.name) + '</span>' +
            '<code class="text-xs text-gray-500 mr-3">' + escapeHtml(item.sku) + '</code>' +
            (item.price ? '<span class="text-sm font-medium text-gray-700">' + formatPrice(item.price) + '</span>' : '') +
        '</div>';
    }).join('');
}

function clearHistory() { printHistory = []; renderHistory(); }

// ========== Manual Mode ==========
function addToManualList(product) {
    const existing = manualList.find(item => item.sku === product.sku);
    if (existing) {
        existing.qty += 1;
        showStatus('<i class="fas fa-plus-circle mr-1"></i>' + escapeHtml(product.name_en || product.sku) + ' 수량 증가 (' + existing.qty + '장)', 'success');
    } else {
        manualList.push({
            product_id: product.product_id,
            sku: product.sku,
            name_en: product.name_en || '',
            name_ko: product.name_ko || '',
            selling_price_raw: product.selling_price_raw || 0,
            qty: 1
        });
        showStatus('<i class="fas fa-check-circle mr-1"></i>' + escapeHtml(product.name_en || product.sku) + ' 추가됨', 'success');
    }
    renderManualTable();
}

function renderManualTable() {
    const tbody = document.getElementById('manualTableBody');
    const totalEl = document.getElementById('totalItems');

    if (manualList.length === 0) {
        tbody.innerHTML = '<tr id="emptyRow"><td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400"><i class="fas fa-barcode text-3xl mb-3 block"></i>바코드를 스캔하여 상품을 추가하세요</td></tr>';
        totalEl.textContent = '0';
        updateSelectedCount();
        return;
    }

    tbody.innerHTML = manualList.map((item, idx) =>
        '<tr class="border-b border-gray-100 hover:bg-gray-50" style="cursor:pointer">' +
            '<td class="px-4 py-3 text-center"><input type="checkbox" class="manual-checkbox w-4 h-4 rounded border-gray-300 text-purple-600" data-index="' + idx + '"></td>' +
            '<td class="px-4 py-3 text-sm"><code>' + escapeHtml(item.sku) + '</code></td>' +
            '<td class="px-4 py-3 text-sm font-medium">' + escapeHtml(item.name_en || item.name_ko || item.sku) + '</td>' +
            '<td class="px-4 py-3 text-sm text-right hidden sm:table-cell">' + formatPrice(item.selling_price_raw) + '</td>' +
            '<td class="px-4 py-3 text-center">' +
                '<div class="inline-flex items-center gap-1">' +
                    '<button class="qty-btn" onclick="changeQty(' + idx + ', -1)">-</button>' +
                    '<input type="number" class="qty-input" value="' + item.qty + '" min="1" max="99" onchange="setQty(' + idx + ', this.value)">' +
                    '<button class="qty-btn" onclick="changeQty(' + idx + ', 1)">+</button>' +
                '</div>' +
            '</td>' +
            '<td class="px-4 py-3 text-center">' +
                '<button onclick="removeFromList(' + idx + ')" class="text-red-400 hover:text-red-600" title="삭제"><i class="fas fa-trash text-sm"></i></button>' +
            '</td>' +
        '</tr>'
    ).join('');

    totalEl.textContent = manualList.length;

    document.querySelectorAll('.manual-checkbox').forEach(cb => cb.addEventListener('change', updateSelectedCount));
    tbody.querySelectorAll('tr').forEach(row => {
        row.addEventListener('click', function(e) {
            if (e.target.closest('button') || e.target.closest('input')) return;
            const cb = this.querySelector('.manual-checkbox');
            if (cb) { cb.checked = !cb.checked; updateSelectedCount(); }
        });
    });
    updateSelectedCount();
}

function changeQty(idx, delta) {
    const newQty = manualList[idx].qty + delta;
    if (newQty < 1 || newQty > 99) return;
    manualList[idx].qty = newQty;
    renderManualTable();
}

function setQty(idx, val) {
    const n = parseInt(val);
    manualList[idx].qty = isNaN(n) || n < 1 ? 1 : (n > 99 ? 99 : n);
    renderManualTable();
}

function removeFromList(idx) { manualList.splice(idx, 1); renderManualTable(); }

function clearManualList() {
    if (manualList.length === 0) return;
    if (!confirm('리스트를 모두 삭제하시겠습니까?')) return;
    manualList = [];
    renderManualTable();
}

function updateSelectedCount() {
    const selected = document.querySelectorAll('.manual-checkbox:checked').length;
    document.getElementById('selectedItems').textContent = selected;
    const all = document.querySelectorAll('.manual-checkbox');
    const selectAllCb = document.getElementById('selectAllCheckbox');
    selectAllCb.checked = all.length > 0 && selected === all.length;
    selectAllCb.indeterminate = selected > 0 && selected < all.length;
}

document.getElementById('selectAllCheckbox').addEventListener('change', function() {
    document.querySelectorAll('.manual-checkbox').forEach(cb => cb.checked = this.checked);
    updateSelectedCount();
});

// ========== Manual Print ==========
function printSelected() {
    const checked = document.querySelectorAll('.manual-checkbox:checked');
    if (checked.length === 0) { alert('출력할 상품을 ��택하세요.'); return; }

    let skuList = [];
    checked.forEach(cb => {
        const idx = parseInt(cb.dataset.index);
        const item = manualList[idx];
        for (let i = 0; i < item.qty; i++) skuList.push(item.sku);
    });

    const skuParam = skuList.join(',');
    const printUrl = 'barcode_print.php?skus=' + encodeURIComponent(skuParam) + '&mode=' + printType;

    const modal = document.getElementById('printModal');
    const container = document.getElementById('printIframeContainer');
    container.innerHTML = '<iframe src="' + printUrl + '" style="width:100%; height:100%; border:none;"></iframe>';
    modal.classList.remove('hidden');
}

function confirmPrint() {
    const iframe = document.querySelector('#printIframeContainer iframe');
    if (iframe) iframe.contentWindow.print();
}

function closePrintModal() {
    document.getElementById('printModal').classList.add('hidden');
    document.getElementById('printIframeContainer').innerHTML = '';
}

// ========== Utils ==========
function escapeHtml(text) {
    if (!text) return '';
    const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function formatPrice(price) {
    if (!price && price !== 0) return '';
    return Math.floor(price).toLocaleString();
}

// ========== Event Listeners ==========
const barcodeInput = document.getElementById('barcodeInput');

// Enter 키: 드롭다운 열려있으면 선택, 아니면 검색
barcodeInput.addEventListener('keydown', function(e) {
    const dropdownVisible = !document.getElementById('searchDropdown').classList.contains('hidden');

    if (e.key === 'ArrowDown' && dropdownVisible) {
        e.preventDefault();
        navigateDropdown(1);
        return;
    }
    if (e.key === 'ArrowUp' && dropdownVisible) {
        e.preventDefault();
        navigateDropdown(-1);
        return;
    }
    if (e.key === 'Enter') {
        e.preventDefault();
        if (dropdownVisible && selectedDropdownIndex >= 0 && currentSearchResults.length > 0) {
            selectDropdownItem(selectedDropdownIndex);
        } else {
            handleSearch();
        }
        return;
    }
    if (e.key === 'Escape' && dropdownVisible) {
        e.preventDefault();
        closeDropdown();
        return;
    }
});

// 실시간 검색 (keyup, 2글자 이상, debounce 300ms)
barcodeInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const term = this.value.trim();

    if (term.length < 2) {
        closeDropdown();
        return;
    }

    // 바코드 패턴 (숫자만 8자리 이상)이면 실시간 검색 하지 않음 (Enter로 처리)
    if (/^\d{8,}$/.test(term)) {
        closeDropdown();
        return;
    }

    document.getElementById('inputSpinner').classList.remove('hidden');
    searchTimeout = setTimeout(() => liveSearch(term), 300);
});

// 외부 클릭 시 드롭다운 닫기
document.addEventListener('click', function(e) {
    if (!e.target.closest('#searchWrapper')) {
        closeDropdown();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('printModal');
        if (!modal.classList.contains('hidden')) closePrintModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    barcodeInput.focus();
});
</script>

<?php
require_once __DIR__ . '/partials/footer.php';
?>
