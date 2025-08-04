<?php
// 단계별 오류 확인
echo "1. PHP 기본 동작 확인<br>";

try {
    echo "2. 파일 경로 확인<br>";
    echo "현재 파일: " . __FILE__ . "<br>";
    echo "디렉토리: " . __DIR__ . "<br>";
    
    echo "3. header.php 경로 확인<br>";
    $header_path = __DIR__ . '/partials/header.php';
    echo "Header 경로: " . $header_path . "<br>";
    
    if (file_exists($header_path)) {
        echo "✅ header.php 파일 존재<br>";
    } else {
        echo "❌ header.php 파일 없음<br>";
    }
    
    echo "4. permission_helper.php 경로 확인<br>";
    $permission_path = __DIR__ . '/../lib/permission_helper.php';
    echo "Permission 경로: " . $permission_path . "<br>";
    
    if (file_exists($permission_path)) {
        echo "✅ permission_helper.php 파일 존재<br>";
    } else {
        echo "❌ permission_helper.php 파일 없음<br>";
    }
    
    echo "5. 세션 확인<br>";
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user_id'])) {
        echo "✅ 로그인됨 - 사용자 ID: " . $_SESSION['user_id'] . "<br>";
        echo "역할: " . ($_SESSION['role'] ?? 'unknown') . "<br>";
    } else {
        echo "❌ 로그인되지 않음<br>";
    }
    
} catch (Exception $e) {
    echo "오류 발생: " . $e->getMessage() . "<br>";
}

echo "<br>기본 확인 완료!";
?>