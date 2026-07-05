<?php
// 영수증 테이블 마이그레이션 실행 (1회 실행 후 삭제 권장)
require_once __DIR__ . '/lib/office_helper.php';
require_office_permission();

$conn = get_db_connection();
$sql  = file_get_contents(__DIR__ . '/sql/create_receipts.sql');

if ($conn->multi_query($sql)) {
    do { $conn->store_result(); } while ($conn->more_results() && $conn->next_result());
    echo '<div style="font-family:sans-serif;padding:20px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;color:#166534;">';
    echo '<strong>✅ Migration Complete</strong><br>office_receipts table created successfully.<br>';
    echo '<small>Please delete this file: <code>run_receipts_migration.php</code></small>';
    echo '</div>';
} else {
    echo '<div style="font-family:sans-serif;padding:20px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;">';
    echo '<strong>❌ 오류:</strong> ' . htmlspecialchars($conn->error);
    echo '</div>';
}
$conn->close();
