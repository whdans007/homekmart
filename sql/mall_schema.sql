-- =====================================================================
-- mall_schema.sql
-- shopping-mall 기능 신규 테이블 (docs/02-design/features/shopping-mall.design.md §3 기준)
-- 전 테이블 mall_ 접두사, utf8mb4, FK 명시. Do phase module-1 산출물.
-- 적용 전 반드시 라이브 DB(u622428657_homekmart)와 테이블명 충돌 여부 재확인할 것.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- mall_members: 쇼핑몰 고객 회원 (관리자 users와 완전히 별도)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_type` enum('retail','wholesale') NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `english_name` varchar(100) DEFAULT NULL COMMENT '해외 배송용 영문 이름',
  `phone` varchar(50) DEFAULT NULL,
  `business_name` varchar(255) DEFAULT NULL COMMENT '사업자 상호 (도매만)',
  `business_reg_no` varchar(50) DEFAULT NULL COMMENT '사업자등록번호 (도매만)',
  `store_id` int(11) UNSIGNED NOT NULL,
  `retail_tier` enum('general','good','vip','platinum') NOT NULL DEFAULT 'general' COMMENT '일반/우수/VIP/플래티넘 4단계',
  `wholesale_status` enum('pending','approved','rejected') DEFAULT NULL,
  `wholesale_customer_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `store_id` (`store_id`),
  KEY `wholesale_customer_id` (`wholesale_customer_id`),
  CONSTRAINT `mall_members_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
  CONSTRAINT `mall_members_ibfk_2` FOREIGN KEY (`wholesale_customer_id`) REFERENCES `wholesale_customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 고객 회원(관리자 users와 별도)';

-- ---------------------------------------------------------------------
-- mall_password_resets: 비밀번호 찾기 토큰 (평문 저장 금지, 해시만 저장)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `token_hash` (`token_hash`),
  CONSTRAINT `mall_password_resets_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 비밀번호 재설정 토큰';

-- ---------------------------------------------------------------------
-- mall_products: 소매 큐레이션 (기존 products 중 노출 대상 선택)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) UNSIGNED NOT NULL,
  `store_id` int(11) UNSIGNED NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `display_name_en` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id` (`product_id`),
  KEY `store_id` (`store_id`),
  CONSTRAINT `mall_products_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `mall_products_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='소매몰 상품 큐레이션';

-- ---------------------------------------------------------------------
-- mall_product_images: 소매/도매 공용, product_id는 항상 products.id
-- ---------------------------------------------------------------------
CREATE TABLE `mall_product_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) UNSIGNED NOT NULL,
  `image_path` varchar(500) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `mall_product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 상품 이미지';

-- ---------------------------------------------------------------------
-- mall_wholesale_visibility: 기존 wholesale_products 중 몰 노출 대상
-- ---------------------------------------------------------------------
CREATE TABLE `mall_wholesale_visibility` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wholesale_product_id` int(11) NOT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `wholesale_product_id` (`wholesale_product_id`),
  CONSTRAINT `mall_wholesale_visibility_ibfk_1` FOREIGN KEY (`wholesale_product_id`) REFERENCES `wholesale_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매몰 상품 노출 관리';

-- ---------------------------------------------------------------------
-- mall_retail_discount_rules: 소매 등급별 할인율
-- ---------------------------------------------------------------------
CREATE TABLE `mall_retail_discount_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tier` enum('general','good','vip','platinum') NOT NULL,
  `discount_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `min_cumulative_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tier` (`tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='소매 등급별 할인 규칙';

-- ---------------------------------------------------------------------
-- mall_wholesale_instant_discount_tiers: 도매 즉석할인 구간
-- ---------------------------------------------------------------------
CREATE TABLE `mall_wholesale_instant_discount_tiers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `min_order_amount` decimal(12,2) NOT NULL,
  `discount_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 주문금액 구간별 즉석할인';

