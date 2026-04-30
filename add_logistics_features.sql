-- 1. products 테이블에 expiration_date 추가
ALTER TABLE products ADD COLUMN expiration_date DATE DEFAULT NULL COMMENT '유통기한 (가장 최근 입고 또는 가장 임박한 기준)';

-- 2. purchase_items 테이블에 expiration_date 추가
ALTER TABLE purchase_items ADD COLUMN expiration_date DATE DEFAULT NULL COMMENT '해당 매입의 유통기한';

-- 3. 물류센터 점포 추가 (없을 경우)
INSERT IGNORE INTO stores (name) VALUES ('물류센터');

