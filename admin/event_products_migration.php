<?php
require_once __DIR__ . '/../config/db_config.php';

$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
$pdo = new PDO($dsn, DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "
CREATE TABLE IF NOT EXISTS event_products (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    store_id         INT NOT NULL,
    product_id       INT NOT NULL,
    event_price      DECIMAL(10,2) NOT NULL,
    original_cost    DECIMAL(10,2) DEFAULT NULL,
    original_selling DECIMAL(10,2) DEFAULT NULL,
    start_date       DATE NOT NULL,
    end_date         DATE NOT NULL,
    remarks          VARCHAR(255) DEFAULT NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_by       INT DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_id (store_id),
    INDEX idx_product_id (product_id),
    INDEX idx_dates (start_date, end_date),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$pdo->exec($sql);
echo "event_products 테이블 생성 완료\n";
