<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOME K MART - 초기 설정</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1>데이터베이스 설정</h1>
        <?php
        require_once __DIR__ . '/../config/db_config.php';

        try {
            // 데이터베이스 연결 (PDO 사용)
            $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 데이터베이스가 존재하지 않으면 생성
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci;");
            $pdo->exec("USE `" . DB_NAME . "`;");

            echo "<div class='message success'>데이터베이스 '" . htmlspecialchars(DB_NAME) . "'에 성공적으로 연결되었습니다.</div>";

            // stores 테이블 SQL
            $stores_sql = "CREATE TABLE IF NOT EXISTS `stores` (
                      `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                      `name` VARCHAR(100) NOT NULL UNIQUE,
                      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($stores_sql);
            echo "<div class='message success'>테이블 'stores'가 성공적으로 생성되었거나 이미 존재합니다.</div>";

            // 초기 지점 데이터 삽입 (재실행 시 오류 방지)
            $initial_stores_sql = "INSERT INTO `stores` (name) VALUES ('CLARK HILLS'), ('THE VILLAGE'), ('KIMS MALL') ON DUPLICATE KEY UPDATE name=name;";
            $pdo->exec($initial_stores_sql);

            // users 테이블 SQL
            $sql = "CREATE TABLE IF NOT EXISTS `users` (
                      `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                      `username` VARCHAR(50) NOT NULL UNIQUE,
                      `password` VARCHAR(255) NOT NULL,
                      `full_name` VARCHAR(100) NOT NULL,
                      `email` VARCHAR(100) UNIQUE,
                      `role` ENUM('user', 'admin', 'super_admin') NOT NULL DEFAULT 'user', /* 일반사용자, 관리자, 총괄관리자 */
                      `store_id` INT(11) UNSIGNED NULL DEFAULT NULL,
                      `remember_token` VARCHAR(255) NULL DEFAULT NULL,
                      `remember_token_expiry` DATETIME NULL DEFAULT NULL,
                      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                      CONSTRAINT `fk_user_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            // SQL 실행
            $pdo->exec($sql);

            echo "<div class='message success'>테이블 'users'가 성공적으로 생성되었습니다.</div>";

            // --- 상품 관리 테이블 생성 시작 ---

            // 2. Categories
            $categories_sql = "
            CREATE TABLE IF NOT EXISTS `categories` (
              `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(100) NOT NULL UNIQUE,
              `parent_id` INT(11) UNSIGNED NULL DEFAULT NULL,
              `default_margin_rate` DECIMAL(5, 4) NULL DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($categories_sql);
            echo "<div class='message success'>테이블 'categories'가 성공적으로 생성되었습니다.</div>";

            // 3. Brands
            $brands_sql = "
            CREATE TABLE IF NOT EXISTS `brands` (
              `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `name_ko` VARCHAR(100) NOT NULL UNIQUE,
              `name_en` VARCHAR(100) NULL DEFAULT NULL,
              `logo_url` VARCHAR(255) NULL DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($brands_sql);
            echo "<div class='message success'>테이블 'brands'가 성공적으로 생성되었습니다.</div>";

            // 4. Suppliers
            $suppliers_sql = "
            CREATE TABLE IF NOT EXISTS `suppliers` (
              `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(100) NOT NULL,
              `contact_person` VARCHAR(100) NULL DEFAULT NULL,
              `phone` VARCHAR(20) NULL DEFAULT NULL,
              `email` VARCHAR(100) NULL DEFAULT NULL,
              `address` VARCHAR(255) NULL DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($suppliers_sql);
            echo "<div class='message success'>테이블 'suppliers'가 성공적으로 생성되었습니다.</div>";

            // 5. Products
            $products_sql = "
            CREATE TABLE IF NOT EXISTS `products` (
              `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `sku` VARCHAR(100) NOT NULL UNIQUE,
              `name_ko` VARCHAR(255) NOT NULL,
              `name_en` VARCHAR(255) NULL DEFAULT NULL,
              `description` TEXT NULL,
              `category_id` INT(11) UNSIGNED NULL DEFAULT NULL,
              `brand_id` INT(11) UNSIGNED NULL DEFAULT NULL,
              `cost_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
              `margin_rate` DECIMAL(5, 4) NULL DEFAULT NULL,
              `selling_price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
              `wholesale_price` DECIMAL(10, 2) NULL DEFAULT NULL,
              `event_price` DECIMAL(10, 2) NULL DEFAULT NULL,
              `event_start_date` DATETIME NULL DEFAULT NULL,
              `event_end_date` DATETIME NULL DEFAULT NULL,
              `image_url` VARCHAR(255) NULL DEFAULT NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `last_modified_by_user_id` INT(11) UNSIGNED NULL DEFAULT NULL,
              FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
              FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE SET NULL,
              FOREIGN KEY (`last_modified_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($products_sql);
            echo "<div class='message success'>테이블 'products'가 성공적으로 생성되었습니다.</div>";

            // 6. Inventory
            $inventory_sql = "
            CREATE TABLE IF NOT EXISTS `inventory` (
              `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `product_id` INT(11) UNSIGNED NOT NULL,
              `store_id` INT(11) UNSIGNED NOT NULL,
              `quantity` INT(11) NOT NULL DEFAULT 0,
              `low_stock_threshold` INT(11) NOT NULL DEFAULT 10,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `product_store_unique` (`product_id`, `store_id`),
              FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
              FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($inventory_sql);
            echo "<div class='message success'>테이블 'inventory'가 성공적으로 생성되었습니다.</div>";

            // 7. Inventory Transactions
            $transactions_sql = "
            CREATE TABLE IF NOT EXISTS `inventory_transactions` (
              `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `inventory_id` INT(11) UNSIGNED NOT NULL,
              `user_id` INT(11) UNSIGNED NULL DEFAULT NULL,
              `transaction_type` ENUM('IN', 'SALE', 'RETURN', 'ADJUST', 'INIT') NOT NULL,
              `quantity_change` INT(11) NOT NULL,
              `remarks` TEXT NULL,
              `transaction_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`inventory_id`) REFERENCES `inventory`(`id`) ON DELETE CASCADE,
              FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($transactions_sql);
            echo "<div class='message success'>테이블 'inventory_transactions'가 성공적으로 생성되었습니다.</div>";

            echo "<h3>초기 설정이 완료되었습니다!</h3>";
            echo '<a href="login.php" class="btn">로그인 페이지로 이동</a>';

        } catch (PDOException $e) { ?>
            <div class='message error'><strong>오류:</strong> 데이터베이스 설정에 실패했습니다. <br> <?php echo htmlspecialchars($e->getMessage()); ?></div>
        <?php } ?>
    </div>
</body>
</html>