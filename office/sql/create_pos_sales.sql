-- POS Sales Data Tables
CREATE TABLE IF NOT EXISTS pos_sales_uploads (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED NOT NULL,
  file_name    VARCHAR(255) NOT NULL,
  row_count    INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by  INT NULL,
  uploaded_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store (store_id),
  INDEX idx_uploaded_at (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_sales_data (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  upload_id     INT UNSIGNED NOT NULL,
  row_no        SMALLINT UNSIGNED NOT NULL,
  sale_date     VARCHAR(20)   NULL,
  sale_time     VARCHAR(20)   NULL,
  pos_store_id  VARCHAR(50)   NULL,
  si_no         VARCHAR(50)   NULL,
  item_code     VARCHAR(50)   NULL,
  item_name     VARCHAR(200)  NULL,
  supplier      VARCHAR(100)  NULL,
  department    VARCHAR(100)  NULL,
  box           DECIMAL(10,2) NULL,
  pcs           DECIMAL(10,2) NULL,
  unit_cost     DECIMAL(10,2) NULL,
  total_cost    DECIMAL(10,2) NULL,
  selling_price DECIMAL(10,2) NULL,
  discount      DECIMAL(10,2) NULL,
  total_sales   DECIMAL(10,2) NULL,
  gross_profit  DECIMAL(10,2) NULL,
  sc_discount   DECIMAL(10,2) NULL,
  pwd_discount  DECIMAL(10,2) NULL,
  less_vat      DECIMAL(10,2) NULL,
  net_sales     DECIMAL(10,2) NULL,
  cashier       VARCHAR(100)  NULL,
  payment_form  VARCHAR(50)   NULL,
  INDEX idx_upload  (upload_id),
  INDEX idx_item    (item_code),
  INDEX idx_date    (sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
