<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>필리핀 배달 앱 API 테스트</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 20px; 
            background-color: #f5f5f5;
        }
        .container { 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .api-section { 
            margin: 20px 0; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            padding: 15px; 
        }
        .api-section h3 { 
            margin: 0 0 15px 0; 
            color: #333; 
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        button { 
            background: #007bff; 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            border-radius: 4px; 
            cursor: pointer;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        button:hover { 
            background: #0056b3; 
        }
        .result { 
            background: #f8f9fa; 
            border: 1px solid #e9ecef; 
            border-radius: 4px; 
            padding: 10px; 
            margin-top: 10px; 
            white-space: pre-wrap; 
            font-family: monospace; 
            max-height: 400px; 
            overflow-y: auto;
        }
        .success { 
            border-color: #28a745; 
            background-color: #d4edda; 
        }
        .error { 
            border-color: #dc3545; 
            background-color: #f8d7da; 
        }
        .input-group {
            margin: 10px 0;
        }
        .input-group label {
            display: inline-block;
            width: 120px;
            font-weight: bold;
        }
        .input-group input, .input-group select {
            width: 200px;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛒 필리핀 배달 앱 API 테스트</h1>
        <p>HOME K MART 배달 시스템 API 엔드포인트 테스트 페이지</p>
        
        <div class="api-section" style="background-color: #e3f2fd;">
            <h3>📍 현재 접속 정보</h3>
            <p><strong>현재 URL:</strong> <span id="current-url"></span></p>
            <p><strong>기본 경로:</strong> <span id="base-path"></span></p>
            <button onclick="showDebugInfo()">접속 정보 새로고침</button>
        </div>
        
        <!-- 상품 API -->
        <div class="api-section">
            <h3>📦 상품 관리 API</h3>
            <button onclick="testAPI('api_products.php?limit=5', 'GET')">상품 목록 조회</button>
            <button onclick="testAPI('api_products_safe.php?limit=5', 'GET')">상품 목록 (안전버전)</button>
            <button onclick="testAPI('api_products_debug.php', 'GET')">상품 API 디버그</button>
            <button onclick="testAPI('api_products_fixed.php?limit=5', 'GET')">상품 목록 (수정버전)</button>
            <button onclick="testAPI('simple_products_api.php', 'GET')">상품 API (초간단)</button>
            <button onclick="testAPI('api_products_working.php?limit=5', 'GET')">상품 API (작동버전)</button>
            <button onclick="testAPI('bulletproof_api.php', 'GET')">방탄 API</button>
            <button onclick="testAPI('working_products_api.php?limit=5', 'GET')">작동 상품 API</button>
            <button onclick="testAPI('simple_working_api.php', 'GET')">샘플 상품 API</button>
            <button onclick="testAPI('html_api.php?limit=5', 'GET')">HTML 형식 API</button>
            <button onclick="testAPI('correct_products_api.php?limit=5', 'GET')">올바른 상품 API</button>
            <button onclick="testAPI('enhanced_products_api.php?limit=5', 'GET')">확장된 상품 API</button>
            <button onclick="testAPI('enhanced_products_api.php?category_id=1&search=phone&limit=3', 'GET')">상품 검색 & 필터</button>
            <button onclick="testAPI('safe_products_api.php?limit=5', 'GET')">안전한 상품 API</button>
            <button onclick="testAPI('api_categories.php', 'GET')">카테고리 목록</button>
            <button onclick="testAPI('api_categories_safe.php', 'GET')">카테고리 목록 (안전버전)</button>
            <button onclick="testAPI('working_categories_api.php', 'GET')">작동 카테고리 API</button>
            <button onclick="testAPI('safe_categories_api.php', 'GET')">안전한 카테고리 API</button>
            <button onclick="testAPI('universal_api.php?type=products&limit=5', 'GET')">범용 상품 API</button>
            <button onclick="testAPI('universal_api.php?type=categories&limit=5', 'GET')">범용 카테고리 API</button>
            <button onclick="testAPI('universal_api.php?type=status', 'GET')">시스템 상태 API</button>
            <div id="products-result" class="result" style="display:none;"></div>
        </div>
        
        <!-- 장바구니 API -->
        <div class="api-section">
            <h3>🛍️ 장바구니 API</h3>
            <div class="input-group">
                <label>사용자 ID:</label>
                <input type="number" id="cart-user-id" value="1" min="1">
            </div>
            <button onclick="testCartAPI('GET')">장바구니 조회</button>
            <button onclick="addToCart()">장바구니 추가 테스트</button>
            <button onclick="testAPI('safe_cart_api.php?user_id=1', 'GET')">안전한 장바구니 API</button>
            <div id="cart-result" class="result" style="display:none;"></div>
        </div>
        
        <!-- 주문 API -->
        <div class="api-section">
            <h3>📋 주문 관리 API</h3>
            <div class="input-group">
                <label>사용자 ID:</label>
                <input type="number" id="order-user-id" value="1" min="1">
            </div>
            <div class="input-group">
                <label>주문 상태:</label>
                <select id="order-status">
                    <option value="">전체</option>
                    <option value="confirmed">확인됨</option>
                    <option value="preparing">준비중</option>
                    <option value="delivering">배송중</option>
                    <option value="delivered">배송완료</option>
                </select>
            </div>
            <button onclick="testOrderAPI('GET')">주문 목록 조회</button>
            <button onclick="createTestOrder()">테스트 주문 생성</button>
            <button onclick="testAPI('delivery_orders_api.php?user_id=1&limit=5', 'GET')">배달 주문 API</button>
            <button onclick="testAPI('safe_orders_api.php?user_id=1&limit=5', 'GET')">안전한 주문 API</button>
            <div id="orders-result" class="result" style="display:none;"></div>
        </div>
        
        <!-- 배달 주소 API -->
        <div class="api-section">
            <h3>📍 배달 주소 API</h3>
            <div class="input-group">
                <label>사용자 ID:</label>
                <input type="number" id="address-user-id" value="1" min="1">
            </div>
            <button onclick="testAddressAPI('GET')">주소 목록 조회</button>
            <button onclick="addTestAddress()">테스트 주소 추가</button>
            <button onclick="testAPI('delivery_addresses_api.php?user_id=1', 'GET')">배달 주소 API</button>
            <button onclick="testAPI('safe_addresses_api.php?user_id=1', 'GET')">안전한 주소 API</button>
            <div id="addresses-result" class="result" style="display:none;"></div>
        </div>
        
        <!-- 배달 구역 및 위치 API -->
        <div class="api-section">
            <h3>🗺️ 배달 구역 및 위치 API</h3>
            <button onclick="testAPI('delivery_zones_api.php?action=zones', 'GET')">배달 구역 목록</button>
            <button onclick="testAPI('delivery_zones_api.php?action=coverage&city=Makati&barangay=San Antonio', 'GET')">배달 가능 여부 확인</button>
            <button onclick="testAPI('delivery_zones_api.php?action=nearby&lat=14.5547&lng=121.0244', 'GET')">근처 배달 구역</button>
            <button onclick="testAPI('location_search_api.php?q=Makati&limit=5', 'GET')">위치 검색</button>
            <button onclick="testAPI('location_search_api.php?q=San&type=barangay&limit=5', 'GET')">바랑가이 검색</button>
            <div id="location-result" class="result" style="display:none;"></div>
        </div>
        
        <!-- 데이터베이스 상태 -->
        <div class="api-section">
            <h3>🔍 시스템 상태</h3>
            <button onclick="testAPI('simple_debug.php', 'GET')">PHP 기본 테스트</button>
            <button onclick="testAPI('config_test.php', 'GET')">설정 파일 테스트</button>
            <button onclick="testAPI('db_test.php', 'GET')">DB 연결 테스트</button>
            <button onclick="testAPI('test_connection.php', 'GET')">연결 테스트 (JSON)</button>
            <button onclick="testAPI('error_log_viewer.php', 'GET')">에러 로그 확인</button>
            <button onclick="testAPI('minimal_test.php', 'GET')">최소 테스트</button>
            <button onclick="testAPI('step_by_step.php', 'GET')">단계별 테스트</button>
            <button onclick="testAPI('syntax_check.php', 'GET')">구문 체크</button>
            <button onclick="testAPI('ultra_simple_api.php', 'GET')">초간단 API</button>
            <button onclick="testAPI('db_only_test.php', 'GET')">DB 연결만 테스트</button>
            <button onclick="testAPI('comprehensive_check.php', 'GET')">종합 진단</button>
            <button onclick="testAPI('final_debug.php', 'GET')">최종 디버그</button>
            <button onclick="testAPI('raw_output.php', 'GET')">Raw 출력</button>
            <button onclick="testAPI('no_htaccess_test.txt', 'GET')">정적 파일 테스트</button>
            <button onclick="testAPI('basic_no_pdo.php', 'GET')">기본 테스트 (PDO 없음)</button>
            <button onclick="testAPI('db_schema_inspector.php', 'GET')">DB 스키마 조사</button>
            <button onclick="testAPI('debug_tables.php', 'GET')">테이블 구조 디버깅</button>
            <div id="system-result" class="result" style="display:none;"></div>
        </div>
    </div>

    <script>
        // 현재 도메인 기반으로 기본 URL 설정
        const getCurrentDomain = () => {
            return window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
        };
        
        async function testAPI(url, method = 'GET', data = null) {
            // 상대 URL인 경우 현재 도메인과 결합
            const fullUrl = url.startsWith('http') ? url : getCurrentDomain() + url;
            
            // 결과를 표시할 div 결정
            let resultDiv;
            if (url.includes('products') || url.includes('categories')) {
                resultDiv = document.getElementById('products-result');
            } else if (url.includes('location') || url.includes('zones')) {
                resultDiv = document.getElementById('location-result');
            } else {
                resultDiv = document.getElementById('system-result');
            }
            
            try {
                const options = {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                    }
                };
                
                if (data && method !== 'GET') {
                    options.body = JSON.stringify(data);
                }
                
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = '요청 처리 중...';
                resultDiv.className = 'result';
                
                const response = await fetch(fullUrl, options);
                const result = await response.text();
                
                // JSON 파싱 시도
                let formattedResult = result;
                try {
                    const jsonData = JSON.parse(result);
                    formattedResult = JSON.stringify(jsonData, null, 2);
                } catch (e) {
                    // JSON이 아닌 경우 그대로 표시
                    formattedResult = result;
                }
                
                resultDiv.innerHTML = `Status: ${response.status}\nURL: ${fullUrl}\n\n${formattedResult}`;
                resultDiv.className = response.ok ? 'result success' : 'result error';
                
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Error: ${error.message}`;
                resultDiv.className = 'result error';
            }
        }
        
        async function testCartAPI(method) {
            const userId = document.getElementById('cart-user-id').value;
            const resultDiv = document.getElementById('cart-result');
            
            let url = `api_cart.php?user_id=${userId}`;
            const fullUrl = getCurrentDomain() + url;
            
            try {
                const response = await fetch(fullUrl, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' }
                });
                
                const result = await response.text();
                
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Status: ${response.status}\n\n${result}`;
                resultDiv.className = response.ok ? 'result success' : 'result error';
                
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Error: ${error.message}`;
                resultDiv.className = 'result error';
            }
        }
        
        async function addToCart() {
            const userId = document.getElementById('cart-user-id').value;
            const resultDiv = document.getElementById('cart-result');
            
            const data = {
                user_id: parseInt(userId),
                product_id: 1, // 첫 번째 상품
                quantity: 2,
                store_id: 1
            };
            
            try {
                const response = await fetch('api_cart.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.text();
                
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Status: ${response.status}\n\n${result}`;
                resultDiv.className = response.ok ? 'result success' : 'result error';
                
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Error: ${error.message}`;
                resultDiv.className = 'result error';
            }
        }
        
        async function testOrderAPI(method) {
            const userId = document.getElementById('order-user-id').value;
            const status = document.getElementById('order-status').value;
            const resultDiv = document.getElementById('orders-result');
            
            let url = `api_orders.php?user_id=${userId}`;
            if (status) url += `&status=${status}`;
            
            try {
                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' }
                });
                
                const result = await response.text();
                
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Status: ${response.status}\n\n${result}`;
                resultDiv.className = response.ok ? 'result success' : 'result error';
                
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Error: ${error.message}`;
                resultDiv.className = 'result error';
            }
        }
        
        async function createTestOrder() {
            const userId = document.getElementById('order-user-id').value;
            const resultDiv = document.getElementById('orders-result');
            
            // 먼저 테스트 주소 생성이 필요할 수 있음
            const data = {
                user_id: parseInt(userId),
                delivery_address_id: 1, // 기존 주소 사용
                payment_method: 'cod',
                notes: 'API 테스트 주문',
                items: [
                    {
                        product_id: 1,
                        quantity: 2,
                        store_id: 1
                    }
                ]
            };
            
            try {
                const response = await fetch('api_orders.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.text();
                
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Status: ${response.status}\n\n${result}`;
                resultDiv.className = response.ok ? 'result success' : 'result error';
                
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Error: ${error.message}`;
                resultDiv.className = 'result error';
            }
        }
        
        async function testAddressAPI(method) {
            const userId = document.getElementById('address-user-id').value;
            const resultDiv = document.getElementById('addresses-result');
            
            let url = `api_addresses.php?user_id=${userId}`;
            
            try {
                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' }
                });
                
                const result = await response.text();
                
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Status: ${response.status}\n\n${result}`;
                resultDiv.className = response.ok ? 'result success' : 'result error';
                
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Error: ${error.message}`;
                resultDiv.className = 'result error';
            }
        }
        
        async function addTestAddress() {
            const userId = document.getElementById('address-user-id').value;
            const resultDiv = document.getElementById('addresses-result');
            
            const data = {
                user_id: parseInt(userId),
                recipient_name: 'Juan Dela Cruz',
                phone_number: '09171234567',
                street_address: '123 Rizal Street',
                barangay: 'San Antonio',
                city: 'Makati',
                province: 'Metro Manila',
                postal_code: '1203',
                landmark: 'Near SM Makati',
                delivery_instructions: 'Ring doorbell twice',
                is_default: true
            };
            
            try {
                const response = await fetch('api_addresses.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.text();
                
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Status: ${response.status}\n\n${result}`;
                resultDiv.className = response.ok ? 'result success' : 'result error';
                
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.innerHTML = `Error: ${error.message}`;
                resultDiv.className = 'result error';
            }
        }
        
        // 디버그 정보 표시
        function showDebugInfo() {
            document.getElementById('current-url').textContent = window.location.href;
            document.getElementById('base-path').textContent = getCurrentDomain();
        }
        
        // 페이지 로드시 시스템 상태 확인
        document.addEventListener('DOMContentLoaded', function() {
            showDebugInfo();
            testAPI('simple_debug.php', 'GET');
        });
    </script>
</body>
</html>