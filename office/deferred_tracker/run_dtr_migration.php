<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

$conn = get_db_connection();
$sqls = [
    "ALTER TABLE office_product_purchases ADD COLUMN IF NOT EXISTS is_dtr_placed TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE office_product_purchases ADD INDEX IF NOT EXISTS idx_dtr_placed (store_id, is_dtr_placed)",
    "ALTER TABLE office_equipment_purchases ADD COLUMN IF NOT EXISTS is_dtr_placed TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE office_equipment_purchases ADD INDEX IF NOT EXISTS idx_dtr_placed (store_id, is_dtr_placed)",
    "ALTER TABLE office_receipts ADD COLUMN IF NOT EXISTS is_dtr_placed TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE office_receipts ADD INDEX IF NOT EXISTS idx_dtr_placed (store_id, is_dtr_placed)",
];
header('Content-Type: text/plain; charset=utf-8');
foreach ($sqls as $sql) {
    $ok = $conn->query($sql);
    echo ($ok ? '✅ OK' : '❌ FAIL (' . $conn->error . ')') . ' — ' . substr($sql,7,60) . "\n";
}
$conn->close();
echo "\nDone. Delete this file.\n";
