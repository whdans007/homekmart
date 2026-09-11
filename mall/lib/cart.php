<?php
/**
 * 장바구니 유스케이스 (Application Layer)
 * Design Ref: shopping-mall.design.md §9.1, §9.3 — pricing.php에만 의존, Presentation 파일 require 금지
 *
 * 장바구니는 회원가입 없이도 쓸 수 있어야 한다(주문 시에만 로그인 필요) — 그래서 모든 함수가
 * $member_id/$guest_token 두 값을 함께 받는다. 호출하는 쪽(Presentation)이 로그인 여부에 따라
 * 둘 중 하나만 채우고 나머지는 null로 넘겨야 한다(둘 다 채우면 안 됨 — member_id 우선 적용).
 */
require_once __DIR__ . '/pricing.php';

/**
 * 소유자 식별 조건의 SQL 조각과 바인드 값을 만듭니다(IDOR 방지 — 항상 이 조건으로만 조회/수정).
 * @param int|null $member_id
 * @param string|null $guest_token
 * @return array{0:string,1:string,2:mixed} [SQL조각, bind타입, bind값]
 */
function mall_cart_owner_clause($member_id, $guest_token) {
    if ($member_id) {
        return ['member_id = ?', 'i', (int)$member_id];
    }
    return ['guest_token = ?', 's', (string)$guest_token];
}

/**
 * 장바구니에 상품을 담습니다. 이미 담긴 상품×채널이면 수량을 더합니다.
 * @param int|null $member_id 로그인 회원이면 회원 id, 아니면 null
 * @param string|null $guest_token 비로그인이면 mall_guest_token() 값, 회원이면 null
 * @param int $product_id
 * @param string $channel retail|wholesale
 * @param int $quantity
 * @return array{success:bool, error?:string}
 */
function mall_cart_add($member_id, $guest_token, $product_id, $channel, $quantity) {
    if ($quantity <= 0) {
        return ['success' => false, 'error' => 'VALIDATION_ERROR'];
    }
    if (!$member_id && !$guest_token) {
        return ['success' => false, 'error' => 'VALIDATION_ERROR'];
    }

    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        'INSERT INTO mall_cart_items (member_id, guest_token, product_id, channel, quantity)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
    );
    $member_id_param = $member_id ? (int)$member_id : null;
    $stmt->bind_param('isisi', $member_id_param, $guest_token, $product_id, $channel, $quantity);
    $result = $stmt->execute();
    $stmt->close();
    // INSERT ... ON DUPLICATE KEY UPDATE에서도 auto_increment PK 기준으로 insert_id가 채워진다(신규/기존 둘 다).
    $cart_item_id = $conn->insert_id;

    $qty_stmt = $conn->prepare('SELECT quantity FROM mall_cart_items WHERE id = ?');
    $qty_stmt->bind_param('i', $cart_item_id);
    $qty_stmt->execute();
    $final_quantity = (int)($qty_stmt->get_result()->fetch_assoc()['quantity'] ?? $quantity);
    $qty_stmt->close();
    $conn->close();

    return ['success' => (bool)$result, 'cart_item_id' => $cart_item_id, 'quantity' => $final_quantity];
}

/**
 * 현재 장바구니에 담긴 상품별 수량/cart_item_id를 조회합니다. 홈/카테고리 화면에서 상품 카드마다
 * "담기" 버튼을 보여줄지 수량 스테퍼를 보여줄지 초기 상태를 정할 때 쓴다.
 * @param int|null $member_id
 * @param string|null $guest_token
 * @return array<int, array{cart_item_id:int, quantity:int}> product_id => 상태
 */
function mall_cart_get_quantities_by_product($member_id, $guest_token) {
    if (!$member_id && !$guest_token) {
        return [];
    }
    [$owner_sql, $owner_type, $owner_value] = mall_cart_owner_clause($member_id, $guest_token);
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare("SELECT id AS cart_item_id, product_id, quantity FROM mall_cart_items WHERE {$owner_sql}");
    $stmt->bind_param($owner_type, $owner_value);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    $by_product = [];
    foreach ($rows as $row) {
        $by_product[(int)$row['product_id']] = [
            'cart_item_id' => (int)$row['cart_item_id'],
            'quantity' => (int)$row['quantity'],
        ];
    }
    return $by_product;
}

