<?php
/**
 * 배달 앱 API 테스트 페이지
 * 개발 중 API 엔드포인트 테스트용
 */

require_once __DIR__ . '/config/api_config.php';

// 관리자 권한 확인
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    api_forbidden('Super admin access required for API testing');
}

$test_results = [];

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>배달 앱 API 테스트</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .test-result { margin: 10px 0; padding: 10px; border-radius: 3px; }
        .success { background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .info { background-color: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .endpoint { font-family: monospace; background: #f8f9fa; padding: 5px; border-radius: 3px; }
        textarea { width: 100%; height: 200px; font-family: monospace; }
        button { padding: 10px 20px; margin: 5px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>🚚 배달 앱 API 테스트</h1>
    
    <div class="test-section">
        <h2>📋 API 기본 정보</h2>
        <button onclick="testEndpoint('/min/api', 'GET')">API Info 테스트</button>
        <div id="api-info-result"></div>
    </div>
    
    <div class="test-section">
        <h2>🔐 인증 API 테스트</h2>
        
        <h3>인증 상태 확인</h3>
        <button onclick="testEndpoint('/min/api/auth/check', 'GET')">인증 확인</button>
        <button onclick="testEndpoint('/min/api/auth/check?detailed=true', 'GET')">상세 인증 확인</button>
        <div id="auth-check-result"></div>
        
        <h3>프로필 조회</h3>
        <button onclick="testEndpoint('/min/api/auth/profile', 'GET')">프로필 조회</button>
        <div id="profile-result"></div>
        
        <h3>로그인 테스트</h3>
        <div>
            <label>이메일: <input type="email" id="login-email" placeholder="test@example.com"></label><br><br>
            <label>패스워드: <input type="password" id="login-password" placeholder="password"></label><br><br>
            <button onclick="testLogin()">로그인 테스트</button>
        </div>
        <div id="login-result"></div>
        
        <h3>로그아웃</h3>
        <button onclick="testEndpoint('/min/api/auth/logout', 'POST')">로그아웃</button>
        <div id="logout-result"></div>
    </div>
    
    <div class="test-section">
        <h2>🛍️ 상품 API 테스트</h2>
        <button onclick="testEndpoint('/min/api/products', 'GET')">상품 목록</button>
        <button onclick="testEndpoint('/min/api/products/categories', 'GET')">카테고리 목록</button>
        <button onclick="testEndpoint('/min/api/products/brands', 'GET')">브랜드 목록</button>
        <div id="products-result"></div>
    </div>
    
    <div class="test-section">
        <h2>🛒 장바구니 API 테스트</h2>
        <button onclick="testEndpoint('/min/api/cart', 'GET')">장바구니 조회</button>
        <button onclick="testEndpoint('/min/api/cart/count', 'GET')">장바구니 개수</button>
        <div id="cart-result"></div>
    </div>
    
    <div class="test-section">
        <h2>🚚 배달 API 테스트</h2>
        <button onclick="testEndpoint('/min/api/delivery/zones', 'GET')">배달 지역</button>
        <button onclick="testEndpoint('/min/api/delivery/addresses', 'GET')">배달 주소</button>
        <div id="delivery-result"></div>
    </div>
    
    <div class="test-section">
        <h2>📍 위치 API 테스트</h2>
        <button onclick="testEndpoint('/min/api/locations/zones', 'GET')">서비스 지역</button>
        <button onclick="testEndpoint('/min/api/locations/provinces', 'GET')">주/지방 목록</button>
        <div id="locations-result"></div>
    </div>
    
    <div class="test-section">
        <h2>🔧 데이터베이스 설치</h2>
        <button onclick="testEndpoint('/min/api/install.php', 'GET')">설치 실행</button>
        <div id="install-result"></div>
    </div>
    
    <div class="test-section">
        <h2>📝 응답 로그</h2>
        <textarea id="response-log" readonly placeholder="API 응답 로그가 여기에 표시됩니다..."></textarea>
        <br>
        <button onclick="clearLog()">로그 지우기</button>
    </div>

    <script>
        function testEndpoint(url, method = 'GET', data = null) {
            const timestamp = new Date().toLocaleTimeString();
            logResponse(`[${timestamp}] ${method} ${url} - 요청 중...`);
            
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            };
            
            if (data && (method === 'POST' || method === 'PUT')) {
                options.body = JSON.stringify(data);
            }
            
            fetch(url, options)
                .then(response => {
                    const status = response.status;
                    const statusText = response.statusText;
                    
                    return response.json().then(data => ({
                        status: status,
                        statusText: statusText,
                        data: data
                    }));
                })
                .then(result => {
                    const resultClass = result.status >= 200 && result.status < 300 ? 'success' : 'error';
                    const resultDiv = document.createElement('div');
                    resultDiv.className = `test-result ${resultClass}`;
                    resultDiv.innerHTML = `
                        <strong>${method} ${url}</strong><br>
                        Status: ${result.status} ${result.statusText}<br>
                        <pre>${JSON.stringify(result.data, null, 2)}</pre>
                    `;
                    
                    // 결과를 해당 섹션에 표시
                    const sectionId = getSectionId(url);
                    const sectionDiv = document.getElementById(sectionId);
                    if (sectionDiv) {
                        sectionDiv.appendChild(resultDiv);
                    }
                    
                    logResponse(`[${timestamp}] ${method} ${url} - ${result.status}: ${JSON.stringify(result.data)}`);
                })
                .catch(error => {
                    const resultDiv = document.createElement('div');
                    resultDiv.className = 'test-result error';
                    resultDiv.innerHTML = `
                        <strong>${method} ${url}</strong><br>
                        Error: ${error.message}
                    `;
                    
                    const sectionId = getSectionId(url);
                    const sectionDiv = document.getElementById(sectionId);
                    if (sectionDiv) {
                        sectionDiv.appendChild(resultDiv);
                    }
                    
                    logResponse(`[${timestamp}] ${method} ${url} - Error: ${error.message}`);
                });
        }
        
        function testLogin() {
            const email = document.getElementById('login-email').value;
            const password = document.getElementById('login-password').value;
            
            if (!email || !password) {
                alert('이메일과 패스워드를 입력해주세요.');
                return;
            }
            
            const loginData = {
                email: email,
                password: password,
                remember_me: false
            };
            
            testEndpoint('/min/api/auth/login', 'POST', loginData);
        }
        
        function getSectionId(url) {
            if (url.includes('/auth/')) return 'auth-check-result';
            if (url.includes('/products')) return 'products-result';
            if (url.includes('/cart')) return 'cart-result';
            if (url.includes('/delivery')) return 'delivery-result';
            if (url.includes('/locations')) return 'locations-result';
            if (url.includes('/install')) return 'install-result';
            if (url.includes('/api') && !url.includes('/')) return 'api-info-result';
            return 'response-log';
        }
        
        function logResponse(message) {
            const log = document.getElementById('response-log');
            log.value += message + '\n\n';
            log.scrollTop = log.scrollHeight;
        }
        
        function clearLog() {
            document.getElementById('response-log').value = '';
        }
        
        // 페이지 로드 시 API 기본 정보 테스트
        window.onload = function() {
            testEndpoint('/min/api', 'GET');
        };
    </script>
</body>
</html>