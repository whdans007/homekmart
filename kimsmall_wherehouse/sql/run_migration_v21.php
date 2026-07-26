<?php
require_once __DIR__ . '/../../config/db_config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre>\n";

mysqli_report(MYSQLI_REPORT_OFF); // PHP 8.1+ 기본 예외 던짐 방지 — 기존 if/else 오류 처리 로직 유지
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

$sql = file_get_contents(__DIR__ . '/kw_migration_v21.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));

foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    if ($conn->query($stmt)) {
        echo "OK: " . substr($stmt, 0, 80) . "...\n";
    } else {
        echo "ERROR: " . $conn->error . "\n  SQL: " . substr($stmt, 0, 80) . "\n";
    }
}

$conn->close();
echo "\n마이그레이션 v21 완료.\n</pre>";
