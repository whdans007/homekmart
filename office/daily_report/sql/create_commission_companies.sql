-- Design Ref: daily-report.design.md §4.2 Out of Scope 재검토 — 수수료 코너 업체 등록
-- 점포별로 수수료 코너(입점업체)를 등록해두면, Daily Report에서 해당 업체명과
-- pos_sales_data.supplier가 정확히 일치하는 날짜별 매출(NET SALES)을 자동 집계해 보여준다.
CREATE TABLE IF NOT EXISTS daily_report_commission_companies (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id      INT UNSIGNED NOT NULL,
  supplier_name VARCHAR(100) NOT NULL COMMENT 'pos_sales_data.supplier와 정확히 일치해야 매출이 집계됨',
  created_by    INT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_store_supplier (store_id, supplier_name),
  INDEX idx_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
