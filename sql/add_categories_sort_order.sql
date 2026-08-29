-- ===================================================================
-- categories 테이블에 sort_order 컬럼 추가
-- 용도: 상품 큐레이션 화면 좌측 카테고리 메뉴의 드래그앤드롭 순서 저장
-- ===================================================================
ALTER TABLE `categories`
  ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `parent_id`;
