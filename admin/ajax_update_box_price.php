<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

header('Content-Type: application/json');

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$product_id = $input['product_id'] ?? 0;
$store_id = $input['store_id'] ?? 0;
$box_price = $input['box_price'] ?? null;

if (empty($product_id) || empty($store_id)) {
    echo json_encode(['success' => false, 'message' => '상품 ID와 점포 ID가 필요합니다.']);
    exit;
}

// box_price가 빈 문자열이거나 null인 경우 NULL로 설정
if ($box_price === '' || $box_price === null) {
    $box_price = null;
} else {
    $box_price = floatval($box_price);
    if ($box_price < 0) {
        echo json_encode(['success' => false, 'message' => '박스단가는 0 이상이어야 합니다.']);
        exit;
    }
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // box_price 컬럼이 존재하는지 확인
    $check_column = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'box_price'");
    $check_column->execute();
    if (!$check_column->fetch()) {
        echo json_encode(['success' => false, 'message' => 'box_price 컬럼이 존재하지 않습니다. 데이터베이스 마이그레이션을 실행해주세요.']);
        exit;
    }

    // 기존 재고 레코드 확인
    $check_stmt = $pdo->prepare("SELECT id FROM inventory WHERE product_id = ? AND store_id = ?");
    $check_stmt->execute([$product_id, $store_id]);
    $existing = $check_stmt->fetch();

    if ($existing) {
        // 기존 레코드 업데이트
        $update_stmt = $pdo->prepare("UPDATE inventory SET box_price = ? WHERE product_id = ? AND store_id = ?");
        $update_stmt->execute([$box_price, $product_id, $store_id]);
    } else {
        // 새로운 레코드 생성 (수량은 0, 박스단가만 설정)
        $insert_stmt = $pdo->prepare("INSERT INTO inventory (product_id, store_id, quantity, box_price) VALUES (?, ?, 0, ?)");
        $insert_stmt->execute([$product_id, $store_id, $box_price]);
    }

    // 업데이트된 정보 반환
    $result_stmt = $pdo->prepare("SELECT box_price FROM inventory WHERE product_id = ? AND store_id = ?");
    $result_stmt->execute([$product_id, $store_id]);
    $result = $result_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'message' => '박스단가가 성공적으로 업데이트되었습니다.',
        'box_price' => $result['box_price']
    ]);

} catch (PDOException $e) {
    error_log("Box price update error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
}
?>