<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/inventory_ledger.php';

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
        if ($_SESSION['role'] !== 'super_admin') {
            $user_store_id = get_user_store_id();
            if ($sale['store_id'] != $user_store_id) {
                $response['message'] = '다른 점포의 판매는 취소할 수 없습니다.';
                echo json_encode($response);
                exit;
            }
        }
        
        // 이미 취소된 판매인지 확인
        if ($sale['status'] === 'cancelled') {
            $response['message'] = '이미 취소된 판매입니다.';
            echo json_encode($response);
            exit;
        }
        
        $pdo->beginTransaction();

        // 판매 상태를 취소로 변경
        $update_sql = "UPDATE wholesale_sales SET status = 'cancelled', updated_at = NOW() WHERE id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$sale_id]);

        // Design §4.5: 취소 시 확정 때 반영했던 WHOLESALE_OUT을 RETURN_IN으로 복구한다.
        // 수기 상품(product_id 없음)은 애초에 재고 반영 대상이 아니므로 제외.
        $items_stmt = $pdo->prepare("SELECT id, product_id, quantity, sale_unit FROM wholesale_sale_items WHERE sale_id = ? AND product_id IS NOT NULL");
        $items_stmt->execute([$sale_id]);
        foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $sale_item) {
            $pieces_stmt = $pdo->prepare('SELECT pieces_per_box FROM products WHERE id = ?');
            $pieces_stmt->execute([$sale_item['product_id']]);
            $pieces_per_box = (int)($pieces_stmt->fetchColumn() ?: 1);
            if ($pieces_per_box <= 0) { $pieces_per_box = 1; }
            $actual_qty = ($sale_item['sale_unit'] === 'box') ? round((float)$sale_item['quantity'] * $pieces_per_box, 2) : round((float)$sale_item['quantity'], 2);
            if ($actual_qty > 0) {
                inventory_apply_delta_pdo($pdo, 'wholesale_sale_item_cancel', (int)$sale_item['id'], (int)$sale['store_id'], (int)$sale_item['product_id'], $actual_qty, 'RETURN_IN', (int)($_SESSION['user_id'] ?? 0) ?: null, "도매 판매 취소 복구 (Sale ID: {$sale_id})");
            }
        }

        $pdo->commit();
        $response['success'] = true;
        $response['message'] = '판매가 성공적으로 취소되었습니다.';
        
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Wholesale sale cancel error: " . $e->getMessage());
        $response['message'] = '데이터베이스 오류가 발생했습니다.';
    }
} else {
    $response['message'] = '잘못된 요청입니다.';
}

echo json_encode($response);
?>
