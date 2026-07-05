-- Design Ref: §2 — sales_daily + sales_transfers tables
CREATE TABLE IF NOT EXISTS sales_daily (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  store_id     INT NOT NULL,
  sale_date    DATE NOT NULL,
  gy_pos1      DECIMAL(12,2) DEFAULT 0,
  gy_pos2      DECIMAL(12,2) DEFAULT 0,
  morning_pos1 DECIMAL(12,2) DEFAULT 0,
  morning_pos2 DECIMAL(12,2) DEFAULT 0,
  mid_pos1     DECIMAL(12,2) DEFAULT 0,
  mid_pos2     DECIMAL(12,2) DEFAULT 0,
  delivery_k   DECIMAL(12,2) DEFAULT 0,
  whole_sale   DECIMAL(12,2) DEFAULT 0,
  notes        TEXT NULL,
  created_by   INT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_store_date (store_id, sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_transfers (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  store_id         INT NOT NULL,
  transfer_date    DATE NOT NULL,
  direction        ENUM('in','out') NOT NULL,
  other_store_id   INT NULL,
  other_store_name VARCHAR(255) NOT NULL,
  amount           DECIMAL(12,2) NOT NULL,
  notes            TEXT NULL,
  created_by       INT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, transfer_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
