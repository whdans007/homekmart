<?php
session_start();
require_once __DIR__ . '/config/db_config.php';

echo "<h2>권한 시스템 디버깅</h2>\n";

// 세션 정보 확인
echo "<h3>세션 정보:</h3>\n";
echo "<pre>";
print_r($_SESSION);
echo "</pre>\n";

// 데이터베이스 연결 확인
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>데이터베이스 연결: ✅ 성공</h3>\n";
    
    // users 테이블 구조 확인
    echo "<h3>users 테이블 구조:</h3>\n";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>\n";
    
    // permissions 컬럼 존재 여부 확인
    $has_permissions = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'permissions') {
            $has_permissions = true;
            break;
        }
    }
    
    echo "<h3>permissions 컬럼 존재: " . ($has_permissions ? "✅ 있음" : "❌ 없음") . "</h3>\n";
    
    // 현재 사용자 정보 확인
    if (isset($_SESSION['user_id'])) {
        echo "<h3>현재 사용자 정보:</h3>\n";
        $stmt = $pdo->prepare("SELECT id, username, full_name, role, permissions FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            echo "<pre>";
            print_r($user);
            echo "</pre>\n";
            
            if ($user['permissions']) {
                echo "<h4>권한 JSON 파싱:</h4>\n";
                $permissions = json_decode($user['permissions'], true);
                echo "<pre>";
                print_r($permissions);
                echo "</pre>\n";
            }
        } else {
            echo "<p>❌ 사용자를 찾을 수 없습니다.</p>\n";
        }
    } else {
        echo "<p>❌ 로그인되지 않았습니다.</p>\n";
    }
    
    // 권한 함수 테스트
    if (isset($_SESSION['user_id'])) {
        require_once __DIR__ . '/lib/permission_helper.php';
        
        echo "<h3>권한 함수 테스트:</h3>\n";
        $test_permissions = [
            'admin_access',
            'user_management', 
            'store_management',
            'product_management',
            'shop_access'
        ];
        
        echo "<table border='1'><tr><th>권한</th><th>결과</th></tr>";
        foreach ($test_permissions as $perm) {
            $result = has_permission($perm);
            echo "<tr><td>$perm</td><td>" . ($result ? "✅ 있음" : "❌ 없음") . "</td></tr>";
        }
        echo "</table>\n";
    }
    
} catch (PDOException $e) {
    echo "<h3>데이터베이스 오류: ❌</h3>\n";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>\n";
} catch (Exception $e) {
    echo "<h3>일반 오류: ❌</h3>\n";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>\n";
}
?>