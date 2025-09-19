-- 운영 서버 products 테이블에 status 컬럼 추가
-- 테스트 서버와 동일한 구조로 맞추기 위함

ALTER TABLE products
ADD COLUMN status enum('active', 'inactive', 'discontinued') COLLATE utf8mb4_unicode_ci DEFAULT 'active' AFTER pieces_per_box;

-- 기존 데이터의 status 설정 (is_active 값 기반)
UPDATE products
SET status = CASE
    WHEN is_active = 1 THEN 'active'
    WHEN is_active = 0 THEN 'inactive'
    ELSE 'active'
END;