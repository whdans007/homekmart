<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// 관리자만 접근 가능
if (!in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    die("이 페이지에 접근할 권한이 없습니다.");
}

$log_file = __DIR__ . '/../database_changes.md';

function log_db_change($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "## {$timestamp}\n\n{$message}\n\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

$page_title = "데이터베이스 설정";
require_once __DIR__ . '/partials/header.php';

echo "<div class='container mx-auto px-4 sm:px-6 lg:px-8 py-8'>";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "CREATE TABLE IF NOT EXISTS `margin_rules` (
        `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `category_id` INT(11) UNSIGNED NOT NULL,
        `margin_percentage` DECIMAL(5, 2) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `category_id_unique` (`category_id`),
        FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);

    $message = "테이블 `margin_rules`가 성공적으로 생성되었습니다.";
    log_db_change("테이블 `margin_rules` 생성 성공.\n\n```sql\n$sql\n```");
    
echo "<div class='bg-green-50 border border-green-200 rounded-md p-4 mb-6'>";
    echo "<p class='text-sm text-green-800'>" . htmlspecialchars($message) . "</p>";
    echo "<p class='mt-2 text-sm text-gray-700'>3초 후에 마진 관리 페이지로 이동합니다...</p>";
    echo "</div>";
    echo "<script>setTimeout(() => { window.location.href = 'margin_management.php'; }, 3000);</script>";

} catch (PDOException $e) {
    $message = "데이터베이스 설정 중 오류가 발생했습니다: " . $e->getMessage();
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'>";
    echo "<p class='text-sm text-red-800'>" . htmlspecialchars($message) . "</p>";
    echo "</div>";
} catch (Exception $e) {
    $message = "알 수 없는 오류가 발생했습니다: " . $e->getMessage();
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'>";
    echo "<p class='text-sm text-red-800'>" . htmlspecialchars($message) . "</p>";
    echo "</div>";
}

echo "</div>";

require_once __DIR__ . '/partials/footer.php';
?>