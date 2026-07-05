-- 도매 상품 메모(특이사항) 컬럼 추가
-- 실행일: 2026-06-24
ALTER TABLE `wholesale_products`
  ADD COLUMN `memo` TEXT DEFAULT NULL COMMENT '도매 상품 메모(특이사항)' AFTER `wholesale_description`;
