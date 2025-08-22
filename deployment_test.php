<?php
/**
 * 배포 후 시스템 검증 테스트
 * 프로덕션 환경에서 실행하여 설정을 확인합니다.
 */

echo "<!DOCTYPE html>";
echo "<html lang='ko'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
echo "<title>HOME K MART - 배포 검증</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }";
echo ".container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }";
echo ".success { color: #22c55e; }";
echo ".error { color: #ef4444; }";
echo ".warning { color: #f59e0b; }";
echo ".info { color: #3b82f6; }";
echo ".test-item { margin: 15px 0; padding: 10px; border-left: 4px solid #ddd; }";
echo ".test-success { border-left-color: #22c55e; background: #f0fdf4; }";
echo ".test-error { border-left-color: #ef4444; background: #fef2f2; }";
echo ".test-warning { border-left-color: #f59e0b; background: #fffbeb; }";
echo "</style>";
echo "</head>";
echo "<body>";
echo "<div class='container'>";
echo "<h1>🏪 HOME K MART 배포 검증</h1>";
echo "<p>시스템 상태를 확인합니다...</p>";

$tests = [];

// 1. PHP 버전 확인
$php_version = phpversion();
$tests[] = [
    'name' => 'PHP 버전',
    'status' => version_compare($php_version, '7.4', '>=') ? 'success' : 'error',
    'message' => "PHP {$php_version} " . (version_compare($php_version, '7.4', '>=') ? '✅' : '❌ (7.4 이상 필요)')
];

// 2. 필수 PHP 확장 확인
$required_extensions = ['mysqli', 'pdo', 'pdo_mysql', 'json', 'mbstring'];
foreach ($required_extensions as $ext) {
    $tests[] = [
        'name' => "PHP 확장: {$ext}",
        'status' => extension_loaded($ext) ? 'success' : 'error',
        'message' => extension_loaded($ext) ? '✅ 설치됨' : '❌ 설치되지 않음'
    ];
}

// 3. 데이터베이스 연결 테스트
try {
    require_once __DIR__ . '/config/db_config.php';
    
    $tests[] = [
        'name' => 'DB 설정 파일',
        'status' => 'success',
        'message' => '✅ 설정 파일 로드됨'
    ];
    
    // MySQLi 연결 테스트
    $mysqli_conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli_conn->connect_error) {
        $tests[] = [
            'name' => 'MySQLi 데이터베이스 연결',
            'status' => 'error',
            'message' => '❌ 연결 실패: ' . $mysqli_conn->connect_error
        ];
    } else {
        $tests[] = [
            'name' => 'MySQLi 데이터베이스 연결',
            'status' => 'success',
            'message' => '✅ 연결 성공'
        ];
        
        // 캐릭터셋 확인
        $charset = $mysqli_conn->character_set_name();
        $tests[] = [
            'name' => 'DB 캐릭터셋',
            'status' => ($charset === 'utf8mb4') ? 'success' : 'warning',
            'message' => "현재: {$charset} " . (($charset === 'utf8mb4') ? '✅' : '⚠️ (utf8mb4 권장)')
        ];
        
        $mysqli_conn->close();
    }
    
    // PDO 연결 테스트
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $tests[] = [
            'name' => 'PDO 데이터베이스 연결',
            'status' => 'success',
            'message' => '✅ 연결 성공'
        ];
        
    } catch (PDOException $e) {
        $tests[] = [
            'name' => 'PDO 데이터베이스 연결',
            'status' => 'error',
            'message' => '❌ 연결 실패: ' . $e->getMessage()
        ];
    }
    
} catch (Exception $e) {
    $tests[] = [
        'name' => 'DB 설정 파일',
        'status' => 'error',
        'message' => '❌ 설정 파일 오류: ' . $e->getMessage()
    ];
}

// 4. 파일 시스템 권한 확인
$writable_dirs = ['public/css'];
foreach ($writable_dirs as $dir) {
    if (is_dir($dir)) {
        $tests[] = [
            'name' => "디렉토리 권한: {$dir}",
            'status' => is_writable($dir) ? 'success' : 'warning',
            'message' => is_writable($dir) ? '✅ 쓰기 가능' : '⚠️ 쓰기 권한 없음'
        ];
    }
}

// 5. 중요 파일 존재 확인
$important_files = [
    'public/css/style.css' => 'CSS 파일',
    'public/partials/header.php' => '헤더 템플릿',
    'public/partials/footer.php' => '푸터 템플릿',
    'lib/permission_helper.php' => '권한 헬퍼',
    'lib/session_helper.php' => '세션 헬퍼'
];

foreach ($important_files as $file => $desc) {
    $tests[] = [
        'name' => $desc,
        'status' => file_exists($file) ? 'success' : 'error',
        'message' => file_exists($file) ? '✅ 존재함' : '❌ 파일 없음: ' . $file
    ];
}

// 6. .htaccess 파일 확인
$htaccess_files = ['.htaccess', 'public/.htaccess'];
foreach ($htaccess_files as $file) {
    $tests[] = [
        'name' => "보안 설정: {$file}",
        'status' => file_exists($file) ? 'success' : 'warning',
        'message' => file_exists($file) ? '✅ 존재함' : '⚠️ 파일 없음'
    ];
}

// 결과 출력
$success_count = 0;
$error_count = 0;
$warning_count = 0;

foreach ($tests as $test) {
    $class = 'test-' . $test['status'];
    echo "<div class='test-item {$class}'>";
    echo "<strong>{$test['name']}</strong>: {$test['message']}";
    echo "</div>";
    
    switch ($test['status']) {
        case 'success': $success_count++; break;
        case 'error': $error_count++; break;
        case 'warning': $warning_count++; break;
    }
}

echo "<hr>";
echo "<h2>검증 결과 요약</h2>";
echo "<p class='success'>✅ 성공: {$success_count}개</p>";
if ($warning_count > 0) echo "<p class='warning'>⚠️ 주의: {$warning_count}개</p>";
if ($error_count > 0) echo "<p class='error'>❌ 오류: {$error_count}개</p>";

if ($error_count === 0) {
    echo "<div class='test-item test-success'>";
    echo "<strong>🎉 배포 완료!</strong> 시스템이 정상적으로 작동할 준비가 되었습니다.";
    echo "</div>";
} else {
    echo "<div class='test-item test-error'>";
    echo "<strong>⚠️ 배포 문제</strong> 위의 오류들을 해결한 후 다시 테스트해주세요.";
    echo "</div>";
}

echo "<hr>";
echo "<p><small>테스트 완료 시간: " . date('Y-m-d H:i:s') . "</small></p>";
echo "<p><small>⚠️ 이 파일은 테스트 완료 후 삭제하는 것을 권장합니다.</small></p>";

echo "</div>";
echo "</body>";
echo "</html>";
?>