<?php
require_once __DIR__ . '/lib/office_helper.php';
require_office_permission();

$conn = get_db_connection();
header('Content-Type: text/html; charset=utf-8');
echo '<style>body{font-family:sans-serif;padding:20px;max-width:600px}
.ok{color:#166534;background:#f0fdf4;border:1px solid #86efac;padding:6px 12px;border-radius:6px;margin:4px 0}
.err{color:#991b1b;background:#fef2f2;border:1px solid #fca5a5;padding:6px 12px;border-radius:6px;margin:4px 0}
.skip{color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:6px 12px;border-radius:6px;margin:4px 0}</style>';
echo '<h2>POS Sales Migration</h2>';

$sql = file_get_contents(__DIR__ . '/sql/create_pos_sales.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));

foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    $ok = $conn->query($stmt);
    $preview = substr($stmt, 0, 60) . '...';
    if ($ok) {
        echo "<div class='ok'>✅ " . htmlspecialchars($preview) . "</div>";
    } else {
        echo "<div class='err'>❌ " . htmlspecialchars($conn->error) . "<br><small>" . htmlspecialchars($preview) . "</small></div>";
    }
}

$conn->close();
echo '<h3 style="color:#166534">완료. 이 파일을 삭제하세요: <code>run_pos_sales_migration.php</code></h3>';
