-- 직무(job_role)에 'kitchen' 추가
-- office_employees, office_schedule_items 두 테이블의 ENUM 에 'kitchen' 을 butcher 다음 위치로 추가.
ALTER TABLE office_employees
  MODIFY job_role ENUM('cashier','patcher','butcher','kitchen','driver','merchandiser','supervisor','admin') NOT NULL;

ALTER TABLE office_schedule_items
  MODIFY job_role ENUM('cashier','patcher','butcher','kitchen','driver','merchandiser','supervisor','admin') NOT NULL;
