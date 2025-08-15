<?php
/**
 * 간단한 배달 앱 스키마 설치
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db_config.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>배달 앱 스키마 설치</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;}.success{color:green;}.error{color:red;}.warning{color:orange;}</style>";
echo "</head><body>";
echo "<h1>🚚 간단 설치</h1>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p class='success'>✓ 데이터베이스 연결 성공</p>";
    
    // 1. users 테이블 확장
    echo "<h2>1. users 테이블 확장</h2>";
    
    $user_columns = [
        "ADD COLUMN google_id VARCHAR(255) UNIQUE NULL COMMENT '구글 계정 고유 ID'",
        "ADD COLUMN auth_provider ENUM('email', 'google') DEFAULT 'email' COMMENT '인증 제공자'",
        "ADD COLUMN profile_image_url TEXT NULL COMMENT '프로필 이미지 URL'",
        "ADD COLUMN default_delivery_address_id INT NULL COMMENT '기본 배달 주소 ID'",
        "ADD COLUMN preferred_language ENUM('en', 'ko') DEFAULT 'en' COMMENT '선호 언어'",
        "ADD COLUMN phone_verified BOOLEAN DEFAULT FALSE COMMENT '전화번호 인증 여부'",
        "ADD COLUMN is_delivery_available BOOLEAN DEFAULT TRUE COMMENT '배달 서비스 이용 가능 여부'"
    ];
    
    foreach ($user_columns as $column) {
        try {
            $pdo->exec("ALTER TABLE users $column");
            echo "<p class='success'>✓ users 테이블: $column</p>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "<p class='warning'>⚠ 이미 존재: $column</p>";
            } else {
                echo "<p class='error'>✗ 오류: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    // 2. 배달 주소 테이블
    echo "<h2>2. 배달 주소 테이블</h2>";
    
    $delivery_addresses_sql = "
    CREATE TABLE IF NOT EXISTS delivery_addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        address_name VARCHAR(100) NOT NULL COMMENT '주소 별칭',
        house_number VARCHAR(50) NULL COMMENT '집 번호',
        street VARCHAR(200) NOT NULL COMMENT '거리명',
        barangay VARCHAR(100) NOT NULL COMMENT '바랑가이',
        city VARCHAR(100) NOT NULL COMMENT '시/도시',
        province VARCHAR(100) NOT NULL COMMENT '주/지방',
        postal_code VARCHAR(20) NULL COMMENT '우편번호',
        detailed_address TEXT NULL COMMENT '상세 주소',
        landmark VARCHAR(200) NULL COMMENT '랜드마크',
        delivery_notes TEXT NULL COMMENT '배달 메모',
        latitude DECIMAL(10, 8) NULL COMMENT '위도',
        longitude DECIMAL(11, 8) NULL COMMENT '경도',
        is_default BOOLEAN DEFAULT FALSE COMMENT '기본 주소 여부',
        is_active BOOLEAN DEFAULT TRUE COMMENT '활성 상태',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_delivery_addresses_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($delivery_addresses_sql);
        echo "<p class='success'>✓ delivery_addresses 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ delivery_addresses 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 3. 배달 지역 테이블
    echo "<h2>3. 배달 지역 테이블</h2>";
    
    $delivery_zones_sql = "
    CREATE TABLE IF NOT EXISTS delivery_zones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        zone_name VARCHAR(100) NOT NULL COMMENT '배달 지역명',
        barangay VARCHAR(100) NULL COMMENT '바랑가이',
        city VARCHAR(100) NOT NULL COMMENT '시/도시',
        province VARCHAR(100) NOT NULL COMMENT '주/지방',
        delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT '기본 배달비',
        min_order_amount DECIMAL(10, 2) DEFAULT 0.00 COMMENT '최소 주문 금액',
        free_delivery_threshold DECIMAL(10, 2) NULL COMMENT '무료 배달 최소 금액',
        estimated_delivery_time INT DEFAULT 60 COMMENT '예상 배달 시간(분)',
        max_delivery_time INT DEFAULT 120 COMMENT '최대 배달 시간(분)',
        service_start_time TIME DEFAULT '08:00:00' COMMENT '서비스 시작 시간',
        service_end_time TIME DEFAULT '22:00:00' COMMENT '서비스 종료 시간',
        is_active BOOLEAN DEFAULT TRUE COMMENT '서비스 가능 여부',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($delivery_zones_sql);
        echo "<p class='success'>✓ delivery_zones 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ delivery_zones 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 4. 주문 테이블
    echo "<h2>4. 주문 테이블</h2>";
    
    $delivery_orders_sql = "
    CREATE TABLE IF NOT EXISTS delivery_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(50) NOT NULL UNIQUE COMMENT '주문 번호',
        user_id INT NOT NULL,
        store_id INT NOT NULL COMMENT '주문한 점포',
        delivery_address_id INT NOT NULL,
        delivery_zone_id INT NULL,
        subtotal DECIMAL(10, 2) NOT NULL COMMENT '상품 총액',
        delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT '배달비',
        discount_amount DECIMAL(10, 2) DEFAULT 0.00 COMMENT '할인 금액',
        total_amount DECIMAL(10, 2) NOT NULL COMMENT '총 결제 금액',
        payment_method ENUM('cod', 'gcash', 'paymaya', 'online') DEFAULT 'cod' COMMENT '결제 방법',
        payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending' COMMENT '결제 상태',
        cod_amount DECIMAL(10, 2) NULL COMMENT 'COD 결제 금액',
        change_amount DECIMAL(10, 2) NULL COMMENT '거스름돈',
        order_status ENUM('pending', 'confirmed', 'preparing', 'ready_for_delivery', 'out_for_delivery', 'delivered', 'cancelled') DEFAULT 'pending' COMMENT '주문 상태',
        delivery_date DATE NULL COMMENT '배달 예정일',
        delivery_time_slot VARCHAR(20) NULL COMMENT '배달 시간대',
        estimated_delivery_time TIMESTAMP NULL COMMENT '예상 배달 시간',
        actual_delivery_time TIMESTAMP NULL COMMENT '실제 배달 시간',
        special_instructions TEXT NULL COMMENT '특별 요청사항',
        delivery_notes TEXT NULL COMMENT '배달 메모',
        cancellation_reason TEXT NULL COMMENT '취소 사유',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT,
        FOREIGN KEY (delivery_address_id) REFERENCES delivery_addresses(id) ON DELETE RESTRICT,
        FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($delivery_orders_sql);
        echo "<p class='success'>✓ delivery_orders 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ delivery_orders 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 5. 장바구니 테이블
    echo "<h2>5. 장바구니 테이블</h2>";
    
    $shopping_cart_sql = "
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
        UNIQUE KEY uk_cart_user_product_store (user_id, product_id, store_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($shopping_cart_sql);
        echo "<p class='success'>✓ shopping_cart 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ shopping_cart 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 6. 배달 설정 테이블
    echo "<h2>6. 배달 설정 테이블</h2>";
    
    $delivery_settings_sql = "
    CREATE TABLE IF NOT EXISTS delivery_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
        description TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($delivery_settings_sql);
        echo "<p class='success'>✓ delivery_settings 테이블 생성 완료</p>";
        
        // 기본 설정 데이터 삽입
        $settings = [
            ['default_delivery_fee', '50.00', 'number', '기본 배달비 (PHP)'],
            ['free_delivery_threshold', '1000.00', 'number', '무료 배달 최소 주문 금액 (PHP)'],
            ['max_delivery_distance', '20', 'number', '최대 배달 거리 (km)'],
            ['currency_symbol', '₱', 'string', '통화 기호'],
            ['currency_code', 'PHP', 'string', '통화 코드'],
            ['cod_enabled', 'true', 'boolean', 'COD 결제 활성화 여부']
        ];
        
        $insert_sql = "INSERT INTO delivery_settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $pdo->prepare($insert_sql);
        
        foreach ($settings as $setting) {
            $stmt->execute($setting);
        }
        
        echo "<p class='success'>✓ 기본 설정 " . count($settings) . "개 삽입 완료</p>";
        
    } catch (PDOException $e) {
        echo "<p class='error'>✗ delivery_settings 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 7. 기본 배달 지역 데이터 삽입
    echo "<h2>7. 기본 배달 지역 데이터</h2>";
    
    try {
        $zones = [
            ['Metro Manila - Makati', 'Makati', 'Metro Manila', 50.00, 300.00, 1000.00],
            ['Metro Manila - BGC', 'Taguig', 'Metro Manila', 60.00, 300.00, 1000.00],
            ['Metro Manila - Quezon City', 'Quezon City', 'Metro Manila', 65.00, 300.00, 1200.00],
            ['Metro Manila - Manila', 'Manila', 'Metro Manila', 45.00, 250.00, 800.00],
            ['Cebu City Center', 'Cebu City', 'Cebu', 40.00, 200.00, 800.00]
        ];
        
        $zone_sql = "INSERT INTO delivery_zones (zone_name, city, province, delivery_fee, min_order_amount, free_delivery_threshold) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE delivery_fee = VALUES(delivery_fee)";
        $zone_stmt = $pdo->prepare($zone_sql);
        
        foreach ($zones as $zone) {
            $zone_stmt->execute($zone);
        }
        
        echo "<p class='success'>✓ 기본 배달 지역 " . count($zones) . "개 삽입 완료</p>";
        
    } catch (PDOException $e) {
        echo "<p class='error'>✗ 기본 배달 지역 삽입 실패: " . $e->getMessage() . "</p>";
    }
    
    // 8. 최종 확인
    echo "<h2>8. 설치 완료 확인</h2>";
    
    $tables = $pdo->query("SHOW TABLES LIKE 'delivery_%' OR SHOW TABLES LIKE 'shopping_cart'")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p>생성된 테이블:</p><ul>";
    foreach ($tables as $table) {
        echo "<li class='success'>✓ $table</li>";
    }
    echo "</ul>";
    
    echo "<div style='background:#d4edda;border:1px solid #c3e6cb;padding:15px;border-radius:5px;margin:20px 0;'>";
    echo "<h3 style='color:#155724;margin:0;'>🎉 설치 완료!</h3>";
    echo "<p>이제 API를 테스트할 수 있습니다:</p>";
    echo "<ul>";
    echo "<li><a href='/min/api' target='_blank'>API 정보</a></li>";
    echo "<li><a href='/min/api/products' target='_blank'>상품 목록</a></li>";
    echo "<li><a href='/min/api/products/categories' target='_blank'>카테고리</a></li>";
    echo "<li><a href='/min/api/test_api.php' target='_blank'>API 테스트 페이지</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<p class='error'>✗ 설치 중 오류 발생: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";
?>