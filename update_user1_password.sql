-- id=1 사용자 비밀번호 복원
-- 백업 파일: pre_restore_backup_2025-11-27_16-49-08.sql에서 추출한 비밀번호 해시

UPDATE users
SET password = '$2y$10$2wwSxmCf9dL6DfftboQYGuKBTjZMXVTAovTW2/55khoYWRsCzU1tS'
WHERE id = 1;

-- 업데이트 결과 확인
SELECT id, username, full_name, email, role, store_id
FROM users
WHERE id = 1;
