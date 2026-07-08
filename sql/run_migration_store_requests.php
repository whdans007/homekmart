<?php
/**
 * 점포 요청사항 게시판 마이그레이션 (store-request-board)
 * 접속: http://서버주소/sunset/sql/run_migration_store_requests.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';

// PHP 8.1+ mysqli 기본값(예외 발생)에서도 각 단계를 OK/SKIP/ERROR로 안전하게 보고하기 위해
// 예외 모드를 끄고 기존 query()의 false 반환 방식으로 통일한다.
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

$steps = [];

// 1. lc_store_requests 테이블
$tbl = $conn->query("SHOW TABLES LIKE 'lc_store_requests'");
if ($tbl && $tbl->num_rows > 0) {
    $steps[] = ['SKIP', 'lc_store_requests 테이블이 이미 존재합니다.'];
} elseif ($conn->query("
    CREATE TABLE lc_store_requests (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        store_id    INT NOT NULL,
        title       VARCHAR(200) NOT NULL,
        content     TEXT NOT NULL,
        status      ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
        created_by  INT NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_store_status (store_id, status),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 요청사항 게시판'
")) {
    $steps[] = ['OK', 'lc_store_requests 테이블 생성 완료 (FK 없이)'];
} else {
    $steps[] = ['ERROR', 'lc_store_requests 생성 실패: ' . $conn->error];
}

// 1b. stores(id) FK는 타입/인덱스 불일치로 실패할 수 있어 별도 단계로 분리, 실패해도 무시 가능
if ($conn->query("
    ALTER TABLE lc_store_requests
    ADD CONSTRAINT fk_lsr_store FOREIGN KEY (store_id) REFERENCES stores(id)
")) {
    $steps[] = ['OK', 'lc_store_requests -> stores FK 추가 완료'];
} else {
    $steps[] = ['SKIP', 'stores FK 추가 생략(이미 존재하거나 타입 불일치): ' . $conn->error];
}

// 2. lc_store_request_comments 테이블 (lc_store_requests에 대한 FK가 있으므로 이후 실행)
$tbl2 = $conn->query("SHOW TABLES LIKE 'lc_store_request_comments'");
if ($tbl2 && $tbl2->num_rows > 0) {
    $steps[] = ['SKIP', 'lc_store_request_comments 테이블이 이미 존재합니다.'];
} elseif ($conn->query("
    CREATE TABLE lc_store_request_comments (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        request_id  INT NOT NULL,
        author_side ENUM('store','logistics') NOT NULL,
        author_id   INT NOT NULL,
        author_name VARCHAR(100) NOT NULL,
        content     TEXT NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES lc_store_requests(id) ON DELETE CASCADE,
        INDEX idx_request (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 요청사항 댓글(답변) 스레드'
")) {
    $steps[] = ['OK', 'lc_store_request_comments 테이블 생성 완료'];
} else {
    $steps[] = ['ERROR', 'lc_store_request_comments 생성 실패: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration: store-request-board</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;}</style>
</head>
<body>
<h2>store-request-board 마이그레이션 결과</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
