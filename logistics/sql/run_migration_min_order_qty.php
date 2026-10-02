<?php
/**
 * 물류센터 DB 마이그레이션: lc_products.min_order_qty 추가
 * 점포 주문 시 최소 주문 수량(배수 단위, 기본 1)
 * 실행: php logistics/sql/run_migration_min_order_qty.php  (로컬 DB 전용)
 */
require_once __DIR__ . '/../../config/db_config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$exists = $conn->query("
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lc_products' AND COLUMN_NAME = 'min_order_qty'
")->num_rows > 0;

if ($exists) {
    $msg = ['SKIP', 'min_order_qty 컬럼이 이미 존재합니다.'];
} elseif ($conn->query("
    ALTER TABLE lc_products
    ADD COLUMN min_order_qty INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '최소 주문 수량(배수 단위)'
")) {
    $msg = ['OK', 'lc_products.min_order_qty 추가 완료 (기본값 1)'];
} else {
    $msg = ['ERROR', 'ALTER 실패: ' . $conn->error];
}
$conn->close();

echo "[{$msg[0]}] {$msg[1]}" . PHP_EOL;
