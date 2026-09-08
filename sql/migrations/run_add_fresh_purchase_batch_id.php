<?php
/**
 * fresh_purchase_items에 전표(배치) 연결 및 박스 원 입력값 보존 컬럼 추가.
 * - batch_id: fresh_purchase_batches.id 참조 (신규 데이터부터 채움, 과거 행은 NULL로 남김)
 * - quantity_boxes / box_weight_kg / box_pieces_per_box / box_cost: 사용자가 입력한 "몇 박스 × 박스당 무게(또는 개수) × 박스당 원가"를
 *   합산 전 원본 그대로 보존한다. 기존 weight_kg/pieces_per_box/total_cost는 계속 "총합" 스냅샷으로 유지된다.
 * - sort_order: 전표 내 품목 표시 순서.
 *
 * 이 마이그레이션 실행 전에 run_create_fresh_purchase_batches.php를 먼저 실행해야 한다.
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_add_fresh_purchase_batch_id.php
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

function fpbid_column_exists(mysqli $conn, string $table, string $column): bool
{
    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    return (bool)($result && $result->num_rows > 0);
}

function fpbid_index_exists(mysqli $conn, string $table, string $indexName): bool
{
    $escapedTable = $conn->real_escape_string($table);
    $escapedIndex = $conn->real_escape_string($indexName);
    $result = $conn->query("SHOW INDEX FROM `{$escapedTable}` WHERE Key_name = '{$escapedIndex}'");
    return (bool)($result && $result->num_rows > 0);
}

function fpbid_fk_exists(mysqli $conn, string $table, string $constraintName): bool
{
    $result = $conn->query(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '" . $conn->real_escape_string($table) . "'
           AND CONSTRAINT_NAME = '" . $conn->real_escape_string($constraintName) . "'"
    );
    return (bool)($result && $result->num_rows > 0);
}

$steps = [
    [
        'label' => 'batch_id 컬럼 추가',
        'already_done' => fpbid_column_exists($conn, 'fresh_purchase_items', 'batch_id'),
        'sql' => "ALTER TABLE `fresh_purchase_items` ADD COLUMN `batch_id` INT NULL COMMENT 'fresh_purchase_batches.id, 과거 데이터는 NULL' AFTER `id`",
    ],
    [
        'label' => 'batch_id 인덱스 추가',
        'already_done' => fpbid_index_exists($conn, 'fresh_purchase_items', 'batch_id'),
        'sql' => "ALTER TABLE `fresh_purchase_items` ADD INDEX `batch_id` (`batch_id`)",
    ],
    [
        'label' => 'batch_id 외래키 추가',
        'already_done' => fpbid_fk_exists($conn, 'fresh_purchase_items', 'fpi_ibfk_5'),
        'sql' => "ALTER TABLE `fresh_purchase_items` ADD CONSTRAINT `fpi_ibfk_5` FOREIGN KEY (`batch_id`) REFERENCES `fresh_purchase_batches` (`id`)",
    ],
    [
        'label' => 'quantity_boxes 컬럼 추가',
        'already_done' => fpbid_column_exists($conn, 'fresh_purchase_items', 'quantity_boxes'),
        'sql' => "ALTER TABLE `fresh_purchase_items` ADD COLUMN `quantity_boxes` INT NULL COMMENT '매입한 박스 수 (원 입력값 보존)' AFTER `store_product_id`",
    ],
    [
        'label' => 'box_weight_kg 컬럼 추가',
        'already_done' => fpbid_column_exists($conn, 'fresh_purchase_items', 'box_weight_kg'),
        'sql' => "ALTER TABLE `fresh_purchase_items` ADD COLUMN `box_weight_kg` DECIMAL(10,3) NULL COMMENT '박스당 무게(kg), 저울 상품 원 입력값' AFTER `quantity_boxes`",
    ],
    [
        'label' => 'box_pieces_per_box 컬럼 추가',
        'already_done' => fpbid_column_exists($conn, 'fresh_purchase_items', 'box_pieces_per_box'),
        'sql' => "ALTER TABLE `fresh_purchase_items` ADD COLUMN `box_pieces_per_box` INT NULL COMMENT '박스당 개수, 낱개 상품 원 입력값' AFTER `box_weight_kg`",
    ],
    [
        'label' => 'box_cost 컬럼 추가',
        'already_done' => fpbid_column_exists($conn, 'fresh_purchase_items', 'box_cost'),
        'sql' => "ALTER TABLE `fresh_purchase_items` ADD COLUMN `box_cost` DECIMAL(10,2) NULL COMMENT '박스당 원가 원 입력값' AFTER `box_pieces_per_box`",
    ],
    [
        'label' => 'sort_order 컬럼 추가',
        'already_done' => fpbid_column_exists($conn, 'fresh_purchase_items', 'sort_order'),
        'sql' => "ALTER TABLE `fresh_purchase_items` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 COMMENT '전표 내 품목 표시 순서' AFTER `batch_id`",
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
    <title>fresh_purchase_items 배치 연결 마이그레이션</title>
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
    <h1>🛠️ fresh_purchase_items 배치 연결 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ run_create_fresh_purchase_batches.php를 먼저 실행했는지 확인하세요. batch_id 외래키는 fresh_purchase_batches 테이블이 있어야 생성됩니다.
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
