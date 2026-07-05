<?php
/**
 * Migration v15 — lc_products capacity 컬럼 추가
 * 접속: http://main.homekmart.net/logistics/sql/run_migration_v15.php
 * 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [
    'capacity 컬럼 추가' => "ALTER TABLE lc_products ADD COLUMN capacity VARCHAR(50) DEFAULT NULL COMMENT '용량/규격 (예: 500ml, 1kg, 20ea)' AFTER name_ko",
];

$results = [];
foreach ($steps as $label => $sql) {
    $ok = $conn->query($sql);
    $err = $conn->error;
    $results[$label] = $ok ? 'ok' : (str_contains($err, 'Duplicate') ? 'skip' : 'error:' . $err);
}
$conn->close();
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>Migration v15</title>
<style>body{font-family:-apple-system,sans-serif;padding:2rem;background:#f9fafb}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:540px;margin:0 auto}
.ok{color:#059669}.skip{color:#9ca3af}.error{color:#dc2626} a{color:#0d9488}</style>
</head><body><div class="card">
<h2 style="margin:0 0 1rem">Migration v15 — capacity 컬럼 추가</h2>
<?php foreach ($results as $label => $r): ?>
<p class="<?php echo strpos($r,'error')===0?'error':$r; ?>">
    <?php echo $r==='ok'?'✅':($r==='skip'?'➖':'❌'); ?> <?php echo htmlspecialchars($label . ' — ' . $r); ?>
</p>
<?php endforeach; ?>
<p style="margin-top:1rem"><a href="http://main.homekmart.net/logistics/products.php">상품 목록으로 →</a></p>
<p style="color:#9ca3af;font-size:.8rem;margin-top:.5rem">⚠️ 보안상 이 파일을 삭제하세요.</p>
</div></body></html>
