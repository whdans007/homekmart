-- 근무확정 + 근태기록 마이그레이션
-- office_schedules: 확정 상태 컬럼 추가
ALTER TABLE office_schedules
  ADD COLUMN IF NOT EXISTS confirmed    TINYINT(1)    NOT NULL DEFAULT 0 AFTER period,
  ADD COLUMN IF NOT EXISTS confirmed_at TIMESTAMP     NULL AFTER confirmed,
  ADD COLUMN IF NOT EXISTS confirmed_by INT UNSIGNED  NULL AFTER confirmed_at;

-- office_schedule_items: 근태 컬럼 추가
-- present=출근, sick_leave=병가, vacation=휴가, absent=결근, suspension=정직, early_leave=조퇴
ALTER TABLE office_schedule_items
  ADD COLUMN IF NOT EXISTS attendance
    ENUM('present','sick_leave','vacation','absent','suspension','early_leave')
    NOT NULL DEFAULT 'present' AFTER supervisor_shift_time;
