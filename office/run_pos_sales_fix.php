<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/lib/office_helper.php';
require_office_permission();

$conn = get_db_connection();
header('Content-Type: text/html; charset=utf-8');
echo '<style>body{font-family:sans-serif;padding:20px;max-width:600px}
.ok{color:#166534;background:#f0fdf4;border:1px solid #86efac;padding:6px 12px;border-radius:6px;margin:4px 0}
.err{color:#991b1b;background:#fef2f2;border:1px solid #fca5a5;padding:6px 12px;border-radius:6px;margin:4px 0}
.skip{color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:6px 12px;border-radius:6px;margin:4px 0}</style>';
echo '<h2>POS Sales Table Fix</h2>';

function drop_index_if_exists($conn, $table, $key_name) {
    $r = $conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$key_name}'");
    if ($r && $r->num_rows > 0) {
        $ok = $conn->query("ALTER TABLE `{$table}` DROP INDEX `{$key_name}`");
        echo $ok
            ? "<div class='ok'>✅ INDEX {$key_name} 제거</div>"
            : "<div class='err'>❌ INDEX {$key_name}: " . htmlspecialchars($conn->error) . "</div>";
    } else {
        echo "<div class='skip'>⏭ INDEX {$key_name} 없음</div>";
    }
}

function drop_col_if_exists($conn, $table, $col) {
    $r = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    if ($r && $r->num_rows > 0) {
        $ok = $conn->query("ALTER TABLE `{$table}` DROP COLUMN `{$col}`");
        echo $ok
            ? "<div class='ok'>✅ COLUMN {$col} 제거</div>"
            : "<div class='err'>❌ COLUMN {$col}: " . htmlspecialchars($conn->error) . "</div>";
    } else {
        echo "<div class='skip'>⏭ COLUMN {$col} 없음</div>";
    }
}

// 1. 인덱스 먼저 제거
drop_index_if_exists($conn, 'pos_sales_uploads', 'uniq_slot');
drop_index_if_exists($conn, 'pos_sales_uploads', 'idx_date');

// 2. 컬럼 제거
drop_col_if_exists($conn, 'pos_sales_uploads', 'upload_date');
drop_col_if_exists($conn, 'pos_sales_uploads', 'file_slot');

// 3. 현재 구조 확인
echo "<h3>현재 pos_sales_uploads 구조</h3><pre>";
$cols = $conn->query("SHOW COLUMNS FROM pos_sales_uploads");
if ($cols) while ($c = $cols->fetch_assoc()) {
    echo $c['Field'] . " (" . $c['Type'] . ") " . ($c['Null']==='NO'?'NOT NULL':'NULL') . "\n";
}
echo "</pre>";

$conn->close();
echo '<p style="color:#6b7280">완료 후 이 파일 삭제: <code>run_pos_sales_fix.php</code></p>';
