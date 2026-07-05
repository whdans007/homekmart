<?php
// 마이그레이션 실행 스크립트: lc_products에 barcode 컬럼 추가
// 브라우저에서 실행: http://192.168.1.116/logistics/sql/run_add_barcode_column.php
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

try {
    $conn = get_lc_db();

    // 이미 컬럼이 있는지 확인
    $check = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lc_products' AND COLUMN_NAME = 'barcode'");
    $exists = (bool)$check->fetch_row()[0];

    if ($exists) {
        echo "[SKIP] lc_products.barcode 컬럼이 이미 존재합니다.\n";
    } else {
        $conn->query("ALTER TABLE lc_products
            ADD COLUMN barcode VARCHAR(100) NULL AFTER name_ko");
        echo "[OK] barcode 컬럼 추가 완료.\n";

        $conn->query("ALTER TABLE lc_products ADD INDEX idx_barcode (barcode)");
        echo "[OK] idx_barcode 인덱스 생성 완료.\n";
    }

    $conn->close();
    echo "\n마이그레이션 완료!\n";
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
?>
