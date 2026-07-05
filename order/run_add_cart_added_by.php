<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/auth.php';
ord_require_manager();
try {
    $conn = get_ord_db();
    $res = $conn->query("SHOW COLUMNS FROM order_cart_items LIKE 'added_by'");
    if ($res->num_rows === 0) {
        $conn->query("ALTER TABLE order_cart_items ADD COLUMN added_by INT NULL AFTER quantity");
        echo "✅ added_by 컬럼 추가 완료.";
    } else {
        echo "ℹ️ 이미 존재합니다.";
    }
    $conn->close();
} catch (Exception $e) {
    echo "❌ 오류: " . htmlspecialchars($e->getMessage());
}
