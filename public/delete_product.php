<?php
require_once __DIR__ . '/../lib/session_helper.php';
ensure_logged_in();

// 접근 권한 확인 (총괄관리자 또는 관리자)
if (!in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '삭제할 권한이 없습니다.'];
    header("Location: product_management.php");
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$product_id = $_GET['id'] ?? null;

if (!$product_id) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '잘못된 접근입니다.'];
    header("Location: product_management.php");
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 상품이 존재하는지 확인
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if ($product) {
        // 상품 삭제
        $delete_stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $delete_stmt->execute([$product_id]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => '상품이 성공적으로 삭제되었습니다.'];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '존재하지 않는 상품입니다.'];
    }

} catch (PDOException $e) {
    // 외래 키 제약 조건 오류 처리
    if ($e->getCode() == '23000') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '해당 상품을 참조하는 다른 데이터(예: 재고)가 있어 삭제할 수 없습니다.'];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '데이터베이스 오류로 인해 상품을 삭제하지 못했습니다: ' . $e->getMessage()];
    }
}

header("Location: product_management.php");
exit;
?>