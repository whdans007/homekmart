-- whdans007 사용자 정보 확인
SELECT id, username, full_name, email, role, store_id, created_at, updated_at
FROM users
WHERE username = 'whdans007';

-- store_id를 1로 업데이트
UPDATE users
SET store_id = 1
WHERE username = 'whdans007';

-- 업데이트 확인
SELECT id, username, full_name, email, role, store_id
FROM users
WHERE username = 'whdans007';
