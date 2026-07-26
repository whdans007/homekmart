<?php
/**
 * 臾쇰쪟?쇳꽣 DB 留덉씠洹몃젅?댁뀡 v17
 * kw_orders.status ENUM??'draft' 異붽? (吏?먯텧怨??꾩떆????뚰겕?뚮줈??
 * Design Ref: branch-outbound-draft-workflow 짠2.1
 * ?묒냽: http://?쒕쾭二쇱냼/kimsmall_wherehouse/sql/run_migration_v17.php
 * 二쇱쓽: ?ㅽ뻾 ?????뚯씪????젣?섏꽭??
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF); // PHP 8.1+ 기본 예외 던짐 방지 — 기존 if/else 오류 처리 로직 유지
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];

// ?꾩옱 ENUM 媛??뺤씤
$row = $conn->query("
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'kw_orders'
      AND COLUMN_NAME  = 'status'
")->fetch_assoc();

if (!$row) {
    $steps[] = ['ERROR', 'kw_orders.status 而щ읆??李얠쓣 ???놁뒿?덈떎.'];
} elseif (str_contains($row['COLUMN_TYPE'], 'draft')) {
    $steps[] = ['SKIP', "ENUM??'draft'媛 ?대? ?ы븿?섏뼱 ?덉뒿?덈떎. ({$row['COLUMN_TYPE']})"];
} elseif ($conn->query("
    ALTER TABLE kw_orders
    MODIFY COLUMN status
        ENUM('draft','pending','approved','cancel_requested','shipped','delivered','cancelled')
        NOT NULL DEFAULT 'pending'
        COMMENT '二쇰Ц ?곹깭'
")) {
    $steps[] = ['OK', "kw_orders.status ENUM??'draft' 異붽? ?꾨즺"];
} else {
    $steps[] = ['ERROR', 'ALTER ?ㅽ뙣: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v17</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;}</style>
</head>
<body>
<h2>Migration v17 寃곌낵 ??kw_orders.status??'draft' 異붽?</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>?꾨즺 ?????뚯씪????젣?섏꽭??</strong></p>
</body>
</html>

