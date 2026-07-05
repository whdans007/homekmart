<?php
// 사용하지 않는 office_receipts 컬럼 삭제
// - used_at: 쓰여지기만 하고 읽히지 않음
// - is_er_placed: er_section IS NOT NULL 로 완전 대체됨
// 실행 후 삭제 권장
require_once __DIR__ . '/lib/office_helper.php';
require_office_permission();

$conn = get_db_connection();
header('Content-Type: text/html; charset=utf-8');
echo '<style>body{font-family:sans-serif;padding:20px;max-width:600px} .ok{color:#166534;background:#f0fdf4;border:1px solid #86efac;padding:6px 12px;border-radius:6px;margin:4px 0} .skip{color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:6px 12px;border-radius:6px;margin:4px 0} .err{color:#991b1b;background:#fef2f2;border:1px solid #fca5a5;padding:6px 12px;border-radius:6px;margin:4px 0}</style>';
echo '<h2>Drop Unused Receipt Columns</h2>';

$drops = [
    'used_at'     => 'used_at (쓰여지기만 하고 미사용)',
    'is_er_placed'=> 'is_er_placed (er_section으로 대체)',
];

foreach ($drops as $col => $label) {
    $chk = $conn->query("SHOW COLUMNS FROM office_receipts LIKE '{$col}'");
    if (!$chk || $chk->num_rows === 0) {
        echo "<div class='skip'>⏭ {$label} — 컬럼 없음 (이미 삭제됨)</div>";
        continue;
    }
    $ok = $conn->query("ALTER TABLE office_receipts DROP COLUMN {$col}");
    if ($ok) {
        echo "<div class='ok'>✅ DROP COLUMN {$label}</div>";
    } else {
        echo "<div class='err'>❌ DROP COLUMN {$col}: " . htmlspecialchars($conn->error) . "</div>";
    }
}

$conn->close();
echo '<h3 style="color:#166534">완료</h3>';
echo '<p style="color:#6b7280;font-size:13px">이 파일을 삭제하세요: <code>run_drop_unused_receipt_cols.php</code></p>';
