-- 점포 주문 리스트 테이블
CREATE TABLE IF NOT EXISTS store_order_lists (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    store_id    INT NOT NULL,
    title       VARCHAR(255) NOT NULL,
    created_by  INT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_id (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 점포 주문 리스트 아이템 테이블
CREATE TABLE IF NOT EXISTS store_order_list_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    order_list_id INT NOT NULL,
    product_id    INT NOT NULL,
    quantity      INT DEFAULT 1,
    remarks       TEXT,
    sort_order    INT DEFAULT 0,
    FOREIGN KEY (order_list_id) REFERENCES store_order_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
