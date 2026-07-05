<?php
// 매입 확정/취소 처리 AJAX 스크립트
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/inventory_helper.php';

header('Content-Type: application/json');

// 로그인 및 권한 확인
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if (!has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

$purchase_id = $_POST['purchase_id'] ?? null;
$action = $_POST['action'] ?? null; // 'confirm' or 'cancel'

// 필수 파라미터 검증
if (!$purchase_id || !$action) {
    echo json_encode(['success' => false, 'message' => '필수 매개변수가 누락되었습니다.']);
    exit;
}

if (!in_array($action, ['confirm', 'cancel'])) {
    echo json_encode(['success' => false, 'message' => '유효하지 않은 작업입니다.']);
    exit;
}

try {
    $conn = get_db_connection();
    $conn->begin_transaction();

    // 1. 매입 정보 조회 및 권한 확인
    $purchase_stmt = $conn->prepare("
        SELECT p.*, u.store_id
        FROM purchases p
        LEFT JOIN users u ON u.id = ?
        WHERE p.purchase_id = ?
    ");
    $purchase_stmt->bind_param("is", $_SESSION['user_id'], $purchase_id);
    $purchase_stmt->execute();
    $purchase = $purchase_stmt->get_result()->fetch_assoc();
    $purchase_stmt->close();

    if (!$purchase) {
        throw new Exception('매입 정보를 찾을 수 없습니다.');
    }

    $current_store_id = $purchase['store_id'];

    if ($action === 'confirm') {
        // 매입 확정 처리
        if ($purchase['is_confirmed']) {
            throw new Exception('이미 확정된 매입입니다.');
        }

        // 매입 상태를 확정으로 변경
        $confirm_stmt = $conn->prepare("
            UPDATE purchases
            SET is_confirmed = 1, confirmed_at = NOW(), confirmed_by_user_id = ?
            WHERE purchase_id = ?
        ");
        $confirm_stmt->bind_param("is", $_SESSION['user_id'], $purchase_id);
        $confirm_stmt->execute();
        $confirm_stmt->close();

        // 각 매입 상품의 단가를 inventory 테이블의 box_price로 업데이트
        $items_stmt = $conn->prepare("
            SELECT pi.product_id, pi.unit_price, pi.quantity, pi.purchase_type, pi.expiration_date, pr.pieces_per_box
            FROM purchase_items pi
            JOIN products pr ON pi.product_id = pr.id
            WHERE pi.purchase_id = ?
        ");
        $items_stmt->bind_param("s", $purchase_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();

        while ($item = $items_result->fetch_assoc()) {
            $product_id = $item['product_id'];
            $unit_price = $item['unit_price'];
            $purchase_type = $item['purchase_type'];
            $pieces_per_box = $item['pieces_per_box'] ?? 1;
            $pi_exp = $item['expiration_date'];

            // 상품 유통기한 업데이트 로직 (가장 최근 입고 정보로 갱신)
            if ($pi_exp) {
                // 제품의 유통기한 업데이트
                $exp_update_stmt = $conn->prepare("UPDATE products SET expiration_date = ? WHERE id = ?");
                $exp_update_stmt->bind_param("si", $pi_exp, $product_id);
                $exp_update_stmt->execute();
                $exp_update_stmt->close();

                // (신규) 유통기한별 로트(Lot) 재고 증가
                add_inventory_by_expiration($conn, $current_store_id, $product_id, $pi_exp, $item['quantity']);
            }

            // 박스단가 계산
            $box_price = $unit_price;
            if ($purchase_type === 'piece' && $pieces_per_box > 1) {
                // 낱개 매입인 경우 박스당 가격으로 환산
                $box_price = $unit_price * $pieces_per_box;
            }

            // inventory 레코드 존재 확인
            $check_stmt = $conn->prepare("
                SELECT id FROM inventory
                WHERE product_id = ? AND store_id = ?
            ");
            $check_stmt->bind_param("ii", $product_id, $current_store_id);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if ($existing) {
                // 기존 레코드 업데이트
                $update_stmt = $conn->prepare("
                    UPDATE inventory
                    SET box_price = ?
                    WHERE product_id = ? AND store_id = ?
                ");
                $update_stmt->bind_param("dii", $box_price, $product_id, $current_store_id);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                // 새로운 레코드 생성 (수량은 0, 박스단가만 설정)
                $insert_stmt = $conn->prepare("
                    INSERT INTO inventory (product_id, store_id, quantity, box_price)
                    VALUES (?, ?, 0, ?)
                ");
                $insert_stmt->bind_param("iid", $product_id, $current_store_id, $box_price);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
        }
        $items_stmt->close();

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => '매입이 성공적으로 확정되었습니다. 각 상품의 박스단가가 업데이트되었습니다.',
            'action' => 'confirmed'
        ]);

    } else if ($action === 'cancel') {
        // 매입 확정 취소 처리 (점장 이상만 가능)
        if (current_user_level() < LEVEL_BRANCH_MANAGER) {
            throw new Exception('매입 확정 취소는 점장 이상만 가능합니다.');
        }

        if (!$purchase['is_confirmed']) {
            throw new Exception('확정되지 않은 매입입니다.');
        }

        // 매입 상태를 미확정으로 변경
        $cancel_stmt = $conn->prepare("
            UPDATE purchases
            SET is_confirmed = 0, confirmed_at = NULL, confirmed_by_user_id = NULL
            WHERE purchase_id = ?
        ");
        $cancel_stmt->bind_param("s", $purchase_id);
        $cancel_stmt->execute();
        $cancel_stmt->close();

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => '매입 확정이 성공적으로 취소되었습니다.',
            'action' => 'cancelled'
        ]);
    }

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
        $conn->close();
    }

    error_log("매입 확정/취소 오류: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>