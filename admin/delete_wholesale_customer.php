<?php
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 로그인 확인
ensure_logged_in();

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$customer_id = $_GET['id'] ?? 0;

if (empty($customer_id)) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => '잘못된 접근입니다.'
    ];
    header('Location: wholesale_customer_management.php');
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 거래처 존재 확인
    $stmt = $pdo->prepare("SELECT name FROM wholesale_customers WHERE id = ? AND is_active = 1");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '거래처를 찾을 수 없습니다.'
        ];
        header('Location: wholesale_customer_management.php');
        exit;
    }
    
    // 해당 거래처의 판매 내역 확인
    $sales_check = $pdo->prepare("SELECT COUNT(*) FROM wholesale_sales WHERE customer_id = ?");
    $sales_check->execute([$customer_id]);
    $sales_count = $sales_check->fetchColumn();
    
    if ($sales_count > 0) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '이 거래처는 판매 내역이 있어 삭제할 수 없습니다. 비활성화만 가능합니다.'
        ];
        header('Location: wholesale_customer_management.php');
        exit;
    }
    
    // 거래처 삭제 (논리적 삭제 - is_active를 0으로 설정)
    $delete_stmt = $pdo->prepare("UPDATE wholesale_customers SET is_active = 0, updated_at = NOW() WHERE id = ?");
    
    if ($delete_stmt->execute([$customer_id])) {
        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => '\'' . htmlspecialchars($customer['name']) . '\' 거래처가 성공적으로 삭제되었습니다.'
        ];
    } else {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => '거래처 삭제 중 오류가 발생했습니다.'
        ];
    }
    
} catch (PDOException $e) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => '데이터베이스 오류: ' . $e->getMessage()
    ];
}

header('Location: wholesale_customer_management.php');
exit;