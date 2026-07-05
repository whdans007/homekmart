-- Design Ref: §2.1 — office_receipts table for receipt management
CREATE TABLE IF NOT EXISTS office_receipts (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  store_id             INT NOT NULL,
  supplier_name        VARCHAR(255) NOT NULL,
  description          TEXT,
  amount               DECIMAL(12,2) NOT NULL,
  receipt_date         DATE NOT NULL,
  file_path            VARCHAR(500) NULL,
  file_original_name   VARCHAR(255) NULL,
  file_mime            VARCHAR(100) NULL,
  notes                TEXT NULL,
  -- Plan SC: 사용 상태 추적 (SC7, SC9, SC10)
  linked_purchase_type ENUM('product','equipment') NULL,
  linked_purchase_id   INT NULL,
  used_at              TIMESTAMP NULL,
  created_by           INT NULL,
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, receipt_date),
  INDEX idx_unused     (store_id, linked_purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
