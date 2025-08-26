<?php
require_once __DIR__ . '/../config/db_config.php';

// 테스트를 위한 간단한 페이지
?>
<!DOCTYPE html>
<html>
<head>
    <title>브랜드 추가 테스트</title>
    <meta charset="utf-8">
</head>
<body>
    <h1>브랜드 테이블 구조 확인</h1>
    <?php
    try {
        $conn = get_db_connection();
        
        echo "<h2>brands 테이블 컬럼:</h2>";
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
        
        // 테스트 브랜드 추가
        echo "<h2>테스트 브랜드 추가 시도:</h2>";
        
        $test_name = "테스트브랜드_" . date('YmdHis');
        
        // 컬럼 확인
        $columns_check = $conn->query("SHOW COLUMNS FROM brands");
        $has_name = false;
        $has_name_ko = false;
        $has_name_en = false;
        $has_created_at = false;
        
        while ($column = $columns_check->fetch_assoc()) {
            if ($column['Field'] == 'name') $has_name = true;
            if ($column['Field'] == 'name_ko') $has_name_ko = true;
            if ($column['Field'] == 'name_en') $has_name_en = true;
            if ($column['Field'] == 'created_at') $has_created_at = true;
        }
        
        echo "<p>has_name: " . ($has_name ? 'true' : 'false') . "</p>";
        echo "<p>has_name_ko: " . ($has_name_ko ? 'true' : 'false') . "</p>";
        echo "<p>has_name_en: " . ($has_name_en ? 'true' : 'false') . "</p>";
        echo "<p>has_created_at: " . ($has_created_at ? 'true' : 'false') . "</p>";
        
        // INSERT 쿼리 생성
        if ($has_name_ko && $has_name_en) {
            $insert_sql = "INSERT INTO brands (name_ko, name_en) VALUES (?, ?)";
            echo "<p>SQL: " . htmlspecialchars($insert_sql) . "</p>";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) {
                echo "<p style='color:red'>Prepare 실패: " . $conn->error . "</p>";
            } else {
                $stmt->bind_param("ss", $test_name, $test_name);
                if ($stmt->execute()) {
                    echo "<p style='color:green'>브랜드 추가 성공! ID: " . $conn->insert_id . "</p>";
                    
                    // 추가된 브랜드 삭제
                    $delete_id = $conn->insert_id;
                    $conn->query("DELETE FROM brands WHERE id = " . $delete_id);
                    echo "<p>테스트 브랜드 삭제 완료</p>";
                } else {
                    echo "<p style='color:red'>Execute 실패: " . $stmt->error . "</p>";
                }
                $stmt->close();
            }
        } else if ($has_name_ko) {
            $insert_sql = "INSERT INTO brands (name_ko) VALUES (?)";
            echo "<p>SQL: " . htmlspecialchars($insert_sql) . "</p>";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) {
                echo "<p style='color:red'>Prepare 실패: " . $conn->error . "</p>";
            } else {
                $stmt->bind_param("s", $test_name);
                if ($stmt->execute()) {
                    echo "<p style='color:green'>브랜드 추가 성공! ID: " . $conn->insert_id . "</p>";
                    
                    // 추가된 브랜드 삭제
                    $delete_id = $conn->insert_id;
                    $conn->query("DELETE FROM brands WHERE id = " . $delete_id);
                    echo "<p>테스트 브랜드 삭제 완료</p>";
                } else {
                    echo "<p style='color:red'>Execute 실패: " . $stmt->error . "</p>";
                }
                $stmt->close();
            }
        } else if ($has_name) {
            $insert_sql = "INSERT INTO brands (name) VALUES (?)";
            echo "<p>SQL: " . htmlspecialchars($insert_sql) . "</p>";
            $stmt = $conn->prepare($insert_sql);
            if (!$stmt) {
                echo "<p style='color:red'>Prepare 실패: " . $conn->error . "</p>";
            } else {
                $stmt->bind_param("s", $test_name);
                if ($stmt->execute()) {
                    echo "<p style='color:green'>브랜드 추가 성공! ID: " . $conn->insert_id . "</p>";
                    
                    // 추가된 브랜드 삭제
                    $delete_id = $conn->insert_id;
                    $conn->query("DELETE FROM brands WHERE id = " . $delete_id);
                    echo "<p>테스트 브랜드 삭제 완료</p>";
                } else {
                    echo "<p style='color:red'>Execute 실패: " . $stmt->error . "</p>";
                }
                $stmt->close();
            }
        } else {
            echo "<p style='color:red'>name 관련 컬럼이 없습니다!</p>";
        }
        
        $conn->close();
        
    } catch (Exception $e) {
        echo "<p style='color:red'>오류: " . $e->getMessage() . "</p>";
    }
    ?>
    
    <h2>AJAX 테스트</h2>
    <button onclick="testAjax()">AJAX로 브랜드 추가 테스트</button>
    <div id="ajax-result"></div>
    
    <script>
    function testAjax() {
        const formData = new FormData();
        formData.append('name', 'AJAX테스트브랜드_' + Date.now());
        
        fetch('ajax_add_brand_quick.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('Raw response:', text);
            try {
                const json = JSON.parse(text);
                document.getElementById('ajax-result').innerHTML = 
                    '<pre>' + JSON.stringify(json, null, 2) + '</pre>';
                    
                if (json.success && json.data && json.data.id) {
                    // 테스트로 추가한 브랜드 삭제
                    deleteBrand(json.data.id);
                }
            } catch (e) {
                document.getElementById('ajax-result').innerHTML = 
                    '<p style="color:red">JSON 파싱 오류: ' + e.message + '</p>' +
                    '<pre>' + text + '</pre>';
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            document.getElementById('ajax-result').innerHTML = 
                '<p style="color:red">요청 오류: ' + error.message + '</p>';
        });
    }
    
    function deleteBrand(id) {
        // 테스트용 삭제 - 실제로는 별도 API 필요
        console.log('추가된 테스트 브랜드 ID:', id);
    }
    </script>
</body>
</html>