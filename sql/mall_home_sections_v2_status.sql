-- =====================================================================
-- mall_home_sections_v2_status.sql
-- mall-home-layout v0.3 (초안/발행 워크플로우) — docs/02-design/features/mall-home-layout.design.md §3.3
-- status/published_at/published_by 컬럼 추가 + 기존 행 1회 부트스트랩 발행.
-- 실제 적용은 sql/run_mall_home_sections_v2_status_migration.php로 실행할 것(직접 실행 금지 — 멱등성 보장 안 됨).
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `mall_home_sections`
  ADD COLUMN `status` enum('draft','published') NOT NULL DEFAULT 'draft' AFTER `is_active`,
  ADD COLUMN `published_at` timestamp NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN `published_by` int(11) DEFAULT NULL AFTER `published_at`,
  ADD KEY `store_status` (`store_id`, `status`),
  ADD CONSTRAINT `mall_home_sections_ibfk_2` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 부트스트랩 발행: 마이그레이션 시점에 이미 존재하던 행(전부 status='draft'로 시작)을
-- 그대로 한 번 더 복제해 published로도 만들어, 고객 화면이 순간적으로 비어 보이지 않게 한다.
INSERT INTO `mall_home_sections`
  (store_id, section_type, title, subtitle, config, sort_order, is_active, status, published_at, published_by)
SELECT store_id, section_type, title, subtitle, config, sort_order, is_active, 'published', NOW(), NULL
FROM `mall_home_sections` WHERE `status` = 'draft';
