-- 참고용 DDL. 실제 생성은 office/lib/office_helper.php 상단의
-- CREATE TABLE IF NOT EXISTS 자동 마이그레이션 블록에서 처리됨.
-- Design Ref: docs/02-design/features/employee-hr-records.design.md §3.3

CREATE TABLE IF NOT EXISTS office_employee_records (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id       INT UNSIGNED NOT NULL,
  store_id          INT UNSIGNED NOT NULL COMMENT '기록 시점 소속 점포',
  event_type        ENUM('warning','late','absence','other') NOT NULL DEFAULT 'warning',
  category          VARCHAR(100) NULL COMMENT '사유 분류',
  content           VARCHAR(500) NULL COMMENT '상세 내용',
  points            INT NOT NULL DEFAULT 0 COMMENT '감점 (향후 인사평가용, 음수 권장)',
  attachment_files  JSON NULL COMMENT '첨부 이미지 파일명 배열',
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_employee_created (employee_id, created_at),
  INDEX idx_store_type (store_id, event_type),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
