<?php
/**
 * categories.image_url 컬럼 추가 마이그레이션
 * Plan Ref: docs/01-plan/features/mall-category-modal-image.plan.md §4
 *
 * 접속 후 POST 확인 버튼으로 실행한다. 컬럼이 이미 존재하면 SKIP 처리되므로 재실행해도 안전하다.
 * 주의: 실행 완료 후 보안을 위해 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

function aciu_column_exists(mysqli $conn, string $table, string $column): bool
{
    $escaped_table = str_replace('`', '``', $table);
    $escaped_column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escaped_table}` LIKE '{$escaped_column}'");
    return (bool)($result && $result->num_rows > 0);
}

$steps = [[
    'label' => 'categories.image_url 컬럼 추가',
    'already_done' => aciu_column_exists($conn, 'categories', 'image_url'),
    'sql' => 'ALTER TABLE categories ADD COLUMN image_url VARCHAR(500) NULL DEFAULT NULL AFTER name_en;',
]];

$ran = false;
$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $ran = true;
    foreach ($steps as $step) {
        if ($step['already_done']) {
            $results[] = ['label' => $step['label'], 'status' => 'SKIP', 'error' => ''];
            continue;
        }
        if ($conn->query($step['sql'])) {
            $results[] = ['label' => $step['label'], 'status' => 'OK', 'error' => ''];
        } else {
            $results[] = ['label' => $step['label'], 'status' => 'ERROR', 'error' => $conn->error];
        }
    }
}
$conn->close();

function aciu_step_badge(string $status): string
{
    return match ($status) {
        'OK' => '<span style="color:#065f46;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:4px;padding:2px 8px;font-size:.75rem">OK</span>',
        'SKIP' => '<span style="color:#374151;background:#f3f4f6;border:1px solid #d1d5db;border-radius:4px;padding:2px 8px;font-size:.75rem">SKIP</span>',
        default => '<span style="color:#991b1b;background:#fef2f2;border:1px solid #fca5a5;border-radius:4px;padding:2px 8px;font-size:.75rem">ERROR</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>categories.image_url 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}.card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:640px;margin:0 auto 1rem}h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}.sub{color:#6b7280;font-size:.875rem}.warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1rem;font-size:.875rem;color:#92400e;margin-bottom:1rem}ul.steps{list-style:none;margin:0;padding:0}ul.steps li{display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid #f3f4f6;font-size:.875rem}ul.steps li:last-child{border-bottom:none}.err-msg{color:#991b1b;font-size:.75rem;margin-top:2px}button{width:100%;padding:.75rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer}button:hover{background:#1d4ed8}code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:.8rem}
    </style>
</head>
<body>
<div class="card"><h1>categories.image_url 마이그레이션</h1><p class="sub">DB: <strong><?php echo htmlspecialchars(DB_NAME); ?></strong></p></div>
<?php if (!$ran): ?>
<div class="card">
    <div class="warn"><code>categories</code> 테이블에 nullable <code>image_url VARCHAR(500)</code> 컬럼을 추가합니다. 데이터 시드나 백필은 없습니다.</div>
    <ul class="steps"><?php foreach ($steps as $step): ?><li><?php echo htmlspecialchars($step['label']); ?><?php echo $step['already_done'] ? aciu_step_badge('SKIP') : ''; ?></li><?php endforeach; ?></ul>
    <form method="post"><input type="hidden" name="action" value="run"><button type="submit" onclick="return confirm('마이그레이션을 실행하시겠습니까?')" style="margin-top:1rem">마이그레이션 실행</button></form>
</div>
<?php else: ?>
<div class="card"><ul class="steps">
    <?php foreach ($results as $result): ?><li style="flex-direction:column;align-items:stretch"><div style="display:flex;justify-content:space-between;align-items:center"><span><?php echo htmlspecialchars($result['label']); ?></span><?php echo aciu_step_badge($result['status']); ?></div><?php if ($result['error']): ?><div class="err-msg"><?php echo htmlspecialchars($result['error']); ?></div><?php endif; ?></li><?php endforeach; ?>
    </ul><p class="sub" style="margin-top:1rem">모두 OK/SKIP이면 완료된 것입니다. 보안을 위해 이 파일을 삭제하세요.</p></div>
<?php endif; ?>
</body>
</html>
