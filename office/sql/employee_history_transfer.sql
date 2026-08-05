-- 참고용 DDL. 실제 생성/컬럼추가는 office/lib/office_helper.php 상단의
-- SHOW COLUMNS/CREATE TABLE IF NOT EXISTS 자동 마이그레이션 블록에서 처리됨.
-- Design Ref: docs/02-design/features/employee-hire-transfer.design.md §2.1

ALTER TABLE office_employees ADD COLUMN hire_date DATE NULL DEFAULT NULL AFTER job_role;

CREATE TABLE IF NOT EXISTS office_employee_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  store_id    INT UNSIGNED NOT NULL COMMENT '기록 시점 소속 점포',
  event_type  ENUM('hire','transfer','role_change','promotion','note') NOT NULL DEFAULT 'note',
  content     VARCHAR(500) NOT NULL,
  event_date  DATE NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_employee_date (employee_id, event_date),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_employee_transfers (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT UNSIGNED NOT NULL,
  from_store_id  INT UNSIGNED NOT NULL,
  to_store_id    INT UNSIGNED NOT NULL,
  reason         VARCHAR(500) NULL,
  status         ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  requested_by   INT UNSIGNED NULL,
  approved_by    INT UNSIGNED NULL,
  decision_note  VARCHAR(500) NULL,
  decided_at     TIMESTAMP NULL DEFAULT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status_to_store (status, to_store_id),
  INDEX idx_employee (employee_id),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
