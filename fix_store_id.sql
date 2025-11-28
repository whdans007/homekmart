-- 현재 로그인한 사용자의 store_id 확인
SELECT id, username, full_name, role, store_id
FROM users
WHERE id = 1;  -- 또는 현재 로그인한 사용자 ID

-- id=1 사용자의 store_id를 1로 업데이트
UPDATE users
SET store_id = 1
WHERE id = 1;

-- 업데이트 확인
SELECT id, username, full_name, role, store_id
FROM users
WHERE id = 1;
