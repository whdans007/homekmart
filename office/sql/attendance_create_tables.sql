-- ============================================================
-- HOME K MART — 출퇴근 기록 시스템 DB 테이블
-- Database: sunset
-- Design Ref: §2 — office_fingerprints + office_attendance_logs
-- ============================================================

-- 1. 지문 슬롯 ↔ 직원 매핑
CREATE TABLE IF NOT EXISTS office_fingerprints (
  id           INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED      NOT NULL,
  employee_id  INT UNSIGNED      NOT NULL,
  finger_slot  TINYINT UNSIGNED  NOT NULL COMMENT '센서 슬롯 번호 (1~127)',
  enrolled_at  TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
  enrolled_by  INT UNSIGNED      NULL,
  UNIQUE KEY uniq_slot     (store_id, finger_slot),
  -- 직원당 최대 2개(지문1/지문2) 등록 가능 (app 로직에서 제한, migrate_fingerprint_multi.php 참조)
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 지문 등록(enroll)/삭제(delete) 요청 — 웹에서 트리거, ESP32가 polling 후 처리
CREATE TABLE IF NOT EXISTS office_fingerprint_enroll_requests (
  id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED  NOT NULL,
  employee_id  INT UNSIGNED  NOT NULL,
  request_type ENUM('enroll','delete') NOT NULL DEFAULT 'enroll',
  finger_slot  TINYINT UNSIGNED NULL COMMENT 'delete 요청 시 삭제할 센서 슬롯 번호',
  status       ENUM('pending','enrolling','success','failed') NOT NULL DEFAULT 'pending',
  result_slot  TINYINT UNSIGNED NULL COMMENT '등록 성공 시 할당된 센서 슬롯 번호',
  error_message VARCHAR(100) NULL,
  requested_by INT UNSIGNED  NULL,
  created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_status (store_id, status),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 타임펀치 이벤트 로그
CREATE TABLE IF NOT EXISTS office_attendance_logs (
  id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED  NOT NULL,
  employee_id  INT UNSIGNED  NOT NULL,
  event_type   ENUM('clock_in','break_start','break_end','clock_out') NOT NULL,
  event_time   DATETIME      NOT NULL,
  source       ENUM('device','manual') NOT NULL DEFAULT 'device',
  device_id    VARCHAR(50)   NULL COMMENT 'ESP32 MAC 주소',
  created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_emp_date (store_id, employee_id, event_time),
  INDEX idx_store_date     (store_id, event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
