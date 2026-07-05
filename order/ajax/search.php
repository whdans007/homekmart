<?php
// Design Ref: §5.1 — 상품 검색 AJAX
require_once dirname(__DIR__) . '/lib/auth.php';
ord_require_manager();
header('Content-Type: application/json; charset=utf-8');

$keyword  = trim($_POST['keyword'] ?? '');
$vendorId = (int)($_POST['vendor_id'] ?? 0);
$page     = max(1, (int)($_POST['page'] ?? 1));
$limit    = min(100, max(10, (int)($_POST['limit'] ?? 50)));
$offset   = ($page - 1) * $limit;
// all=1 이면 페이징 없이 조건에 맞는 전체 목록을 한 번에 반환 (업체 선택 시 사용)
$showAll  = !empty($_POST['all']);
$storeId  = ord_current_store_id();

try {
    $conn = get_ord_db();

    // 업체별 활성 재고: is_current=1 이 없으면 최신 업로드로 fallback
    $activeInvJoin = "
        JOIN (
            SELECT vendor_id,
                   COALESCE(MAX(CASE WHEN is_current = 1 THEN id END), MAX(id)) AS target_id
            FROM order_vendor_inventories
            GROUP BY vendor_id
        ) vmax ON i.vendor_id = vmax.vendor_id AND i.inventory_id = vmax.target_id
    ";

    $where = "(i.product_name LIKE ? OR i.product_name_en LIKE ?)";
    $params = ['%' . $keyword . '%', '%' . $keyword . '%'];
    $types  = 'ss';

    if ($vendorId) {
        $where .= " AND i.vendor_id = ?";
        $params[] = $vendorId;
        $types .= 'i';
    }

    // 총 건수
    $countSql = "SELECT COUNT(*) FROM order_vendor_inventory_items i $activeInvJoin WHERE $where";
    $stmt = $conn->prepare($countSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($total);
    $stmt->fetch();
    $stmt->close();

    // 데이터 조회 (장바구니 여부 포함)
    $sql = "
        SELECT i.id, i.vendor_id, v.name AS vendor_name,
               i.product_name, i.product_name_en, i.order_unit, i.unit_qty, i.brand, i.unit_price, i.unit_price_pcs, i.remark, i.expiry_date,
               COALESCE(c.quantity, 0) AS cart_quantity,
               (c.id IS NOT NULL) AS in_cart
        FROM order_vendor_inventory_items i
        $activeInvJoin
        JOIN order_vendors v ON i.vendor_id = v.id
        LEFT JOIN order_cart_items c ON c.inventory_item_id = i.id AND c.store_id = ?
        WHERE $where
        ORDER BY i.vendor_id ASC, i.row_number ASC
    ";
    if ($showAll) {
        // 페이징 없이 전체 반환
        $allParams = array_merge([$storeId], $params);
        $allTypes  = 'i' . $types;
    } else {
        $sql .= " LIMIT ? OFFSET ?";
        $allParams = array_merge([$storeId], $params, [$limit, $offset]);
        $allTypes  = 'i' . $types . 'ii';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'data' => $data, 'total' => $total, 'page' => $page, 'limit' => $limit, 'all' => $showAll]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
