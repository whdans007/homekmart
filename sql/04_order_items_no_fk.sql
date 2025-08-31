-- 4단계: 주문 상품 상세 테이블 생성 (외래키 최소화)
USE `u622428657_homekmart`;

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
  INDEX `idx_order_items_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문 상품 상세';

-- orders 테이블이 존재한다면 외래키 추가
ALTER TABLE `order_items` 
ADD CONSTRAINT `order_items_ibfk_1` 
FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;