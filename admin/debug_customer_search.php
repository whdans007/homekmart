<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

echo "<h1>거래처 검색 디버깅</h1>";

// 세션 확인
echo "<h2>1. 세션 상태</h2>";
echo "<p>로그인 상태: " . (is_logged_in() ? '✅ 로그인됨' : '❌ 로그아웃됨') . "</p>";
if (is_logged_in()) {
    echo "<p>사용자 ID: " . $_SESSION['user_id'] . "</p>";
    echo "<p>역할: " . $_SESSION['role'] . "</p>";
    echo "<p>도매판매 권한: " . (has_permission('wholesale_management') ? '✅ 있음' : '❌ 없음') . "</p>";
}

// 데이터베이스 연결 테스트
echo "<h2>2. 데이터베이스 연결</h2>";
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p>✅ 데이터베이스 연결 성공</p>";
    
    // 거래처 테이블 확인
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM wholesale_customers WHERE is_active = 1");
    $total_customers = $count_stmt->fetchColumn();
    echo "<p>활성 거래처 수: <strong>$total_customers</strong></p>";
    
    if ($total_customers > 0) {
        // 샘플 데이터 표시  
        $sample_stmt = $pdo->query("SELECT id, name, phone, address FROM wholesale_customers WHERE is_active = 1 LIMIT 3");
        $samples = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<h3>샘플 거래처:</h3>";
        echo "<ul>";
        foreach ($samples as $customer) {
            echo "<li>ID:{$customer['id']} - {$customer['name']} ({$customer['phone']})</li>";
        }
        echo "</ul>";
    }
    
} catch (PDOException $e) {
    echo "<p>❌ 데이터베이스 오류: " . $e->getMessage() . "</p>";
}

// 직접 AJAX 요청 테스트
echo "<h2>3. AJAX 요청 직접 테스트</h2>";
if (!empty($_POST['test_query'])) {
    echo "<h3>테스트 결과:</h3>";
    $test_query = $_POST['test_query'];
    echo "<p>검색어: <strong>" . htmlspecialchars($test_query) . "</strong></p>";
    
    // AJAX 엔드포인트 직접 호출
    $_POST['q'] = $test_query;
    $_POST['limit'] = 10;
    
    ob_start();
    include 'ajax_search_wholesale_customers.php';
    $ajax_output = ob_get_clean();
    
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
    echo "AJAX 응답:\n" . htmlspecialchars($ajax_output);
    echo "</pre>";
}

echo "<form method='POST'>";
echo "<input type='text' name='test_query' placeholder='거래처명을 입력하세요' value='" . htmlspecialchars($_POST['test_query'] ?? '') . "'>";
echo "<button type='submit'>직접 테스트</button>";
echo "</form>";

// JavaScript AJAX 테스트
echo "<h2>4. JavaScript AJAX 테스트</h2>";
echo "<input type='text' id='js_test_input' placeholder='JavaScript AJAX 테스트'>";
echo "<button onclick='testJsAjax()'>JS 테스트</button>";
echo "<div id='js_results' style='margin-top: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;'></div>";

echo "<script>
function testJsAjax() {
    const query = document.getElementById('js_test_input').value;
    const resultsDiv = document.getElementById('js_results');
    
    if (!query.trim()) {
        resultsDiv.innerHTML = '<p style=\"color: red;\">검색어를 입력해주세요.</p>';
        return;
    }
    
    resultsDiv.innerHTML = '<p>검색 중...</p>';
    console.log('AJAX 요청 시작:', query);
    
    fetch('ajax_search_wholesale_customers.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'q=' + encodeURIComponent(query) + '&limit=10'
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: \${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        console.log('Raw response:', text);
        
        let html = '<h4>원본 응답:</h4>';
        html += '<pre style=\"background: #f0f0f0; padding: 5px; font-size: 12px;\">' + text.substring(0, 500) + '</pre>';
        
        try {
            const data = JSON.parse(text);
            console.log('Parsed data:', data);
            
            html += '<h4>파싱된 데이터:</h4>';
            html += '<pre style=\"background: #e8f5e8; padding: 5px; font-size: 12px;\">' + JSON.stringify(data, null, 2) + '</pre>';
            
            if (data.success && data.customers) {
                html += '<h4>고객 목록 (' + data.customers.length + '개):</h4>';
                if (data.customers.length === 0) {
                    html += '<p>검색 결과가 없습니다.</p>';
                } else {
                    html += '<ul>';
                    data.customers.forEach(customer => {
                        html += `<li><strong>\${customer.name}</strong> - \${customer.phone || 'N/A'}</li>`;
                    });
                    html += '</ul>';
                }
            } else {
                html += '<p style=\"color: red;\">오류: ' + (data.message || '알 수 없는 오류') + '</p>';
            }
        } catch (parseError) {
            console.error('JSON 파싱 오류:', parseError);
            html += '<h4>JSON 파싱 오류:</h4>';
            html += '<p style=\"color: red;\">' + parseError.message + '</p>';
        }
        
        resultsDiv.innerHTML = html;
    })
    .catch(error => {
        console.error('네트워크 오류:', error);
        resultsDiv.innerHTML = '<h4>네트워크 오류:</h4><p style=\"color: red;\">' + error.message + '</p>';
    });
}
</script>";

echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
h2 { color: #333; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
h3 { color: #666; }
pre { overflow-x: auto; max-width: 100%; }
</style>";
?>