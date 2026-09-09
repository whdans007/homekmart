<?php
/**
 * mall_device_tokens(주문톡 푸시용 회원-기기 FCM 토큰) 테이블 신설 DB 마이그레이션
 * 접속: http://서버주소/sql/run_add_mall_device_tokens_migration.php
 * Design Ref: mall-order-chat-push.design.md §4.1 — 회원-기기 토큰 모델
 * SHOW TABLES로 이미 적용됐는지 확인해 재실행해도 안전하다.
 *
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

$steps = [];
$ran = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run';

$table_check = $conn->query("SHOW TABLES LIKE 'mall_device_tokens'");
if ($table_check && $table_check->num_rows > 0) {
    $steps[] = ['SKIP', 'mall_device_tokens 테이블이 이미 존재합니다.'];
} elseif ($ran) {
    $sql = "CREATE TABLE `mall_device_tokens` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `member_id` int(11) NOT NULL COMMENT 'mall_members.id — 이 토큰이 현재 귀속된 회원(최근 로그인한 사람 기준)',
        `platform` enum('android') NOT NULL DEFAULT 'android' COMMENT 'v1은 android만, 확장 대비 컬럼만 유지',
        `token_hash` char(64) NOT NULL COMMENT 'SHA-256(token) — FCM 토큰은 가변길이라 해시로 유일성 보장',
        `token` text NOT NULL COMMENT '원본 FCM registration token(발송 시 사용)',
        `app_version` varchar(20) DEFAULT NULL,
        `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'FCM이 UNREGISTERED/INVALID_ARGUMENT 응답 시 0으로 비활성화',
        `last_registered_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `token_hash` (`token_hash`),
        KEY `member_id_active` (`member_id`, `is_active`),
        CONSTRAINT `mall_device_tokens_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원별 FCM 기기 토큰(주문톡 푸시 발송용)'";

    if ($conn->query($sql)) {
        $steps[] = ['OK', 'mall_device_tokens 테이블 생성 완료'];
    } else {
        $steps[] = ['ERROR', '테이블 생성 실패: ' . $conn->error];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>주문톡 푸시(FCM) 기기토큰 테이블 DB 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:720px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        td,th{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;text-align:left}
        .ok{color:#059669}.skip{color:#9ca3af}.error{color:#dc2626}
        .success-box{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:1rem;font-size:.875rem;color:#065f46;margin-bottom:1rem}
        .err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:1rem;font-size:.875rem;color:#991b1b;margin-bottom:1rem}
    </style>
</head>
<body>
<div class="card">
    <h1>🚀 주문톡 푸시(FCM) 기기토큰 테이블 DB 마이그레이션</h1>
    <p class="sub">mall_device_tokens 신설 · DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<div class="card">
    <?php if (!$ran && empty($steps)): ?>
    <p class="sub">아래 버튼을 누르면 <code>mall_device_tokens</code> 테이블을 생성합니다. 기존 테이블과 데이터는 변경하지 않습니다.</p>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('mall_device_tokens 테이블을 생성하시겠습니까?')">마이그레이션 실행</button>
    </form>
    <?php else: ?>
    <?php $has_error = false; foreach ($steps as [$s]) { if ($s === 'ERROR') $has_error = true; } ?>
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 단계 실패. 아래 오류를 확인하세요.</div>
    <?php else: ?>
    <div class="success-box">✅ 완료!</div>
    <p class="sub">⚠️ 보안을 위해 이 파일을 삭제하세요.</p>
    <?php endif; ?>
    <table>
        <thead><tr><th>단계</th><th>결과</th></tr></thead>
        <tbody>
        <?php foreach ($steps as [$status, $msg]): ?>
        <tr><td colspan="2" class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</body>
</html>
