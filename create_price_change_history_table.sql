-- 가격변경 이력 테이블 생성
CREATE TABLE IF NOT EXISTS price_change_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    store_id INT NULL,
    old_cost_price DECIMAL(10,2) NULL,
    new_cost_price DECIMAL(10,2) NULL,
    old_selling_price DECIMAL(10,2) NULL,
    new_selling_price DECIMAL(10,2) NULL,
    old_margin_rate DECIMAL(5,2) NULL,
    new_margin_rate DECIMAL(5,2) NULL,
    change_type ENUM('cost_only', 'selling_only', 'both', 'margin_adjust') NOT NULL DEFAULT 'both',
    change_reason VARCHAR(255) NULL,
    purchase_id VARCHAR(50) NULL COMMENT '매입이력에서 변경된 경우 매입ID',
    changed_by_user_id INT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_product_id (product_id),
    INDEX idx_store_id (store_id),
    INDEX idx_changed_at (changed_at),
    INDEX idx_changed_by_user_id (changed_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 가격변경 이력';