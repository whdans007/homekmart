<?php
/**
 * mall_reference_store_history 테이블 생성 마이그레이션
 * Design Ref: docs/02-design/features/unified-inventory-reference-store.design.md §2.1
 *
 * 쇼핑몰 가격/재고/품절 자동화가 기준으로 삼는 점포(Reference Store)의 변경 이력을 기록한다.
 * 실제 매입/판매/이동 등 거래는 항상 거래 자체의 store_id를 쓰고, 이 테이블은 몰 조회 시점의
 * 기준 점포만 추적한다(Plan §4.1). 테이블이 비어 있으면 기존 MALL_STORE_ID를 최초 기준 점포로
 * 소급 적용되도록 아주 이른 시각(1970-01-01)부터 열린 이력 행 1개를 시드한다.
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_create_mall_reference_store_history_migration.php
 * 이미 적용되어 있으면 SKIP 처리되어 여러 번 실행해도 안전하다(idempotent).
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../mall/config/mall_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

function mrsh_table_exists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return (bool)($result && $result->num_rows > 0);
}

$table_already_exists = mrsh_table_exists($conn, 'mall_reference_store_history');

$create_sql = "CREATE TABLE `mall_reference_store_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `store_id` int unsigned NOT NULL,
  `effective_from` datetime NOT NULL,
  `effective_to` datetime NULL DEFAULT NULL,
  `changed_by` int unsigned NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reference_effective` (`effective_from`, `effective_to`),
  KEY `idx_reference_store` (`store_id`),
  CONSTRAINT `mrsh_fk_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `mrsh_fk_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Reference Store 변경 이력 (Plan/Design: unified-inventory-reference-store)'";

$steps = [];
$steps[] = [
    'label' => 'mall_reference_store_history 테이블 생성',
    'already_done' => $table_already_exists,
    'sql' => $create_sql,
];

$ran = false;
$results = [];
$seed_status = null;
$seed_error = '';

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

    // 시드: 테이블에 행이 하나도 없을 때만, 기존 MALL_STORE_ID를 기준으로 1970-01-01부터 열린
    // 이력 행을 만든다. 과거 어떤 시점을 조회해도 이 초기값으로 귀속되게 하기 위함이다.
    $count_result = $conn->query("SELECT COUNT(*) AS cnt FROM `mall_reference_store_history`");
    $row_count = $count_result ? (int)$count_result->fetch_assoc()['cnt'] : -1;

    if ($row_count === 0) {
        $default_store_id = (int)MALL_STORE_ID;
        $seed_stmt = $conn->prepare(
            "INSERT INTO mall_reference_store_history (store_id, effective_from, effective_to, changed_by) VALUES (?, '1970-01-01 00:00:00', NULL, NULL)"
        );
        if ($seed_stmt) {
            $seed_stmt->bind_param('i', $default_store_id);
            if ($seed_stmt->execute()) {
                $seed_status = 'OK';
            } else {
                $seed_status = 'ERROR';
                $seed_error = $seed_stmt->error;
            }
            $seed_stmt->close();
        } else {
            $seed_status = 'ERROR';
            $seed_error = $conn->error;
        }
    } else {
        $seed_status = 'SKIP';
    }
}

$conn->close();

function mrsh_step_badge(string $status): string
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
    <title>mall_reference_store_history 마이그레이션</title>
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
    <h1>🛠️ mall_reference_store_history 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ Reference Store 변경 이력 테이블을 생성하고, 비어 있으면 기존 기준 점포(<code>MALL_STORE_ID = <?php echo (int)MALL_STORE_ID; ?></code>)로 초기 이력 1건을 시드합니다.
    </div>
    <ul class="steps">
        <?php foreach ($steps as $step): ?>
            <li><?php echo htmlspecialchars($step['label']); ?><?php echo $step['already_done'] ? mrsh_step_badge('SKIP') : ''; ?></li>
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
                    <?php echo mrsh_step_badge($result['status']); ?>
                </div>
                <?php if ($result['error']): ?><div class="err-msg"><?php echo htmlspecialchars($result['error']); ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
        <li style="flex-direction:column;align-items:stretch">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <span>초기 이력 시드(MALL_STORE_ID 기준)</span>
                <?php echo mrsh_step_badge($seed_status ?? 'ERROR'); ?>
            </div>
            <?php if ($seed_error): ?><div class="err-msg"><?php echo htmlspecialchars($seed_error); ?></div><?php endif; ?>
        </li>
    </ul>
    <p class="sub" style="margin-top:1rem">⚠️ 모두 OK/SKIP이면 완료된 것입니다. 보안을 위해 이 파일을 삭제하세요.</p>
</div>
<?php endif; ?>

</body>
</html>
