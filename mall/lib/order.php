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
require_once __DIR__ . '/address.php';
require_once __DIR__ . '/romanize.php';
require_once __DIR__ . '/fresh_order.php';

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
    // 신선상품 장바구니(mall_fresh_cart_items)는 정가상품과 완전히 분리된 테이블이라 별도로 조회해
    // 같은 주문에 함께 담는다. Design Ref: mall-fresh-products.design.md §4.3.
    $fresh_summary = mall_fresh_cart_get_summary($member_id, null);

    if (empty($summary['items']) && empty($fresh_summary['items'])) {
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

    foreach ($fresh_summary['items'] as $fresh_item) {
        if (!empty($fresh_item['requires_readd'])) {
            return ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => '신선상품이 낱개 판매로 변경되었습니다. 수량을 선택해 다시 담아주세요.']];
        }
    }
    if ($fresh_summary['has_sold_out']) {
        return ['success' => false, 'error' => ['code' => 'SOLD_OUT', 'message' => '품절된 신선상품이 있습니다']];
    }

    // 배송지 스냅샷: 주문 시점의 기본 배송지 값을 그대로 복사해 저장한다(mall_order_items 가격
    // 스냅샷과 동일한 원칙 — 이후 회원이 배송지를 수정/변경해도 이미 만든 주문은 영향받지 않는다).
    $addresses = mall_address_list($member_id);
    $address = $addresses[0] ?? null;
    if (!$address) {
        return ['success' => false, 'error' => ['code' => 'NO_ADDRESS', 'message' => '배송지를 먼저 등록해주세요']];
    }

    // 신선상품은 할인 개념이 없어 estimated_price(=subtotal 기여분)가 그대로 total에도 기여한다.
    // subtotal/total 모두에 똑같이 더해지므로 discount_amount(subtotal-total)에는 영향이 없다.
    $combined_subtotal = round($summary['subtotal'] + $fresh_summary['subtotal'], 2);
    $combined_total_before_shipping = round($summary['total'] + $fresh_summary['subtotal'], 2);
    $shipping_fee = mall_calculate_shipping_fee($combined_subtotal);
    $total_with_shipping = round($combined_total_before_shipping + $shipping_fee, 2);

    $conn = get_db_connection();
    $conn->begin_transaction();
    $in_txn = true;

    try {
        // Design Ref: mall-member-english-name.design.md §4.2 — 레거시 회원 영문 이름 자동 채움
        // (FR-04, FR-05). 이미 값이 있으면 절대 덮어쓰지 않고, 변환 실패는 주문을 막지 않는다.
        if (empty($member['english_name']) && !empty($member['name'])) {
            try {
                $auto_english_name = mall_romanize_korean_name($member['name']);
                if ($auto_english_name !== '') {
                    $fill_stmt = $conn->prepare('UPDATE mall_members SET english_name = ? WHERE id = ?');
                    $fill_stmt->bind_param('si', $auto_english_name, $member_id);
                    $fill_stmt->execute();
                    $fill_stmt->close();
                }
            } catch (Exception $e) {
                error_log('mall_create_order english_name auto-fill error: ' . $e->getMessage());
            }
        }

        $order_number = 'MALL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $order_stmt = $conn->prepare(
            'INSERT INTO mall_orders (order_number, member_id, store_id, channel, subtotal, discount_amount, shipping_fee, total_amount,
                    ship_recipient_name, ship_phone, ship_region, ship_city, ship_barangay, ship_detail_address, ship_landmark, ship_lat, ship_lng,
                    status, payment_method, memo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", "cod", ?)'
        );
        $store_id = MALL_STORE_ID;
        $ship_lat = (float)$address['lat'];
        $ship_lng = (float)$address['lng'];
        $order_stmt->bind_param(
            'siisddddsssssssdds',
            $order_number, $member_id, $store_id, $requested_channel,
            $combined_subtotal, $summary['discount_amount'], $shipping_fee, $total_with_shipping,
            $address['recipient_name'], $address['phone'], $address['region'], $address['city'],
            $address['barangay'], $address['detail_address'], $address['landmark'], $ship_lat, $ship_lng,
            $memo
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

        mall_fresh_create_order_items($conn, $order_id, $member_id, $fresh_summary);

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

/**
 * 주문 항목의 품절 제외 상태가 바뀐 뒤 mall_orders.subtotal/discount_amount/shipping_fee/total_amount를
 * 다시 계산해 저장합니다. is_sold_out=1인 항목은 소계/합계 계산에서 제외됩니다.
 * Design Ref: mall-delivery-dispatch 피킹 중 품절 처리 — 항목별 base_price는 저장돼 있지 않으므로
 * unit_price_snapshot(할인 적용 후 단가)과 discount_rate_snapshot으로 역산한다.
 *
 * mall_fresh_order_items(신선상품, 완전 분리 테이블)도 함께 합산한다 — 실측 전(confirmed_price가
 * NULL)이면 estimated_price를, 실측 후면 confirmed_price를 쓴다. 신선상품은 할인이 없어 subtotal/
 * total에 동일하게 기여한다(mall_create_order()의 combined_subtotal 계산과 동일한 원칙).
 * Design Ref: mall-fresh-products.design.md §4.3.
 * @param mysqli $conn
 * @param int $order_id
 * @return array{subtotal:float, discount_amount:float, shipping_fee:float, total_amount:float}
 */
function mall_recalculate_order_totals($conn, $order_id) {
    $stmt = $conn->prepare(
        'SELECT unit_price_snapshot, discount_rate_snapshot, quantity, line_total, is_sold_out
         FROM mall_order_items WHERE order_id = ?'
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $subtotal = 0.0;
    $total = 0.0;
    foreach ($items as $it) {
        if ((int)$it['is_sold_out'] === 1) {
            continue;
        }
        $unit_price = (float)$it['unit_price_snapshot'];
        $discount_rate = (float)$it['discount_rate_snapshot'];
        $quantity = (int)$it['quantity'];
        $unit_base = $discount_rate < 100 ? round($unit_price / (1 - $discount_rate / 100), 2) : $unit_price;
        $subtotal += $unit_base * $quantity;
        $total += (float)$it['line_total'];
    }

    $fresh_stmt = $conn->prepare(
        'SELECT estimated_price, confirmed_price, is_sold_out FROM mall_fresh_order_items WHERE order_id = ?'
    );
    $fresh_stmt->bind_param('i', $order_id);
    $fresh_stmt->execute();
    $fresh_items = $fresh_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fresh_stmt->close();
    foreach ($fresh_items as $it) {
        if ((int)$it['is_sold_out'] === 1) {
            continue;
        }
        $amount = $it['confirmed_price'] !== null ? (float)$it['confirmed_price'] : (float)$it['estimated_price'];
        $subtotal += $amount;
        $total += $amount;
    }

    $subtotal = round($subtotal, 2);
    $total = round($total, 2);
    $discount_amount = round($subtotal - $total, 2);
    $shipping_fee = mall_calculate_shipping_fee($subtotal);
    $total_amount = round($total + $shipping_fee, 2);

    $update = $conn->prepare(
        'UPDATE mall_orders SET subtotal = ?, discount_amount = ?, shipping_fee = ?, total_amount = ? WHERE id = ?'
    );
    $update->bind_param('ddddi', $subtotal, $discount_amount, $shipping_fee, $total_amount, $order_id);
    $update->execute();
    $update->close();

    return [
        'subtotal' => $subtotal,
        'discount_amount' => $discount_amount,
        'shipping_fee' => $shipping_fee,
        'total_amount' => $total_amount,
    ];
}
