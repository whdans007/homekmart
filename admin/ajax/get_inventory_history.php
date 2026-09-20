<?php
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$product_id = (int)($_GET['product_id'] ?? 0);
$store_id = (int)($_GET['store_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 30)));
$offset = ($page - 1) * $limit;
$role = $_SESSION['role'] ?? '';
$session_store_id = (int)($_SESSION['store_id'] ?? 0);

if ($product_id <= 0 || $store_id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => '상품과 점포 정보가 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($role, ['admin', 'super_admin'], true) && $session_store_id !== $store_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '해당 점포의 이력을 조회할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$labels = [
    'PURCHASE_IN' => '매입', 'POS_OUT' => 'POS 판매', 'MALL_OUT' => '몰 판매',
    'WHOLESALE_OUT' => '도매 판매', 'CREDIT_OUT' => '외상 판매',
    'TRANSFER_OUT' => '이동 출고', 'TRANSFER_IN' => '이동 입고', 'DISPOSAL_OUT' => '폐기',
    'ADJUSTMENT_IN' => '재고 조정(증가)', 'ADJUSTMENT_OUT' => '재고 조정(감소)',
    'RETURN_IN' => '반품/복구', 'REVERSAL_OUT' => '거래 취소',
];

try {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        'SELECT l.id, l.quantity_change, l.quantity_after, l.event_type, l.source_type, l.source_id,
                l.occurred_at, l.remarks, u.full_name, u.username
         FROM inventory_ledger l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE l.product_id = ? AND l.store_id = ?
         ORDER BY l.occurred_at DESC, l.id DESC'
    );
    $stmt->bind_param('ii', $product_id, $store_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];

    // 모든 원장 이벤트를 개별 행으로 표시한다. 판매 데이터도 일자별 합산하지 않는다.
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['id'],
            'occurred_at' => $row['occurred_at'],
            'event_type' => $row['event_type'],
            'event_label' => $labels[$row['event_type']] ?? $row['event_type'],
            'quantity_change' => (float)$row['quantity_change'],
            'quantity_after' => (float)$row['quantity_after'],
            'source_type' => $row['source_type'],
            'source_id' => (int)$row['source_id'],
            'remarks' => $row['remarks'] ?? '',
            'operator' => $row['full_name'] ?: ($row['username'] ?: '시스템'),
        ];
    }
    $total = count($items);
    $items = array_slice($items, $offset, $limit);

    $stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'items' => $items,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => max(1, (int)ceil($total / $limit))],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_inventory_history error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '이력 조회 중 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE);
}
