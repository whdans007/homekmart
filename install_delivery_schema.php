<?php
/**
 * 배달 앱 데이터베이스 스키마 설치
 * 직접 실행 가능한 설치 스크립트
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db_config.php';

$results = [];
$errors = [];

echo "<!DOCTYPE html>";
echo "<html><head><title>배달 앱 스키마 설치</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;}.success{color:green;}.error{color:red;}.warning{color:orange;}</style>";
echo "</head><body>";
echo "<h1>🚚 배달 앱 데이터베이스 스키마 설치</h1>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p class='success'>✓ 데이터베이스 연결 성공</p>";
    
    // 1. 스키마 파일 읽기
    $schema_file = __DIR__ . '/sql/delivery_app_schema.sql';
    if (!file_exists($schema_file)) {
        throw new Exception("스키마 파일을 찾을 수 없습니다: $schema_file");
    }
    
    $sql_content = file_get_contents($schema_file);
    echo "<p>스키마 파일 읽기 완료: " . round(strlen($sql_content) / 1024, 2) . " KB</p>";
    
    // 2. SQL 문을 세미콜론으로 분할
    $statements = explode(';', $sql_content);
    $executed = 0;
    $skipped = 0;
    
    echo "<h2>SQL 문 실행 중...</h2>";
    echo "<div style='max-height: 300px; overflow-y: scroll; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;'>";
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        // 빈 문장이나 주석 건너뛰기
        if (empty($statement) || strpos($statement, '--') === 0 || strpos($statement, '/*') === 0) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $executed++;
            
            // 테이블 생성/수정 명령어만 로그
            if (preg_match('/^(CREATE|ALTER|INSERT|UPDATE)/i', $statement)) {
                $preview = substr($statement, 0, 100) . (strlen($statement) > 100 ? '...' : '');
                echo "<div class='success'>✓ " . htmlspecialchars($preview) . "</div>";
            }
            
        } catch (PDOException $e) {
            // 이미 존재하는 것들은 경고로 처리
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate column') !== false ||
                strpos($e->getMessage(), 'Duplicate key') !== false) {
                $skipped++;
                $preview = substr($statement, 0, 100) . (strlen($statement) > 100 ? '...' : '');
                echo "<div class='warning'>⚠ " . htmlspecialchars($preview) . " (이미 존재)</div>";
            } else {
                $errors[] = "SQL 오류: " . $e->getMessage() . " | 문장: " . substr($statement, 0, 100);
                echo "<div class='error'>✗ " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
    
    echo "</div>";
    echo "<p><strong>실행 완료:</strong> 성공 {$executed}개, 건너뛰기 {$skipped}개, 오류 " . count($errors) . "개</p>";
    
    // 3. 설치된 테이블 확인
    echo "<h2>설치 확인</h2>";
    
    $required_tables = [
        'delivery_addresses' => '배달 주소',
        'delivery_zones' => '배달 지역', 
        'delivery_orders' => '주문',
        'delivery_order_items' => '주문 상품',
        'delivery_tracking' => '배달 추적',
        'shopping_cart' => '장바구니',
        'wishlists' => '위시리스트',
        'delivery_settings' => '설정'
    ];
    
    $tables_created = 0;
    foreach ($required_tables as $table => $description) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        
        if ($stmt->rowCount() > 0) {
            echo "<p class='success'>✓ {$description} 테이블 ({$table})</p>";
            $tables_created++;
        } else {
            echo "<p class='error'>✗ {$description} 테이블 ({$table}) 생성 실패</p>";
        }
    }
    
    // 4. 기본 설정 확인
    echo "<h2>기본 설정 확인</h2>";
    
    $settings_stmt = $pdo->query("SELECT COUNT(*) FROM delivery_settings");
    $settings_count = $settings_stmt->fetchColumn();
    
    if ($settings_count > 0) {
        echo "<p class='success'>✓ 기본 설정 {$settings_count}개 설치됨</p>";
        
        // 설정 내용 표시
        $settings = $pdo->query("SELECT setting_key, setting_value FROM delivery_settings")->fetchAll(PDO::FETCH_ASSOC);
        echo "<ul>";
        foreach ($settings as $setting) {
            echo "<li>{$setting['setting_key']}: {$setting['setting_value']}</li>";
        }
        echo "</ul>";
    } else {
        echo "<p class='warning'>⚠ 기본 설정이 없습니다</p>";
    }
    
    // 5. users 테이블 확장 확인
    echo "<h2>사용자 테이블 확장 확인</h2>";
    
    $columns = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC);
    $new_columns = ['google_id', 'auth_provider', 'profile_image_url', 'preferred_language'];
    
    foreach ($new_columns as $column) {
        $found = false;
        foreach ($columns as $col) {
            if ($col['Field'] === $column) {
                $found = true;
                break;
            }
        }
        
        if ($found) {
            echo "<p class='success'>✓ users.{$column} 컬럼 존재</p>";
        } else {
            echo "<p class='warning'>⚠ users.{$column} 컬럼 없음</p>";
        }
    }
    
    // 6. 최종 결과
    echo "<h2>설치 결과</h2>";
    
    if ($tables_created === count($required_tables) && count($errors) === 0) {
        echo "<div style='background:#d4edda;border:1px solid #c3e6cb;padding:15px;border-radius:5px;'>";
        echo "<h3 style='color:#155724;margin:0;'>🎉 설치 완료!</h3>";
        echo "<p>모든 테이블이 성공적으로 설치되었습니다.</p>";
        echo "<p>이제 API를 테스트할 수 있습니다:</p>";
        echo "<ul>";
        echo "<li><a href='/min/api'>API 정보</a></li>";
        echo "<li><a href='/min/api/products'>상품 목록</a></li>";
        echo "<li><a href='/min/api/products/categories'>카테고리</a></li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div style='background:#f8d7da;border:1px solid #f5c6cb;padding:15px;border-radius:5px;'>";
        echo "<h3 style='color:#721c24;margin:0;'>⚠ 부분적 설치</h3>";
        echo "<p>일부 테이블이 설치되지 않았거나 오류가 발생했습니다.</p>";
        echo "<p>설치된 테이블: {$tables_created}/" . count($required_tables) . "</p>";
        echo "<p>오류 수: " . count($errors) . "</p>";
        echo "</div>";
    }
    
    if (!empty($errors)) {
        echo "<h3>오류 목록</h3>";
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li class='error'>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>✗ 설치 중 오류 발생: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<br><hr>";
echo "<p><a href='/min/api/debug.php'>API 디버그 페이지로 이동</a></p>";
echo "<p><a href='/min/api/test_api.php'>API 테스트 페이지로 이동</a></p>";
echo "</body></html>";
?>