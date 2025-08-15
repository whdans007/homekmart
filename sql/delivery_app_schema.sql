-- ===================================================================
-- 필리핀 배달 앱을 위한 데이터베이스 스키마
-- 작성일: 2025-01-16
-- 설명: COD 결제 중심의 배달 쇼핑몰 앱을 위한 테이블 구조
-- ===================================================================

-- 1. users 테이블 확장 (구글 로그인 지원)
-- 기존 users 테이블에 컬럼 추가
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) UNIQUE NULL COMMENT '구글 계정 고유 ID',
ADD COLUMN IF NOT EXISTS auth_provider ENUM('email', 'google') DEFAULT 'email' COMMENT '인증 제공자',
ADD COLUMN IF NOT EXISTS profile_image_url TEXT NULL COMMENT '프로필 이미지 URL',
ADD COLUMN IF NOT EXISTS default_delivery_address_id INT NULL COMMENT '기본 배달 주소 ID',
ADD COLUMN IF NOT EXISTS preferred_language ENUM('en', 'ko') DEFAULT 'en' COMMENT '선호 언어',
ADD COLUMN IF NOT EXISTS phone_verified BOOLEAN DEFAULT FALSE COMMENT '전화번호 인증 여부',
ADD COLUMN IF NOT EXISTS is_delivery_available BOOLEAN DEFAULT TRUE COMMENT '배달 서비스 이용 가능 여부';

-- 구글 ID에 인덱스 추가
CREATE INDEX IF NOT EXISTS idx_users_google_id ON users(google_id);

