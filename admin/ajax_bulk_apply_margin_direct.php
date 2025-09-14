<?php
// 출력 버퍼링 시작
ob_start();

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 세션 시작
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 출력 버퍼 정리
ob_clean();
header('Content-Type: application/json');

// 권한 확인
if (!is_logged_in() || !has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

// 파라미터 받기
$purchase_id = $_POST['purchase_id'] ?? '';
$item_ids = $_POST['item_ids'] ?? [];
$margin_rate = $_POST['margin_rate'] ?? '';
$store_id = $_POST['store_id'] ?? '';

// 검증
if (empty($purchase_id) || empty($item_ids) || !is_array($item_ids)) {
    echo json_encode(['success' => false, 'message' => '필수 데이터가 없습니다.']);
    exit;
}

// 데이터베이스 연결
$conn = get_db_connection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => '데이터베이스 연결 실패']);
    exit;
}

$updated_count = 0;
$updated_items = [];
$errors = [];

// 각 아이템 개별 처리
foreach ($item_ids as $item_id) {
    if (!is_numeric($item_id)) {
        continue;
    }
    
    // 직접 매핑으로 데이터 가져오기
    $product_id = $_POST["product_id_{$item_id}"] ?? null;
    $selling_price = $_POST["selling_price_{$item_id}"] ?? null;
    
    if (!$product_id || !is_numeric($product_id)) {
        $errors[] = "Item {$item_id}의 product_id를 찾을 수 없습니다";
        continue;
    }
    
    // 1. purchase_items에서 정보 조회
    $sql = "SELECT pi.*, p.name_ko, p.pieces_per_box 
            FROM purchase_items pi
            JOIN products p ON pi.product_id = p.id
            WHERE pi.item_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    
    if (!$item) {
        $errors[] = "Item {$item_id}를 찾을 수 없습니다";
        continue;
    }
    
    // 원가 계산 (낱개 기준)
    $cost_price = $item['unit_price'];
    if ($item['purchase_type'] === 'box' && $item['pieces_per_box'] > 0) {
        $cost_price = $item['unit_price'] / $item['pieces_per_box'];
    }
    
    // 판매가 설정
    if ($selling_price && is_numeric($selling_price)) {
        $new_selling_price = intval($selling_price);
    } else {
        $new_selling_price = ceil($cost_price * (1 + ($margin_rate / 100)));
    }
    
    // 2. store_id가 있으면 inventory 업데이트
    if ($store_id && is_numeric($store_id)) {
        // inventory 확인
        $sql_check = "SELECT id FROM inventory WHERE product_id = ? AND store_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ii", $product_id, $store_id);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        
        if ($check_result->num_rows > 0) {
            // 업데이트
            $sql_update = "UPDATE inventory SET cost_price = ?, selling_price = ?, updated_at = NOW() 
                          WHERE product_id = ? AND store_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("ddii", $cost_price, $new_selling_price, $product_id, $store_id);
            $stmt_update->execute();
        } else {
            // 삽입
            $sql_insert = "INSERT INTO inventory (product_id, store_id, cost_price, selling_price, quantity, created_at, updated_at) 
                          VALUES (?, ?, ?, ?, 0, NOW(), NOW())";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("iidd", $product_id, $store_id, $cost_price, $new_selling_price);
            $stmt_insert->execute();
        }
    } else {
        // 3. products 테이블 업데이트
        $sql_update = "UPDATE products SET cost_price = ?, selling_price = ?, updated_at = NOW() WHERE id = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("ddi", $cost_price, $new_selling_price, $product_id);
        $stmt_update->execute();
    }
    
    $updated_count++;
    $updated_items[] = [
        'item_id' => $item_id,
        'product_id' => $product_id,
        'product_name' => $item['name_ko'],
        'cost_price' => number_format($cost_price, 2),
        'selling_price' => number_format($new_selling_price, 0)
    ];
}

$conn->close();

// 응답
if ($updated_count > 0) {
    echo json_encode([
        'success' => true,
        'message' => $updated_count . '개 상품의 가격이 업데이트되었습니다.',
        'updated_count' => $updated_count,
        'updated_items' => $updated_items,
        'errors' => $errors
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => '업데이트된 상품이 없습니다.',
        'errors' => $errors
    ]);
}
?>