<?php
require_once __DIR__ . '/../lib/session_helper.php';
ensure_logged_in();
require_once __DIR__ . '/../lib/permission_helper.php';

// 접근 권한 확인 (점장 이상: 점장/센터장, 관리자, 총괄관리자)
if (current_user_level() < LEVEL_BRANCH_MANAGER) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '삭제할 권한이 없습니다.'];
    header("Location: product_management.php");
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$product_id = $_GET['id'] ?? null;

if (!$product_id || !is_numeric($product_id)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '잘못된 접근입니다.'];
    header("Location: product_management.php");
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 상품이 존재하는지 확인
    $stmt = $pdo->prepare("SELECT name_ko FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if ($product) {
        // 트랜잭션 시작
        $pdo->beginTransaction();
        
        try {
            // 관련 데이터 삭제 (inventory 테이블의 데이터)
            $inventory_stmt = $pdo->prepare("DELETE FROM inventory WHERE product_id = ?");
            $inventory_stmt->execute([$product_id]);
            
            // 상품 삭제
            $delete_stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $delete_stmt->execute([$product_id]);

            // 트랜잭션 커밋
            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'success', 'message' => "상품 '{$product['name_ko']}'이(가) 성공적으로 삭제되었습니다."];
            
        } catch (Exception $e) {
            // 트랜잭션 롤백
            $pdo->rollback();
            throw $e;
        }
        
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '존재하지 않는 상품입니다.'];
    }

} catch (PDOException $e) {
    error_log("Product deletion error: " . $e->getMessage());
    
    // 외래 키 제약 조건 오류 처리
    if ($e->getCode() == '23000') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '해당 상품을 참조하는 다른 데이터가 있어 삭제할 수 없습니다. 관련 데이터를 먼저 삭제해주세요.'];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '데이터베이스 오류로 인해 상품을 삭제하지 못했습니다.'];
    }
}

header("Location: product_management.php");
exit;
?>