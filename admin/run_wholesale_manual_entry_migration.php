<?php
// wholesale_sale_items 수기 입력 지원 마이그레이션 (1회 실행 후 삭제 권장)
require_once __DIR__ . '/partials/header.php';

if ($_SESSION['role'] !== 'super_admin') {
    echo '<div style="font-family:sans-serif;padding:20px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;">';
    echo '<strong>❌ 권한 없음:</strong> super_admin만 실행할 수 있습니다.';
    echo '</div>';
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();
$results = [];

// 각 스텝 실행 (이미 적용된 경우 건너뜀)
$steps = [
    'product_id NULL 허용' => function() use ($conn) {
        // 이미 NULL 허용인지 확인
        $check = $conn->query("
            SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'wholesale_sale_items'
              AND COLUMN_NAME = 'product_id'
        ");
        $row = $check->fetch_assoc();
        if ($row && $row['IS_NULLABLE'] === 'YES') {
            return ['skip' => true, 'msg' => '이미 적용됨'];
        }
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $ok = $conn->query("ALTER TABLE `wholesale_sale_items` MODIFY COLUMN `product_id` int(11) DEFAULT NULL COMMENT '상품 ID (NULL이면 수기 입력 상품)'");
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        return $ok ? ['skip' => false, 'msg' => '완료'] : ['error' => $conn->error];
    },
    'custom_product_name 컬럼 추가' => function() use ($conn) {
        $check = $conn->query("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'wholesale_sale_items'
              AND COLUMN_NAME = 'custom_product_name'
        ");
        $row = $check->fetch_assoc();
        if ($row['cnt'] > 0) {
            return ['skip' => true, 'msg' => '이미 존재함'];
        }
        $ok = $conn->query("ALTER TABLE `wholesale_sale_items` ADD COLUMN `custom_product_name` varchar(255) DEFAULT NULL COMMENT '수기 입력 상품명' AFTER `product_id`");
        return $ok ? ['skip' => false, 'msg' => '완료'] : ['error' => $conn->error];
    },
    'custom_cost_price 컬럼 추가' => function() use ($conn) {
        $check = $conn->query("
            SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'wholesale_sale_items'
              AND COLUMN_NAME = 'custom_cost_price'
        ");
        $row = $check->fetch_assoc();
        if ($row['cnt'] > 0) {
            return ['skip' => true, 'msg' => '이미 존재함'];
        }
        $ok = $conn->query("ALTER TABLE `wholesale_sale_items` ADD COLUMN `custom_cost_price` decimal(10,2) DEFAULT NULL COMMENT '수기 입력 원가' AFTER `custom_product_name`");
        return $ok ? ['skip' => false, 'msg' => '완료'] : ['error' => $conn->error];
    },
];

foreach ($steps as $label => $fn) {
    $results[$label] = $fn();
}

$conn->close();

$hasError = array_filter($results, fn($r) => isset($r['error']));
?>

<div class="container mx-auto px-4 py-8 max-w-2xl">
    <div class="bg-white shadow-sm rounded-lg border p-6">
        <h1 class="text-xl font-semibold text-gray-900 mb-2">
            <i class="fas fa-database mr-2 text-indigo-500"></i>
            수기 입력 마이그레이션
        </h1>
        <p class="text-sm text-gray-500 mb-6">wholesale_sale_items 테이블에 수기 입력 지원 컬럼을 추가합니다.</p>

        <div class="space-y-3">
            <?php foreach ($results as $label => $result): ?>
                <?php if (isset($result['error'])): ?>
                    <div class="flex items-center gap-3 p-3 bg-red-50 border border-red-200 rounded-md">
                        <span class="text-red-500 text-lg">❌</span>
                        <div>
                            <div class="text-sm font-medium text-red-800"><?= htmlspecialchars($label) ?></div>
                            <div class="text-xs text-red-600 mt-1"><?= htmlspecialchars($result['error']) ?></div>
                        </div>
                    </div>
                <?php elseif ($result['skip'] ?? false): ?>
                    <div class="flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-md">
                        <span class="text-gray-400 text-lg">⏭</span>
                        <div>
                            <div class="text-sm font-medium text-gray-600"><?= htmlspecialchars($label) ?></div>
                            <div class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($result['msg']) ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="flex items-center gap-3 p-3 bg-green-50 border border-green-200 rounded-md">
                        <span class="text-green-500 text-lg">✅</span>
                        <div>
                            <div class="text-sm font-medium text-green-800"><?= htmlspecialchars($label) ?></div>
                            <div class="text-xs text-green-600 mt-1"><?= htmlspecialchars($result['msg']) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 pt-4 border-t border-gray-200">
            <?php if ($hasError): ?>
                <div class="p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
                    <strong>마이그레이션 실패.</strong> 위 오류를 확인하세요.
                </div>
            <?php else: ?>
                <div class="p-4 bg-green-50 border border-green-200 rounded-md text-sm text-green-700">
                    <strong>마이그레이션 완료!</strong>
                    이제 도매판매 화면에서 수기 입력 기능을 사용할 수 있습니다.
                    <br><span class="text-xs text-green-600 mt-1 block">보안을 위해 이 파일(<code>run_wholesale_manual_entry_migration.php</code>)을 삭제하세요.</span>
                </div>
                <div class="mt-3">
                    <a href="wholesale_sales.php" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                        <i class="fas fa-arrow-right mr-2"></i>
                        도매판매 화면으로 이동
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
