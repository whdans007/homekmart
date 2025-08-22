<?php
require_once __DIR__ . '/../config/db_config.php';

// 관리자만 접근 가능하도록 간단한 보안 검사
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    die('Access denied. Super admin only.');
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>도매판매 테이블 생성 중...</h2>";
    
    // 도매 거래처 테이블
    $sql1 = "
    CREATE TABLE IF NOT EXISTS `wholesale_customers` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(255) NOT NULL COMMENT '거래처명',
        `phone` varchar(50) DEFAULT NULL COMMENT '전화번호',
        `address` text DEFAULT NULL COMMENT '주소',
        `memo` text DEFAULT NULL COMMENT '기타 메모',
        `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_name` (`name`),
        INDEX `idx_phone` (`phone`),
        INDEX `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 거래처 정보'
    ";
    
    $pdo->exec($sql1);
    echo "✅ wholesale_customers 테이블 생성 완료<br>";
    
    // 도매 상품 테이블 (외래키 제약조건 없이)
    $sql2 = "
    CREATE TABLE IF NOT EXISTS `wholesale_products` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `product_id` int(11) NOT NULL COMMENT '상품 ID',
        `store_id` int(11) NOT NULL COMMENT '점포 ID',
        `wholesale_price` decimal(10,2) NOT NULL COMMENT '도매가',
        `min_quantity` int(11) DEFAULT 1 COMMENT '최소 주문 수량',
        `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_product_store` (`product_id`, `store_id`),
        INDEX `idx_product_id` (`product_id`),
        INDEX `idx_store_id` (`store_id`),
        INDEX `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 상품 가격 정보'
    ";
    
    $pdo->exec($sql2);
    echo "✅ wholesale_products 테이블 생성 완료<br>";
    
    // 도매 판매 테이블 (외래키 제약조건 없이)
    $sql3 = "
    CREATE TABLE IF NOT EXISTS `wholesale_sales` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `customer_id` int(11) NOT NULL COMMENT '거래처 ID',
        `store_id` int(11) NOT NULL COMMENT '점포 ID',
        `user_id` int(11) NOT NULL COMMENT '판매자 ID',
        `sale_date` date NOT NULL COMMENT '판매 날짜',
        `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '총 판매 금액',
        `tax_amount` decimal(12,2) DEFAULT 0.00 COMMENT '세금 금액',
        `discount_amount` decimal(12,2) DEFAULT 0.00 COMMENT '할인 금액',
        `final_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '최종 금액',
        `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft' COMMENT '상태',
        `notes` text DEFAULT NULL COMMENT '비고',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_customer_id` (`customer_id`),
        INDEX `idx_store_id` (`store_id`),
        INDEX `idx_user_id` (`user_id`),
        INDEX `idx_sale_date` (`sale_date`),
        INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 판매 내역'
    ";
    
    $pdo->exec($sql3);
    echo "✅ wholesale_sales 테이블 생성 완료<br>";
    
    // 도매 판매 항목 테이블 (외래키 제약조건 없이)
    $sql4 = "
    CREATE TABLE IF NOT EXISTS `wholesale_sale_items` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `sale_id` int(11) NOT NULL COMMENT '판매 ID',
        `product_id` int(11) NOT NULL COMMENT '상품 ID',
        `quantity` int(11) NOT NULL COMMENT '수량',
        `unit_price` decimal(10,2) NOT NULL COMMENT '단가',
        `total_price` decimal(12,2) NOT NULL COMMENT '총가격',
        `notes` varchar(255) DEFAULT NULL COMMENT '비고',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_sale_id` (`sale_id`),
        INDEX `idx_product_id` (`product_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 판매 항목'
    ";
    
    $pdo->exec($sql4);
    echo "✅ wholesale_sale_items 테이블 생성 완료<br>";
    
    // 샘플 데이터 삽입
    $sample_customers = "
    INSERT INTO `wholesale_customers` (`name`, `phone`, `address`, `memo`) VALUES
    ('테스트 거래처1', '02-1234-5678', '서울시 강남구', '테스트용 거래처입니다'),
    ('테스트 거래처2', '031-123-4567', '경기도 성남시', '정기 거래처')
    ";
    
    try {
        $pdo->exec($sample_customers);
        echo "✅ 샘플 거래처 데이터 삽입 완료<br>";
    } catch (PDOException $e) {
        // 이미 데이터가 있는 경우 무시
        echo "ℹ️ 샘플 데이터는 이미 존재하거나 삽입할 수 없습니다<br>";
    }
    
    echo "<br><h3>✅ 모든 도매판매 테이블이 성공적으로 생성되었습니다!</h3>";
    echo "<a href='wholesale_customer_management.php'>거래처 관리로 이동</a>";
    
} catch (PDOException $e) {
    echo "<h3>❌ 오류 발생:</h3>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<p>SQL 상태 코드: " . $e->getCode() . "</p>";
}
?>