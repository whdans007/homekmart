<?php
/**
 * shopping-mall 기능 DB 마이그레이션 (mall_schema.sql 적용)
 * Design Ref: docs/02-design/features/shopping-mall.design.md §3.3, §11.2 1단계
 * 접속: http://서버주소/sunset/sql/run_mall_schema_migration.php
 *
 * mall_schema.sql을 단일 소스로 두고, 이 스크립트가 파일을 읽어 테이블 단위로
 * 순서대로(FK 의존성 순) 적용한다. 이미 존재하는 테이블은 SKIP 처리되어 여러 번
 * 실행해도 안전하다(idempotent).
 *
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';

// PHP 8.1+ mysqli 기본값(예외 발생)에서도 각 단계를 OK/SKIP/ERROR로 안전하게 보고하기 위해
// 예외 모드를 끄고 기존 query()의 false 반환 방식으로 통일한다.
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

/**
 * mall_schema.sql에서 "CREATE TABLE `이름` ( ... ) ... ;" 블록만 순서대로 추출합니다.
 * 파일 내 테이블 정의에는 세미콜론이 문장 끝에만 나오므로 단순 정규식으로 안전하게 분리된다.
 * @return array<int, array{name:string, sql:string}>
 */
function mall_migration_parse_tables($sql_file) {
    $content = file_get_contents($sql_file);
    preg_match_all('/CREATE TABLE `(\w+)`.*?;/s', $content, $matches, PREG_SET_ORDER);

    $tables = [];
    foreach ($matches as $m) {
        $tables[] = ['name' => $m[1], 'sql' => rtrim($m[0], ';')];
    }
    return $tables;
}

$schema_file = __DIR__ . '/mall_schema.sql';
$tables = mall_migration_parse_tables($schema_file);

// 사전 점검: mall_ 테이블이 이미 일부 존재하는지 (재실행/부분실행 흔적 확인용)
$existing_before = [];
$res = $conn->query("SHOW TABLES LIKE 'mall\\_%'");
if ($res) {
    while ($row = $res->fetch_row()) {
        $existing_before[] = $row[0];
    }
}

$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $ran = true;
    foreach ($tables as $t) {
        $check = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t['name']) . "'");
        if ($check && $check->num_rows > 0) {
            $steps[] = ['SKIP', "{$t['name']} 테이블이 이미 존재합니다."];
            continue;
        }

        if ($conn->query($t['sql'])) {
            $steps[] = ['OK', "{$t['name']} 테이블 생성 완료"];
        } else {
            $steps[] = ['ERROR', "{$t['name']} 생성 실패: " . $conn->error];
        }
    }
}

// 사후 점검: 최종적으로 존재하는 mall_ 테이블 목록
$existing_after = [];
$res2 = $conn->query("SHOW TABLES LIKE 'mall\\_%'");
if ($res2) {
    while ($row = $res2->fetch_row()) {
        $existing_after[] = $row[0];
    }
}

$has_error = false;
foreach ($steps as [$status]) {
    if ($status === 'ERROR') {
        $has_error = true;
        break;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>shopping-mall DB 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:760px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        td,th{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;text-align:left}
        th{color:#6b7280;font-weight:500;font-size:.8rem}
        .ok{color:#059669}.skip{color:#9ca3af}.error{color:#dc2626}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1rem;font-size:.875rem;color:#92400e;margin-bottom:1rem}
        .success-box{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:1rem;font-size:.875rem;color:#065f46;margin-bottom:1rem}
        .err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:1rem;font-size:.875rem;color:#991b1b;margin-bottom:1rem}
        button{width:100%;padding:.75rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer}
        button:hover{background:#1d4ed8}
        code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:.8rem}
        .mono{font-family:monospace;font-size:.8rem}
        .badge{display:inline-block;padding:1px 6px;border-radius:9999px;font-size:.75rem;margin:2px}
        .badge-green{background:#d1fae5;color:#065f46}
        .badge-gray{background:#f3f4f6;color:#6b7280}
    </style>
</head>
<body>
<div class="card">
    <h1>🛒 shopping-mall DB 마이그레이션</h1>
    <p class="sub">mall_schema.sql 적용 · DB: <strong><?php echo DB_NAME; ?></strong> · 총 <?php echo count($tables); ?>개 테이블</p>
</div>

<?php if (!empty($existing_before) && !$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ 이미 존재하는 mall_ 테이블이 있습니다 (이전 실행 흔적일 수 있습니다). 새로 생성되는 테이블만 진행되고, 존재하는 테이블은 SKIP됩니다.
    </div>
    <div><?php foreach ($existing_before as $name): ?><span class="badge badge-gray"><?php echo htmlspecialchars($name); ?></span><?php endforeach; ?></div>
</div>
<?php endif; ?>

<?php if ($ran): ?>
<div class="card">
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 단계 실패. 아래 오류를 확인하고, 원인 해결 후 이 페이지를 다시 실행하세요(이미 생성된 테이블은 SKIP되어 안전합니다).</div>
    <?php else: ?>
    <div class="success-box">✅ 마이그레이션 완료! 총 <?php echo count($existing_after); ?>개 mall_ 테이블 확인됨.</div>
    <p class="sub">⚠️ 보안을 위해 이 파일을 삭제하세요.</p>
    <?php endif; ?>
    <table>
        <thead><tr><th>테이블</th><th>결과</th></tr></thead>
        <tbody>
        <?php foreach ($steps as $s): [$status, $msg] = $s; ?>
        <tr>
            <td class="mono"><?php echo htmlspecialchars(explode(' ', $msg)[0]); ?></td>
            <td class="<?php echo strtolower($status); ?>">
                <?php echo $status === 'OK' ? '✅' : ($status === 'SKIP' ? '➖' : '❌'); ?>
                <?php echo htmlspecialchars($msg); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <p style="font-size:.875rem;font-weight:600;color:#374151;margin:0 0 .75rem">현재 mall_ 테이블 (<?php echo count($existing_after ?: $existing_before); ?>개)</p>
    <div>
        <?php foreach (($ran ? $existing_after : $existing_before) as $name): ?>
        <span class="badge badge-green"><?php echo htmlspecialchars($name); ?></span>
        <?php endforeach; ?>
        <?php if (empty($ran ? $existing_after : $existing_before)): ?>
        <span class="sub">아직 생성된 mall_ 테이블이 없습니다.</span>
        <?php endif; ?>
    </div>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ <strong>주의:</strong> <?php echo count($tables); ?>개 테이블(<code>mall_members</code>, <code>mall_orders</code> 등)을 생성합니다.
        기존 <code>stores</code>/<code>products</code>/<code>wholesale_products</code>/<code>wholesale_customers</code> 테이블을 FK로 참조하므로
        해당 테이블들이 먼저 존재해야 합니다. mall_ 접두사 테이블만 생성하며 기존 테이블은 변경하지 않습니다.
    </div>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('mall_schema.sql을 적용하시겠습니까?\n\n<?php echo count($tables); ?>개 테이블이 생성됩니다.')">
            마이그레이션 실행
        </button>
    </form>
</div>
<?php elseif (!$has_error): ?>
<div class="card">
    <a href="/sunset/mall/signup.php">쇼핑몰 회원가입 페이지로 이동 →</a>
</div>
<?php endif; ?>

</body>
</html>
