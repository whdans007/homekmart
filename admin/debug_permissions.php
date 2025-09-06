<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';

ensure_logged_in();

echo "<h1>현재 사용자 권한 디버그</h1>";
echo "<p><strong>사용자:</strong> " . ($_SESSION['username'] ?? 'N/A') . "</p>";
echo "<p><strong>역할:</strong> " . ($_SESSION['role'] ?? 'N/A') . "</p>";
echo "<p><strong>사용자 ID:</strong> " . ($_SESSION['user_id'] ?? 'N/A') . "</p>";

echo "<h2>권한 체크</h2>";
$permissions = [
    'admin_access',
    'wholesale_management',
    'product_management',
    'purchase_management',
    'user_management',
    'store_management'
];

foreach ($permissions as $permission) {
    $has_perm = has_permission($permission);
    echo "<p><strong>{$permission}:</strong> " . ($has_perm ? '✅ 허용' : '❌ 거부') . "</p>";
}

echo "<h2>사용자 상세 정보</h2>";
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/db_config.php';
    $conn = get_db_connection();
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if ($user) {
        echo "<pre>";
        print_r($user);
        echo "</pre>";
    }
    
    $conn->close();
}
?>