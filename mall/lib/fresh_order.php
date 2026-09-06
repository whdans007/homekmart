<?php
/**
 * 신선상품 전용 주문 유스케이스 (Application Layer)
 * Design Ref: mall-fresh-products.design.md §3.3, §4.2, §4.3, §9, §10
 *
 * mall_order_items(정가상품)와 완전히 분리된 mall_fresh_order_items를 다룬다. weight 타입은 주문 시
 * estimated_price만 채워지고(actual_weight_g/confirmed_price는 NULL), 준비중 단계에서 실측 입력 후
 * confirmed_price가 채워진다. piece 타입은 가격이 이미 고정이라 INSERT 시점에 confirmed_price까지
 * estimated_price와 동일하게 즉시 채운다(실측 단계 없음).
 *
 * 주의: mall_fresh_confirm_weight()는 mall/lib/order.php의 mall_recalculate_order_totals()를
 * 호출한다. 순환 require를 피하기 위해 이 파일은 order.php를 require하지 않으므로, 이 파일을 쓰는
 * 쪽(예: admin/ajax/confirm_fresh_weight.php)이 order.php를 먼저(또는 함께) require해야 한다.
 */
require_once __DIR__ . '/fresh_cart.php';

/**
 * mall_create_order() 트랜잭션 내부에서 호출한다. 신선상품 장바구니를 주문 항목으로 옮기고
 * 장바구니를 비운다. 같은 $conn(이미 시작된 트랜잭션)을 그대로 사용한다.
 * $summary는 트랜잭션 시작 "전"에 mall_fresh_cart_get_summary()로 미리 조회해 넘겨받는다
 * (mall_create_order()가 정가상품 mall_cart_get_summary()를 트랜잭션 전에 조회하는 것과 동일한 패턴).
 * @param mysqli $conn
 * @param int $order_id
 * @param int $member_id
 * @param array $summary mall_fresh_cart_get_summary() 반환값
 * @return void
 */
function mall_fresh_create_order_items($conn, $order_id, $member_id, $summary) {
    if (empty($summary['items'])) {
        return;
    }

    $item_stmt = $conn->prepare(
        'INSERT INTO mall_fresh_order_items
            (order_id, mall_fresh_product_id, product_name_snapshot, sale_type_snapshot, unit_price_snapshot,
             weight_g, quantity, estimated_price, confirmed_price)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($summary['items'] as $item) {
        if ($item['sold_out']) {
            continue;
        }
        $sale_type = $item['sale_type'];
        $weight_g = $sale_type === 'weight' ? (int)$item['weight_g'] : null;
        $quantity = $sale_type === 'piece' ? (int)$item['quantity'] : null;
        // piece는 가격이 이미 고정이라 확정금액을 즉시 채운다(실측 단계가 없음).
        $confirmed_price = $sale_type === 'piece' ? $item['estimated_price'] : null;

        $item_stmt->bind_param(
            'iissdiidd',
            $order_id, $item['mall_fresh_product_id'], $item['name_ko'], $sale_type, $item['unit_price'],
            $weight_g, $quantity, $item['estimated_price'], $confirmed_price
        );
        $item_stmt->execute();
    }
    $item_stmt->close();

    $delete_cart = $conn->prepare('DELETE FROM mall_fresh_cart_items WHERE member_id = ?');
    $delete_cart->bind_param('i', $member_id);
    $delete_cart->execute();
    $delete_cart->close();
}

/**
 * 주문의 신선상품 라인을 조회합니다(고객 주문상세, 관리자 준비중 화면, 피킹슬립/영수증 공용).
 * @param int $order_id
 * @return array<int, array>
 */
function mall_fresh_order_items_get_by_order($order_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        'SELECT id, mall_fresh_product_id, product_name_snapshot, sale_type_snapshot, unit_price_snapshot,
                weight_g, actual_weight_g, quantity, estimated_price, confirmed_price, is_sold_out
         FROM mall_fresh_order_items WHERE order_id = ? ORDER BY id'
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * "준비완료" 처리를 막아야 하는지 확인합니다 — weight 타입 라인 중 아직 실측(actual_weight_g)이
 * 입력되지 않은 것이 하나라도 있으면 true.
 * @param int $order_id
 * @return bool
 */
function mall_fresh_order_has_unconfirmed_weight($order_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM mall_fresh_order_items
         WHERE order_id = ? AND sale_type_snapshot = 'weight' AND is_sold_out = 0 AND actual_weight_g IS NULL"
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    $conn->close();
    return $count > 0;
}

/**
 * 관리자 준비중 화면에서 weight 타입 신선 라인의 실측 무게를 입력해 확정금액을 계산합니다.
 * @param int $mall_fresh_order_item_id
 * @param int $actual_weight_g
 * @return array{success:bool, error?:array, data?:array}
 */
function mall_fresh_confirm_weight($mall_fresh_order_item_id, $actual_weight_g) {
    if ($actual_weight_g <= 0) {
        return ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => '실측 무게를 확인해주세요']];
    }

    $conn = mall_get_db_connection();
    // Design Ref: mall-fresh-products.design.md §7 — 대상 order_id가 실제 존재하고 preparing
    // 상태인지 서버에서 재검증한다(이미 배송/완료/취소된 주문의 확정금액을 몰래 바꾸지 못하게 함).
    $stmt = $conn->prepare(
        'SELECT foi.order_id, foi.sale_type_snapshot, foi.unit_price_snapshot, o.status
         FROM mall_fresh_order_items foi
         INNER JOIN mall_orders o ON o.id = foi.order_id
         WHERE foi.id = ?'
    );
    $stmt->bind_param('i', $mall_fresh_order_item_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->close();
        return ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => '신선상품 주문 항목을 찾을 수 없습니다']];
    }
    if ($row['status'] !== 'preparing') {
        $conn->close();
        return ['success' => false, 'error' => ['code' => 'INVALID_STATE_TRANSITION', 'message' => '상품준비중 상태의 주문만 실측 입력할 수 있습니다']];
    }
    if ($row['sale_type_snapshot'] !== 'weight') {
        $conn->close();
        return ['success' => false, 'error' => ['code' => 'INVALID_STATE', 'message' => '무게 상품이 아닙니다']];
    }

    $confirmed_price = round($actual_weight_g / 100 * (float)$row['unit_price_snapshot'], 2);

    $update = $conn->prepare('UPDATE mall_fresh_order_items SET actual_weight_g = ?, confirmed_price = ? WHERE id = ?');
    $update->bind_param('idi', $actual_weight_g, $confirmed_price, $mall_fresh_order_item_id);
    $update->execute();
    $update->close();

    $order_totals = mall_recalculate_order_totals($conn, (int)$row['order_id']);
    $conn->close();

    return ['success' => true, 'data' => ['confirmed_price' => $confirmed_price, 'order_totals' => $order_totals]];
}
