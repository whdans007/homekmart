<?php
// Design Ref: box-pcs-unit.design.md §4.2 — 박스 개봉 API
// actions: get_box_lots(개봉 대상 BOX lot 목록) / submit_break(개봉 실행) / get_history(이력)
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/unit_helper.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── 상품의 묶음(BOX/PACK) lot 목록 (개봉 대상, 유통기한 ASC) ────────────────
// Design Ref: pack-unit §6 — 개봉 대상 lot을 묶음 단위(BOX/PACK)로 확장, i.unit 반환
if ($action === 'get_box_lots') {
    $product_id = (int)($_GET['product_id'] ?? 0);
    if (!$product_id) { echo json_encode(['success' => false, 'message' => t('logistics.ajax_box_break.invalid_product')]); exit; }
    try {
        $conn = get_lc_db();
        $st = $conn->prepare(
            "SELECT i.id AS inventory_id, i.unit, i.lot_number, i.expiry_date, i.storage_location,
                    i.quantity_remain,
                    GREATEST(1, b.pieces_per_box) AS pieces_per_box,
                    b.cost_price AS cost_price_box,
                    IF(b.cost_price_pcs > 0, b.cost_price_pcs, b.cost_price / GREATEST(1, b.pieces_per_box)) AS cost_price_pcs
             FROM lc_inventory i
             JOIN lc_inbound b ON i.inbound_id = b.id
             WHERE i.product_id = ? AND i.unit IN ('BOX','PACK') AND i.quantity_remain > 0
             ORDER BY i.expiry_date ASC, i.id ASC"
        );
        $st->bind_param('i', $product_id);
        $st->execute();
        $lots = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        // 단위별 현재고 함께 반환 (화면 상단 표시용)
        $stock = lc_get_stock_by_unit($conn, $product_id);
        $conn->close();
        echo json_encode(['success' => true, 'lots' => $lots, 'stock' => $stock]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_box_break.error', ['error' => $e->getMessage()])]);
    }
    exit;
}

// ── 개봉 실행 (Design §4.2 — 단일 트랜잭션: BOX 차감 + PCS lot 생성 + 이력) ──
if ($action === 'submit_break' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    lc_verify_csrf();

    $inventory_id = (int)($_POST['inventory_id'] ?? 0);
    $boxes        = (int)($_POST['boxes_opened'] ?? 0);
    $damaged      = (int)($_POST['damaged_qty'] ?? 0);
    $notes        = trim($_POST['notes'] ?? '');

    try {
        $conn = get_lc_db();
        $conn->autocommit(false);

        // 개봉 트랜잭션 본체는 lib/unit_helper.php — L1 테스트와 동일 경로 (Design §9 import 규칙)
        $result = lc_execute_box_break($conn, $inventory_id, $boxes, $damaged, $notes, lc_current_user_id());

        if ($result['success']) { $conn->commit(); } else { $conn->rollback(); }
        $conn->close();
        echo json_encode($result);
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->close(); }
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_box_break.error', ['error' => $e->getMessage()])]);
    }
    exit;
}

// ── 개봉/파손 이력 조회 ─────────────────────────────────────────
if ($action === 'get_history') {
    $product_id = (int)($_GET['product_id'] ?? 0);
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
    try {
        $conn = get_lc_db();
        $where = $product_id ? 'WHERE bb.product_id = ?' : '';
        $sql =
            "SELECT bb.*, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                    u.full_name AS created_by_name
             FROM lc_box_breaks bb
             JOIN lc_products p ON bb.product_id = p.id
             LEFT JOIN users u ON bb.created_by = u.id
             $where
             ORDER BY bb.id DESC
             LIMIT $limit";
        if ($product_id) {
            $st = $conn->prepare($sql);
            $st->bind_param('i', $product_id);
            $st->execute();
            $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        } else {
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
        }
        $conn->close();
        echo json_encode(['success' => true, 'history' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_box_break.error', ['error' => $e->getMessage()])]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => t('logistics.ajax_box_break.unknown_action')]);
