<?php
/**
 * 배송지(mall_addresses) 테이블 생성 DB 마이그레이션
 * 접속: http://서버주소/sunset/sql/run_create_mall_addresses_migration.php
 *
 * mall/address.php의 구글 맵 핀 입력 기능이 저장할 배송지 데이터를 위한 테이블.
 * lat/lng은 지도에서 찍은 핀 좌표, region/city/barangay는 리버스 지오코딩 결과를
 * 사용자가 확인/수정한 값(구조화 데이터가 아니라 자유 입력 텍스트).
 * 이미 테이블이 있으면 안전하게 SKIP한다.
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

$table_check = $conn->query("SHOW TABLES LIKE 'mall_addresses'");
if ($table_check && $table_check->num_rows > 0) {
    $steps[] = ['SKIP', 'mall_addresses 테이블이 이미 존재합니다.'];
} else {
    $sql = "
        CREATE TABLE `mall_addresses` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `member_id` int(11) NOT NULL,
          `recipient_name` varchar(100) NOT NULL,
          `phone` varchar(50) NOT NULL,
          `region` varchar(100) DEFAULT NULL,
          `city` varchar(100) DEFAULT NULL,
          `barangay` varchar(100) DEFAULT NULL,
          `detail_address` varchar(255) DEFAULT NULL,
          `landmark` varchar(255) NOT NULL,
          `lat` decimal(10,7) NOT NULL,
          `lng` decimal(10,7) NOT NULL,
          `is_default` tinyint(1) NOT NULL DEFAULT 0,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `member_id` (`member_id`),
          CONSTRAINT `mall_addresses_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원 배송지(구글 맵 핀 좌표 포함)'
    ";
    if ($conn->query($sql)) {
        $steps[] = ['OK', 'mall_addresses 테이블 생성 완료'];
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
    <title>배송지 테이블 DB 마이그레이션</title>
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
    <h1>🚀 배송지 테이블 DB 마이그레이션</h1>
    <p class="sub">mall_addresses 테이블 생성 · DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<div class="card">
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
</div>
</body>
</html>
