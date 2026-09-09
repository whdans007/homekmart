<?php
require_once __DIR__ . '/../config/db_config.php';
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) die('DB 연결 실패: '.$conn->connect_error);
$conn->set_charset(DB_CHARSET);
$check = $conn->query("SHOW TABLES LIKE 'mall_driver_login_tokens'");
$exists = $check && $check->num_rows > 0;
$message = $exists ? '이미 설치되어 있습니다.' : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$exists) {
    $sql = "CREATE TABLE mall_driver_login_tokens (
      id BIGINT NOT NULL AUTO_INCREMENT,
      driver_id INT NOT NULL,
      token_hash CHAR(64) NOT NULL,
      expires_at DATETIME NOT NULL,
      last_used_at DATETIME DEFAULT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id), UNIQUE KEY token_hash(token_hash), KEY driver_id(driver_id), KEY expires_at(expires_at),
      CONSTRAINT mall_driver_login_tokens_driver_fk FOREIGN KEY(driver_id) REFERENCES mall_drivers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $message = $conn->query($sql) ? '설치가 완료되었습니다.' : '오류: '.$conn->error;
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>드라이버 자동 로그인 설치</title></head><body style="font-family:sans-serif;padding:30px">
<h2>드라이버 자동 로그인 DB 설치</h2><?php if($message): ?><p><?=htmlspecialchars($message)?></p><?php else: ?><form method="post"><button style="padding:12px 20px">마이그레이션 실행</button></form><?php endif; ?></body></html>
