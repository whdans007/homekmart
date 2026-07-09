<?php
// 도매판매 반품 기능 마이그레이션 (1회 실행 후 삭제 권장)
// Design Ref: docs/02-design/features/wholesale-sales-return.design.md §3.1
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

function column_exists($conn, $table, $column) {
    $table_esc = $conn->real_escape_string($table);
    $column_esc = $conn->real_escape_string($column);
    $check = $conn->query("
        SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_esc}' AND COLUMN_NAME = '{$column_esc}'
    ");
    $row = $check ? $check->fetch_assoc() : ['cnt' => 0];
    return $row['cnt'] > 0;
}

function table_exists($conn, $table) {
    $table_esc = $conn->real_escape_string($table);
    $check = $conn->query("SHOW TABLES LIKE '{$table_esc}'");
    return $check && $check->num_rows > 0;
}

// $conn->query()를 직접 감싸 mysqli 예외(PHP 8.1+ 기본 예외 모드)와
// query()가 false를 반환하는 구모드(예외 미사용) 두 경우 모두 안전하게 처리한다.
function run_query($conn, $sql) {
    try {
        $ok = $conn->query($sql);
        if (!$ok) {
            return ['error' => $conn->error];
        }
        return ['skip' => false, 'msg' => '완료'];
    } catch (\mysqli_sql_exception $e) {
        return ['error' => $e->getMessage() . ' (code ' . $e->getCode() . ')'];
    } catch (\Throwable $e) {
        return ['error' => get_class($e) . ': ' . $e->getMessage()];
    }
}

$steps = [
    'wholesale_sales.returned_amount 컬럼 추가' => function() use ($conn) {
        if (column_exists($conn, 'wholesale_sales', 'returned_amount')) {
            return ['skip' => true, 'msg' => '이미 존재함'];
        }
        return run_query($conn, "ALTER TABLE `wholesale_sales` ADD COLUMN `returned_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '누적 반품 금액' AFTER `final_amount`");
    },
    'wholesale_sales.return_status 컬럼 추가' => function() use ($conn) {
        if (column_exists($conn, 'wholesale_sales', 'return_status')) {
            return ['skip' => true, 'msg' => '이미 존재함'];
        }
        return run_query($conn, "ALTER TABLE `wholesale_sales` ADD COLUMN `return_status` ENUM('none','partial','full') NOT NULL DEFAULT 'none' COMMENT '반품 상태' AFTER `returned_amount`");
    },
    'wholesale_sale_items.returned_quantity 컬럼 추가' => function() use ($conn) {
        if (column_exists($conn, 'wholesale_sale_items', 'returned_quantity')) {
            return ['skip' => true, 'msg' => '이미 존재함'];
        }
        return run_query($conn, "ALTER TABLE `wholesale_sale_items` ADD COLUMN `returned_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '누적 반품 수량' AFTER `quantity`");
    },
    'wholesale_sale_returns 테이블 생성' => function() use ($conn) {
        if (table_exists($conn, 'wholesale_sale_returns')) {
            return ['skip' => true, 'msg' => '이미 존재함'];
        }
        return run_query($conn, "
            CREATE TABLE `wholesale_sale_returns` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `sale_id` INT(11) NOT NULL COMMENT '원 판매 ID',
              `reason` VARCHAR(255) DEFAULT NULL COMMENT '반품 사유',
              `total_amount` DECIMAL(12,2) NOT NULL COMMENT '이 반품 건의 총 금액',
              `processed_by` INT(11) UNSIGNED NOT NULL COMMENT '처리자 user_id',
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_wsr_sale` (`sale_id`),
              CONSTRAINT `wsr_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `wholesale_sales` (`id`) ON DELETE CASCADE,
              CONSTRAINT `wsr_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매판매 반품 헤더'
        ");
    },
    'wholesale_sale_return_items 테이블 생성' => function() use ($conn) {
        if (table_exists($conn, 'wholesale_sale_return_items')) {
            return ['skip' => true, 'msg' => '이미 존재함'];
        }
        return run_query($conn, "
            CREATE TABLE `wholesale_sale_return_items` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `return_id` INT(11) NOT NULL COMMENT '반품 헤더 ID',
              `sale_item_id` INT(11) NOT NULL COMMENT '원 판매 품목 ID',
              `quantity` DECIMAL(10,2) NOT NULL COMMENT '반품 수량',
              `unit_price` DECIMAL(10,2) NOT NULL COMMENT '반품 시점 단가 스냅샷',
              `amount` DECIMAL(12,2) NOT NULL COMMENT '반품 금액',
              `restocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '재고 복원 여부 (수기 품목=0)',
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              INDEX `idx_wsri_return` (`return_id`),
              INDEX `idx_wsri_sale_item` (`sale_item_id`),
              CONSTRAINT `wsri_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `wholesale_sale_returns` (`id`) ON DELETE CASCADE,
              CONSTRAINT `wsri_ibfk_2` FOREIGN KEY (`sale_item_id`) REFERENCES `wholesale_sale_items` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매판매 반품 품목'
        ");
    },
];

foreach ($steps as $label => $fn) {
    try {
        $results[$label] = $fn();
    } catch (\Throwable $e) {
        $results[$label] = ['error' => get_class($e) . ': ' . $e->getMessage()];
    }
}

$conn->close();

$hasError = array_filter($results, fn($r) => isset($r['error']));
?>

<div class="container mx-auto px-4 py-8 max-w-2xl">
    <div class="bg-white shadow-sm rounded-lg border p-6">
        <h1 class="text-xl font-semibold text-gray-900 mb-2">
            <i class="fas fa-database mr-2 text-indigo-500"></i>
            도매판매 반품 기능 마이그레이션
        </h1>
        <p class="text-sm text-gray-500 mb-6">wholesale_sales / wholesale_sale_items 컬럼 추가 및 반품 테이블 생성.</p>

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
                    이제 도매판매 미리보기 화면에서 반품 기능을 사용할 수 있습니다.
                    <br><span class="text-xs text-green-600 mt-1 block">보안을 위해 이 파일(<code>run_wholesale_sale_return_migration.php</code>)을 삭제하세요.</span>
                </div>
                <div class="mt-3">
                    <a href="wholesale_sales_list.php" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                        <i class="fas fa-arrow-right mr-2"></i>
                        도매판매 목록으로 이동
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
