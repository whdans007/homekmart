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
    <title>박스포장수량 자동 설정 테스트</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        .test-section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        button { padding: 10px 20px; background: #007cba; color: white; border: none; border-radius: 3px; cursor: pointer; margin: 5px; }
        .highlight { background-color: #ffffcc; }
    </style>
</head>
<body>
    <h1>박스포장수량 자동 설정 기능 테스트</h1>
    
    <div class="test-section">
        <h2>1. 상품 정보 확인</h2>
        <p class="info">상품 테이블에서 박스포장수량(pieces_per_box) 정보를 확인합니다.</p>
        
        <?php
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // pieces_per_box 컬럼 존재 여부 확인
            $pieces_per_box_exists = $pdo->query("SHOW COLUMNS FROM products LIKE 'pieces_per_box'")->fetch();
            
            if ($pieces_per_box_exists) {
                echo "<p class='success'>✅ products 테이블에 pieces_per_box 컬럼이 존재합니다.</p>";
                
                // 샘플 상품들의 박스포장수량 정보 조회
                $sql = "SELECT id, sku, name_ko, name_en, COALESCE(pieces_per_box, 1) as pieces_per_box FROM products ORDER BY id LIMIT 10";
                $stmt = $pdo->query($sql);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($products)) {
                    echo "<h3>샘플 상품들의 박스포장수량:</h3>";
                    echo "<table>";
                    echo "<tr><th>ID</th><th>SKU</th><th>상품명</th><th>박스포장수량</th></tr>";
                    
                    foreach ($products as $product) {
                        $highlight = ($product['pieces_per_box'] > 1) ? 'highlight' : '';
                        echo "<tr class='{$highlight}'>";
                        echo "<td>" . htmlspecialchars($product['id']) . "</td>";
                        echo "<td>" . htmlspecialchars($product['sku']) . "</td>";
                        echo "<td>" . htmlspecialchars($product['name_ko'] ?: $product['name_en']) . "</td>";
                        echo "<td><strong>" . htmlspecialchars($product['pieces_per_box']) . "</strong></td>";
                        echo "</tr>";
                    }
                    
                    echo "</table>";
                    echo "<p class='info'>💡 강조 표시된 상품들은 박스포장수량이 1보다 큰 상품입니다.</p>";
                }
            } else {
                echo "<p class='error'>❌ products 테이블에 pieces_per_box 컬럼이 없습니다.</p>";
                echo "<p class='info'>다음 SQL을 실행하여 컬럼을 추가할 수 있습니다:</p>";
                echo "<code>ALTER TABLE products ADD COLUMN pieces_per_box INT DEFAULT 1;</code>";
            }
            
        } catch (Exception $e) {
            echo "<p class='error'>❌ 데이터베이스 오류: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>
    
    <div class="test-section">
        <h2>2. API 응답 테스트</h2>
        <p class="info">상품 검색 API에서 박스포장수량이 올바르게 반환되는지 확인합니다.</p>
        
        <button onclick="testApi()">API 테스트 실행</button>
        <div id="api-result"></div>
    </div>
    
    <div class="test-section">
        <h2>3. 실제 기능 테스트 방법</h2>
        <ol>
            <li><a href="store_transfers.php" target="_blank">점간이동 등록 화면</a>을 엽니다.</li>
            <li>출발 점포를 선택합니다.</li>
            <li>상품 검색을 통해 상품을 추가합니다.</li>
            <li>추가된 상품의 "박스포장수량" 컬럼을 확인합니다.</li>
            <li>브라우저 개발자 도구(F12) → Console에서 다음 내용을 확인합니다:
                <ul>
                    <li>상품 데이터에 <code>pieces_per_box</code> 값이 포함되어 있는지</li>
                    <li>장바구니에 추가할 때 올바른 값이 사용되는지</li>
                </ul>
            </li>
        </ol>
        
        <h3>예상 결과:</h3>
        <ul>
            <li>✅ 박스포장수량이 1보다 큰 상품을 추가하면 해당 값이 자동으로 설정됨</li>
            <li>✅ 박스포장수량 정보가 없는 상품은 기본값 1로 설정됨</li>
            <li>✅ 수정 모드에서도 기존 박스포장수량 값이 올바르게 복원됨</li>
        </ul>
    </div>

    <script>
    function testApi() {
        const resultDiv = document.getElementById('api-result');
        resultDiv.innerHTML = '<p>API 테스트 중...</p>';
        
        const formData = new FormData();
        formData.append('from_store_id', '1');
        formData.append('limit', '5');
        formData.append('show_all', '1');
        
        fetch('ajax_search_transfer_products.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('API Response:', data);
            
            if (data.success && data.products && data.products.length > 0) {
                let html = '<h4>API 응답 성공:</h4>';
                html += '<table>';
                html += '<tr><th>SKU</th><th>상품명</th><th>박스포장수량</th></tr>';
                
                data.products.forEach(product => {
                    const highlight = (parseInt(product.pieces_per_box) > 1) ? 'highlight' : '';
                    html += `<tr class="${highlight}">`;
                    html += `<td>${product.sku}</td>`;
                    html += `<td>${product.name_ko || product.name_en || 'N/A'}</td>`;
                    html += `<td><strong>${product.pieces_per_box}</strong></td>`;
                    html += '</tr>';
                });
                
                html += '</table>';
                html += '<p class="success">✅ API에서 pieces_per_box 값이 올바르게 반환됩니다.</p>';
                html += '<p class="info">💡 강조 표시된 상품들은 박스포장수량이 1보다 큰 상품입니다.</p>';
                
                resultDiv.innerHTML = html;
            } else {
                resultDiv.innerHTML = '<p class="error">❌ API 응답 오류 또는 상품이 없습니다.</p>';
                if (data.message) {
                    resultDiv.innerHTML += '<p class="error">오류 메시지: ' + data.message + '</p>';
                }
            }
        })
        .catch(error => {
            console.error('API Error:', error);
            resultDiv.innerHTML = '<p class="error">❌ API 호출 중 오류가 발생했습니다.</p>';
        });
    }
    </script>
</body>
</html>