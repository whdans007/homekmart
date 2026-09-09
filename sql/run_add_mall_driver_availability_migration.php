<?php
require_once __DIR__ . '/../config/db_config.php';
$message = '';
$is_error = false;
$exists = false;
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) throw new Exception('DB 연결 실패: ' . $conn->connect_error);
    $conn->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
    $db = $conn->real_escape_string(DB_NAME);
    $result = $conn->query("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'mall_drivers' AND COLUMN_NAME = 'is_available'");
    if (!$result) throw new Exception('컬럼 확인 실패: ' . $conn->error);
    $row = $result->fetch_assoc();
    $exists = ((int)$row['cnt'] > 0);
    if ($exists) {
        $message = '이미 설치되어 있습니다.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$conn->query("ALTER TABLE mall_drivers ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active")) throw new Exception('컬럼 추가 실패: ' . $conn->error);
        if (!$conn->query("ALTER TABLE mall_drivers ADD INDEX driver_available (is_active, is_available)")) throw new Exception('인덱스 추가 실패: ' . $conn->error);
        $message = '설치가 완료되었습니다.';
    }
    $conn->close();
} catch (Throwable $e) {
    $is_error = true;
    $message = $e->getMessage();
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>드라이버 근무상태 설치</title></head>
<body style="font-family:sans-serif;padding:30px"><h2>드라이버 근무상태 DB 설치</h2>
<?php if ($message !== ''): ?><p style="color:<?php echo $is_error ? '#c00' : '#080'; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<?php if (!$exists && !$is_error && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?><form method="post"><button type="submit" style="padding:12px 20px">마이그레이션 실행</button></form><?php endif; ?>
</body></html>
