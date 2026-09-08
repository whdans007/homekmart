-- Design Ref: §2 — sales_daily + sales_transfers tables
CREATE TABLE IF NOT EXISTS sales_daily (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  store_id     INT NOT NULL,
  sale_date    DATE NOT NULL,
  -- Design Ref: homekmart-store-config §3.4 — 아래 6개 컬럼은 deprecated.
  -- 유일한 소비자였던 pos2_entry.php/ajax_save_sales.php가 삭제되어 읽기/쓰기 모두 없음.
  -- 권위 소스는 sales_pos_reconciliation. 컬럼 자체는 DROP하지 않고 남겨둠(회귀 위험 최소화).
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
