-- wholesale_customers 테이블에 store_id 컬럼 추가
-- 실행일: 2025-10-26

-- 1. store_id 컬럼 추가
ALTER TABLE `wholesale_customers`
ADD COLUMN `store_id` INT UNSIGNED NULL COMMENT '점포 ID' AFTER `id`;

-- 2. 기존 데이터에 기본 점포(store_id = 1) 할당
UPDATE `wholesale_customers`
SET `store_id` = 1
WHERE `store_id` IS NULL;

-- 3. store_id를 NOT NULL로 변경
ALTER TABLE `wholesale_customers`
MODIFY COLUMN `store_id` INT UNSIGNED NOT NULL COMMENT '점포 ID';

-- 4. stores 테이블과 외래키 제약 조건 추가
ALTER TABLE `wholesale_customers`
ADD CONSTRAINT `fk_wholesale_customers_store_id`
FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`)
ON DELETE RESTRICT
ON UPDATE CASCADE;

-- 5. 인덱스 추가 (성능 향상)
ALTER TABLE `wholesale_customers`
ADD INDEX `idx_store_id` (`store_id`);

-- 6. 점포별 거래처 조회 복합 인덱스
ALTER TABLE `wholesale_customers`
ADD INDEX `idx_store_active` (`store_id`, `is_active`);
