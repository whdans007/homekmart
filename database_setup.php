<?php
/**
 * 데이터베이스 스키마 추출 및 분석 도구
 * 현재 로컬 데이터베이스 구조를 분석하여 전체 스키마를 생성합니다.
 */

echo "<!DOCTYPE html>";
echo "<html lang='ko'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<title>DB 스키마 분석기</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }";
echo ".container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }";
echo ".sql-output { background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 4px; font-family: monospace; white-space: pre-wrap; }";
echo ".success { color: #22c55e; }";
echo ".error { color: #ef4444; }";
echo ".warning { color: #f59e0b; }";
echo "</style>";
echo "</head>";
echo "<body>";
echo "<div class='container'>";
echo "<h1>🗃️ 데이터베이스 스키마 분석기</h1>";

try {
    require_once __DIR__ . '/config/db_config.php';
    
    // 로컬 데이터베이스 연결 (스키마 추출용)
    $local_conn = new mysqli('localhost', DB_USER, 'PGm9pJCqUW', 'min');
    
    if ($local_conn->connect_error) {
        echo "<p class='error'>❌ 로컬 데이터베이스 연결 실패: " . $local_conn->connect_error . "</p>";
        echo "<p class='warning'>⚠️ 로컬 데이터베이스가 없는 경우, 아래 기본 스키마를 사용하세요.</p>";
    } else {
        echo "<p class='success'>✅ 로컬 데이터베이스 연결 성공</p>";
        
        // 모든 테이블 목록 가져오기
        $tables_result = $local_conn->query("SHOW TABLES");
        $tables = [];
        
        if ($tables_result) {
            while ($row = $tables_result->fetch_array()) {
                $tables[] = $row[0];
            }
            
            echo "<h2>📋 발견된 테이블 목록 (" . count($tables) . "개)</h2>";
            echo "<ul>";
            foreach ($tables as $table) {
                echo "<li>{$table}</li>";
            }
            echo "</ul>";
            
            // 각 테이블의 CREATE 구문 생성
            echo "<h2>🔧 전체 데이터베이스 스키마</h2>";
            echo "<div class='sql-output'>";
            echo "-- HOME K MART 데이터베이스 스키마\n";
            echo "-- 생성일: " . date('Y-m-d H:i:s') . "\n";
            echo "-- 데이터베이스: " . DB_NAME . "\n\n";
            
            echo "-- 데이터베이스 생성 및 사용\n";
            echo "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
            echo "USE `" . DB_NAME . "`;\n\n";
            
            foreach ($tables as $table) {
                $create_result = $local_conn->query("SHOW CREATE TABLE `{$table}`");
                if ($create_result) {
                    $create_row = $create_result->fetch_array();
                    echo "-- 테이블: {$table}\n";
                    echo $create_row[1] . ";\n\n";
                }
            }
            echo "</div>";
        }
        
        $local_conn->close();
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ 오류 발생: " . $e->getMessage() . "</p>";
}

// 기본 스키마 제공
echo "<h2>📄 기본 스키마 (로컬 DB 없는 경우)</h2>";
echo "<div class='sql-output'>";

$basic_schema = "-- HOME K MART 기본 데이터베이스 스키마
-- 최소 필수 테이블 구조

CREATE DATABASE IF NOT EXISTS `if0_39723369_min` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `if0_39723369_min`;

-- 1. 브랜드 테이블
CREATE TABLE `brands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 카테고리 테이블
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 공급업체 테이블
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 점포 테이블
CREATE TABLE `stores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `manager` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 사용자 테이블
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','admin','staff','office_staff','user') NOT NULL DEFAULT 'user',
  `store_id` int(11) DEFAULT NULL,
  `permissions` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `store_id` (`store_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 상품 테이블
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `barcode` varchar(255) DEFAULT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `pieces_per_box` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`),
  KEY `brand_id` (`brand_id`),
  KEY `category_id` (`category_id`),
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_ibfk_3` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. 재고 테이블
CREATE TABLE `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `selling_price` decimal(10,2) DEFAULT NULL,
  `box_price` decimal(10,2) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_store` (`product_id`,`store_id`),
  KEY `store_id` (`store_id`),
  CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. 구매 테이블
CREATE TABLE `purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `purchase_date` date NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchases_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchases_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. 구매 항목 테이블
CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `box_price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. 마진 규칙 테이블
CREATE TABLE `margin_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `margin_rate` decimal(5,2) NOT NULL DEFAULT 30.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_id` (`category_id`),
  CONSTRAINT `margin_rules_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기본 데이터 삽입
INSERT INTO `stores` (`id`, `name`, `address`, `phone`, `manager`) VALUES
(1, 'HOME K MART 본점', '대한민국 서울시', '02-1234-5678', '관리자');

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `store_id`) VALUES
(1, 'admin', 'admin@homekmart.com', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 1);

INSERT INTO `categories` (`id`, `name`, `description`) VALUES
(1, '기본 카테고리', '기본 상품 카테고리');

INSERT INTO `brands` (`id`, `name`, `description`) VALUES
(1, '기본 브랜드', '기본 브랜드');
";

echo $basic_schema;
echo "</div>";

echo "<h2>📥 배포 가이드</h2>";
echo "<ol>";
echo "<li><strong>데이터베이스 생성</strong>: InfinityFree 호스팅의 MySQL 관리 도구 접속</li>";
echo "<li><strong>스키마 실행</strong>: 위의 SQL 코드를 복사하여 실행</li>";
echo "<li><strong>파일 업로드</strong>: 프로젝트 파일들을 htdocs 폴더에 업로드</li>";
echo "<li><strong>검증</strong>: deployment_test.php로 설정 확인</li>";
echo "</ol>";

echo "<h2>🔑 기본 로그인 정보</h2>";
echo "<ul>";
echo "<li><strong>아이디</strong>: admin</li>";
echo "<li><strong>이메일</strong>: admin@homekmart.com</li>";
echo "<li><strong>비밀번호</strong>: password (변경 권장)</li>";
echo "</ul>";

echo "</div>";
echo "</body>";
echo "</html>";
?>