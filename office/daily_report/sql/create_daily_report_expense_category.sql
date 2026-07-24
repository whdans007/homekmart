-- Design Ref: daily-report.design.md §3.1 — 기타지출 12개 고정 카테고리 배치 저장
-- 금액/내역은 저장하지 않음. er_saved_state.state_json에서 source_item_id로 원본을 조인해 조회한다.
CREATE TABLE IF NOT EXISTS daily_report_expense_category (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  store_id       INT NOT NULL,
  sale_date      DATE NOT NULL,
  source_item_id VARCHAR(20) NOT NULL,   -- er_saved_state item_id 원본 형식 (예: 'p_123','pc_45','e_9','r_7')
  category_key   ENUM(
                   'return','payroll','utilities','pldt_lpg','office_supply',
                   'produce','vehicle','discount5','koreanchamber5',
                   'maintenance','other','points'
                 ) NOT NULL,
  created_by     INT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_item (store_id, sale_date, source_item_id),
  INDEX idx_date (store_id, sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
