<?php
require_once __DIR__ . '/../config/db_config.php';

echo "<h1>테스트 거래처 추가</h1>";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 현재 거래처 수 확인
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM wholesale_customers WHERE is_active = 1");
    $current_count = $count_stmt->fetchColumn();
    
    echo "<p><strong>현재 활성 거래처 수: $current_count</strong></p>";
    
    if ($current_count == 0) {
        echo "<p style='color: red;'>등록된 거래처가 없습니다. 테스트 거래처를 추가합니다...</p>";
        
        // 테스트 거래처 데이터
        $test_customers = [
            [
                'name' => '대한상사',
                'phone' => '02-123-4567',
                'address' => '서울시 강남구 테헤란로 123',
                'memo' => '테스트 거래처 1'
            ],
            [
                'name' => '한국무역',
                'phone' => '02-987-6543',
                'address' => '서울시 중구 명동길 456',
                'memo' => '테스트 거래처 2'
            ],
            [
                'name' => '글로벌마트',
                'phone' => '031-555-1234',
                'address' => '경기도 성남시 분당구 정자동 789',
                'memo' => '테스트 거래처 3'
            ],
            [
                'name' => '동서식품',
                'phone' => '051-777-8888',
                'address' => '부산시 해운대구 센텀시티 101',
                'memo' => '테스트 거래처 4'
            ],
            [
                'name' => 'ABC마켓',
                'phone' => '010-1111-2222',
                'address' => '대구시 수성구 범어동 202',
                'memo' => '테스트 거래처 5'
            ]
        ];
        
        $insert_stmt = $pdo->prepare("
            INSERT INTO wholesale_customers (name, phone, address, memo, is_active, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        
        $success_count = 0;
        foreach ($test_customers as $customer) {
            try {
                $insert_stmt->execute([
                    $customer['name'],
                    $customer['phone'],
                    $customer['address'],
                    $customer['memo']
                ]);
                $success_count++;
                echo "<p style='color: green;'>✅ " . htmlspecialchars($customer['name']) . " 추가됨</p>";
            } catch (PDOException $e) {
                echo "<p style='color: red;'>❌ " . htmlspecialchars($customer['name']) . " 추가 실패: " . $e->getMessage() . "</p>";
            }
        }
        
        echo "<h2>결과</h2>";
        echo "<p><strong>$success_count 개 테스트 거래처가 추가되었습니다.</strong></p>";
        
        if ($success_count > 0) {
            echo "<p style='color: green;'>이제 도매 판매 페이지에서 거래처를 검색해보세요!</p>";
            echo "<p><a href='wholesale_sales.php'>도매 판매 페이지로 이동</a></p>";
        }
        
    } else {
        echo "<p style='color: green;'>이미 거래처가 등록되어 있습니다.</p>";
        
        // 기존 거래처 목록 표시
        $stmt = $pdo->query("
            SELECT id, name, phone, address, created_at 
            FROM wholesale_customers 
            WHERE is_active = 1 
            ORDER BY created_at DESC
        ");
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h2>기존 거래처 목록</h2>";
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
        
        echo "<p><a href='wholesale_sales.php'>도매 판매 페이지로 이동</a></p>";
    }
    
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
a {
    color: #007cba;
    text-decoration: none;
    font-weight: bold;
}
a:hover {
    text-decoration: underline;
}
</style>