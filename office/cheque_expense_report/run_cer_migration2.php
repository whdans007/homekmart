<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

$conn = get_db_connection();
$sqls = [
    "ALTER TABLE office_product_purchases ADD COLUMN IF NOT EXISTS cer_check_no VARCHAR(100) NULL COMMENT 'CER에 사용된 수표번호'",
    "ALTER TABLE office_product_purchases ADD COLUMN IF NOT EXISTS is_cer_returned TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'CER 반품 여부'",
];
header('Content-Type: text/plain; charset=utf-8');
foreach ($sqls as $sql) {
    $ok = $conn->query($sql);
    echo ($ok ? '✅ OK' : '❌ FAIL (' . $conn->error . ')') . ' — ' . substr($sql, 0, 80) . "...\n";
}
$conn->close();
echo "\nDone. Please delete this file.\n";
