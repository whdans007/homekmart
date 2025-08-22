<?php
require_once __DIR__ . '/../config/db_config.php';

echo "<h1>도매 거래처 데이터 확인</h1>";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 전체 거래처 수 확인
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM wholesale_customers WHERE is_active = 1");
    $total_customers = $count_stmt->fetchColumn();
    
    echo "<p><strong>활성 거래처 총 개수: $total_customers</strong></p>";
    
    if ($total_customers == 0) {
        echo "<div style='color: red; padding: 10px; border: 1px solid red; background: #ffe6e6;'>";
        echo "<h3>❌ 등록된 도매 거래처가 없습니다!</h3>";
        echo "<p>도매 판매를 위해서는 먼저 거래처를 등록해야 합니다.</p>";
        echo "<p><a href='wholesale_customer_management.php'>거래처 관리 페이지로 이동</a></p>";
        echo "</div>";
    } else {
        echo "<h2>등록된 거래처 목록</h2>";
        
        $stmt = $pdo->query("
            SELECT id, name, phone, address, created_at 
            FROM wholesale_customers 
            WHERE is_active = 1 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background-color: #f0f0f0;'>";
        echo "<th>ID</th><th>거래처명</th><th>전화번호</th><th>주소</th><th>등록일</th>";
        echo "</tr>";
        
        foreach ($customers as $customer) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($customer['id']) . "</td>";
            echo "<td>" . htmlspecialchars($customer['name']) . "</td>";
            echo "<td>" . htmlspecialchars($customer['phone'] ?: '-') . "</td>";
            echo "<td>" . htmlspecialchars($customer['address'] ?: '-') . "</td>";
            echo "<td>" . htmlspecialchars($customer['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // 검색 테스트
        echo "<h2>검색 테스트</h2>";
        echo "<form method='GET'>";
        echo "<input type='text' name='test_search' placeholder='거래처명을 입력하세요' value='" . htmlspecialchars($_GET['test_search'] ?? '') . "'>";
        echo "<button type='submit'>검색 테스트</button>";
        echo "</form>";
        
        if (!empty($_GET['test_search'])) {
            $search_term = $_GET['test_search'];
            $search_pattern = "%{$search_term}%";
            
            $search_stmt = $pdo->prepare("
                SELECT id, name, phone, address 
                FROM wholesale_customers 
                WHERE is_active = 1 
                AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)
                ORDER BY name ASC
                LIMIT 10
            ");
            $search_stmt->execute([$search_pattern, $search_pattern, $search_pattern]);
            $search_results = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<h3>검색 결과: '" . htmlspecialchars($search_term) . "'</h3>";
            
            if (empty($search_results)) {
                echo "<p style='color: red;'>검색 결과가 없습니다.</p>";
            } else {
                echo "<table border='1' style='border-collapse: collapse;'>";
                echo "<tr style='background-color: #f0f0f0;'>";
                echo "<th>ID</th><th>거래처명</th><th>전화번호</th><th>주소</th>";
                echo "</tr>";
                
                foreach ($search_results as $result) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($result['id']) . "</td>";
                    echo "<td>" . htmlspecialchars($result['name']) . "</td>";
                    echo "<td>" . htmlspecialchars($result['phone'] ?: '-') . "</td>";
                    echo "<td>" . htmlspecialchars($result['address'] ?: '-') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        }
    }
    
    // AJAX 테스트
    echo "<h2>AJAX 검색 테스트</h2>";
    echo "<input type='text' id='ajax_test_input' placeholder='AJAX 검색 테스트'>";
    echo "<button onclick='testAjaxSearch()'>AJAX 테스트</button>";
    echo "<div id='ajax_results'></div>";
    
    echo "<script>
    function testAjaxSearch() {
        const query = document.getElementById('ajax_test_input').value;
        const resultsDiv = document.getElementById('ajax_results');
        
        if (!query.trim()) {
            resultsDiv.innerHTML = '<p style=\"color: red;\">검색어를 입력해주세요.</p>';
            return;
        }
        
        resultsDiv.innerHTML = '<p>검색 중...</p>';
        
        fetch('ajax_search_wholesale_customers.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(response => response.json())
        .then(data => {
            console.log('AJAX Response:', data);
            
            if (data.success && data.customers) {
                let html = '<h3>AJAX 검색 결과:</h3>';
                if (data.customers.length === 0) {
                    html += '<p>검색 결과가 없습니다.</p>';
                } else {
                    html += '<table border=\"1\" style=\"border-collapse: collapse;\">';
                    html += '<tr><th>ID</th><th>거래처명</th><th>전화번호</th><th>주소</th></tr>';
                    data.customers.forEach(customer => {
                        html += `<tr>
                            <td>\${customer.id}</td>
                            <td>\${customer.name}</td>
                            <td>\${customer.phone || '-'}</td>
                            <td>\${customer.address || '-'}</td>
                        </tr>`;
                    });
                    html += '</table>';
                }
                resultsDiv.innerHTML = html;
            } else {
                resultsDiv.innerHTML = '<p style=\"color: red;\">오류: ' + (data.message || '알 수 없는 오류') + '</p>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            resultsDiv.innerHTML = '<p style=\"color: red;\">네트워크 오류: ' + error.message + '</p>';
        });
    }
    </script>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>데이터베이스 오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
body { 
    font-family: Arial, sans-serif; 
    margin: 20px; 
    line-height: 1.6;
}
table { 
    border-collapse: collapse; 
    margin: 10px 0; 
    width: 100%;
}
th, td { 
    padding: 8px 12px; 
    text-align: left; 
    border: 1px solid #ddd;
}
th { 
    background-color: #f2f2f2; 
}
input, button {
    padding: 8px;
    margin: 5px;
}
</style>