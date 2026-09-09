<?php
require_once __DIR__ . '/../config/db_config.php';
$message=''; $error=false; $exists=false;
try {
    $conn=new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME);
    if($conn->connect_error) throw new Exception('DB 연결 실패: '.$conn->connect_error);
    $conn->set_charset(defined('DB_CHARSET')?DB_CHARSET:'utf8mb4');
    $check=$conn->query("SHOW TABLES LIKE 'mall_member_login_tokens'");
    $exists=$check && $check->num_rows>0;
    if($exists) $message='이미 설치되어 있습니다.';
    elseif($_SERVER['REQUEST_METHOD']==='POST') {
        $sql="CREATE TABLE mall_member_login_tokens (
          id BIGINT NOT NULL AUTO_INCREMENT, member_id INT NOT NULL, token_hash CHAR(64) NOT NULL,
          expires_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id), UNIQUE KEY token_hash(token_hash), KEY member_id(member_id), KEY expires_at(expires_at),
          CONSTRAINT mall_member_login_tokens_member_fk FOREIGN KEY(member_id) REFERENCES mall_members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if(!$conn->query($sql)) throw new Exception('테이블 생성 실패: '.$conn->error);
        $message='설치가 완료되었습니다.'; $exists=true;
    }
} catch(Throwable $e) { $error=true; $message=$e->getMessage(); }
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>쇼핑몰 자동 로그인 설치</title></head><body style="font-family:sans-serif;padding:30px">
<h2>쇼핑몰 자동 로그인 DB 설치</h2><?php if($message!==''): ?><p style="color:<?php echo $error?'#c00':'#080'; ?>"><?php echo htmlspecialchars($message,ENT_QUOTES,'UTF-8'); ?></p><?php endif; ?>
<?php if(!$exists&&!$error): ?><form method="post"><button type="submit" style="padding:12px 20px">마이그레이션 실행</button></form><?php endif; ?></body></html>
