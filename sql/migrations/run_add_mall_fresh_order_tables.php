<?php
/**
 * 신선상품 고객 주문(100g 단위/개수 단위) 기능을 위한 신규 테이블 2종 마이그레이션
 * 설계 근거: docs/02-design/features/mall-fresh-products.design.md §3
 *
 * mall_cart_items / mall_order_items / mall_orders 등 기존 정가상품 테이블은 전혀 건드리지 않는다.
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_add_mall_fresh_order_tables.php
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

function mfot_table_exists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return (bool)($result && $result->num_rows > 0);
}

$steps = [
    [
        'label' => 'mall_fresh_cart_items 테이블 생성 (신선상품 전용 장바구니)',
        'already_done' => mfot_table_exists($conn, 'mall_fresh_cart_items'),
        'sql' => "CREATE TABLE `mall_fresh_cart_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `member_id` int(11) DEFAULT NULL,
            `guest_token` varchar(64) DEFAULT NULL,
            `mall_fresh_product_id` int(11) NOT NULL,
            `weight_g` int(11) DEFAULT NULL COMMENT 'sale_type=weight일 때만 값 존재(100g 단위)',
            `quantity` int(11) DEFAULT NULL COMMENT 'sale_type=piece일 때만 값 존재(개수)',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `member_product` (`member_id`,`mall_fresh_product_id`),
            UNIQUE KEY `guest_product` (`guest_token`,`mall_fresh_product_id`),
            KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
            CONSTRAINT `mfci_ibfk_1` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='신선상품 전용 장바구니(정가상품 mall_cart_items와 완전 분리)'",
    ],
    [
        'label' => 'mall_fresh_order_items 테이블 생성 (신선상품 전용 주문 항목)',
        'already_done' => mfot_table_exists($conn, 'mall_fresh_order_items'),
        'sql' => "CREATE TABLE `mall_fresh_order_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `order_id` int(11) NOT NULL,
            `mall_fresh_product_id` int(11) NOT NULL,
            `product_name_snapshot` varchar(255) NOT NULL,
            `sale_type_snapshot` enum('piece','weight') NOT NULL,
            `unit_price_snapshot` decimal(10,2) NOT NULL COMMENT 'weight: 100g당 단가 / piece: 개당 고정가',
            `weight_g` int(11) DEFAULT NULL COMMENT '고객 요청 그램수(주문 시점, weight만)',
            `actual_weight_g` int(11) DEFAULT NULL COMMENT '준비중 실측 그램수(weight만, 입력 전 NULL)',
            `quantity` int(11) DEFAULT NULL COMMENT '개수(piece만)',
            `estimated_price` decimal(12,2) NOT NULL COMMENT '주문 시점 예상금액',
            `confirmed_price` decimal(12,2) DEFAULT NULL COMMENT 'weight: 실측 후 확정 / piece: INSERT 시점에 즉시 채움',
            `is_sold_out` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `order_id` (`order_id`),
            KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
            CONSTRAINT `mfoi_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE,
            CONSTRAINT `mfoi_ibfk_2` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='신선상품 전용 주문 항목(가격 스냅샷). mall_order_items와 완전 분리'",
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
    <title>신선상품 주문 테이블 마이그레이션</title>
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
    <h1>🛠️ 신선상품 주문 테이블 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ <code>mall_fresh_cart_items</code>, <code>mall_fresh_order_items</code> 테이블을 신규 생성합니다. 기존 <code>mall_cart_items</code>/<code>mall_order_items</code>/<code>mall_orders</code>는 전혀 변경되지 않습니다.
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
