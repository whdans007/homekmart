-- Design Ref: §2.2 — POS 결제수단 상세 입력 + 현금 정산 테이블 (pos-payment-detail)
-- 셀 = (store_id, sale_date, shift, pos_no). shift: gy/morning/mid, pos_no: 1/2

-- ① 현금 권종 카운트 (셀 단위, 권종 1행)
CREATE TABLE IF NOT EXISTS sales_pos_cash_count (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  store_id     INT NOT NULL,
  sale_date    DATE NOT NULL,
  shift        ENUM('gy','morning','mid') NOT NULL,
  pos_no       TINYINT NOT NULL,
  denomination DECIMAL(7,2) NOT NULL,        -- 1000 / 500 / 100 / 50 / 20 / 10 / 5 / 1
  qty          INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ② 기타결제 라인 (Credit/Debit Card, Gcash, PayMaya, PhQR + 수기) — 셀 단위 다중 행
CREATE TABLE IF NOT EXISTS sales_pos_payment (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  store_id    INT NOT NULL,
  sale_date   DATE NOT NULL,
  shift       ENUM('gy','morning','mid') NOT NULL,
  pos_no      TINYINT NOT NULL,
  method      VARCHAR(30) NOT NULL,          -- credit_card / debit_card / gcash / paymaya / phqr / 수기 라벨
  description VARCHAR(255) NULL DEFAULT '',
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ③ 지출 (Expenses, DETAIL/PRICE) — 셀 단위 다중 행
CREATE TABLE IF NOT EXISTS sales_pos_expense (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  store_id    INT NOT NULL,
  sale_date   DATE NOT NULL,
  shift       ENUM('gy','morning','mid') NOT NULL,
  pos_no      TINYINT NOT NULL,
  detail      VARCHAR(255) NOT NULL DEFAULT '',
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ④ Whole Sale 선택 (admin 입력분 참조 — 직접 입력 아님)
CREATE TABLE IF NOT EXISTS sales_pos_wholesale_pick (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  store_id     INT NOT NULL,
  sale_date    DATE NOT NULL,
  shift        ENUM('gy','morning','mid') NOT NULL,
  pos_no       TINYINT NOT NULL,
  source_type  ENUM('wholesale','delivery_k','credit') NOT NULL,  -- REMARK 구분
  source_id    INT NOT NULL,                 -- wholesale_sales.id 또는 sales_daily_items.id
  client       VARCHAR(255) NULL DEFAULT '',
  remark       VARCHAR(255) NULL DEFAULT '',
  amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ⑤ 셀 정산 요약 (1셀 1행, upsert)
CREATE TABLE IF NOT EXISTS sales_pos_reconciliation (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  store_id        INT NOT NULL,
  sale_date       DATE NOT NULL,
  shift           ENUM('gy','morning','mid') NOT NULL,
  pos_no          TINYINT NOT NULL,
  cash_total      DECIMAL(12,2) NOT NULL DEFAULT 0,   -- 권종 합계
  other_total     DECIMAL(12,2) NOT NULL DEFAULT 0,   -- 기타결제 합계
  wholesale_total DECIMAL(12,2) NOT NULL DEFAULT 0,   -- Whole Sale 선택 합계
  expense_total   DECIMAL(12,2) NOT NULL DEFAULT 0,   -- 지출 합계
  starting_money  DECIMAL(12,2) NOT NULL DEFAULT 0,   -- ₱100 우선 배분 (목표 10,000)
  deposit_cash    DECIMAL(12,2) NOT NULL DEFAULT 0,   -- cash_total - starting_money
  expected_cash   DECIMAL(12,2) NULL,                 -- 마감 현금 기대치
  over_short      DECIMAL(12,2) NULL,                 -- deposit_cash - expected_cash (+/-)
  shortage_flag   TINYINT(1) NOT NULL DEFAULT 0,      -- 준비금 부족 경고
  total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,   -- 셀 Total (cash + other + wholesale)
  created_by      INT NULL,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cell (store_id, sale_date, shift, pos_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
