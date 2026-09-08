<?php
/**
 * 몰 전용 신선상품(과일/채소/정육/수산물) 무게 주문 마이그레이션 실행 스크립트
 * 설계 근거: docs/01-plan/features/mall-fresh-products.plan.md §8
 * 참고용 원본 DDL: sql/migrations/create_mall_fresh_products.sql (이 파일과 내용을 동기화할 것)
 *
 * 신규 테이블 3개(mall_fresh_products, mall_fresh_product_store_links, fresh_purchase_items) +
 * mall_order_items 컬럼 추가. 기존 products/inventory/purchase_items/mall_products는 무변경.
 *
 * 접속: https://main.homekmart.net/sql/migrations/run_create_mall_fresh_products.php
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

function table_exists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return (bool)($result && $result->num_rows > 0);
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $escapedTable = $conn->real_escape_string($table);
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    return (bool)($result && $result->num_rows > 0);
}

$steps = [
    [
        'label' => 'mall_fresh_products 테이블 생성',
        'already_done' => table_exists($conn, 'mall_fresh_products'),
        'sql' => "CREATE TABLE `mall_fresh_products` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `code` varchar(50) NOT NULL COMMENT '몰 전용 신선상품 코드',
            `name_ko` varchar(255) NOT NULL,
            `name_en` varchar(255) DEFAULT NULL,
            `category_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'categories.id (과일/채소/정육/수산물)',
            `unit_step_g` int(11) NOT NULL DEFAULT 100 COMMENT '주문 단위(그램). 현재 요구사항은 100 고정',
            `price_per_100g` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '몰 판매단가(100g당)',
            `image_url` varchar(255) DEFAULT NULL,
            `status` enum('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `code` (`code`),
            KEY `category_id` (`category_id`),
            CONSTRAINT `mall_fresh_products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='몰 전용 신선상품 대표코드 마스터'",
    ],
    [
        'label' => 'mall_fresh_product_store_links 테이블 생성',
        'already_done' => table_exists($conn, 'mall_fresh_product_store_links'),
        'sql' => "CREATE TABLE `mall_fresh_product_store_links` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `mall_fresh_product_id` int(11) NOT NULL,
            `store_id` int(11) UNSIGNED NOT NULL,
            `store_product_id` int(11) UNSIGNED NOT NULL COMMENT '점포에서 실제 사용하는 products.id',
            `match_source` enum('auto_suggested','manual') NOT NULL DEFAULT 'manual' COMMENT '자동매칭 후보 확정인지 수동 연결인지 기록',
            `linked_by` int(11) UNSIGNED DEFAULT NULL COMMENT 'users.id',
            `linked_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `store_product_unique` (`store_id`,`store_product_id`),
            KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
            CONSTRAINT `mffpl_ibfk_1` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`) ON DELETE CASCADE,
            CONSTRAINT `mffpl_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
            CONSTRAINT `mffpl_ibfk_3` FOREIGN KEY (`store_product_id`) REFERENCES `products` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='점포 상품코드 - 몰 대표 신선코드 매핑'",
    ],
    [
        'label' => 'fresh_purchase_items 테이블 생성',
        'already_done' => table_exists($conn, 'fresh_purchase_items'),
        'sql' => "CREATE TABLE `fresh_purchase_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `store_id` int(11) UNSIGNED NOT NULL,
            `mall_fresh_product_id` int(11) DEFAULT NULL COMMENT '매칭 확정 전에는 NULL 허용',
            `store_product_id` int(11) UNSIGNED NOT NULL COMMENT '매입 시점 점포 상품(products.id)',
            `purchase_date` date NOT NULL,
            `weight_kg` decimal(10,3) NOT NULL COMMENT '실측 매입 중량(kg)',
            `total_cost` decimal(12,2) NOT NULL COMMENT '실제 매입 총액',
            `unit_cost_per_100g` decimal(10,2) NOT NULL COMMENT '저장 시점에 애플리케이션에서 계산: total_cost / (weight_kg*10)',
            `registered_by` int(11) UNSIGNED DEFAULT NULL COMMENT 'users.id',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `store_id` (`store_id`),
            KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
            CONSTRAINT `fpi_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
            CONSTRAINT `fpi_ibfk_2` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fpi_ibfk_3` FOREIGN KEY (`store_product_id`) REFERENCES `products` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='신선상품 전용 매입 실측 원가 (기존 purchase_items 무변경)'",
    ],
    [
        'label' => 'mall_order_items에 신선상품 컬럼 추가',
        'already_done' => column_exists($conn, 'mall_order_items', 'mall_fresh_product_id'),
        'sql' => "ALTER TABLE `mall_order_items`
            ADD COLUMN `mall_fresh_product_id` int(11) DEFAULT NULL COMMENT '신선상품 라인일 때만 값 존재, 정가상품은 NULL' AFTER `product_id`,
            ADD COLUMN `weight_g` int(11) DEFAULT NULL COMMENT '고객이 주문한 요청 그램수(100g 단위)' AFTER `quantity`,
            ADD COLUMN `actual_weight_g` int(11) DEFAULT NULL COMMENT '점포 직원이 준비중 단계에서 입력한 실측 그램수' AFTER `weight_g`,
            ADD COLUMN `estimated_price` decimal(12,2) DEFAULT NULL COMMENT '주문 시점 예상금액(weight_g 기준)' AFTER `actual_weight_g`,
            ADD COLUMN `confirmed_price` decimal(12,2) DEFAULT NULL COMMENT '실측 후 확정금액(배송 시 현금 수령 기준)' AFTER `estimated_price`,
            ADD KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
            ADD CONSTRAINT `mall_order_items_fresh_fk` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`)",
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
            break; // 뒤 단계는 앞 단계(FK 대상 테이블)에 의존하므로 실패 시 중단
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
    <title>mall-fresh-products 마이그레이션</title>
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
    <h1>🛠️ mall-fresh-products 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran): ?>
<div class="card">
    <div class="warn">
        ⚠️ 신선상품 관련 테이블 3개(<code>mall_fresh_products</code>, <code>mall_fresh_product_store_links</code>, <code>fresh_purchase_items</code>)를 생성하고,
        <code>mall_order_items</code>에 컬럼 5개를 추가합니다. 기존 <code>products</code>/<code>inventory</code>/<code>purchase_items</code>/<code>mall_products</code>는 건드리지 않습니다.
    </div>
    <ul class="steps">
        <?php foreach ($steps as $step): ?>
            <li><?php echo htmlspecialchars($step['label']); ?><?php echo $step['already_done'] ? step_badge('SKIP') : ''; ?></li>
        <?php endforeach; ?>
    </ul>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('신선상품 마이그레이션을 실행하시겠습니까?')" style="margin-top:1rem">
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