-- ===================================================================
-- 2. 배달 주소 관리 테이블
-- ===================================================================
CREATE TABLE IF NOT EXISTS delivery_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    address_name VARCHAR(100) NOT NULL COMMENT '주소 별칭 (집, 회사 등)',
    
    -- 필리핀 주소 체계
    house_number VARCHAR(50) NULL COMMENT '집 번호',
    street VARCHAR(200) NOT NULL COMMENT '거리명',
    barangay VARCHAR(100) NOT NULL COMMENT '바랑가이 (최소 행정구역)',
    city VARCHAR(100) NOT NULL COMMENT '시/도시',
    province VARCHAR(100) NOT NULL COMMENT '주/지방',
    postal_code VARCHAR(20) NULL COMMENT '우편번호',
    
    -- 상세 주소 및 랜드마크
    detailed_address TEXT NULL COMMENT '상세 주소',
    landmark VARCHAR(200) NULL COMMENT '랜드마크 (필리핀에서 중요)',
    delivery_notes TEXT NULL COMMENT '배달 메모',
    
    -- GPS 좌표
    latitude DECIMAL(10, 8) NULL COMMENT '위도',
    longitude DECIMAL(11, 8) NULL COMMENT '경도',
    
    -- 메타데이터
    is_default BOOLEAN DEFAULT FALSE COMMENT '기본 주소 여부',
    is_active BOOLEAN DEFAULT TRUE COMMENT '활성 상태',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_delivery_addresses_user_id (user_id),
    INDEX idx_delivery_addresses_location (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배달 주소 관리';

-- ===================================================================
-- 3. 배달 지역 및 배달비 관리 테이블
-- ===================================================================
CREATE TABLE IF NOT EXISTS delivery_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_name VARCHAR(100) NOT NULL COMMENT '배달 지역명',
    
    -- 지역 범위 (바랑가이, 시, 주 단위)
    barangay VARCHAR(100) NULL COMMENT '바랑가이',
    city VARCHAR(100) NOT NULL COMMENT '시/도시',
    province VARCHAR(100) NOT NULL COMMENT '주/지방',
    
    -- 배달 설정
    delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT '기본 배달비 (PHP)',
    min_order_amount DECIMAL(10, 2) DEFAULT 0.00 COMMENT '최소 주문 금액',
    free_delivery_threshold DECIMAL(10, 2) NULL COMMENT '무료 배달 최소 금액',
    
    -- 배달 시간 설정
    estimated_delivery_time INT DEFAULT 60 COMMENT '예상 배달 시간 (분)',
    max_delivery_time INT DEFAULT 120 COMMENT '최대 배달 시간 (분)',
    
    -- 서비스 가능 시간
    service_start_time TIME DEFAULT '08:00:00' COMMENT '서비스 시작 시간',
    service_end_time TIME DEFAULT '22:00:00' COMMENT '서비스 종료 시간',
    
    -- 상태
    is_active BOOLEAN DEFAULT TRUE COMMENT '서비스 가능 여부',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_delivery_zones_location (city, province),
    INDEX idx_delivery_zones_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배달 지역 및 배달비 관리';

-- ===================================================================
-- 4. 배달 주문 테이블 (COD 중심)
-- ===================================================================
CREATE TABLE IF NOT EXISTS delivery_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE COMMENT '주문 번호',
    
    -- 주문자 정보
    user_id INT NOT NULL,
    store_id INT NOT NULL COMMENT '주문한 점포',
    
    -- 배달 정보
    delivery_address_id INT NOT NULL,
    delivery_zone_id INT NULL,
    
    -- 주문 금액 (PHP Peso)
    subtotal DECIMAL(10, 2) NOT NULL COMMENT '상품 총액',
    delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT '배달비',
    discount_amount DECIMAL(10, 2) DEFAULT 0.00 COMMENT '할인 금액',
    total_amount DECIMAL(10, 2) NOT NULL COMMENT '총 결제 금액',
    
    -- 결제 정보 (COD 중심)
    payment_method ENUM('cod', 'gcash', 'paymaya', 'online') DEFAULT 'cod' COMMENT '결제 방법',
    payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending' COMMENT '결제 상태',
    cod_amount DECIMAL(10, 2) NULL COMMENT 'COD 결제 금액',
    change_amount DECIMAL(10, 2) NULL COMMENT '거스름돈',
    
    -- 주문 상태
    order_status ENUM('pending', 'confirmed', 'preparing', 'ready_for_delivery', 'out_for_delivery', 'delivered', 'cancelled') DEFAULT 'pending' COMMENT '주문 상태',
    
    -- 배달 정보
    delivery_date DATE NULL COMMENT '배달 예정일',
    delivery_time_slot VARCHAR(20) NULL COMMENT '배달 시간대',
    estimated_delivery_time TIMESTAMP NULL COMMENT '예상 배달 시간',
    actual_delivery_time TIMESTAMP NULL COMMENT '실제 배달 시간',
    
    -- 특이사항
    special_instructions TEXT NULL COMMENT '특별 요청사항',
    delivery_notes TEXT NULL COMMENT '배달 메모',
    cancellation_reason TEXT NULL COMMENT '취소 사유',
    
    -- 메타데이터
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT,
    FOREIGN KEY (delivery_address_id) REFERENCES delivery_addresses(id) ON DELETE RESTRICT,
    FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL,
    
    INDEX idx_delivery_orders_user_id (user_id),
    INDEX idx_delivery_orders_store_id (store_id),
    INDEX idx_delivery_orders_status (order_status),
    INDEX idx_delivery_orders_date (created_at),
    INDEX idx_delivery_orders_number (order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배달 주문 관리';

-- ===================================================================
-- 5. 배달 주문 상품 테이블
-- ===================================================================
CREATE TABLE IF NOT EXISTS delivery_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    
    -- 상품 정보 (주문 시점 스냅샷)
    product_name VARCHAR(255) NOT NULL COMMENT '상품명',
    product_price DECIMAL(10, 2) NOT NULL COMMENT '단가',
    quantity INT NOT NULL COMMENT '수량',
    subtotal DECIMAL(10, 2) NOT NULL COMMENT '소계',
    
    -- 상품 옵션 (추후 확장용)
    product_options JSON NULL COMMENT '상품 옵션 (JSON)',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (order_id) REFERENCES delivery_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    
    INDEX idx_delivery_order_items_order_id (order_id),
    INDEX idx_delivery_order_items_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배달 주문 상품';

-- ===================================================================
-- 6. 배달 추적 테이블
-- ===================================================================
CREATE TABLE IF NOT EXISTS delivery_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    
    -- 상태 변경 정보
    status ENUM('order_placed', 'order_confirmed', 'preparing', 'ready_for_pickup', 'picked_up', 'out_for_delivery', 'delivered', 'failed_delivery', 'cancelled') NOT NULL,
    status_message VARCHAR(255) NULL COMMENT '상태 메시지',
    
    -- 위치 정보 (배달원 위치 추적)
    latitude DECIMAL(10, 8) NULL COMMENT '현재 위도',
    longitude DECIMAL(11, 8) NULL COMMENT '현재 경도',
    
    -- 메타데이터
    updated_by_user_id INT NULL COMMENT '상태 업데이트한 사용자',
    notes TEXT NULL COMMENT '추가 메모',
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (order_id) REFERENCES delivery_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_delivery_tracking_order_id (order_id),
    INDEX idx_delivery_tracking_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배달 추적';

-- ===================================================================
-- 7. 장바구니 테이블 (기존에 없다면 생성)
-- ===================================================================
CREATE TABLE IF NOT EXISTS shopping_cart (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    store_id INT NOT NULL COMMENT '상품을 구매할 점포',
    quantity INT NOT NULL DEFAULT 1,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    
    UNIQUE KEY uk_cart_user_product_store (user_id, product_id, store_id),
    INDEX idx_shopping_cart_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='장바구니';

-- ===================================================================
-- 8. 위시리스트 테이블
-- ===================================================================
CREATE TABLE IF NOT EXISTS wishlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    
    UNIQUE KEY uk_wishlist_user_product (user_id, product_id),
    INDEX idx_wishlists_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='위시리스트';

-- ===================================================================
-- 9. 배달 관련 설정 테이블
-- ===================================================================
CREATE TABLE IF NOT EXISTS delivery_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_delivery_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배달 앱 설정';

-- ===================================================================
-- 10. 기본 설정 데이터 삽입
-- ===================================================================
INSERT INTO delivery_settings (setting_key, setting_value, setting_type, description) VALUES
('default_delivery_fee', '50.00', 'number', '기본 배달비 (PHP)'),
('free_delivery_threshold', '1000.00', 'number', '무료 배달 최소 주문 금액 (PHP)'),
('max_delivery_distance', '20', 'number', '최대 배달 거리 (km)'),
('default_delivery_time', '60', 'number', '기본 배달 시간 (분)'),
('cod_enabled', 'true', 'boolean', 'COD 결제 활성화 여부'),
('gcash_enabled', 'false', 'boolean', 'GCash 결제 활성화 여부'),
('paymaya_enabled', 'false', 'boolean', 'PayMaya 결제 활성화 여부'),
('service_hours_start', '08:00', 'string', '서비스 시작 시간'),
('service_hours_end', '22:00', 'string', '서비스 종료 시간'),
('currency_symbol', '₱', 'string', '통화 기호'),
('currency_code', 'PHP', 'string', '통화 코드')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ===================================================================
-- 11. 기본 배달 지역 데이터 (예시 - 마닐라 메트로 지역)
-- ===================================================================
INSERT INTO delivery_zones (zone_name, city, province, delivery_fee, min_order_amount, free_delivery_threshold) VALUES
('Metro Manila - Makati', 'Makati', 'Metro Manila', 50.00, 300.00, 1000.00),
('Metro Manila - BGC', 'Taguig', 'Metro Manila', 60.00, 300.00, 1000.00),
('Metro Manila - Ortigas', 'Pasig', 'Metro Manila', 55.00, 300.00, 1000.00),
('Metro Manila - Quezon City', 'Quezon City', 'Metro Manila', 65.00, 300.00, 1200.00),
('Metro Manila - Manila', 'Manila', 'Metro Manila', 45.00, 250.00, 800.00)
ON DUPLICATE KEY UPDATE delivery_fee = VALUES(delivery_fee);

-- ===================================================================
-- 인덱스 최적화
-- ===================================================================
-- users 테이블의 기본 배달 주소 외래키 추가 (delivery_addresses 테이블 생성 후)
ALTER TABLE users 
ADD CONSTRAINT fk_users_default_delivery_address 
FOREIGN KEY (default_delivery_address_id) REFERENCES delivery_addresses(id) ON DELETE SET NULL;

-- ===================================================================
-- 완료 메시지
-- ===================================================================
SELECT 'Philippines Delivery App Database Schema Created Successfully!' as status;