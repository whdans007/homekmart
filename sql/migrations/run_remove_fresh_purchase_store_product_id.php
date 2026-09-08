<?php
/**
 * fresh_purchase_items 테이블에서 store_product_id 컬럼(및 FK) 제거 마이그레이션
 * 설계 근거: docs/01-plan/features/fresh-purchase-remove-store-product-match.plan.md
 *
 * 신선매입 등록 화면에서 점포상품(SKU) 매칭 기능 자체를 제거하기로 결정 —
 * fresh_purchase_items.store_product_id (NOT NULL, FK -> products.id) 컬럼을 삭제한다.
 * mall_fresh_product_store_links 테이블은 별개이며 이 마이그레이션에서 건드리지 않는다.
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_remove_fresh_purchase_store_product_id.php
 *
 * 각 단계는 이미 적용되어 있으면 SKIP 처리되어 여러 번 실행해도 안전하다(idempotent).
 *
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    return (bool)($result && $result->num_rows > 0);
}

function fk_exists(mysqli $conn, string $table, string $constraint): bool
{
    $escapedTable = $conn->real_escape_string($table);
    $escapedConstraint = $conn->real_escape_string($constraint);
    $result = $conn->query(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$escapedTable}'
           AND CONSTRAINT_NAME = '{$escapedConstraint}' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    return (bool)($result && $result->num_rows > 0);
}

$hasColumn = column_exists($conn, 'fresh_purchase_items', 'store_product_id');
$hasFk = $hasColumn && fk_exists($conn, 'fresh_purchase_items', 'fpi_ibfk_3');

$steps = [
    [
        'label' => 'fresh_purchase_items: store_product_id FK(fpi_ibfk_3) 삭제',
        'already_done' => !$hasFk,
        'sql' => "ALTER TABLE `fresh_purchase_items` DROP FOREIGN KEY `fpi_ibfk_3`",
    ],
    [
        'label' => 'fresh_purchase_items: store_product_id 컬럼 삭제',
        'already_done' => !$hasColumn,
        'sql' => "ALTER TABLE `fresh_purchase_items` DROP COLUMN `store_product_id`",
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
            break;
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
    <title>fresh_purchase_items store_product_id 제거 마이그레이션</title>
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
        button{width:100%;padding:.75rem;background:#dc2626;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer}
        button:hover{background:#b91c1c}
        code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:.8rem}
    </style>
</head>
<body>
<div class="card">
    <h1>🛠️ fresh_purchase_items store_product_id 제거</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ <code>fresh_purchase_items.store_product_id</code> 컬럼과 FK를 삭제합니다.
        기존 매입 항목에 저장돼 있던 store_product_id 값은 <strong>영구히 소실</strong>됩니다(원가/수량 등 다른 데이터는 무변화).
        되돌리려면 DB 백업이 필요하니, 실행 전 반드시 확인하세요.
    </div>
    <ul class="steps">
        <?php foreach ($steps as $step): ?>
            <li><?php echo htmlspecialchars($step['label']); ?><?php echo $step['already_done'] ? step_badge('SKIP') : ''; ?></li>
        <?php endforeach; ?>
    </ul>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('store_product_id 컬럼을 삭제하시겠습니까? 되돌릴 수 없습니다.')" style="margin-top:1rem">
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
