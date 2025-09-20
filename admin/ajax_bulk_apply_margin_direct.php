<?php
// 에러 로깅 활성화
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 출력 버퍼링 시작
ob_start();

try {
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

// 디버그 로깅
error_log("Ajax bulk apply - purchase_id: $purchase_id, store_id: $store_id, margin_rate: $margin_rate");
error_log("Ajax bulk apply - item_ids: " . json_encode($item_ids));
error_log("Ajax bulk apply - POST data: " . json_encode($_POST));

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

    // 기존 가격 정보 초기화
    $old_cost_price = 0;
    $old_selling_price = 0;
    $old_margin_rate = 0;
    $new_margin_rate = 0;

    // 2. store_id가 있으면 inventory 업데이트
    if ($store_id && is_numeric($store_id)) {
        // 기존 가격 정보 조회 (이력 저장용)
        $sql_old = "SELECT cost_price, selling_price FROM inventory WHERE product_id = ? AND store_id = ?";
        $stmt_old = $conn->prepare($sql_old);
        $stmt_old->bind_param("ii", $product_id, $store_id);
        $stmt_old->execute();
        $old_result = $stmt_old->get_result();
        $old_data = $old_result->fetch_assoc();

        $old_cost_price = $old_data ? $old_data['cost_price'] : 0;
        $old_selling_price = $old_data ? $old_data['selling_price'] : 0;

        // inventory 확인
        $sql_check = "SELECT id FROM inventory WHERE product_id = ? AND store_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ii", $product_id, $store_id);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();

        if ($check_result->num_rows > 0) {
            // 업데이트
            $sql_update = "UPDATE inventory SET cost_price = ?, selling_price = ? WHERE product_id = ? AND store_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("ddii", $cost_price, $new_selling_price, $product_id, $store_id);
            $stmt_update->execute();
        } else {
            // 삽입 (add_purchase.php와 동일한 방식)
            $sql_insert = "INSERT INTO inventory (product_id, store_id, quantity, cost_price, selling_price) VALUES (?, ?, 0, ?, ?)";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("iidd", $product_id, $store_id, $cost_price, $new_selling_price);
            $stmt_insert->execute();
        }

        // 마진율 계산
        $old_margin_rate = ($old_cost_price > 0) ? (($old_selling_price - $old_cost_price) / $old_cost_price) * 100 : 0;
        $new_margin_rate = ($cost_price > 0) ? (($new_selling_price - $cost_price) / $cost_price) * 100 : 0;

        $change_reason = "매입가격 변동 반영 (매입번호: {$purchase_id}, 마진율: {$margin_rate}%)";
        $change_type = 'both'; // 변수로 할당
        $user_id = $_SESSION['user_id'] ?? 1; // 기본값으로 1 사용

        $sql_history = "INSERT INTO price_change_history
                        (product_id, store_id, old_cost_price, new_cost_price, old_selling_price, new_selling_price,
                         old_margin_rate, new_margin_rate, change_type, change_reason, purchase_id, changed_by_user_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_history = $conn->prepare($sql_history);

        // 디버깅: 변수 값 확인
        error_log("History variables: product_id=$product_id, store_id=$store_id, old_cost=$old_cost_price, new_cost=$cost_price");
        error_log("old_selling=$old_selling_price, new_selling=$new_selling_price, old_margin=$old_margin_rate, new_margin=$new_margin_rate");
        error_log("change_type=$change_type, change_reason=$change_reason, purchase_id=$purchase_id, user_id=$user_id");

        $stmt_history->bind_param("iiddddddsssi",
            $product_id, $store_id, $old_cost_price, $cost_price, $old_selling_price, $new_selling_price,
            $old_margin_rate, $new_margin_rate, $change_type, $change_reason, $purchase_id, $user_id);
        $stmt_history->execute();
    } else {
        // 3. store_id가 없는 경우 에러 처리 (products 테이블에는 더 이상 가격 정보를 저장하지 않음)
        $errors[] = "store_id가 필요합니다. 가격 정보는 inventory 테이블에만 저장됩니다.";
        continue;
    }
    
    $updated_count++;
    $updated_items[] = [
        'item_id' => $item_id,
        'product_id' => $product_id,
        'product_name' => $item['name_ko'],
        'old_cost_price' => number_format($old_cost_price ?? 0, 2),
        'new_cost_price' => number_format($cost_price, 2),
        'old_selling_price' => number_format($old_selling_price ?? 0, 0),
        'new_selling_price' => number_format($new_selling_price, 0),
        'old_margin_rate' => number_format($old_margin_rate ?? 0, 1),
        'new_margin_rate' => number_format($new_margin_rate ?? 0, 1),
        'history_saved' => true
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

} catch (Exception $e) {
    error_log("Ajax bulk apply margin error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '서버 오류: ' . $e->getMessage(),
        'error_details' => $e->getTraceAsString()
    ]);
}
?>