-- ---------------------------------------------------------------------
-- mall_wholesale_cumulative_tiers: 도매 누적실적 등급
-- ---------------------------------------------------------------------
CREATE TABLE `mall_wholesale_cumulative_tiers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tier_name` varchar(50) NOT NULL,
  `min_cumulative_amount` decimal(12,2) NOT NULL,
  `additional_discount_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 누적실적 등급';

-- ---------------------------------------------------------------------
-- mall_member_stats: 회원별 누적실적/현재 도매등급 (월배치 갱신)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_member_stats` (
  `member_id` int(11) NOT NULL,
  `cumulative_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `current_wholesale_tier_id` int(11) DEFAULT NULL,
  `last_recalculated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`member_id`),
  KEY `current_wholesale_tier_id` (`current_wholesale_tier_id`),
  CONSTRAINT `mall_member_stats_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_member_stats_ibfk_2` FOREIGN KEY (`current_wholesale_tier_id`) REFERENCES `mall_wholesale_cumulative_tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원 누적실적/도매등급 (월배치 갱신)';

-- ---------------------------------------------------------------------
-- mall_cart_items: 장바구니 (product_id는 항상 products.id)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_cart_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `product_id` int(11) UNSIGNED NOT NULL,
  `channel` enum('retail','wholesale') NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_product_channel` (`member_id`,`product_id`,`channel`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `mall_cart_items_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_cart_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 장바구니';

-- ---------------------------------------------------------------------
-- mall_orders: 주문
-- ---------------------------------------------------------------------
CREATE TABLE `mall_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `member_id` int(11) NOT NULL,
  `store_id` int(11) UNSIGNED NOT NULL,
  `channel` enum('retail','wholesale') NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `shipping_fee` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '주문 확정 시점 배송비 스냅샷',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','confirmed','preparing','ready','assigned','delivering','arrived','completed','cancelled','delivery_failed') NOT NULL DEFAULT 'pending',
  `estimated_ready_at` datetime DEFAULT NULL COMMENT '접수확인 시 입력한 준비 소요시간을 더한 예상 준비완료 시각',
  `confirmed_at` datetime DEFAULT NULL COMMENT '접수확인 처리 시각(pending->preparing)',
  `ready_at` datetime DEFAULT NULL COMMENT '준비완료 처리 시각(preparing->ready)',
  `current_driver_id` int(11) DEFAULT NULL COMMENT '현재 배정된 배송기사(최신 배정 빠른 조회용)',
  `ship_recipient_name` varchar(100) DEFAULT NULL COMMENT '주문 시점 배송지 스냅샷 — 이후 mall_addresses 변경 무관',
  `ship_phone` varchar(50) DEFAULT NULL,
  `ship_region` varchar(100) DEFAULT NULL,
  `ship_city` varchar(100) DEFAULT NULL,
  `ship_barangay` varchar(100) DEFAULT NULL,
  `ship_detail_address` varchar(255) DEFAULT NULL,
  `ship_landmark` varchar(255) DEFAULT NULL,
  `ship_lat` decimal(10,7) DEFAULT NULL,
  `ship_lng` decimal(10,7) DEFAULT NULL,
  `payment_method` enum('cod','offline') NOT NULL DEFAULT 'cod',
  `memo` text DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL COMMENT '관리자가 고객 요청 등으로 취소 처리할 때 남기는 사유',
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `member_id` (`member_id`),
  KEY `store_id` (`store_id`),
  CONSTRAINT `mall_orders_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`),
  CONSTRAINT `mall_orders_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
  CONSTRAINT `mall_orders_driver_fk` FOREIGN KEY (`current_driver_id`) REFERENCES `mall_drivers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 주문';

-- ---------------------------------------------------------------------
-- mall_drivers: 배송기사 계정(mall_members/admin users와 완전 분리)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_drivers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `driver_type` enum('inhouse','external') NOT NULL DEFAULT 'inhouse' COMMENT '이번 범위는 inhouse만 사용, 추후 외부기사 확장 대비',
  `vehicle_info` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_lat` decimal(10,7) DEFAULT NULL COMMENT '최신 위치 캐시(폴링 조회 성능용)',
  `last_lng` decimal(10,7) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배송기사 계정';

-- ---------------------------------------------------------------------
-- mall_order_driver_assignments: 주문-기사 배정 이력(재배정마다 새 행)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_order_driver_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `status` enum('assigned','delivering','arrived','completed','failed','reassigned') NOT NULL DEFAULT 'assigned',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivering_at` datetime DEFAULT NULL,
  `arrived_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `failed_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `mall_oda_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_oda_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `mall_drivers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문-기사 배정 이력';

-- ---------------------------------------------------------------------
-- mall_driver_locations: 기사 위치 이력(경로 재생용)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_driver_locations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `driver_order_time` (`driver_id`,`order_id`,`recorded_at`),
  CONSTRAINT `mall_dl_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `mall_drivers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_dl_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='기사 위치 이력';

-- ---------------------------------------------------------------------
-- mall_order_items: 주문 항목 (확정 시점 가격 스냅샷)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) UNSIGNED NOT NULL,
  `product_name_snapshot` varchar(255) NOT NULL,
  `unit_price_snapshot` decimal(12,2) NOT NULL,
  `discount_rate_snapshot` decimal(5,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `is_sold_out` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `mall_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 주문 항목 (가격 스냅샷)';

-- ---------------------------------------------------------------------
-- mall_order_messages: 주문톡 — 주문별 채팅 메시지 (고객/관리자/배송기사 3자 참여)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_order_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `sender_type` enum('member','admin','driver') NOT NULL,
  `sender_member_id` int(11) DEFAULT NULL,
  `sender_admin_id` int(11) DEFAULT NULL,
  `sender_driver_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read_by_member` tinyint(1) NOT NULL DEFAULT 0,
  `is_read_by_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_read_by_driver` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id_created` (`order_id`, `id`),
  CONSTRAINT `mall_order_messages_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문별 채팅 메시지';

-- ---------------------------------------------------------------------
-- mall_order_chat_closed: 주문톡 종료 상태 (행이 있으면 완료, 없으면 진행중)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_order_chat_closed` (
  `order_id` int(11) NOT NULL,
  `closed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closed_by_admin_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`order_id`),
  CONSTRAINT `mall_order_chat_closed_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문톡 종료 상태(행이 있으면 완료, 없으면 진행중)';

-- ---------------------------------------------------------------------
-- mall_wishlist: 위시리스트
-- ---------------------------------------------------------------------
CREATE TABLE `mall_wishlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `product_id` int(11) UNSIGNED NOT NULL,
  `channel` enum('retail','wholesale') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_product_channel` (`member_id`,`product_id`,`channel`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `mall_wishlist_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_wishlist_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 위시리스트';

-- ---------------------------------------------------------------------
-- mall_reviews: 리뷰/평점 (구매 검증: order_id 필수)
-- ---------------------------------------------------------------------
CREATE TABLE `mall_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `product_id` int(11) UNSIGNED NOT NULL,
  `order_id` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `product_id` (`product_id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `mall_reviews_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_reviews_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `mall_reviews_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `mall_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mall_reviews_chk_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 리뷰/평점';