/**
 * 장바구니 항목의 수량을 변경합니다. 소유자가 아니면 아무 것도 하지 않습니다(IDOR 방지).
 * @param int|null $member_id
 * @param string|null $guest_token
 * @param int $cart_item_id
 * @param int $quantity 0 이하면 삭제
 * @return array{success:bool, error?:string}
 */
function mall_cart_update_quantity($member_id, $guest_token, $cart_item_id, $quantity) {
    if ($quantity <= 0) {
        return mall_cart_remove($member_id, $guest_token, $cart_item_id);
    }

    [$owner_sql, $owner_type, $owner_value] = mall_cart_owner_clause($member_id, $guest_token);
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare("UPDATE mall_cart_items SET quantity = ? WHERE id = ? AND {$owner_sql}");
    $stmt->bind_param('ii' . $owner_type, $quantity, $cart_item_id, $owner_value);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return ['success' => $affected > 0];
}

/**
 * 장바구니 항목을 삭제합니다. 소유자가 아니면 아무 것도 하지 않습니다(IDOR 방지).
 * @param int|null $member_id
 * @param string|null $guest_token
 * @param int $cart_item_id
 * @return array{success:bool}
 */
function mall_cart_remove($member_id, $guest_token, $cart_item_id) {
    [$owner_sql, $owner_type, $owner_value] = mall_cart_owner_clause($member_id, $guest_token);
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare("DELETE FROM mall_cart_items WHERE id = ? AND {$owner_sql}");
    $stmt->bind_param('i' . $owner_type, $cart_item_id, $owner_value);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return ['success' => $affected > 0];
}

/**
 * 장바구니에 담긴 총 수량(헤더 뱃지용)을 반환합니다.
 * @param int|null $member_id
 * @param string|null $guest_token
 * @return int
 */
function mall_cart_count($member_id, $guest_token) {
    if (!$member_id && !$guest_token) {
        return 0;
    }
    [$owner_sql, $owner_type, $owner_value] = mall_cart_owner_clause($member_id, $guest_token);
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) AS cnt FROM mall_cart_items WHERE {$owner_sql}");
    $stmt->bind_param($owner_type, $owner_value);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    $conn->close();

    return $count;
}

/**
 * 장바구니 항목 원본 행(상품명/이미지 포함)을 조회합니다.
 * @param int|null $member_id
 * @param string|null $guest_token
 * @return array<int, array>
 */
function mall_cart_get_raw_items($member_id, $guest_token) {
    if (!$member_id && !$guest_token) {
        return [];
    }
    [$owner_sql, $owner_type, $owner_value] = mall_cart_owner_clause($member_id, $guest_token);
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        "SELECT ci.id AS cart_item_id, ci.product_id, ci.channel, ci.quantity,
                COALESCE(mp.display_name, p.name_ko) AS display_name,
                (SELECT image_path FROM mall_product_images WHERE product_id = ci.product_id ORDER BY sort_order LIMIT 1) AS image_path
         FROM mall_cart_items ci
         INNER JOIN products p ON p.id = ci.product_id
         LEFT JOIN mall_products mp ON mp.product_id = ci.product_id
         WHERE ci.{$owner_sql}
         ORDER BY ci.created_at DESC"
    );
    $stmt->bind_param($owner_type, $owner_value);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    return $rows;
}

/**
 * 장바구니 요약(항목별 가격 포함 + 소계/할인/합계)을 계산합니다.
 * 도매 즉석할인은 주문금액 구간에 따라 달라지므로 2단계로 계산합니다:
 * 1) 기본가 합계(subtotal_base)로 구간을 먼저 정하고, 2) 그 구간을 적용해 각 항목의 최종가를 재계산합니다.
 *
 * @param int|null $member_id
 * @param string|null $guest_token
 * @param array|null $member mall_current_member() 반환값(비로그인/게스트는 null)
 * @return array{items: array, subtotal: float, discount_amount: float, total: float, has_out_of_stock: bool}
 */
