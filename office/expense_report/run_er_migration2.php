<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

$conn = get_db_connection();
$sqls = [
    "ALTER TABLE office_receipts ADD COLUMN IF NOT EXISTS is_er_placed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Expense Report 처리 여부'",
    "ALTER TABLE office_receipts ADD INDEX IF NOT EXISTS idx_er_placed (store_id, is_er_placed, receipt_date)",
];
header('Content-Type: text/plain; charset=utf-8');
foreach ($sqls as $sql) {
    $ok = $conn->query($sql);
    echo ($ok ? '✅ OK' : '❌ FAIL (' . $conn->error . ')') . "\n";
}
$conn->close();
echo "\nDone. Delete this file.\n";
