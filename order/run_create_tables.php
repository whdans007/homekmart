<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/auth.php';
ord_require_manager();

try {
    $conn = get_ord_db();
} catch (Exception $e) {
    die('DB 연결 실패: ' . htmlspecialchars($e->getMessage()));
}

$sqlFile = __DIR__ . '/sql/order_create_tables.sql';
if (!file_exists($sqlFile)) {
    die('SQL 파일을 찾을 수 없습니다: ' . htmlspecialchars($sqlFile));
}

$sql = file_get_contents($sqlFile);

// 주석 제거 후 세미콜론으로 구분
$lines = explode("\n", $sql);
$cleaned = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '--') === 0) continue;
    $cleaned[] = $line;
}
$sql = implode("\n", $cleaned);
$queries = array_filter(array_map('trim', explode(';', $sql)));

$results  = [];
$hasError = false;

foreach ($queries as $query) {
    if ($query === '') continue;
    if ($conn->query($query)) {
        preg_match('/CREATE TABLE IF NOT EXISTS\s+(\w+)/i', $query, $m);
        $results[] = ['ok' => true, 'table' => $m[1] ?? substr($query, 0, 40), 'msg' => '생성 완료'];
    } else {
        preg_match('/CREATE TABLE IF NOT EXISTS\s+(\w+)/i', $query, $m);
        $results[] = ['ok' => false, 'table' => $m[1] ?? substr($query, 0, 40), 'msg' => $conn->error];
        $hasError = true;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>발주 시스템 테이블 생성</title>
    <style>
        body { font-family: sans-serif; max-width: 620px; margin: 60px auto; padding: 0 20px; }
        h2 { color: #4338ca; }
        .row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 6px; margin-bottom: 6px; font-size: 14px; }
        .ok   { background: #f0fdf4; color: #15803d; }
        .fail { background: #fef2f2; color: #dc2626; }
        .tname { font-weight: bold; min-width: 240px; }
        .msg   { font-size: 12px; color: #6b7280; }
        .msg.fail { color: #dc2626; }
        .summary { margin-top: 20px; padding: 12px 16px; border-radius: 8px; font-weight: bold; }
        .summary.ok   { background: #dcfce7; color: #166534; }
        .summary.fail { background: #fee2e2; color: #991b1b; }
        .hint { margin-top: 14px; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <h2>발주 시스템 — 테이블 생성</h2>

    <?php foreach ($results as $r): ?>
    <div class="row <?php echo $r['ok'] ? 'ok' : 'fail'; ?>">
        <span><?php echo $r['ok'] ? '✅' : '❌'; ?></span>
        <span class="tname"><?php echo htmlspecialchars($r['table']); ?></span>
        <span class="msg <?php echo $r['ok'] ? '' : 'fail'; ?>"><?php echo htmlspecialchars($r['msg']); ?></span>
    </div>
    <?php endforeach; ?>

    <div class="summary <?php echo $hasError ? 'fail' : 'ok'; ?>">
        <?php if (!$hasError): ?>
            ✅ 모든 테이블 생성 완료 (<?php echo count($results); ?>개)
        <?php else: ?>
            ❌ 일부 실패 — 위 오류를 확인하세요
        <?php endif; ?>
    </div>

    <p class="hint">실행 완료 후 이 파일을 삭제하는 것을 권장합니다.</p>
</body>
</html>
