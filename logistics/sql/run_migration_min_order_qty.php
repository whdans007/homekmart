<?php
// Migration: lc_products.min_order_qty 추가
// 점포 주문 시 최소 주문 수량(배수 단위, 기본 1)
// 실행: 브라우저(https://homekmart.net/logistics/sql/run_migration_min_order_qty.php) 또는 CLI. 재실행 안전.
ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../../config/db_config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre>\n";
echo "=== Migration: lc_products.min_order_qty 추가 ===\n\n";

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset(DB_CHARSET);

    $column_exists = function (mysqli $conn): bool {
        $res = $conn->query("SHOW COLUMNS FROM `lc_products` LIKE 'min_order_qty'");
        return $res && $res->num_rows > 0;
    };

    $before = $column_exists($conn);
    echo '[적용 전] lc_products.min_order_qty 컬럼: ' . ($before ? 'Y' : 'N') . "\n";

    if ($before) {
        echo "SKIP: min_order_qty 컬럼이 이미 존재합니다.\n";
    } else {
        $conn->query("
            ALTER TABLE lc_products
            ADD COLUMN min_order_qty INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '최소 주문 수량(배수 단위)'
        ");
        echo "OK: lc_products.min_order_qty 추가 완료 (기본값 1)\n";
    }

    $after = $column_exists($conn);
    echo '[적용 후] lc_products.min_order_qty 컬럼: ' . ($after ? 'Y' : 'N') . "\n";
    echo $after ? "\n=== 완료 ===\n" : "\n=== 실패: 컬럼이 생성되지 않았습니다 ===\n";

    $conn->close();
} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
}
echo "</pre>\n";
