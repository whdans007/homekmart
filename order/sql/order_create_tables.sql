-- 업체별 주문 요청 시스템 테이블
-- Design Ref: §4.2 — 6개 테이블 DDL

CREATE TABLE IF NOT EXISTS order_vendors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  contact_info VARCHAR(200),
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_vendor_column_maps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  sheet_index INT DEFAULT 0,
  header_row INT DEFAULT 1,
  product_name_col VARCHAR(5) NOT NULL,
  product_name_en_col VARCHAR(5),
  quantity_col VARCHAR(5) NOT NULL,
  unit_price_col VARCHAR(5),
  unit_price_pcs_col VARCHAR(5),
  remark_col VARCHAR(5),
  expiry_col VARCHAR(5),
  notes TEXT,
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_vendor_inventories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filepath VARCHAR(500) NOT NULL,
  upload_date DATE NOT NULL,
  uploaded_by INT,
  row_count INT DEFAULT 0,
  is_current TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_vendor_inventory_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT NOT NULL,
  vendor_id INT NOT NULL,
  `row_number` INT NOT NULL,
  product_name VARCHAR(500) NOT NULL,
  product_name_en VARCHAR(500),
  product_code VARCHAR(100),
  unit_price DECIMAL(10,2),
  unit_price_pcs DECIMAL(10,2),
  remark VARCHAR(500),
  expiry_date VARCHAR(50),
  extra_data JSON,
  FOREIGN KEY (inventory_id) REFERENCES order_vendor_inventories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_cart_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,
  vendor_id INT NOT NULL,
  inventory_item_id INT NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_cart_item (store_id, inventory_item_id),
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id),
  FOREIGN KEY (inventory_item_id) REFERENCES order_vendor_inventory_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  store_id INT NOT NULL,
  vendor_id INT NOT NULL,
  vendor_name VARCHAR(100) NOT NULL,
  ordered_by INT,
  order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  items_json JSON NOT NULL,
  item_count INT DEFAULT 0,
  inventory_id INT,
  FOREIGN KEY (vendor_id) REFERENCES order_vendors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
