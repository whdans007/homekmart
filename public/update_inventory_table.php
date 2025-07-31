<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

$page_title = "데이터베이스 업데이트";
require_once __DIR__ . '/partials/header.php';

// 관리자만 접근 가능 (헤더가 로드된 후, 세션이 활성화된 상태에서 확인)
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

echo "<div class='container mx-auto px-4 sm:px-6 lg:px-8 py-8'>";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // inventory 테이블에 selling_price 컬럼이 없는 경우에만 추가
    $stmt = $pdo->query("SHOW COLUMNS FROM `inventory` LIKE 'selling_price'");
    $column_exists = $stmt->fetch();

    if (!$column_exists) {
        $sql = "ALTER TABLE `inventory` ADD `selling_price` DECIMAL(10, 2) NULL DEFAULT NULL AFTER `quantity`;";
        $pdo->exec($sql);
        $message = "테이블 `inventory`가 성공적으로 업데이트되었습니다. `selling_price` 컬럼이 추가되었습니다.";
        
        // 데이터베이스 변경 로그 기록
        $log_message = "테이블 `inventory`에 `selling_price` 컬럼 추가.\n\n```sql\n$sql\n```";
        log_db_change($log_message);

    } else {
        $message = "테이블 `inventory`는 이미 최신 상태입니다.";
    }
    
    echo "<div class='bg-green-50 border border-green-200 rounded-md p-4 mb-6'>";
    echo "<p class='text-sm text-green-800'>" . htmlspecialchars($message) . "</p>";
    echo "<p class='mt-2 text-sm text-gray-700'>3초 후에 상품 관리 페이지로 이동합니다...</p>";
    echo "</div>";
    echo "<script>setTimeout(() => { window.location.href = 'product_management.php'; }, 3000);</script>";

} catch (PDOException $e) {
    $message = "데이터베이스 업데이트 중 오류가 발생했습니다: " . $e->getMessage();
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'>";
    echo "<p class='text-sm text-red-800'>" . htmlspecialchars($message) . "</p>";
    echo "</div>";
}

echo "</div>";

require_once __DIR__ . '/partials/footer.php';
?>