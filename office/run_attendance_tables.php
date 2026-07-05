<?php
// Design Ref: §9 — 마이그레이션 실행 스크립트. 브라우저에서 1회 실행 후 삭제 권장.
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();
$sql  = file_get_contents(__DIR__ . '/sql/attendance_create_tables.sql');

// 주석 줄 제거 후 세미콜론으로 분리해 각 CREATE/INSERT 문 실행
$lines      = explode("\n", $sql);
$clean      = implode("\n", array_filter($lines, fn($l) => !str_starts_with(trim($l), '--')));
$statements = array_filter(array_map('trim', explode(';', $clean)));
$results    = [];

foreach ($statements as $stmt) {
    if ($stmt === '') continue;
    if ($conn->query($stmt) === true) {
        $results[] = ['ok', substr($stmt, 0, 80) . '...'];
    } else {
        $results[] = ['err', $conn->error . ' | ' . substr($stmt, 0, 80)];
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Attendance Migration</title>
<style>body{font-family:monospace;padding:2rem;}
.ok{color:green;} .err{color:red;}</style>
</head>
<body>
<h2>Attendance Tables Migration</h2>
<?php foreach ($results as [$status, $msg]): ?>
  <p class="<?= $status ?>">[<?= strtoupper($status) ?>] <?= htmlspecialchars($msg) ?></p>
<?php endforeach; ?>
<p><strong>완료. 이 파일은 실행 후 삭제하거나 접근 제한을 권장합니다.</strong></p>
</body>
</html>
