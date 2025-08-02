<?php
// 오류 출력 설정
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 출력 버퍼링 시작
ob_start();

try {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Include file error: ' . $e->getMessage()]);
    exit;
}

// 세션 시작 (세션이 시작되지 않은 경우)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// 권한 확인
if (!function_exists('is_logged_in')) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Session helper function not found']);
    exit;
}

if (!is_logged_in()) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $default_presets = [20, 25, 30, 35]; // 기본값
    
    // settings 테이블 존재 여부 확인
    $table_check = $pdo->prepare("SHOW TABLES LIKE 'settings'");
    $table_check->execute();
    if (!$table_check->fetch()) {
        // 테이블이 없으면 기본값 반환
        ob_clean();
        echo json_encode([
            'success' => true, 
            'data' => $default_presets
        ]);
        exit;
    }

    // settings 테이블에서 마진율 프리셋 조회
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'margin_presets'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['setting_value'])) {
        // JSON 형태로 저장된 프리셋 파싱
        $presets = json_decode($result['setting_value'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($presets)) {
            // 숫자 배열로 변환하고 정렬
            $presets = array_map('floatval', $presets);
            sort($presets);
        } else {
            $presets = $default_presets;
        }
    } else {
        $presets = $default_presets;
    }

    ob_clean();
    echo json_encode([
        'success' => true, 
        'data' => $presets
    ]);

} catch (PDOException $e) {
    error_log("Database error in get presets: " . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}

// 출력 버퍼 정리
ob_end_flush();
?>