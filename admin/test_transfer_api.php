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
    <title>점간이동 API 테스트</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .test-section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        button { padding: 10px 20px; margin: 5px; }
        #results { background: #f5f5f5; padding: 10px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>점간이동 API 테스트</h1>
    
    <div class="test-section">
        <h2>1. 권한 테스트</h2>
        <?php
        if (has_permission('store_transfer_management')) {
            echo "<p class='success'>✓ store_transfer_management 권한 확인됨</p>";
        } else {
            echo "<p class='error'>✗ store_transfer_management 권한 없음</p>";
        }
        ?>
    </div>
    
    <div class="test-section">
        <h2>2. 점포 정보 테스트</h2>
        <?php
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stores_stmt = $pdo->query("SELECT id, name FROM stores ORDER BY id LIMIT 5");
            $stores = $stores_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($stores)) {
                echo "<p class='success'>✓ " . count($stores) . "개 점포 발견</p>";
                echo "<ul>";
                foreach ($stores as $store) {
                    echo "<li>ID: {$store['id']}, 이름: " . htmlspecialchars($store['name']) . "</li>";
                }
                echo "</ul>";
                
                // 첫 번째 점포를 테스트용으로 저장
                $test_store_id = $stores[0]['id'];
                echo "<script>const testStoreId = {$test_store_id};</script>";
            } else {
                echo "<p class='error'>✗ 점포가 없습니다</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>✗ 오류: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>
    
    <div class="test-section">
        <h2>3. 상품 검색 API 테스트</h2>
        <p>출발 점포 ID: <input type="number" id="fromStoreId" value="1" min="1"></p>
        <p>검색어: <input type="text" id="searchQuery" value="" placeholder="예: 상품명 또는 SKU"></p>
        <button onclick="testProductSearch()">상품 검색 테스트</button>
        <div id="productResults"></div>
    </div>
    
    <div class="test-section">
        <h2>4. 전체 결과</h2>
        <div id="results">테스트를 실행하려면 위의 버튼을 클릭하세요.</div>
    </div>

    <script>
    function testProductSearch() {
        const fromStoreId = document.getElementById('fromStoreId').value;
        const searchQuery = document.getElementById('searchQuery').value;
        const resultsDiv = document.getElementById('productResults');
        const overallResults = document.getElementById('results');
        
        resultsDiv.innerHTML = '검색 중...';
        
        const formData = new FormData();
        formData.append('q', searchQuery);
        formData.append('from_store_id', fromStoreId);
        formData.append('limit', '5');
        if (!searchQuery) {
            formData.append('show_all', '1');
        }
        
        fetch('test_ajax_search_transfer_products_v2.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('API Response:', data);
            
            if (data.success) {
                let html = `<p class="success">✓ API 호출 성공 (${data.products.length}개 상품 발견)</p>`;
                
                // 테스트 모드 정보 표시
                if (data.test_mode) {
                    html += '<p class="info">🧪 테스트 모드로 실행됨</p>';
                }
                
                if (data.debug_info) {
                    html += '<h4>디버그 정보:</h4>';
                    html += `<p class="info">전체 조회된 상품 수: ${data.debug_info.raw_count}</p>`;
                    html += `<p class="info">필터링 후 상품 수: ${data.products.length}</p>`;
                }
                
                if (data.products.length > 0) {
                    html += '<table border="1" cellpadding="5">';
                    html += '<tr><th>SKU</th><th>상품명</th><th>원가</th><th>재고</th><th>브랜드</th></tr>';
                    
                    data.products.forEach(product => {
                        html += '<tr>';
                        html += `<td>${product.sku}</td>`;
                        html += `<td>${product.name_en || product.name_ko || 'N/A'}</td>`;
                        html += `<td>₩${parseFloat(product.cost_price).toFixed(2)}</td>`;
                        html += `<td>${product.available_quantity}</td>`;
                        html += `<td>${product.brand_name || 'N/A'}</td>`;
                        html += '</tr>';
                    });
                    
                    html += '</table>';
                } else {
                    html += '<p class="info">검색 결과가 없습니다.</p>';
                }
                
                resultsDiv.innerHTML = html;
                overallResults.innerHTML += '<p class="success">✓ 상품 검색 API 테스트 성공</p>';
                
            } else {
                let errorHtml = `<p class="error">✗ API 오류: ${data.message || '알 수 없는 오류'}</p>`;
                
                if (data.error_detail) {
                    errorHtml += `<p class="error">상세 오류: ${data.error_detail}</p>`;
                }
                
                if (data.test_mode) {
                    errorHtml += '<p class="info">🧪 테스트 모드에서 발생한 오류입니다.</p>';
                }
                
                resultsDiv.innerHTML = errorHtml;
                overallResults.innerHTML += '<p class="error">✗ 상품 검색 API 테스트 실패</p>';
            }
        })
        .catch(error => {
            console.error('Fetch Error:', error);
            resultsDiv.innerHTML = `<p class="error">✗ 네트워크 오류: ${error.message}</p>`;
            overallResults.innerHTML += '<p class="error">✗ 상품 검색 API 네트워크 오류</p>';
        });
    }
    
    // 페이지 로드시 자동으로 상품 검색 테스트 실행
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(testProductSearch, 1000);
    });
    </script>
</body>
</html>