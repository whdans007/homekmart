<?php
/**
 * 신선식품(과일/채소/정육/수산) 카테고리별 마진율 관리 기능 마이그레이션
 * - fresh_margin_rules 테이블 신설 (카테고리별 마진율, 기본값 40%)
 * - mall_fresh_products에 box_sale_price 컬럼 추가 (박스판매가, 매입 시 자동계산 + 수동 override 허용)
 * 설계 근거: docs/01-plan/features/fresh-margin-management.plan.md
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_add_fresh_margin_rules.php
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

function fmr_table_exists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return (bool)($result && $result->num_rows > 0);
}

function fmr_column_exists(mysqli $conn, string $table, string $column): bool
{
    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    return (bool)($result && $result->num_rows > 0);
}

$tableExists = fmr_table_exists($conn, 'fresh_margin_rules');
$defaultRowsPresent = false;
if ($tableExists) {
    $countResult = $conn->query("SELECT COUNT(*) AS cnt FROM fresh_margin_rules");
    $countRow = $countResult ? $countResult->fetch_assoc() : null;
    $defaultRowsPresent = $countRow && (int)$countRow['cnt'] >= 4;
}

$steps = [
    [
        'label' => 'fresh_margin_rules 테이블 생성',
        'already_done' => $tableExists,
        'sql' => "CREATE TABLE `fresh_margin_rules` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `fresh_category` enum('fruit','vegetable','meat','seafood') NOT NULL,
            `margin_percentage` decimal(5,2) NOT NULL DEFAULT 40.00,
            `updated_by` int(11) unsigned DEFAULT NULL COMMENT 'users.id',
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `fresh_category` (`fresh_category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='신선식품 카테고리별 마진율'",
    ],
    [
        'label' => '기본 마진율 4종 등록 (과일/채소/정육/수산 각 40%)',
        'already_done' => $defaultRowsPresent,
        'sql' => "INSERT IGNORE INTO `fresh_margin_rules` (`fresh_category`, `margin_percentage`) VALUES
            ('fruit', 40.00), ('vegetable', 40.00), ('meat', 40.00), ('seafood', 40.00)",
    ],
    [
        'label' => 'mall_fresh_products.box_sale_price 컬럼 추가',
        'already_done' => fmr_column_exists($conn, 'mall_fresh_products', 'box_sale_price'),
        'sql' => "ALTER TABLE `mall_fresh_products` ADD COLUMN `box_sale_price` DECIMAL(10,2) NULL COMMENT '박스판매가(매입 시 자동계산, 수동 override 가능)' AFTER `price_per_100g`",
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
    <title>신선식품 마진 관리 마이그레이션</title>
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
    <h1>🛠️ 신선식품 마진 관리 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ <code>fresh_margin_rules</code> 테이블(카테고리별 마진율, 기본 40%)을 생성하고, <code>mall_fresh_products</code>에 <code>box_sale_price</code>(박스판매가) 컬럼을 추가합니다.
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
