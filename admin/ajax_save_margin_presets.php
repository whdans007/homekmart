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

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'POST 요청만 허용됩니다.']);
    exit;
}

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

// 입력 값 검증
$presets_input = $_POST['presets'] ?? '';
if (empty($presets_input)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '마진율 프리셋이 필요합니다.']);
    exit;
}

// 쉼표로 구분된 마진율 파싱
$presets_array = array_map('trim', explode(',', $presets_input));
$validated_presets = [];

foreach ($presets_array as $preset) {
    $preset_num = floatval($preset);
    if ($preset_num >= 0 && $preset_num <= 100) {
        $validated_presets[] = $preset_num;
    }
}

if (empty($validated_presets)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '유효한 마진율(0~100%)을 하나 이상 입력해주세요.']);
    exit;
}

// 중복 제거 및 정렬
$validated_presets = array_unique($validated_presets);
sort($validated_presets);

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // settings 테이블 존재 여부 확인
    $table_check = $pdo->prepare("SHOW TABLES LIKE 'settings'");
    $table_check->execute();
    if (!$table_check->fetch()) {
        // settings 테이블이 없으면 생성
        $create_table = $pdo->prepare("
            CREATE TABLE settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(255) NOT NULL UNIQUE,
                setting_value TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $create_table->execute();
        error_log("Settings table created successfully");
    }

    // JSON 형태로 저장
    $presets_json = json_encode($validated_presets);
    error_log("Saving presets: " . $presets_json);
    
    // settings 테이블에 저장 (INSERT ... ON DUPLICATE KEY UPDATE 패턴)
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value, updated_at) 
        VALUES ('margin_presets', ?, NOW()) 
        ON DUPLICATE KEY UPDATE 
        setting_value = VALUES(setting_value), 
        updated_at = NOW()
    ");
    
    $result = $stmt->execute([$presets_json]);
    error_log("Insert result: " . ($result ? 'success' : 'failed'));

    ob_clean();
    echo json_encode([
        'success' => true, 
        'message' => '마진율 프리셋이 성공적으로 저장되었습니다.',
        'data' => $validated_presets
    ]);

} catch (PDOException $e) {
    error_log("Database error in save presets: " . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}

// 출력 버퍼 정리
ob_end_flush();
?>