<?php
/**
 * 테이블 수정 및 외래키 없이 생성
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db_config.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>테이블 수정</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;}.success{color:green;}.error{color:red;}.warning{color:orange;}</style>";
echo "</head><body>";
echo "<h1>🔧 테이블 수정</h1>";

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p class='success'>✓ 데이터베이스 연결 성공</p>";
    
    // 1. 배달 주소 테이블 (외래키 없이)
    echo "<h2>1. 배달 주소 테이블</h2>";
    
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
        INDEX idx_delivery_addresses_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($delivery_addresses_sql);
        echo "<p class='success'>✓ delivery_addresses 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ delivery_addresses 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 2. 주문 테이블 (외래키 없이)
    echo "<h2>2. 주문 테이블</h2>";
    
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
        INDEX idx_delivery_orders_user_id (user_id),
        INDEX idx_delivery_orders_store_id (store_id),
        INDEX idx_delivery_orders_status (order_status),
        INDEX idx_delivery_orders_number (order_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($delivery_orders_sql);
        echo "<p class='success'>✓ delivery_orders 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ delivery_orders 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 3. 주문 상품 테이블
    echo "<h2>3. 주문 상품 테이블</h2>";
    
    $delivery_order_items_sql = "
    CREATE TABLE IF NOT EXISTS delivery_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(255) NOT NULL COMMENT '상품명',
        product_price DECIMAL(10, 2) NOT NULL COMMENT '단가',
        quantity INT NOT NULL COMMENT '수량',
        subtotal DECIMAL(10, 2) NOT NULL COMMENT '소계',
        product_options JSON NULL COMMENT '상품 옵션',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_delivery_order_items_order_id (order_id),
        INDEX idx_delivery_order_items_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($delivery_order_items_sql);
        echo "<p class='success'>✓ delivery_order_items 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ delivery_order_items 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 4. 배달 추적 테이블
    echo "<h2>4. 배달 추적 테이블</h2>";
    
    $delivery_tracking_sql = "
    CREATE TABLE IF NOT EXISTS delivery_tracking (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        status ENUM('order_placed', 'order_confirmed', 'preparing', 'ready_for_pickup', 'picked_up', 'out_for_delivery', 'delivered', 'failed_delivery', 'cancelled') NOT NULL,
        status_message VARCHAR(255) NULL COMMENT '상태 메시지',
        latitude DECIMAL(10, 8) NULL COMMENT '현재 위도',
        longitude DECIMAL(11, 8) NULL COMMENT '현재 경도',
        updated_by_user_id INT NULL COMMENT '상태 업데이트한 사용자',
        notes TEXT NULL COMMENT '추가 메모',
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_delivery_tracking_order_id (order_id),
        INDEX idx_delivery_tracking_timestamp (timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($delivery_tracking_sql);
        echo "<p class='success'>✓ delivery_tracking 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ delivery_tracking 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 5. 장바구니 테이블 (외래키 없이)
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
        UNIQUE KEY uk_cart_user_product_store (user_id, product_id, store_id),
        INDEX idx_shopping_cart_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($shopping_cart_sql);
        echo "<p class='success'>✓ shopping_cart 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ shopping_cart 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 6. 위시리스트 테이블
    echo "<h2>6. 위시리스트 테이블</h2>";
    
    $wishlists_sql = "
    CREATE TABLE IF NOT EXISTS wishlists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_wishlist_user_product (user_id, product_id),
        INDEX idx_wishlists_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($wishlists_sql);
        echo "<p class='success'>✓ wishlists 테이블 생성 완료</p>";
    } catch (PDOException $e) {
        echo "<p class='error'>✗ wishlists 테이블 생성 실패: " . $e->getMessage() . "</p>";
    }
    
    // 7. 생성된 테이블 확인
    echo "<h2>7. 설치 완료 확인</h2>";
    
    $delivery_tables = $pdo->query("SHOW TABLES LIKE 'delivery_%'")->fetchAll(PDO::FETCH_COLUMN);
    $other_tables = [];
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'shopping_cart'");
    if ($stmt->rowCount() > 0) {
        $other_tables[] = 'shopping_cart';
    }
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'wishlists'");
    if ($stmt->rowCount() > 0) {
        $other_tables[] = 'wishlists';
    }
    
    $all_tables = array_merge($delivery_tables, $other_tables);
    
    echo "<p>생성된 테이블 (" . count($all_tables) . "개):</p><ul>";
    foreach ($all_tables as $table) {
        echo "<li class='success'>✓ $table</li>";
    }
    echo "</ul>";
    
    // 8. 설정 확인
    echo "<h2>8. 기본 설정 확인</h2>";
    
    $settings_count = $pdo->query("SELECT COUNT(*) FROM delivery_settings")->fetchColumn();
    echo "<p class='success'>✓ 기본 설정: {$settings_count}개</p>";
    
    $zones_count = $pdo->query("SELECT COUNT(*) FROM delivery_zones")->fetchColumn();
    echo "<p class='success'>✓ 배달 지역: {$zones_count}개</p>";
    
    echo "<div style='background:#d4edda;border:1px solid #c3e6cb;padding:15px;border-radius:5px;margin:20px 0;'>";
    echo "<h3 style='color:#155724;margin:0;'>🎉 테이블 생성 완료!</h3>";
    echo "<p>이제 API를 테스트할 수 있습니다:</p>";
    echo "<ul>";
    echo "<li><a href='/min/api' target='_blank'>API 정보</a></li>";
    echo "<li><a href='/min/api/products' target='_blank'>상품 목록</a></li>";
    echo "<li><a href='/min/api/products/categories' target='_blank'>카테고리</a></li>";
    echo "<li><a href='/min/api/products/brands' target='_blank'>브랜드</a></li>";
    echo "<li><a href='/min/api/test_api.php' target='_blank'>API 테스트 페이지</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<p class='error'>✗ 오류 발생: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";
?>