-- 2단계: 상품 진열 테이블 생성 (display_sections 테이블이 이미 존재한다고 가정)
USE `u622428657_homekmart`;

-- 상품 진열 테이블 (어떤 상품을 어느 섹션에 진열할지)
CREATE TABLE `product_displays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL COMMENT '진열 섹션 ID',
  `product_id` int(11) NOT NULL COMMENT '상품 ID',
  `store_id` int(11) NOT NULL COMMENT '점포 ID',
  `display_order` int(11) NOT NULL DEFAULT 0 COMMENT '섹션 내 표시 순서',
  `custom_title` varchar(255) DEFAULT NULL COMMENT '커스텀 제목',
  `custom_description` text DEFAULT NULL COMMENT '커스텀 설명',
  `custom_image_url` varchar(500) DEFAULT NULL COMMENT '커스텀 이미지 URL',
  `badge_text` varchar(50) DEFAULT NULL COMMENT '배지 텍스트 (예: NEW, SALE)',
  `badge_color` enum('red','blue','green','orange','purple','gray') DEFAULT NULL COMMENT '배지 색상',
  `start_date` date DEFAULT NULL COMMENT '진열 시작일',
  `end_date` date DEFAULT NULL COMMENT '진열 종료일',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
  `click_count` int(11) DEFAULT 0 COMMENT '클릭 수 (통계용)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_section_product_store` (`section_id`, `product_id`, `store_id`),
  KEY `section_id` (`section_id`),
  KEY `product_id` (`product_id`),
  KEY `store_id` (`store_id`),
  INDEX `idx_product_displays_order` (`section_id`, `display_order`),
  INDEX `idx_product_displays_active` (`is_active`),
  INDEX `idx_product_displays_date` (`start_date`, `end_date`),
  CONSTRAINT `product_displays_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `display_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_displays_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_displays_ibfk_3` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 진열 관리';