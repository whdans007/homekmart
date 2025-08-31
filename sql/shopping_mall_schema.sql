-- ===================================================================
-- HOME K MART 쇼핑몰 시스템 추가 테이블
-- 생성일: 2025-08-31
-- 용도: 카탈로그형 쇼핑몰 시스템 (재고관리 없음)
-- 설명: 관리자가 원하는 상품을 원하는 위치에 진열하는 시스템
-- ===================================================================

USE `u622428657_homekmart`;

-- ===================================================================
-- 1. 진열 섹션 관리 테이블
-- ===================================================================

-- 진열 섹션 정의 테이블 (메인배너, 추천상품, 신상품 등)
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

-- ===================================================================
-- 2. 상품 진열 관리 테이블
-- ===================================================================

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

-- ===================================================================
-- 3. 주문 관리 테이블 (재고관리 없는 간단한 주문 시스템)
-- ===================================================================

-- 주문 테이블
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL COMMENT '주문번호',
  `customer_name` varchar(100) NOT NULL COMMENT '고객명',
  `customer_phone` varchar(20) NOT NULL COMMENT '고객 전화번호',
  `customer_email` varchar(255) DEFAULT NULL COMMENT '고객 이메일',
  `store_id` int(11) NOT NULL COMMENT '주문 점포',
  `delivery_address` text DEFAULT NULL COMMENT '배송 주소',
  `delivery_notes` text DEFAULT NULL COMMENT '배송 메모',
  `order_date` datetime NOT NULL DEFAULT current_timestamp() COMMENT '주문 날짜',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '총 주문 금액',
  `delivery_fee` decimal(10,2) DEFAULT 0.00 COMMENT '배송비',
  `discount_amount` decimal(10,2) DEFAULT 0.00 COMMENT '할인 금액',
  `final_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '최종 결제 금액',
  `payment_method` enum('card','cash','transfer','mobile') DEFAULT 'card' COMMENT '결제 방법',
  `order_status` enum('pending','confirmed','preparing','shipping','delivered','cancelled') DEFAULT 'pending' COMMENT '주문 상태',
  `notes` text DEFAULT NULL COMMENT '주문 메모',
  `processed_by` int(11) DEFAULT NULL COMMENT '처리한 관리자 ID',
  `processed_at` datetime DEFAULT NULL COMMENT '처리 날짜',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `store_id` (`store_id`),
  KEY `processed_by` (`processed_by`),
  INDEX `idx_orders_date` (`order_date`),
  INDEX `idx_orders_status` (`order_status`),
  INDEX `idx_orders_customer` (`customer_phone`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문 정보';

-- 주문 상품 상세 테이블
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL COMMENT '주문 ID',
  `product_id` int(11) NOT NULL COMMENT '상품 ID',
  `product_name` varchar(255) NOT NULL COMMENT '주문 당시 상품명',
  `quantity` int(11) NOT NULL COMMENT '주문 수량',
  `unit_price` decimal(10,2) NOT NULL COMMENT '단가',
  `total_price` decimal(12,2) NOT NULL COMMENT '소계',
  `notes` varchar(255) DEFAULT NULL COMMENT '상품별 메모',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  INDEX `idx_order_items_order` (`order_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문 상품 상세';

-- ===================================================================
-- 4. 기본 데이터 삽입
-- ===================================================================

-- 기본 진열 섹션 생성
INSERT INTO `display_sections` (`name`, `description`, `section_type`, `display_order`, `max_products`, `layout_type`, `show_on_main`) VALUES
('메인 배너', '메인페이지 상단 배너 섹션', 'banner', 1, 5, 'banner', 1),
('추천 상품', '관리자 추천 상품 섹션', 'featured', 2, 8, 'grid', 1),
('신상품', '최신 등록 상품 섹션', 'new_products', 3, 12, 'grid', 1),
('인기 상품', '인기 상품 섹션', 'custom', 4, 8, 'carousel', 1),
('카테고리별 특가', '카테고리별 특가 상품', 'category', 5, NULL, 'list', 1);

-- 주문번호 생성을 위한 함수는 PHP에서 구현 예정
-- 형식: HK + YYYYMMDD + 일련번호 (예: HK20250831001)

-- ===================================================================
-- 완료 메시지
-- ===================================================================
SELECT '쇼핑몰 시스템 테이블이 성공적으로 생성되었습니다!' as status;