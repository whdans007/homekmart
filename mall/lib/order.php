<?php
/**
 * 주문 생성 유스케이스 (Application Layer, 트랜잭션)
 * Design Ref: shopping-mall.design.md §9.1, §4.2 submit_order.php, §7 서버 재계산 원칙
 *
 * 클라이언트가 보낸 가격을 신뢰하지 않고, 서버에서 장바구니를 다시 조회해 pricing.php로 재계산한다.
 */
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/auth.php';

/**
 * 회원의 장바구니를 주문으로 확정합니다.
 * @param int $member_id
 * @param array $member mall_current_member() 반환값
 * @param string $requested_channel retail|wholesale (클라이언트가 요청한 채널)
 * @param string $memo
 * @return array{success:bool, error?:array, data?:array}
 */
function mall_create_order($member_id, $member, $requested_channel, $memo = '') {
    if ($requested_channel === 'wholesale' && !mall_is_wholesale_approved()) {
        return ['success' => false, 'error' => ['code' => 'WHOLESALE_NOT_APPROVED', 'message' => '도매 승인 대기중입니다']];
    }

    $summary = mall_cart_get_summary($member_id, null, $member);

    if (empty($summary['items'])) {
        return ['success' => false, 'error' => ['code' => 'EMPTY_CART', 'message' => '장바구니가 비어있습니다']];
    }

    if ($summary['has_out_of_stock']) {
        $first = null;
        foreach ($summary['items'] as $item) {
            if ($item['out_of_stock']) {
                $first = $item;
                break;
            }
        }
        return [
            'success' => false,
            'error' => [
                'code' => 'OUT_OF_STOCK',
                'message' => '재고가 부족합니다',
                'details' => ['product_id' => $first['product_id'], 'available' => $first['stock']],
            ],
        ];
    }

    $shipping_fee = mall_calculate_shipping_fee($summary['subtotal']);
    $total_with_shipping = round($summary['total'] + $shipping_fee, 2);

    $conn = get_db_connection();
    $conn->begin_transaction();
    $in_txn = true;

    try {
        $order_number = 'MALL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $order_stmt = $conn->prepare(
            'INSERT INTO mall_orders (order_number, member_id, store_id, channel, subtotal, discount_amount, shipping_fee, total_amount, status, payment_method, memo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", "cod", ?)'
        );
        $store_id = MALL_STORE_ID;
        $order_stmt->bind_param(
            'siisdddds',
            $order_number, $member_id, $store_id, $requested_channel,
            $summary['subtotal'], $summary['discount_amount'], $shipping_fee, $total_with_shipping, $memo
        );
        $order_stmt->execute();
        $order_id = $order_stmt->insert_id;
        $order_stmt->close();

        // 몰의 재고 관리는 mall_products.is_sold_out 스위치로만 하고(mall_get_stock_quantity() 참고),
        // 실제 inventory.quantity는 발주/이동/유통기한 로트 등 별도 프로세스가 관리해 몰 판매 시점과
        // 안 맞을 수 있으므로 주문 확정 시 여기서 차감/검증하지 않는다.
        $item_stmt = $conn->prepare(
            'INSERT INTO mall_order_items (order_id, product_id, product_name_snapshot, unit_price_snapshot, discount_rate_snapshot, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($summary['items'] as $item) {
            $unit_price = $item['price']['final_price'] ?? 0.0;
            $discount_rate = $item['price']['discount_rate'] ?? 0.0;

            $item_stmt->bind_param(
                'iisddid',
                $order_id, $item['product_id'], $item['display_name'],
                $unit_price, $discount_rate, $item['quantity'], $item['line_total']
            );
            $item_stmt->execute();
        }
        $item_stmt->close();

        $delete_cart = $conn->prepare('DELETE FROM mall_cart_items WHERE member_id = ?');
        $delete_cart->bind_param('i', $member_id);
        $delete_cart->execute();
        $delete_cart->close();

        $conn->commit();
        $in_txn = false;
        $conn->close();

        return [
            'success' => true,
            'data' => ['order_id' => $order_id, 'order_number' => $order_number, 'total_amount' => $total_with_shipping],
        ];
    } catch (Exception $e) {
        if ($in_txn) {
            $conn->rollback();
        }
        $conn->close();

        error_log('mall_create_order error: ' . $e->getMessage());
        return ['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '주문 처리 중 오류가 발생했습니다']];
    }
}
