<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 로그인 확인
if (!is_logged_in()) {
    die("로그인이 필요합니다.");
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>브랜드 추가 에러 진단</title>
    <meta charset="utf-8">
    <style>
        body { font-family: monospace; padding: 20px; }
        .section { margin: 20px 0; padding: 10px; border: 1px solid #ccc; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f4f4f4; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>브랜드 추가 에러 진단</h1>
    
    <div class="section">
        <h2>1. 세션 상태</h2>
        <?php
        echo "<pre>";
        echo "Session ID: " . session_id() . "\n";
        echo "User ID: " . ($_SESSION['user_id'] ?? 'Not set') . "\n";
        echo "User Role: " . ($_SESSION['role'] ?? 'Not set') . "\n";
        echo "Logged in: " . (is_logged_in() ? 'Yes' : 'No') . "\n";
        echo "</pre>";
        ?>
    </div>
    
    <div class="section">
        <h2>2. 권한 확인</h2>
        <?php
        $permissions = [
            'purchase_management',
            'brand_management',
            'category_management'
        ];
        
        echo "<ul>";
        foreach ($permissions as $perm) {
            $has = has_permission($perm);
            echo "<li>" . $perm . ": " . 
                 ($has ? "<span class='success'>✓ 있음</span>" : "<span class='error'>✗ 없음</span>") . 
                 "</li>";
        }
        echo "</ul>";
        ?>
    </div>
    
    <div class="section">
        <h2>3. 데이터베이스 연결</h2>
        <?php
        try {
            $conn = get_db_connection();
            if ($conn) {
                echo "<p class='success'>✓ 데이터베이스 연결 성공</p>";
                
                // brands 테이블 구조 확인
                echo "<h3>brands 테이블 구조:</h3>";
                echo "<table border='1'>";
                echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
                
                $result = $conn->query("SHOW COLUMNS FROM brands");
                while ($row = $result->fetch_assoc()) {
                    echo "<tr>";
                    foreach ($row as $value) {
                        echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
                
                $conn->close();
            } else {
                echo "<p class='error'>✗ 데이터베이스 연결 실패</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>✗ 오류: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>4. AJAX 테스트</h2>
        <input type="text" id="brand-name" placeholder="브랜드명 입력" value="테스트브랜드">
        <button onclick="testAddBrand()">브랜드 추가 테스트</button>
        <div id="result"></div>
    </div>
    
    <div class="section">
        <h2>5. 직접 PHP 호출 테스트</h2>
        <?php
        if (isset($_POST['direct_test'])) {
            $test_name = "직접테스트_" . date('YmdHis');
            
            echo "<h3>테스트 시작: '$test_name'</h3>";
            
            try {
                $conn = get_db_connection();
                
                // 컬럼 확인
                $columns_check = $conn->query("SHOW COLUMNS FROM brands");
                $has_name_ko = false;
                $has_name_en = false;
                $has_created_at = false;
                
                while ($column = $columns_check->fetch_assoc()) {
                    if ($column['Field'] == 'name_ko') $has_name_ko = true;
                    if ($column['Field'] == 'name_en') $has_name_en = true;
                    if ($column['Field'] == 'created_at') $has_created_at = true;
                }
                
                echo "<p>has_name_ko: " . ($has_name_ko ? 'true' : 'false') . "</p>";
                echo "<p>has_name_en: " . ($has_name_en ? 'true' : 'false') . "</p>";
                echo "<p>has_created_at: " . ($has_created_at ? 'true' : 'false') . "</p>";
                
                // INSERT 시도
                if ($has_name_ko && $has_name_en) {
                    $sql = "INSERT INTO brands (name_ko, name_en) VALUES (?, ?)";
                    echo "<p>SQL: " . htmlspecialchars($sql) . "</p>";
                    
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception("Prepare 실패: " . $conn->error);
                    }
                    
                    $stmt->bind_param("ss", $test_name, $test_name);
                    
                    if ($stmt->execute()) {
                        $new_id = $conn->insert_id;
                        echo "<p class='success'>✓ 브랜드 추가 성공! ID: $new_id</p>";
                        
                        // 테스트 데이터 삭제
                        $conn->query("DELETE FROM brands WHERE id = $new_id");
                        echo "<p class='info'>테스트 데이터 삭제 완료</p>";
                    } else {
                        echo "<p class='error'>✗ Execute 실패: " . $stmt->error . "</p>";
                    }
                    
                    $stmt->close();
                }
                
                $conn->close();
                
            } catch (Exception $e) {
                echo "<p class='error'>✗ 오류: " . $e->getMessage() . "</p>";
            }
        }
        ?>
        <form method="post">
            <button type="submit" name="direct_test" value="1">직접 PHP 테스트 실행</button>
        </form>
    </div>
    
    <script>
    function testAddBrand() {
        const brandName = document.getElementById('brand-name').value;
        const resultDiv = document.getElementById('result');
        
        resultDiv.innerHTML = '<p>요청 중...</p>';
        
        const formData = new FormData();
        formData.append('name', brandName);
        
        // 디버깅 정보 출력
        console.log('Sending request with name:', brandName);
        
        fetch('ajax_add_brand_quick.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            return response.text();
        })
        .then(text => {
            console.log('Raw response:', text);
            
            try {
                const json = JSON.parse(text);
                console.log('Parsed JSON:', json);
                
                resultDiv.innerHTML = '<h3>응답:</h3><pre>' + JSON.stringify(json, null, 2) + '</pre>';
                
                if (json.success) {
                    resultDiv.innerHTML += '<p class="success">✓ 성공!</p>';
                } else {
                    resultDiv.innerHTML += '<p class="error">✗ 실패: ' + json.message + '</p>';
                    if (json.debug) {
                        resultDiv.innerHTML += '<p class="info">Debug: ' + json.debug + '</p>';
                    }
                }
            } catch (e) {
                resultDiv.innerHTML = '<p class="error">JSON 파싱 오류:</p><pre>' + text + '</pre>';
                console.error('JSON parse error:', e);
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            resultDiv.innerHTML = '<p class="error">요청 오류: ' + error.message + '</p>';
        });
    }
    </script>
</body>
</html>