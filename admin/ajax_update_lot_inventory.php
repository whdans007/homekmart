<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

if (!has_permission('product_management') && !has_permission('shop_access')) {
    echo json_encode(['success' => false, 'error' => '권한이 없습니다.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => '잘못된 요청입니다.']);
    exit;
}

$product_id = (int)($input['product_id'] ?? 0);
$store_id = (int)($input['store_id'] ?? ($_SESSION['store_id'] ?? 0));
$lots = $input['lots'] ?? [];

if (!$product_id || !$store_id) {
    echo json_encode(['success' => false, 'error' => '필수 파라미터 누락']);
    exit;
}

try {
    $conn = get_db_connection();
    $conn->begin_transaction();

    $total_quantity = 0;

    foreach ($lots as $lot) {
        $id = isset($lot['id']) ? (int)$lot['id'] : 0;
        $expiration_date = $lot['expiration_date'] ?? '';
        $quantity = (int)($lot['quantity'] ?? 0);
        
        if (empty($expiration_date)) continue;

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE inventory_expirations SET expiration_date = ?, quantity = ? WHERE id = ? AND store_id = ? AND product_id = ?");
            $stmt->bind_param("siiii", $expiration_date, $quantity, $id, $store_id, $product_id);
            $stmt->execute();
            $stmt->close();
        } else {
            // New Lot
            $stmt = $conn->prepare("INSERT INTO inventory_expirations (store_id, product_id, expiration_date, quantity) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iisi", $store_id, $product_id, $expiration_date, $quantity);
            $stmt->execute();
            $stmt->close();
        }
        $total_quantity += $quantity;
    }

    // 변경된 유통기한 로트의 총 재고량에 맞추어 주 재고 테이블(inventory)도 동기화
    // 기존 inventory 레코드가 있을 경우만 업데이트 (데이터 손실 방지)
    // 없으면 새로 생성
    $check_stmt = $conn->prepare("SELECT id FROM inventory WHERE product_id = ? AND store_id = ? LIMIT 1");
    $check_stmt->bind_param("ii", $product_id, $store_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows > 0) {
        // 기존 레코드 업데이트
        $inv_stmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE product_id = ? AND store_id = ?");
        $inv_stmt->bind_param("iii", $total_quantity, $product_id, $store_id);
        $inv_stmt->execute();
        $inv_stmt->close();
    } else {
        // 새로 생성 (inventory 테이블이 있을 경우)
        $inv_stmt = $conn->prepare("INSERT IGNORE INTO inventory (product_id, store_id, quantity, cost_price, selling_price) VALUES (?, ?, ?, 0, 0)");
        $inv_stmt->bind_param("iii", $product_id, $store_id, $total_quantity);
        $inv_stmt->execute();
        $inv_stmt->close();
    }
    $check_stmt->close();

    $conn->commit();
    $conn->close();

    echo json_encode(['success' => true, 'message' => '로트 재고가 성공적으로 업데이트되고 주 재고와 동기화되었습니다.']);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    echo json_encode(['success' => false, 'error' => '업데이트 실패: ' . $e->getMessage()]);
}
