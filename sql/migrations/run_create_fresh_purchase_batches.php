<?php
/**
 * 신선상품 매입 "전표(배치)" 부모 테이블 신설.
 * - 한 거래처로부터 여러 신선상품을 한 번에 매입한 것을 하나의 전표로 묶기 위한 테이블.
 * - fresh_purchase_items.batch_id가 이 테이블을 참조한다 (run_add_fresh_purchase_batch_id.php에서 추가).
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_create_fresh_purchase_batches.php
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

function fpb_table_exists(mysqli $conn, string $table): bool
{
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return (bool)($result && $result->num_rows > 0);
}

$steps = [
    [
        'label' => 'fresh_purchase_batches 테이블 생성',
        'already_done' => fpb_table_exists($conn, 'fresh_purchase_batches'),
        'sql' => "CREATE TABLE `fresh_purchase_batches` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `store_id` int(11) UNSIGNED NOT NULL,
            `supplier_id` int(11) UNSIGNED NOT NULL,
            `purchase_date` date NOT NULL,
            `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '전표 총액 = 품목 합계금액의 합',
            `total_items` int(11) NOT NULL DEFAULT 0 COMMENT '전표에 포함된 품목 행 수',
            `registered_by` int(11) UNSIGNED DEFAULT NULL COMMENT 'users.id',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `deleted_at` timestamp NULL DEFAULT NULL COMMENT '전표 취소(soft delete) 시각',
            `deleted_by_user_id` int(11) UNSIGNED DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `store_id` (`store_id`),
            KEY `supplier_id` (`supplier_id`),
            KEY `purchase_date` (`purchase_date`),
            CONSTRAINT `fpb_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
            CONSTRAINT `fpb_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='신선상품 매입 전표(배치) 헤더'",
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
    <title>fresh_purchase_batches 생성 마이그레이션</title>
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
    <h1>🛠️ fresh_purchase_batches 생성 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ 신선상품 매입을 "전표(배치)" 단위로 묶기 위한 부모 테이블을 생성합니다. 이 마이그레이션을 먼저 실행한 뒤 run_add_fresh_purchase_batch_id.php를 실행하세요.
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
    <p class="sub" style="margin-top:1rem">⚠️ 모두 OK/SKIP이면 완료된 것입니다. 이어서 run_add_fresh_purchase_batch_id.php를 실행하세요. 완료 후 이 파일을 삭제하세요.</p>
</div>
<?php endif; ?>

</body>
</html>
