-- =====================================================================
-- add_mall_home_sections_slot_key.sql
-- 홈 화면을 "관리자가 자유롭게 섹션을 추가/삭제하는 빌더"가 아니라
-- 디자인 목업에 정해진 고정 3슬롯(배너/오늘의특가/새로들어온상품)의
-- 내용만 편집하는 구조로 바꾸면서 slot_key 컬럼을 추가한다.
-- 적용은 sql/run_add_mall_home_sections_slot_key_migration.php로 할 것.
-- =====================================================================

ALTER TABLE `mall_home_sections`
  ADD COLUMN `slot_key` varchar(30) DEFAULT NULL AFTER `section_type`,
  ADD UNIQUE KEY `store_slot_status` (`store_id`, `slot_key`, `status`);
