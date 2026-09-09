<?php
require_once __DIR__ . '/../config/db_config.php';
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) die('DB 연결 실패: ' . $conn->connect_error);
$conn->set_charset(DB_CHARSET);
$ran = $_SERVER['REQUEST_METHOD'] === 'POST';
$exists = $conn->query("SHOW TABLES LIKE 'mall_driver_device_tokens'");
$message = ($exists && $exists->num_rows) ? '이미 설치되어 있습니다.' : '';
if ($ran && !$message) {
    $sql = "CREATE TABLE mall_driver_device_tokens (
      id INT NOT NULL AUTO_INCREMENT,
      driver_id INT NOT NULL,
      platform ENUM('android') NOT NULL DEFAULT 'android',
      token_hash CHAR(64) NOT NULL,
      token TEXT NOT NULL,
      app_version VARCHAR(20) DEFAULT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      last_registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id), UNIQUE KEY token_hash (token_hash),
      KEY driver_active (driver_id, is_active),
      CONSTRAINT mall_driver_device_tokens_driver_fk FOREIGN KEY (driver_id) REFERENCES mall_drivers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $message = $conn->query($sql) ? '설치가 완료되었습니다.' : '오류: ' . $conn->error;
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>드라이버 푸시 마이그레이션</title></head>
<body style="font-family:sans-serif;padding:30px"><h2>드라이버 앱 푸시 알림 DB 설치</h2>
<?php if ($message): ?><p><?= htmlspecialchars($message) ?></p><?php else: ?>
<form method="post"><button type="submit" style="padding:12px 20px">마이그레이션 실행</button></form><?php endif; ?></body></html>
