-- Line items for Delivery K and Whole Sale (multiple entries per day)
CREATE TABLE IF NOT EXISTS sales_daily_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  store_id    INT NOT NULL,
  sale_date   DATE NOT NULL,
  item_type   ENUM('delivery_k','whole_sale') NOT NULL,
  description VARCHAR(255) NULL DEFAULT '',
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_date_type (store_id, sale_date, item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
