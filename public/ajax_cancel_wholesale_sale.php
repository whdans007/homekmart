<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 세션 및 권한 확인
ensure_logged_in();
if (!has_permission('wholesale_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sale_id = (int)($_POST['sale_id'] ?? 0);
    
    if ($sale_id <= 0) {
        $response['message'] = '유효하지 않은 판매 ID입니다.';
        echo json_encode($response);
        exit;
    }
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 판매 정보 확인
        $check_sql = "SELECT id, status, store_id FROM wholesale_sales WHERE id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$sale_id]);
        $sale = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sale) {
            $response['message'] = '해당 판매 내역을 찾을 수 없습니다.';
            echo json_encode($response);
            exit;
        }
        
        // 권한 확인 (super_admin이 아닌 경우 자신의 점포만 취소 가능)
        if ($_SESSION['role'] !== 'super_admin' && $sale['store_id'] != $_SESSION['store_id']) {
            $response['message'] = '다른 점포의 판매는 취소할 수 없습니다.';
            echo json_encode($response);
            exit;
        }
        
        // 이미 취소된 판매인지 확인
        if ($sale['status'] === 'cancelled') {
            $response['message'] = '이미 취소된 판매입니다.';
            echo json_encode($response);
            exit;
        }
        
        // 판매 상태를 취소로 변경
        $update_sql = "UPDATE wholesale_sales SET status = 'cancelled', updated_at = NOW() WHERE id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        
        if ($update_stmt->execute([$sale_id])) {
            $response['success'] = true;
            $response['message'] = '판매가 성공적으로 취소되었습니다.';
        } else {
            $response['message'] = '판매 취소 중 오류가 발생했습니다.';
        }
        
    } catch (PDOException $e) {
        error_log("Wholesale sale cancel error: " . $e->getMessage());
        $response['message'] = '데이터베이스 오류가 발생했습니다.';
    }
} else {
    $response['message'] = '잘못된 요청입니다.';
}

echo json_encode($response);
?>