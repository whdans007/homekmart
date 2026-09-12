<?php
/**
 * mall_fresh_products에 selling_price_override 컬럼 추가.
 * - 몰 큐레이션 화면(mall/admin/products.php)에서 판매가를 원본(price_per_100g, admin/fresh_products.php
 *   마스터 목록이 보여주는 값)과 분리해서 자유롭게 덮어쓸 수 있게 하기 위함.
 * - cost_price_override/wholesale_reference_price_override와 동일한 패턴: NULL이면 원본
 *   price_per_100g을 그대로 쓰고, 값이 있으면 그 값을 유효 판매가로 사용한다.
 * - 이 컬럼이 생기기 전까지는 큐레이션 저장이 price_per_100g(원본)을 직접 덮어썼는데, 그러면
 *   admin/fresh_products.php 마스터 목록의 "원본" 값도 함께 바뀌어버리는 문제가 있었다.
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_add_mall_fresh_products_selling_price_override.php
 * 이미 적용되어 있으면 SKIP 처리되어 여러 번 실행해도 안전하다(idempotent).
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

function mfpspo_column_exists(mysqli $conn, string $table, string $column): bool
{
    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    return (bool)($result && $result->num_rows > 0);
}

$steps = [
    [
        'label' => 'selling_price_override 컬럼 추가 (판매가 오버라이드, NULL 허용)',
        'already_done' => mfpspo_column_exists($conn, 'mall_fresh_products', 'selling_price_override'),
        'sql' => "ALTER TABLE `mall_fresh_products` ADD COLUMN `selling_price_override` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '몰 큐레이션 판매가 오버라이드 - NULL이면 원본 price_per_100g 사용' AFTER `wholesale_reference_price_override`",
    ],
];

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

function step_badge(string $status): string
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
    <title>mall_fresh_products selling_price_override 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:640px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1rem;font-size:.875rem;color:#92400e;margin-bottom:1rem}
        ul.steps{list-style:none;margin:0;padding:0}
        ul.steps li{display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid #f3f4f6;font-size:.875rem}
        ul.steps li:last-child{border-bottom:none}
        .err-msg{color:#991b1b;font-size:.75rem;margin-top:2px}
        button{width:100%;padding:.75rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer}
        button:hover{background:#1d4ed8}
    </style>
</head>
<body>
<div class="card">
    <h1>🛠️ mall_fresh_products selling_price_override 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ 몰 큐레이션 판매가를 원본(price_per_100g)과 분리해서 저장할 수 있는 오버라이드 컬럼을 추가합니다.
    </div>
    <ul class="steps">
        <?php foreach ($steps as $step): ?>
            <li><?php echo htmlspecialchars($step['label']); ?><?php echo $step['already_done'] ? step_badge('SKIP') : ''; ?></li>
        <?php endforeach; ?>
    </ul>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('마이그레이션을 실행하시겠습니까?')" style="margin-top:1rem">
            마이그레이션 실행
        </button>
    </form>
</div>
<?php else: ?>
<div class="card">
    <ul class="steps">
        <?php foreach ($results as $result): ?>
            <li style="flex-direction:column;align-items:stretch">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <span><?php echo htmlspecialchars($result['label']); ?></span>
                    <?php echo step_badge($result['status']); ?>
                </div>
                <?php if ($result['error']): ?><div class="err-msg"><?php echo htmlspecialchars($result['error']); ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="sub" style="margin-top:1rem">⚠️ 모두 OK/SKIP이면 완료된 것입니다. 보안을 위해 이 파일을 삭제하세요.</p>
</div>
<?php endif; ?>

</body>
</html>
