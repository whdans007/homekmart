<?php
require_once __DIR__ . '/../config/db_config.php';

$messages = [];
$error = false;

try {
    $conn = get_db_connection();

    $column = $conn->query("SHOW COLUMNS FROM mall_members LIKE 'deleted_at'");
    if ($column && $column->num_rows === 0) {
        if (!$conn->query("ALTER TABLE mall_members ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER is_active")) {
            throw new RuntimeException('deleted_at 컬럼 추가 실패: ' . $conn->error);
        }
        $messages[] = 'mall_members.deleted_at 컬럼을 추가했습니다.';
    } else {
        $messages[] = 'mall_members.deleted_at 컬럼이 이미 있습니다.';
    }

    $table = $conn->query("SHOW TABLES LIKE 'mall_account_deletion_requests'");
    if ($table && $table->num_rows === 0) {
        $sql = "CREATE TABLE mall_account_deletion_requests (
            id BIGINT NOT NULL AUTO_INCREMENT,
            member_id INT NULL,
            email VARCHAR(255) NOT NULL,
            email_hash CHAR(64) NOT NULL,
            request_source ENUM('authenticated','web') NOT NULL DEFAULT 'web',
            status ENUM('pending','completed','rejected') NOT NULL DEFAULT 'pending',
            request_note VARCHAR(1000) NULL,
            ip_hash CHAR(64) NULL,
            requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_deletion_request_status (status, requested_at),
            KEY idx_deletion_request_email_hash (email_hash),
            KEY idx_deletion_request_member (member_id),
            CONSTRAINT mall_account_deletion_requests_member_fk
              FOREIGN KEY (member_id) REFERENCES mall_members(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$conn->query($sql)) {
            throw new RuntimeException('요청 테이블 생성 실패: ' . $conn->error);
        }
        $messages[] = 'mall_account_deletion_requests 테이블을 생성했습니다.';
    } else {
        $messages[] = 'mall_account_deletion_requests 테이블이 이미 있습니다.';
    }
} catch (Throwable $e) {
    $error = true;
    $messages[] = $e->getMessage();
}

if (PHP_SAPI === 'cli') {
    foreach ($messages as $message) {
        fwrite($error ? STDERR : STDOUT, $message . PHP_EOL);
    }
    exit($error ? 1 : 0);
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>계정 삭제 DB 설치</title></head>
<body style="font-family:sans-serif;padding:30px"><h2>계정 삭제 DB 설치</h2>
<?php foreach ($messages as $message): ?><p style="color:<?php echo $error ? '#c00' : '#080'; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endforeach; ?>
</body></html>
