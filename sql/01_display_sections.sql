-- 1단계: 진열 섹션 테이블만 생성
USE `u622428657_homekmart`;

-- 진열 섹션 정의 테이블
CREATE TABLE `display_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '섹션명',
  `description` text DEFAULT NULL COMMENT '섹션 설명',
  `section_type` enum('banner','featured','new_products','category','custom') NOT NULL DEFAULT 'custom' COMMENT '섹션 유형',
  `display_order` int(11) NOT NULL DEFAULT 0 COMMENT '표시 순서',
  `max_products` int(11) DEFAULT NULL COMMENT '최대 상품 수 (NULL = 무제한)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
  `layout_type` enum('grid','list','carousel','banner') DEFAULT 'grid' COMMENT '레이아웃 유형',
  `show_on_main` tinyint(1) DEFAULT 1 COMMENT '메인페이지 표시 여부',
  `custom_css_class` varchar(255) DEFAULT NULL COMMENT '커스텀 CSS 클래스',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_display_sections_order` (`display_order`),
  INDEX `idx_display_sections_active` (`is_active`),
  INDEX `idx_display_sections_main` (`show_on_main`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='진열 섹션 정의';

-- 기본 진열 섹션 생성
INSERT INTO `display_sections` (`name`, `description`, `section_type`, `display_order`, `max_products`, `layout_type`, `show_on_main`) VALUES
('메인 배너', '메인페이지 상단 배너 섹션', 'banner', 1, 5, 'banner', 1),
('추천 상품', '관리자 추천 상품 섹션', 'featured', 2, 8, 'grid', 1),
('신상품', '최신 등록 상품 섹션', 'new_products', 3, 12, 'grid', 1),
('인기 상품', '인기 상품 섹션', 'custom', 4, 8, 'carousel', 1),
('카테고리별 특가', '카테고리별 특가 상품', 'category', 5, NULL, 'list', 1);