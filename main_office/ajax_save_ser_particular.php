<?php
// Design Ref: sales-expenses-report — PARTICULAR(일자별 메모) 저장. 참고 서식(SALES AND EXPENSES REPORT)의
// PARTICULAR 열은 자동집계 데이터가 아니라 수기 메모라, 이 화면에서 인라인으로 입력/저장한다.
require_once __DIR__ . '/lib/auth.php';
mo_require_admin();
require_once __DIR__ . '/../config/db_config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$store_id   = (int)($_POST['store_id'] ?? 0);
$date       = (string)($_POST['date'] ?? '');
$particular = trim((string)($_POST['particular'] ?? ''));

if ($store_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}
if (mb_strlen($particular) > 500) {
    $particular = mb_substr($particular, 0, 500);
}

try {
    $conn = get_db_connection();
    $conn->query("CREATE TABLE IF NOT EXISTS sales_expenses_particular (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        store_id INT UNSIGNED NOT NULL,
        note_date DATE NOT NULL,
        particular VARCHAR(500) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ser_particular (store_id, note_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $conn->prepare(
        "INSERT INTO sales_expenses_particular (store_id, note_date, particular)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE particular = VALUES(particular), updated_at = NOW()"
    );
    $stmt->bind_param('iss', $store_id, $date, $particular);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('main_office/ajax_save_ser_particular.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Save failed']);
}
