<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit();
}

$product_id  = (int)($_GET['product_id']  ?? 0);
$supplier_id = (int)($_GET['supplier_id'] ?? 0);

if ($product_id <= 0 || $supplier_id <= 0) {
    echo json_encode(['success' => false, 'message' => '잘못된 파라미터입니다.']);
    exit();
}

try {
    $conn = get_db_connection();

    // 같은 거래처, 같은 상품, 박스 매입 중 가장 최근 단가
    $stmt = $conn->prepare("
        SELECT pi.unit_price, p.purchase_date
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.purchase_id
        WHERE pi.product_id = ?
          AND p.supplier_id = ?
          AND pi.purchase_type = 'box'
        ORDER BY p.purchase_date DESC, p.purchase_id DESC
        LIMIT 1
    ");
    $stmt->bind_param("ii", $product_id, $supplier_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'success'       => true,
            'unit_price'    => (float)$row['unit_price'],
            'purchase_date' => $row['purchase_date'],
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '이전 매입 내역 없음']);
    }

    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    error_log("ajax_get_last_purchase_price error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '오류가 발생했습니다.']);
}
