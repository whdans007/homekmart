<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => '권한이 없습니다.'
    ];
    header('Location: shop.php');
    exit;
}

// ID 확인
$wholesale_product_id = (int)($_GET['id'] ?? 0);
if ($wholesale_product_id <= 0) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => '잘못된 요청입니다.'
    ];
    header('Location: wholesale_product_management.php');
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 현재 사용자 점포 정보 가져오기
    $current_store_id = null;
    if (!empty($_SESSION['user_id'])) {
        $user_stmt = $pdo->prepare("SELECT store_id FROM users WHERE id = ?");
        $user_stmt->execute([$_SESSION['user_id']]);
        $user_result = $user_stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_result) {
            $current_store_id = $user_result['store_id'];
        }
    }
    
    // 도매상품 정보 가져오기
    $stmt = $pdo->prepare("
        SELECT wp.*, p.name_ko, p.name_en, p.sku
        FROM wholesale_products wp
        LEFT JOIN products p ON wp.product_id = p.id
        WHERE wp.id = ? AND wp.is_active = 1
    ");
    $stmt->execute([$wholesale_product_id]);
    $wholesale_product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$wholesale_product) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '해당 도매상품을 찾을 수 없습니다.'
        ];
        header('Location: wholesale_product_management.php');
        exit;
    }
    
    // 권한 확인 (super_admin이 아닌 경우 자신의 점포만 삭제 가능)
    if ($_SESSION['role'] !== 'super_admin' && $wholesale_product['store_id'] != $current_store_id) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '다른 점포의 상품은 삭제할 수 없습니다.'
        ];
        header('Location: wholesale_product_management.php');
        exit;
    }
    
    // 이 도매상품이 판매 내역에 있는지 확인
    $sales_check_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM wholesale_sale_items wsi
        INNER JOIN wholesale_sales ws ON wsi.sale_id = ws.id
        WHERE wsi.product_id = ? AND ws.store_id = ?
    ");
    $sales_check_stmt->execute([$wholesale_product['product_id'], $wholesale_product['store_id']]);
    $sales_count = $sales_check_stmt->fetchColumn();
    
    if ($sales_count > 0) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '판매 내역이 있는 상품은 삭제할 수 없습니다. 비활성화만 가능합니다.'
        ];
        header('Location: wholesale_product_management.php');
        exit;
    }
    
    // 도매상품 삭제 (실제로는 is_active = 0으로 변경)
    $delete_stmt = $pdo->prepare("
        UPDATE wholesale_products 
        SET is_active = 0, updated_at = NOW() 
        WHERE id = ?
    ");
    
    if ($delete_stmt->execute([$wholesale_product_id])) {
        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => '도매상품이 성공적으로 삭제되었습니다.'
        ];
    } else {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '도매상품 삭제 중 오류가 발생했습니다.'
        ];
    }
    
} catch (PDOException $e) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => '데이터베이스 오류: ' . $e->getMessage()
    ];
}

header('Location: wholesale_product_management.php');
exit;
?>