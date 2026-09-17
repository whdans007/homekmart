<?php
// Design Ref: store-order-popup §2 — 신규 점포 주문(pending) 폴링 엔드포인트
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff(); // 비인증 시 리다이렉트 → 클라이언트는 JSON 파싱 실패로 조용히 무시 (NFR-2)

$since = max(0, (int)($_GET['since'] ?? 0)); // 클라이언트가 마지막으로 본 주문 ID

try {
    $conn = get_lc_db();

    // Plan SC-1/SC-2: since 보다 큰 pending 주문만 신규로 반환 (점포명/금액/항목수 포함)
    $st = $conn->prepare(
        "SELECT o.id,
                s.name          AS store_name,
                o.total_amount,
                o.created_at,
                (SELECT COUNT(*) FROM lc_order_items oi WHERE oi.order_id = o.id) AS item_count
         FROM lc_orders o
         LEFT JOIN stores s ON o.store_id = s.id
         WHERE o.status = 'pending' AND o.id > ?
         ORDER BY o.id DESC
         LIMIT 20"
    );
    $st->bind_param('i', $since);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    // 현재 pending 주문의 최대 id — 신규 없을 때 최초 로드 워터마크 초기화/동기화용
    $latest_id = (int)$conn->query("SELECT IFNULL(MAX(id), 0) FROM lc_orders WHERE status = 'pending'")->fetch_row()[0];
    $conn->close();

    $orders = [];
    foreach ($rows as $r) {
        $orders[] = [
            'id'           => (int)$r['id'],
            'order_no'     => '#' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT),
            'store_name'   => $r['store_name'] !== null && $r['store_name'] !== '' ? $r['store_name'] : t('logistics.ajax_check_new_orders.unknown_store'),
            'total_amount' => (float)$r['total_amount'],
            'item_count'   => (int)$r['item_count'],
            'created_at'   => $r['created_at'],
        ];
    }

    echo json_encode(['success' => true, 'latest_id' => $latest_id, 'orders' => $orders], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false]); // 메시지 불필요 — 클라이언트는 다음 주기 재시도
}
