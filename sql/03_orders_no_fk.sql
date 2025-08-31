-- 3단계: 주문 테이블 생성 (외래키 제약조건 최소화)
USE `u622428657_homekmart`;

-- 외래키 최소화한 주문 테이블 생성
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
  INDEX `idx_orders_customer` (`customer_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문 정보';