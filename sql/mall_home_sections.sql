-- =====================================================================
-- mall_home_sections.sql
-- mall-home-layout 기능 신규 테이블 (docs/02-design/features/mall-home-layout.design.md §3 기준)
-- mall_ 접두사, utf8mb4, FK 명시. Do phase module-1 산출물.
-- 2026-08-12에 폐기된 layout_rows/layout_columns/layout_presets/display_sections/
-- product_displays 테이블명과 절대 겹치지 않는지 적용 전 반드시 재확인할 것.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE `mall_home_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_id` int(11) UNSIGNED NOT NULL,
  `section_type` enum('banner','category_shortcut','product_list') NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`),
  KEY `store_sort` (`store_id`, `sort_order`),
  CONSTRAINT `mall_home_sections_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 홈 화면 섹션(드래그앤드롭 레이아웃)';
