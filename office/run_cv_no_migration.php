<?php
require_once __DIR__ . '/lib/office_helper.php';
require_office_permission();

$conn = get_db_connection();
$result = $conn->query("ALTER TABLE office_receipts ADD COLUMN cv_no VARCHAR(100) NULL AFTER notes");

if ($result) {
    echo '<div style="font-family:sans-serif;padding:20px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;color:#166534;">';
    echo '<strong>✅ Migration Complete</strong><br>cv_no column added successfully.<br>';
    echo '<small>Please delete this file: <code>run_cv_no_migration.php</code></small>';
    echo '</div>';
} else {
    echo '<div style="font-family:sans-serif;padding:20px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;">';
    echo '<strong>오류 또는 이미 적용됨:</strong> ' . htmlspecialchars($conn->error);
    echo '</div>';
}
$conn->close();
