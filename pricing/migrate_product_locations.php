<?php
// product_locations 테이블 생성 마이그레이션
// 실행: 브라우저에서 http://서버주소/pricing/migrate_product_locations.php 접속
// 실행 후 이 파일은 삭제해도 됩니다.
require_once __DIR__ . '/../config/db_config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        store_id INT NOT NULL,
        location VARCHAR(100) NOT NULL DEFAULT '',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_product_store (product_id, store_id),
        KEY idx_store (store_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='상품 위치 정보 (점포별)'");

    // 결과 확인
    $exists = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'product_locations'"
    )->fetchColumn();

    echo $exists
        ? "<p style='color:green;font-weight:bold'>✅ product_locations 테이블이 준비되었습니다.</p>"
        : "<p style='color:red'>❌ 테이블 생성에 실패했습니다.</p>";

} catch (Throwable $e) {
    echo "<p style='color:red'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}
