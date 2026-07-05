<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
$conn = get_db_connection();
$sql  = file_get_contents(__DIR__ . '/../sql/alter_equipment_expense_type.sql');
if ($conn->multi_query($sql)) {
    do { $conn->store_result(); } while ($conn->more_results() && $conn->next_result());
    echo '<div style="font-family:sans-serif;padding:20px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;color:#166534;">';
    echo '<strong>✅ Migration Complete</strong><br>expense_type and expense_category columns added to office_equipment_purchases.<br>';
    echo '<small>Please delete this file after running.</small>';
    echo '</div>';
} else {
    echo '<div style="font-family:sans-serif;padding:20px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;">';
    echo '<strong>Error:</strong> ' . htmlspecialchars($conn->error);
    echo '</div>';
}
$conn->close();
