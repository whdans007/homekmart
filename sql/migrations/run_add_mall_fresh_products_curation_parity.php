<?php
/**
 * mall_fresh_products에 "큐레이션된 상품" 테이블과 동일한 기능(정렬순서/원가·기준도매가 오버라이드/
 * 강제품절/할인허용 플래그)을 제공하기 위한 컬럼 추가 마이그레이션
 * 설계 근거: docs/01-plan/features/mall-fresh-curation-tab.plan.md
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_add_mall_fresh_products_curation_parity.php
 * 각 단계는 이미 적용되어 있으면 SKIP 처리되어 여러 번 실행해도 안전하다(idempotent).
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

function mfppc_column_exists(mysqli $conn, string $table, string $column): bool
{
    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    return (bool)($result && $result->num_rows > 0);
}

$steps = [
    [
        'label' => 'display_order 컬럼 추가 (정렬순서, 기본값 0)',
        'already_done' => mfppc_column_exists($conn, 'mall_fresh_products', 'display_order'),
        'sql' => "ALTER TABLE `mall_fresh_products` ADD COLUMN `display_order` INT NOT NULL DEFAULT 0 COMMENT '큐레이션 테이블 정렬순서' AFTER `category_id`",
    ],
    [
        'label' => 'cost_price_override 컬럼 추가 (원가 오버라이드, NULL 허용)',
        'already_done' => mfppc_column_exists($conn, 'mall_fresh_products', 'cost_price_override'),
        'sql' => "ALTER TABLE `mall_fresh_products` ADD COLUMN `cost_price_override` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '오리지널(최근 매입원가)과 다를 때만 저장' AFTER `box_sale_price`",
    ],
    [
        'label' => 'wholesale_reference_price_override 컬럼 추가 (기준도매가 오버라이드, NULL 허용)',
        'already_done' => mfppc_column_exists($conn, 'mall_fresh_products', 'wholesale_reference_price_override'),
        'sql' => "ALTER TABLE `mall_fresh_products` ADD COLUMN `wholesale_reference_price_override` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '오리지널(원가 x 마진율 계산값)과 다를 때만 저장' AFTER `cost_price_override`",
    ],
    [
        'label' => 'is_sold_out 컬럼 추가 (강제 품절, 기본값 0)',
        'already_done' => mfppc_column_exists($conn, 'mall_fresh_products', 'is_sold_out'),
        'sql' => "ALTER TABLE `mall_fresh_products` ADD COLUMN `is_sold_out` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '켜면 실제 상태와 무관하게 몰 화면에서 무조건 품절 표시' AFTER `status`",
    ],
    [
        'label' => 'retail_discount_allowed 컬럼 추가 (소매 할인 허용, 기본값 1)',
        'already_done' => mfppc_column_exists($conn, 'mall_fresh_products', 'retail_discount_allowed'),
        'sql' => "ALTER TABLE `mall_fresh_products` ADD COLUMN `retail_discount_allowed` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_sold_out`",
    ],
    [
        'label' => 'wholesale_discount_allowed 컬럼 추가 (도매 할인 허용, 기본값 1)',
        'already_done' => mfppc_column_exists($conn, 'mall_fresh_products', 'wholesale_discount_allowed'),
        'sql' => "ALTER TABLE `mall_fresh_products` ADD COLUMN `wholesale_discount_allowed` TINYINT(1) NOT NULL DEFAULT 1 AFTER `retail_discount_allowed`",
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
    <title>mall_fresh_products 큐레이션 통합 마이그레이션</title>
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
        code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:.8rem}
    </style>
</head>
<body>
<div class="card">
    <h1>🛠️ mall_fresh_products 큐레이션 통합 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ <code>mall_fresh_products</code> 테이블에 정렬순서/원가·기준도매가 오버라이드/강제품절/할인허용 컬럼 6개를 추가합니다. 기존 <code>products</code>/<code>mall_products</code>/<code>inventory</code> 등 다른 테이블은 전혀 변경되지 않습니다.
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
