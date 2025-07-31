<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// Super admin check
if (!is_logged_in() || !($_SESSION['role'] === 'super_admin')) {
    http_response_code(403);
    echo "<h1>접근 권한이 없습니다.</h1><p>슈퍼 관리자 계정으로 로그인해야 합니다.</p>";
    exit;
}

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $conn = null;
    try {
        $conn = get_db_connection();
        
        // 1. 외래 키 체크 비활성화
        $conn->query("SET FOREIGN_KEY_CHECKS=0");

        // 2. 테이블 데이터 삭제
        $sql = "TRUNCATE TABLE products";
        
        if ($conn->query($sql) === TRUE) {
            $message = "'products' 테이블의 모든 데이터가 성공적으로 삭제되었습니다.";
            $message_type = 'success';
        } else {
            $message = "오류: " . htmlspecialchars($conn->error);
            $message_type = 'error';
        }
    } catch (Exception $e) {
        $message = "스크립트 실행 중 예외가 발생했습니다: " . htmlspecialchars($e->getMessage());
        $message_type = 'error';
    } finally {
        if ($conn) {
            // 3. 외래 키 체크 다시 활성화
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>상품 데이터 전체 삭제</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; padding: 2em; max-width: 800px; margin: auto; background-color: #f8f9fa; color: #212529; }
        .container { background-color: #fff; border: 1px solid #dee2e6; padding: 2em; border-radius: 8px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { color: #dc3545; }
        p { margin-bottom: 1em; font-size: 1.1em;}
        strong { color: #dc3545; }
        button { background-color: #dc3545; color: white; border: none; padding: 12px 24px; font-size: 16px; border-radius: 5px; cursor: pointer; transition: background-color 0.2s; }
        button:hover { background-color: #c82333; }
        .message { padding: 1em; border-radius: 5px; margin-top: 1.5em; }
        .success { background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .final-warning { margin-top: 1.5em; padding: 1em; background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚠️ 경고: 상품 데이터 전체 삭제</h1>
        <p>이 작업은 <strong>되돌릴 수 없습니다.</strong></p>
        <p>'products' 테이블에 있는 모든 상품 데이터가 영구적으로 삭제됩니다.<br>계속 진행하시겠습니까?</p>
        
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php else: ?>
            <form method="POST" action="">
                <button type="submit" name="confirm_delete" value="1">예, 모든 상품 데이터를 삭제합니다.</button>
            </form>
        <?php endif; ?>

        <?php if ($message_type === 'success'): ?>
            <div class="final-warning">
                <strong>중요:</strong> 작업이 완료되었으니, 보안을 위해 이 파일(public/clear_products.php)을 즉시 삭제해 주세요.
            </div>
        <?php endif; ?>
    </div>
</body>
</html> 