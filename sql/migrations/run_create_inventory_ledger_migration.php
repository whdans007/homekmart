<?php
/**
 * inventory_ledger 테이블 생성 마이그레이션
 * Design Ref: docs/02-design/features/unified-inventory-reference-store.design.md §2.3
 *
 * 매입/POS/몰/도매/크레딧/이동/폐기/조정 등 모든 재고 증감을 append-only로 기록하는
 * 공통 재고 원장. 실제 점포 재고 기준은 여전히 inventory(product_id, store_id)이며,
 * 이 테이블은 변경 이력과 중복 방지(idempotency)를 담당한다(Plan §4.3, §4.4).
 *
 * quantity_change/quantity_after는 inventory.quantity, inventory_expirations.quantity와
 * 동일하게 DECIMAL(소숫점 둘째자리)로 저장한다 — Design 문서 초안은 INT였으나 실제
 * inventory 테이블이 DECIMAL(10,2)이라 여기서 정밀도를 맞춘다(정수 낱개 수량도 문제 없이 저장됨).
 *
 * UNIQUE KEY (source_type, source_id, store_id, product_id, event_type)로 동일 원본 거래의
 * 중복 반영을 DB 레벨에서도 차단한다. 신규 테이블이라 기존 데이터 중복 검사는 필요 없다
 * (Design §2.3 "복합 UNIQUE 적용 전 기존 데이터 중복 여부를 검사한다"는 기존 테이블 전환 시 주의사항).
 *
 * FK는 의도적으로 걸지 않는다 — 원장은 append-only 감사 기록이므로 상품/점포 행이 훗날
 * 삭제되더라도 이력 자체는 남아야 한다(Design §1 원칙 4).
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_create_inventory_ledger_migration.php
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

function mil_table_exists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return (bool)($result && $result->num_rows > 0);
}

$create_sql = "CREATE TABLE `inventory_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `quantity_change` decimal(12,2) NOT NULL COMMENT '음수 허용(출고/취소), 소숫점 둘째자리',
  `quantity_after` decimal(12,2) NOT NULL COMMENT '반영 후 inventory.quantity 스냅샷, 음수 허용',
  `event_type` varchar(40) NOT NULL COMMENT 'PURCHASE_IN/POS_OUT/MALL_OUT/... (Design §2.3)',
  `source_type` varchar(40) NOT NULL COMMENT '원본 거래 종류 (purchase/pos_upload/mall_order/... )',
  `source_id` bigint unsigned NOT NULL COMMENT '원본 거래 PK',
  `reference_history_id` int unsigned NULL DEFAULT NULL COMMENT 'mall_reference_store_history.id (발생 시점 기준 점포 정책 참조용, 거래 store_id는 변경하지 않음)',
  `occurred_at` datetime NOT NULL,
  `user_id` int unsigned NULL DEFAULT NULL,
  `remarks` varchar(500) NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ledger_source` (`source_type`, `source_id`, `store_id`, `product_id`, `event_type`),
  KEY `idx_ledger_product_store_time` (`product_id`, `store_id`, `occurred_at`),
  KEY `idx_ledger_source` (`source_type`, `source_id`),
  KEY `idx_ledger_event` (`event_type`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='통합 재고 원장 (Plan/Design: unified-inventory-reference-store)'";

$steps = [
    [
        'label' => 'inventory_ledger 테이블 생성 (UNIQUE 중복방지 키 포함)',
        'already_done' => mil_table_exists($conn, 'inventory_ledger'),
        'sql' => $create_sql,
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

function mil_step_badge(string $status): string
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
    <title>inventory_ledger 마이그레이션</title>
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
    <h1>🛠️ inventory_ledger 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ 통합 재고 원장 테이블을 생성합니다. 기존 <code>inventory</code>, <code>inventory_expirations</code> 데이터는 건드리지 않습니다.
    </div>
    <ul class="steps">
        <?php foreach ($steps as $step): ?>
            <li><?php echo htmlspecialchars($step['label']); ?><?php echo $step['already_done'] ? mil_step_badge('SKIP') : ''; ?></li>
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
                    <?php echo mil_step_badge($result['status']); ?>
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
