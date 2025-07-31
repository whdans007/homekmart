<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

if (!is_logged_in()) {
    echo json_encode(['error' => '로그인이 필요합니다.']);
    exit();
}

$term = $_GET['term'] ?? '';

if (empty($term)) {
    echo json_encode([]);
    exit();
}

$conn = get_db_connection();
$data = [];

try {
    // 바코드 검색을 우선으로 처리
    $sql = "SELECT id, sku, name_ko, name_en, barcode, cost_price, selling_price, pieces_per_box 
            FROM products 
            WHERE barcode = ? 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $term);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        $product['exact_match'] = true; // 바코드는 정확히 일치
        $data[] = $product;
    } else {
        // 바코드와 일치하는 상품이 없으면, 상품명 또는 SKU로 검색
        $searchTerm = '%' . $term . '%';
        $sql = "SELECT id, sku, name_ko, name_en, barcode, cost_price, selling_price, pieces_per_box 
                FROM products 
                WHERE name_ko LIKE ? OR name_en LIKE ? OR sku LIKE ?
                LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $searchTerm, $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }

    echo json_encode($data);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
