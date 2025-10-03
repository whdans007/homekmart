-- ===================================================================
-- 기존 테이블 확인 쿼리
-- delivery_app_schema.sql 실행 전 충돌 확인용
-- ===================================================================

-- 1. 현재 데이터베이스의 모든 테이블 확인
SHOW TABLES;

-- 2. 배달 관련 테이블이 이미 있는지 확인
SHOW TABLES LIKE 'delivery_%';

-- 3. users 테이블 존재 여부 확인
SHOW TABLES LIKE 'users';

-- 4. shopping_cart, wishlists 테이블 확인
SHOW TABLES LIKE 'shopping_cart';
SHOW TABLES LIKE 'wishlists';

-- 5. users 테이블 구조 확인 (테이블이 존재하는 경우에만 실행)
-- DESCRIBE users;

-- 6. users 테이블에 배달 관련 컬럼이 있는지 확인 (테이블이 존재하는 경우에만 실행)
-- SELECT COLUMN_NAME
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'users'
--   AND COLUMN_NAME IN ('google_id', 'auth_provider', 'profile_image_url', 'default_delivery_address_id', 'preferred_language');

-- ===================================================================
-- 참고: 위의 주석 처리된 쿼리는 users 테이블이 존재하는 경우에만 실행하세요
-- ===================================================================
