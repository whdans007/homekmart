-- leave panel 시간 옵션 추가 마이그레이션
ALTER TABLE office_schedule_items
  MODIFY COLUMN supervisor_shift_time
    ENUM('8AM-8PM','8PM-8AM','11PM~8AM','3PM~12AM','8AM~5PM','8AM~8PM','8PM~8AM') NULL
    COMMENT '교대시간 (전 직원 공통)';
