-- ============================================================
-- HOME K MART — 오피스 관리 시스템 DB 테이블 생성
-- Database: u622428657_homekmart
-- Created: 2026-04-28
-- ============================================================

-- 1. 상품구매지출 (현금/수표 통합)
CREATE TABLE IF NOT EXISTS office_product_purchases (
  id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  store_id          INT UNSIGNED    NOT NULL,
  payment_type      ENUM('cash','check') NOT NULL,
  supplier_name     VARCHAR(200)    NOT NULL COMMENT '거래처명',
  delivery_content  TEXT            NOT NULL COMMENT '배달상품 내용',
  amount            DECIMAL(15,2)   NOT NULL COMMENT '금액',
  payment_date      DATE            NOT NULL COMMENT '현금:결제일 / 수표:결제예정일',
  check_issued_date DATE            NULL     COMMENT '수표 발행일 (cash는 NULL)',
  created_by        INT UNSIGNED    NULL,
  created_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, payment_date),
  INDEX idx_type (payment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 비품구매지출 (현금)
CREATE TABLE IF NOT EXISTS office_equipment_purchases (
  id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  store_id          INT UNSIGNED    NOT NULL,
  supplier_name     VARCHAR(200)    NOT NULL COMMENT '거래처명',
  delivery_content  TEXT            NOT NULL COMMENT '비품 내용',
  amount            DECIMAL(15,2)   NOT NULL COMMENT '금액',
  payment_date      DATE            NOT NULL COMMENT '결제일',
  created_by        INT UNSIGNED    NULL,
  created_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 직원 등록
-- job_role: cashier=캐쉬어, patcher=파처, butcher=부처, driver=드라이버,
--           merchandiser=머천다이져, supervisor=슈퍼바이저, admin=어드민
CREATE TABLE IF NOT EXISTS office_employees (
  id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED    NOT NULL,
  name       VARCHAR(100)    NOT NULL COMMENT '직원명',
  job_role   ENUM('cashier','patcher','butcher','kitchen','driver',
                  'merchandiser','supervisor','admin') NOT NULL,
  status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_role (store_id, job_role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 휴무 계획서 헤더
-- period: first=1~15일, second=16~말일
CREATE TABLE IF NOT EXISTS office_schedules (
  id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED    NOT NULL,
  year       SMALLINT UNSIGNED NOT NULL,
  month      TINYINT UNSIGNED NOT NULL,
  period     ENUM('first','second') NOT NULL,
  created_by INT UNSIGNED    NULL,
  created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_schedule (store_id, year, month, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 휴무 계획서 상세 (날짜별 근무 배정)
CREATE TABLE IF NOT EXISTS office_schedule_items (
  id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  schedule_id             INT UNSIGNED    NOT NULL,
  schedule_date           DATE            NOT NULL COMMENT '해당 날짜',
  shift                   ENUM('morning','mid','gy') NOT NULL COMMENT '근무 구분',
  job_role                ENUM('cashier','patcher','butcher','kitchen','driver',
                               'merchandiser','supervisor','admin') NOT NULL,
  employee_id             INT UNSIGNED    NULL COMMENT '배정 직원',
  is_off                  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1=휴무',
  replacement_employee_id INT UNSIGNED    NULL COMMENT '대체인원 (is_off=1일때)',
  supervisor_shift_time   ENUM('8AM-8PM','8PM-8AM','11PM~8AM','3PM~12AM','8AM~5PM','8AM~8PM','8PM~8AM') NULL COMMENT '교대시간 (전 직원 공통)',
  INDEX idx_schedule_date (schedule_id, schedule_date),
  FOREIGN KEY (schedule_id) REFERENCES office_schedules(id) ON DELETE CASCADE,
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE SET NULL,
  FOREIGN KEY (replacement_employee_id) REFERENCES office_employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
