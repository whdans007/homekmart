<?php
// 매입 확정/취소 처리 AJAX 스크립트
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/inventory_helper.php';
require_once __DIR__ . '/../lib/inventory_service.php';
require_once __DIR__ . '/../lib/reference_store_service.php';

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
    // 매입확정/취소 버튼 클릭 1회마다 별도의 원장 이벤트를 남긴다.
    $action_source_id = random_int(100000000000, 999999999999);

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
            SELECT pi.item_id, pi.product_id, pi.unit_price, pi.quantity, pi.purchase_type, pi.expiration_date, pr.pieces_per_box
            FROM purchase_items pi
            JOIN products pr ON pi.product_id = pr.id
            WHERE pi.purchase_id = ?
        ");
        $items_stmt->bind_param("s", $purchase_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();

        // Design §4.1: 매입확정 시 Reference Store 조건을 만족하는 일반상품은 품절 해제 대상이 된다.
        // 실제 입고 수량(박스→낱개 환산 후)이 0보다 큰 상품만 후보로 모은다.
        $restocked_product_ids = [];

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
                $lot_quantity = ($item['purchase_type'] === 'box')
                    ? (float)$item['quantity'] * (int)($item['pieces_per_box'] ?? 1)
                    : (float)$item['quantity'];
                add_inventory_by_expiration($conn, $current_store_id, $product_id, $pi_exp, $lot_quantity);
            }

            // 박스단가 계산
            $box_price = $unit_price;
            if ($purchase_type === 'piece' && $pieces_per_box > 1) {
                // 낱개 매입인 경우 박스당 가격으로 환산
                $box_price = $unit_price * $pieces_per_box;
            }

            // 실제 입고 수량(낱개 기준) — 품절 해제 조건 판단용(Design §4.1)
            $actual_quantity = (float)$item['quantity'];
            if ($purchase_type === 'box') {
                $actual_quantity = (float)$item['quantity'] * (int)$pieces_per_box;
            }
            if ($actual_quantity > 0) {
                $restocked_product_ids[$product_id] = true;

                // 신규 매입은 등록 시점에 이미 PURCHASE_IN이 기록되어 있으므로
                // 중복 반영하지 않는다. 기존 매입(원장 도입 전 생성분)은 확정 시
                // 누락된 입고를 보정한다. inventory_apply_delta()의 UNIQUE 키가
                // 재시도 중복도 함께 차단한다.
                // 최신 매입 관련 원장이 이미 입고이면 신규 등록 시점의 입고를
                // 중복 반영하지 않는다. 마지막 관련 원장이 취소이면 재확정 입고를 적용한다.
                $state_stmt = $conn->prepare(
                    "SELECT
                        SUM(event_type = 'PURCHASE_IN' AND source_type = 'purchase_item_add' AND source_id = ?) AS initial_in_count,
                        SUM(event_type = 'REVERSAL_OUT' AND source_type IN ('purchase_confirm_cancel', 'purchase_cancel')
                            AND remarks LIKE CONCAT('%Purchase ID: ', ?, '%')) AS cancel_count
                     FROM inventory_ledger
                     WHERE store_id = ? AND product_id = ?"
                );
                $state_stmt->bind_param('isii', $item['item_id'], $purchase_id, $current_store_id, $product_id);
                $state_stmt->execute();
                $state = $state_stmt->get_result()->fetch_assoc();
                $state_stmt->close();
                $has_initial_in = (int)($state['initial_in_count'] ?? 0) > 0;
                $has_prior_cancel = (int)($state['cancel_count'] ?? 0) > 0;
                if (!$has_initial_in || $has_prior_cancel) {
                    $purchase_in_result = inventory_apply_delta($conn, [
                        'store_id' => (int)$current_store_id,
                        'product_id' => (int)$product_id,
                        'quantity_change' => $actual_quantity,
                        'event_type' => 'PURCHASE_IN',
                        'source_type' => 'purchase_confirm',
                        'source_id' => $action_source_id,
                        'user_id' => $_SESSION['user_id'] ?? null,
                        'remarks' => "매입 확정 입고 (Purchase ID: {$purchase_id})",
                        'manage_transaction' => false,
                    ]);
                    if (!$purchase_in_result['success']) {
                        throw new Exception('매입 확정 재고 반영에 실패했습니다: ' . ($purchase_in_result['error'] ?? '알 수 없는 오류'));
                    }
                }
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

        // Design §4.1 품절 자동 해제: 매입 점포가 현재 Reference Store와 같고, 실제 입고 수량이
        // 있는 일반상품(mall_products에 등록된 상품)만 대상으로 한다. 재고 수량 자체는 이 파일에서
        // 건드리지 않는다 — 실제 증감은 admin/edit_purchase.php가 이미 담당한다(운영 정책 확인됨).
        $reactivated_product_ids = [];
        if (!empty($restocked_product_ids)) {
            $current_reference_store_id = reference_store_get_current($conn);
            if ((int)$current_store_id === $current_reference_store_id) {
                // mall_products.store_id도 현재 Reference Store와 일치해야 해제한다 — product_id만으로
                // 걸면 mall_products 행이 다른(과거) 기준 점포로 설정된 경우까지 잘못 해제될 수 있다.
                $reactivate_stmt = $conn->prepare(
                    "UPDATE mall_products SET is_sold_out = 0 WHERE store_id = ? AND product_id = ? AND is_sold_out = 1"
                );
                foreach (array_keys($restocked_product_ids) as $product_id) {
                    $reactivate_stmt->bind_param('ii', $current_reference_store_id, $product_id);
                    $reactivate_stmt->execute();
                    if ($reactivate_stmt->affected_rows > 0) {
                        $reactivated_product_ids[] = (int)$product_id;
                    }
                }
                $reactivate_stmt->close();
            }
        }

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => '매입이 성공적으로 확정되었습니다. 각 상품의 박스단가가 업데이트되었습니다.',
            'action' => 'confirmed',
            'reactivated_product_ids' => $reactivated_product_ids
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
        $items_stmt = $conn->prepare("
            SELECT pi.item_id, pi.product_id, pi.quantity, pi.purchase_type, pi.expiration_date, pr.pieces_per_box
            FROM purchase_items pi
            JOIN products pr ON pr.id = pi.product_id
            WHERE pi.purchase_id = ?
        ");
        $items_stmt->bind_param('s', $purchase_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        while ($item = $items_result->fetch_assoc()) {
            $pieces_per_box = (int)($item['pieces_per_box'] ?? 1);
            if ($pieces_per_box <= 0) {
                $pieces_per_box = 1;
            }
            $actual_quantity = $item['purchase_type'] === 'box'
                ? (float)$item['quantity'] * $pieces_per_box
                : (float)$item['quantity'];
            if ($actual_quantity <= 0) {
                continue;
            }
            $delta_result = inventory_apply_delta($conn, [
                'store_id' => (int)$purchase['store_id'],
                'product_id' => (int)$item['product_id'],
                'quantity_change' => -$actual_quantity,
                'event_type' => 'REVERSAL_OUT',
                'source_type' => 'purchase_cancel',
                'source_id' => $action_source_id,
                'user_id' => $_SESSION['user_id'] ?? null,
                'remarks' => "매입확정 취소 (Purchase ID: {$purchase_id})",
                'manage_transaction' => false,
            ]);
            if (!$delta_result['success']) {
                throw new Exception('매입 취소 재고 반영에 실패했습니다: ' . ($delta_result['error'] ?? '알 수 없는 오류'));
            }
            if (!empty($item['expiration_date'])) {
                $lot_stmt = $conn->prepare('UPDATE inventory_expirations SET quantity = quantity - ? WHERE store_id = ? AND product_id = ? AND expiration_date = ?');
                $lot_stmt->bind_param('diis', $actual_quantity, $purchase['store_id'], $item['product_id'], $item['expiration_date']);
                $lot_stmt->execute();
                $lot_stmt->close();
            }
        }
        $items_stmt->close();

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