function mall_cart_get_summary($member_id, $guest_token, $member) {
    $raw_items = mall_cart_get_raw_items($member_id, $guest_token);

    if (empty($raw_items)) {
        return ['items' => [], 'subtotal' => 0.0, 'discount_amount' => 0.0, 'total' => 0.0, 'has_out_of_stock' => false];
    }

    // 1단계: 채널별 기본가 합계로 도매 즉석할인 구간을 먼저 판정
    $subtotal_base = 0.0;
    foreach ($raw_items as $row) {
        $price = mall_calculate_price($row['product_id'], $member, $row['channel'], 0.0);
        $subtotal_base += ($price['base_price'] ?? 0.0) * $row['quantity'];
    }

    // 2단계: 확정된 소계를 기준으로 실제 할인율/최종가 재계산
    $items = [];
    $subtotal = 0.0;
    $total = 0.0;
    $has_out_of_stock = false;

    foreach ($raw_items as $row) {
        $price = mall_calculate_price($row['product_id'], $member, $row['channel'], $subtotal_base);
        $stock = mall_get_stock_quantity($row['product_id']);
        $out_of_stock = $stock < $row['quantity'];
        if ($out_of_stock) {
            $has_out_of_stock = true;
        }

        $line_base = ($price['base_price'] ?? 0.0) * $row['quantity'];
        $line_total = ($price['final_price'] ?? 0.0) * $row['quantity'];

        $items[] = array_merge($row, [
            'price' => $price,
            'stock' => $stock,
            'out_of_stock' => $out_of_stock,
            'line_total' => round($line_total, 2),
        ]);

        $subtotal += $line_base;
        $total += $line_total;
    }

    return [
        'items' => $items,
        'subtotal' => round($subtotal, 2),
        'discount_amount' => round($subtotal - $total, 2),
        'total' => round($total, 2),
        'has_out_of_stock' => $has_out_of_stock,
    ];
}

/**
 * 배송비를 계산합니다. 소계(할인 적용 전, mall_cart_get_summary()의 subtotal)가 무료배송
 * 기준금액 이상이면 0원, 아니면 기본 배송비를 반환합니다.
 * order_checkout.php(표시용)와 mall_create_order()(실제 부과)가 반드시 이 함수를 함께 써서
 * 화면에 보여준 금액과 실제 청구 금액이 어긋나지 않게 한다.
 * @param float $subtotal
 * @return float
 */
function mall_calculate_shipping_fee($subtotal) {
    return $subtotal >= MALL_FREE_SHIPPING_THRESHOLD ? 0.0 : (float)MALL_BASE_SHIPPING_FEE;
}

/**
 * 로그인 직전까지 게스트로 담아둔 장바구니를 로그인한 회원 장바구니로 병합합니다.
 * 같은 상품×채널이 이미 회원 장바구니에 있으면 수량을 더하고, 없으면 그대로 옮깁니다.
 * 호출 시점 주의: mall_attempt_login()이 성공 시 session_regenerate_id()를 호출해
 * mall_guest_token()의 값이 바뀌므로, 로그인 호출 "전에" 미리 캡처해둔 토큰을 넘겨야 한다.
 * @param int $member_id
 * @param string|null $guest_token 로그인 전 게스트 토큰(없었으면 null — 아무 것도 안 함)
 * @return void
 */
function mall_cart_merge_guest_into_member($member_id, $guest_token) {
    if (!$member_id || !$guest_token) {
        return;
    }

    $conn = mall_get_db_connection();
    $stmt = $conn->prepare('SELECT id, product_id, channel, quantity FROM mall_cart_items WHERE guest_token = ?');
    $stmt->bind_param('s', $guest_token);
    $stmt->execute();
    $guest_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    foreach ($guest_items as $item) {
        mall_cart_add($member_id, null, (int)$item['product_id'], $item['channel'], (int)$item['quantity']);
        mall_cart_remove(null, $guest_token, (int)$item['id']);
    }
}
