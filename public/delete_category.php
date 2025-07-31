<?php
require_once __DIR__ . '/../lib/session_helper.php';
ensure_logged_in();

// 총괄관리자만 접근 가능
if ($_SESSION['role'] !== 'super_admin') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '이 작업에 대한 권한이 없습니다.'];
    header('Location: category_management.php');
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$category_id = $_GET['id'] ?? null;
if (!$category_id || !filter_var($category_id, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 카테고리 ID입니다.'];
    header('Location: category_management.php');
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->execute([$category_id]);

    $_SESSION['flash'] = ['type' => 'success', 'message' => '카테고리가 성공적으로 삭제되었습니다.'];

} catch (PDOException $e) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '카테고리 삭제 중 오류가 발생했습니다: ' . $e->getMessage()];
}

header('Location: category_management.php');
exit;