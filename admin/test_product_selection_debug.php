<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 테스트용 세션 설정
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'super_admin';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>상품 선택 디버깅 테스트</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .debug-log {
            background-color: #1f2937;
            color: #10b981;
            font-family: 'Consolas', monospace;
            font-size: 12px;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #374151;
        }
        .error-log {
            color: #ef4444;
        }
        .warning-log {
            color: #f59e0b;
        }
        .info-log {
            color: #06b6d4;
        }
        .product-item {
            transition: all 0.2s ease;
        }
        .product-item:hover {
            background-color: #f3f4f6;
            transform: translateX(2px);
        }
    </style>
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">
            <i class="fas fa-bug mr-2 text-red-500"></i>상품 선택 디버깅 테스트
        </h1>
        
        <!-- 상태 정보 -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">🔍 디버깅 정보</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-blue-50 p-4 rounded">
                    <div class="font-medium text-blue-800">현재 점포</div>
                    <div class="text-blue-700" id="current-store-info">본점 (ID: 1)</div>
                </div>
                <div class="bg-green-50 p-4 rounded">
                    <div class="font-medium text-green-800">검색 상태</div>
                    <div class="text-green-700" id="search-status">대기 중</div>
                </div>
                <div class="bg-purple-50 p-4 rounded">
                    <div class="font-medium text-purple-800">이벤트 상태</div>
                    <div class="text-purple-700" id="event-status">준비됨</div>
                </div>
            </div>
        </div>
        
        <!-- 테스트 컨트롤 -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">🎮 테스트 컨트롤</h2>
            <div class="flex flex-wrap gap-4">
                <div class="flex-1 min-w-64">
                    <label class="block text-sm font-medium text-gray-700 mb-2">상품 검색</label>
                    <input type="text" 
                           id="product-search" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                           placeholder="상품명, SKU, 바코드로 검색...">
                </div>
                <div class="flex items-end">
                    <button onclick="clearLog()" 
                            class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600">
                        <i class="fas fa-broom mr-2"></i>로그 지우기
                    </button>
                </div>
            </div>
            
            <!-- 검색 결과 -->
            <div id="search-results" class="mt-4 hidden">
                <h3 class="text-lg font-medium text-gray-800 mb-3">검색 결과</h3>
                <div id="products-list" class="space-y-2"></div>
            </div>
        </div>
        
        <!-- 디버그 로그 -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4">📝 실시간 디버그 로그</h2>
            <div id="debug-log" class="debug-log">
                <div class="info-log">디버깅 콘솔이 준비되었습니다. 상품을 검색하고 클릭해보세요.</div>
            </div>
        </div>
    </div>

    <script>
    let debugLogElement = document.getElementById('debug-log');
    
    // 디버그 로그 함수
    function addDebugLog(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString('ko-KR');
        const logClass = `${type}-log`;
        const icon = type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️';
        
        const logEntry = document.createElement('div');
        logEntry.className = logClass;
        logEntry.innerHTML = `[${timestamp}] ${icon} ${message}`;
        
        debugLogElement.appendChild(logEntry);
        debugLogElement.scrollTop = debugLogElement.scrollHeight;
        
        // 콘솔에도 출력
        console.log(`[DEBUG] ${message}`);
    }
    
    function clearLog() {
        debugLogElement.innerHTML = '<div class="info-log">디버그 로그가 초기화되었습니다.</div>';
    }
    
    // 검색 기능
    const productSearchInput = document.getElementById('product-search');
    const searchResultsDiv = document.getElementById('search-results');
    const productsListDiv = document.getElementById('products-list');
    const searchStatusDiv = document.getElementById('search-status');
    
    let searchTimeout;
    
    productSearchInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            searchResultsDiv.classList.add('hidden');
            searchStatusDiv.textContent = '대기 중';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            searchProducts(query);
        }, 500);
    });
    
    function searchProducts(query) {
        addDebugLog(`상품 검색 시작: "${query}"`, 'info');
        searchStatusDiv.textContent = '검색 중...';
        
        const formData = new FormData();
        formData.append('q', query);
        formData.append('from_store_id', '1'); // 테스트용 점포 ID
        formData.append('limit', '10');
        
        fetch('ajax_search_transfer_products.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            addDebugLog(`API 응답 받음 (상태: ${response.status})`, 'info');
            return response.json();
        })
        .then(data => {
            addDebugLog(`API 데이터: ${JSON.stringify(data, null, 2)}`, 'info');
            
            if (data.success && data.products) {
                displaySearchResults(data.products);
                searchStatusDiv.textContent = `${data.products.length}개 상품 발견`;
                addDebugLog(`${data.products.length}개 상품을 성공적으로 가져왔습니다.`, 'info');
            } else {
                searchStatusDiv.textContent = '검색 실패';
                addDebugLog(`검색 실패: ${data.message || '알 수 없는 오류'}`, 'error');
                searchResultsDiv.classList.add('hidden');
            }
        })
        .catch(error => {
            addDebugLog(`네트워크 오류: ${error.message}`, 'error');
            searchStatusDiv.textContent = '네트워크 오류';
            searchResultsDiv.classList.add('hidden');
        });
    }
    
    function displaySearchResults(products) {
        let html = '';
        
        products.forEach((product, index) => {
            addDebugLog(`상품 ${index + 1}: ID=${product.id}, SKU=${product.sku}, Name=${product.name_ko || product.name_en}`, 'info');
            
            html += `
                <div class="product-item p-4 border border-gray-200 rounded-lg cursor-pointer hover:shadow-md"
                     data-id="${product.id}"
                     data-sku="${product.sku}"
                     data-name-ko="${product.name_ko || ''}"
                     data-name-en="${product.name_en || ''}"
                     data-cost-price="${product.cost_price}"
                     data-available-quantity="${product.available_quantity}"
                     data-min-quantity="${product.min_quantity || 1}"
                     data-pieces-per-box="${product.pieces_per_box || 1}">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="font-medium text-gray-900">
                                ${product.name_en || product.name_ko || 'N/A'}
                            </div>
                            <div class="text-sm text-gray-600 mt-1">
                                ${product.name_ko && product.name_en && product.name_ko !== product.name_en ? product.name_ko : ''}
                            </div>
                            <div class="text-xs text-gray-500 mt-2 space-x-3">
                                <span>SKU: <strong>${product.sku}</strong></span>
                                <span>원가: <strong>${parseFloat(product.cost_price).toFixed(2)}</strong></span>
                                <span>재고: <strong>${product.available_quantity}개</strong></span>
                                <span>박스포장: <strong>${product.pieces_per_box || 1}개</strong></span>
                            </div>
                        </div>
                        <div class="ml-4">
                            <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold">
                                ${index + 1}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        productsListDiv.innerHTML = html;
        searchResultsDiv.classList.remove('hidden');
        
        // 이벤트 리스너 추가
        addProductEventListeners();
    }
    
    function addProductEventListeners() {
        const productItems = document.querySelectorAll('.product-item');
        addDebugLog(`${productItems.length}개 상품에 이벤트 리스너 추가 중...`, 'info');
        
        productItems.forEach((item, index) => {
            addDebugLog(`상품 ${index + 1} 이벤트 리스너 추가: ID=${item.dataset.id}`, 'info');
            
            item.addEventListener('click', function(event) {
                addDebugLog(`상품 클릭됨! 인덱스: ${index + 1}, ID: ${this.dataset.id}`, 'info');
                addDebugLog(`이벤트 객체: ${JSON.stringify({
                    target: event.target.tagName,
                    currentTarget: event.currentTarget.tagName,
                    dataset: this.dataset
                }, null, 2)}`, 'info');
                
                try {
                    // 데이터 추출 테스트
                    const productData = {
                        id: this.getAttribute('data-id') || this.dataset.id,
                        sku: this.getAttribute('data-sku') || this.dataset.sku,
                        nameKo: this.getAttribute('data-name-ko') || this.dataset.nameKo || '',
                        nameEn: this.getAttribute('data-name-en') || this.dataset.nameEn || '',
                        costPrice: parseFloat(this.getAttribute('data-cost-price') || this.dataset.costPrice || 0),
                        availableQuantity: parseInt(this.getAttribute('data-available-quantity') || this.dataset.availableQuantity || 0),
                        minQuantity: parseInt(this.getAttribute('data-min-quantity') || this.dataset.minQuantity || 1),
                        piecesPerBox: parseInt(this.getAttribute('data-pieces-per-box') || this.dataset.piecesPerBox || 1)
                    };
                    
                    addDebugLog(`추출된 상품 데이터: ${JSON.stringify(productData, null, 2)}`, 'info');
                    
                    // 데이터 검증
                    if (!productData.id) {
                        throw new Error('상품 ID가 없습니다');
                    }
                    
                    if (!productData.sku) {
                        throw new Error('상품 SKU가 없습니다');
                    }
                    
                    if (productData.costPrice <= 0) {
                        throw new Error('유효하지 않은 원가입니다');
                    }
                    
                    addDebugLog(`✅ 상품 선택 성공! 다음 단계는 매입 이력 모달을 표시하는 것입니다.`, 'info');
                    addDebugLog(`실제 시스템에서는 showPurchaseHistoryBeforeAdd() 함수가 호출됩니다.`, 'info');
                    
                    // 시뮬레이션: 실제 함수 호출 테스트
                    if (typeof window.showPurchaseHistoryBeforeAdd === 'function') {
                        addDebugLog(`showPurchaseHistoryBeforeAdd 함수 발견됨. 호출 중...`, 'info');
                        window.showPurchaseHistoryBeforeAdd(this);
                    } else {
                        addDebugLog(`showPurchaseHistoryBeforeAdd 함수를 찾을 수 없습니다. 테스트용 시뮬레이션을 실행합니다.`, 'warning');
                        
                        // 성공 메시지
                        document.getElementById('event-status').textContent = '선택 성공!';
                        document.getElementById('event-status').className = 'text-green-700';
                        
                        setTimeout(() => {
                            document.getElementById('event-status').textContent = '준비됨';
                            document.getElementById('event-status').className = 'text-purple-700';
                        }, 3000);
                    }
                    
                } catch (error) {
                    addDebugLog(`❌ 상품 선택 중 오류 발생: ${error.message}`, 'error');
                    addDebugLog(`오류 스택: ${error.stack}`, 'error');
                    
                    document.getElementById('event-status').textContent = '선택 실패!';
                    document.getElementById('event-status').className = 'text-red-700';
                    
                    setTimeout(() => {
                        document.getElementById('event-status').textContent = '준비됨';
                        document.getElementById('event-status').className = 'text-purple-700';
                    }, 3000);
                }
            });
        });
        
        addDebugLog(`✅ 모든 상품에 이벤트 리스너 추가 완료`, 'info');
    }
    
    // 페이지 로드 완료
    document.addEventListener('DOMContentLoaded', function() {
        addDebugLog('페이지 로드 완료. 디버깅 테스트를 시작할 수 있습니다.', 'info');
        addDebugLog('상품명을 검색 입력창에 입력하여 테스트하세요.', 'info');
    });
    </script>
</body>
</html>