-- 캐쉬어 야간 교대시간(11PM~8AM) ENUM 추가 마이그레이션
ALTER TABLE office_schedule_items
  MODIFY COLUMN supervisor_shift_time
    ENUM('8AM-8PM','8PM-8AM','11PM~8AM') NULL
    COMMENT '교대시간 (슈퍼바이저/캐쉬어)';
