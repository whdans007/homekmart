<?php
// Run from CLI after backing up the warehouse database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
$db = get_lc_db();
$sql = file_get_contents(__DIR__ . '/kw_stock_count.sql');
if (!$db->multi_query($sql)) { throw new RuntimeException($db->error); }
do {
    if ($result = $db->store_result()) $result->free();
    if (!$db->more_results()) break;
} while ($db->next_result());
if ($db->errno) throw new RuntimeException($db->error);
$columns = $db->query("SHOW COLUMNS FROM kw_stock_count_sessions")->fetch_all(MYSQLI_ASSOC);
$names = array_column($columns, 'Field');
if (!in_array('inbound_closed_ack_at', $names, true)) {
    $db->query('ALTER TABLE kw_stock_count_sessions ADD COLUMN inbound_closed_ack_at DATETIME NULL AFTER opened_by');
}
if (!in_array('inbound_closed_ack_by', $names, true)) {
    $db->query('ALTER TABLE kw_stock_count_sessions ADD COLUMN inbound_closed_ack_by INT NULL AFTER inbound_closed_ack_at');
}
$entryColumns = $db->query("SHOW COLUMNS FROM kw_stock_count_entries")->fetch_all(MYSQLI_ASSOC);
$entryNames = array_column($entryColumns, 'Field');
if (!in_array('voided_at', $entryNames, true)) {
    $db->query('ALTER TABLE kw_stock_count_entries ADD COLUMN voided_at DATETIME NULL AFTER entered_by');
}
if (!in_array('voided_by', $entryNames, true)) {
    $db->query('ALTER TABLE kw_stock_count_entries ADD COLUMN voided_by INT NULL AFTER voided_at');
}
if (!in_array('void_reason', $entryNames, true)) {
    $db->query("ALTER TABLE kw_stock_count_entries ADD COLUMN void_reason ENUM('cancel','correction') NULL AFTER voided_by");
}
if (!in_array('corrected_from_entry_id', $entryNames, true)) {
    $db->query('ALTER TABLE kw_stock_count_entries ADD COLUMN corrected_from_entry_id BIGINT NULL AFTER void_reason, ADD INDEX idx_kw_sc_corrected_from (corrected_from_entry_id)');
}
echo "Stock count schema ready.\n";
