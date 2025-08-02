-- users 테이블에 permissions 컬럼 추가
-- 기존 데이터에 영향을 주지 않으면서 세분화된 권한 관리를 위함

ALTER TABLE users 
ADD COLUMN permissions JSON DEFAULT NULL 
COMMENT '사용자별 세분화된 권한 설정 (JSON 형태)';

-- 기본 권한 템플릿 생성
-- super_admin: 모든 권한
-- admin: 제한된 관리 권한  
-- user: 쇼핑몰만 접근

-- 예시 권한 구조:
-- {
--   "admin_access": true/false,           -- 관리자 메뉴 접근
--   "user_management": true/false,        -- 회원 관리
--   "store_management": true/false,       -- 지점 관리
--   "product_management": true/false,     -- 상품 관리
--   "purchase_management": true/false,    -- 매입 관리
--   "brand_management": true/false,       -- 브랜드 관리
--   "category_management": true/false,    -- 카테고리 관리
--   "supplier_management": true/false,    -- 공급처 관리
--   "settings": true/false,               -- 환경 설정
--   "shop_access": true/false             -- 쇼핑몰 접근
-- }

-- 기존 사용자들에 대한 기본 권한 설정
UPDATE users 
SET permissions = CASE 
    WHEN role = 'super_admin' THEN JSON_OBJECT(
        'admin_access', true,
        'user_management', true,
        'store_management', true,
        'product_management', true,
        'purchase_management', true,
        'brand_management', true,
        'category_management', true,
        'supplier_management', true,
        'settings', true,
        'shop_access', true
    )
    WHEN role = 'admin' THEN JSON_OBJECT(
        'admin_access', true,
        'user_management', true,
        'store_management', false,
        'product_management', true,
        'purchase_management', true,
        'brand_management', false,
        'category_management', false,
        'supplier_management', false,
        'settings', false,
        'shop_access', true
    )
    WHEN role = 'user' THEN JSON_OBJECT(
        'admin_access', false,
        'user_management', false,
        'store_management', false,
        'product_management', false,
        'purchase_management', false,
        'brand_management', false,
        'category_management', false,
        'supplier_management', false,
        'settings', false,
        'shop_access', true
    )
    ELSE JSON_OBJECT(
        'admin_access', false,
        'user_management', false,
        'store_management', false,
        'product_management', false,
        'purchase_management', false,
        'brand_management', false,
        'category_management', false,
        'supplier_management', false,
        'settings', false,
        'shop_access', true
    )
END
WHERE permissions IS NULL;