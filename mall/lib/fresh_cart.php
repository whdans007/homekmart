<?php
require_once __DIR__ . '/fresh_pricing.php';
/**
 * 신선상품 전용 장바구니 유스케이스 (Application Layer)
 * Design Ref: mall-fresh-products.design.md §3.2, §9, §10 — mall/lib/cart.php와 대칭되는 함수형 헬퍼.
 *
 * 정가상품 mall_cart_items와 완전히 분리된 mall_fresh_cart_items를 다룬다. 신선상품은 채널(retail/
 * wholesale) 구분이 없는 단일가라 channel 파라미터가 없다. 신규 담기는 모두 quantity를 사용한다.
 * 상품의 매입 방식과 관계없이 고객은 설정된 판매 구성 1개 단위로 주문한다.
 *
 * cart.php와 마찬가지로 회원가입 없이도 쓸 수 있어야 하므로 모든 함수가 $member_id/$guest_token을
 * 함께 받는다(둘 중 하나만 채우고 나머지는 null — member_id 우선).
 */

/**
 * 소유자 식별 조건의 SQL 조각과 바인드 값을 만듭니다(IDOR 방지 — mall_cart_owner_clause()와 동일 패턴).
 * @param int|null $member_id
 * @param string|null $guest_token
 * @return array{0:string,1:string,2:mixed}
 */
function mall_fresh_cart_owner_clause($member_id, $guest_token) {
    if ($member_id) {
        return ['member_id = ?', 'i', (int)$member_id];
    }
    return ['guest_token = ?', 's', (string)$guest_token];
}

/**
 * 신선상품 마스터 조회 (담기 전 sale_type/status/is_sold_out 확인용).
 * @param int $mall_fresh_product_id
 * @return array|null
 */
function mall_fresh_product_get($mall_fresh_product_id) {
    $conn = mall_get_db_connection();
    // The configured selling amount is the price of one purchasable unit.
    $stmt = $conn->prepare(
        "SELECT id, COALESCE(display_name_override, name_ko) AS name_ko,
                COALESCE(display_name_en_override, name_en) AS name_en, sale_type,
                price_per_100g, selling_price_override, selling_weight_reference_g, status, is_sold_out
         FROM mall_fresh_products WHERE id = ?"
    );
    $stmt->bind_param('i', $mall_fresh_product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    if ($row) {
        $row['price_per_100g'] = mall_fresh_sale_unit_price($row);
        $row['sale_type'] = 'piece';
    }
    return $row ?: null;
}

/**
 * 신선상품을 장바구니에 담습니다(최종 수량 지정). $weight_g는 null,
 * $quantity는 양의 정수만 허용하며 기존 무게 장바구니도 다시 담으면 낱개 수량으로 교체됩니다.
 * @param int|null $member_id
 * @param string|null $guest_token
 * @param int $mall_fresh_product_id
 * @param int|null $weight_g
 * @param int|null $quantity
 * @return array{success:bool, error?:string, data?:array}
 */
function mall_fresh_cart_add($member_id, $guest_token, $mall_fresh_product_id, $weight_g, $quantity) {
    if (!$member_id && !$guest_token) {
        return ['success' => false, 'error' => 'VALIDATION_ERROR'];
    }

    $product = mall_fresh_product_get($mall_fresh_product_id);
    if (!$product || $product['status'] !== 'active') {
        return ['success' => false, 'error' => 'NOT_FOUND'];
    }
    if ((int)$product['is_sold_out'] === 1) {
        return ['success' => false, 'error' => 'SOLD_OUT'];
    }

    $quantity = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($weight_g !== null || $quantity === false) {
        return ['success' => false, 'error' => 'VALIDATION_ERROR'];
    }

    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        'INSERT INTO mall_fresh_cart_items (member_id, guest_token, mall_fresh_product_id, weight_g, quantity)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE weight_g = VALUES(weight_g), quantity = VALUES(quantity)'
    );
    $member_id_param = $member_id ? (int)$member_id : null;
    $stmt->bind_param('isiii', $member_id_param, $guest_token, $mall_fresh_product_id, $weight_g, $quantity);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    $estimated_price = round((float)$product['price_per_100g'] * $quantity, 2);

    return ['success' => true, 'data' => ['weight_g' => $weight_g, 'quantity' => $quantity, 'estimated_price' => $estimated_price]];
}

/**
 * 신선상품 장바구니 항목을 삭제합니다. 소유자가 아니면 아무 것도 하지 않습니다(IDOR 방지).
 * @param int|null $member_id
 * @param string|null $guest_token
 * @param int $cart_item_id
 * @return array{success:bool}
 */
function mall_fresh_cart_remove($member_id, $guest_token, $cart_item_id) {
    [$owner_sql, $owner_type, $owner_value] = mall_fresh_cart_owner_clause($member_id, $guest_token);
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare("DELETE FROM mall_fresh_cart_items WHERE id = ? AND {$owner_sql}");
    $stmt->bind_param('i' . $owner_type, $cart_item_id, $owner_value);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    return ['success' => $affected > 0];
}

/**
 * 신선상품 장바구니에 담긴 총 라인 수(헤더 뱃지 합산용 — mall_cart_count()와 더해서 쓴다).
 * @param int|null $member_id
 * @param string|null $guest_token
 * @return int
 */
function mall_fresh_cart_count($member_id, $guest_token) {
    if (!$member_id && !$guest_token) {
        return 0;
    }
    [$owner_sql, $owner_type, $owner_value] = mall_fresh_cart_owner_clause($member_id, $guest_token);
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM mall_fresh_cart_items WHERE {$owner_sql}");
    $stmt->bind_param($owner_type, $owner_value);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    $conn->close();

    return $count;
}

/**
 * 신선상품 장바구니 요약(항목별 예상금액 포함 + 합계)을 계산합니다. 서버가 price_per_100g을 다시
 * 조회해 재계산하므로 클라이언트가 보낸 금액은 신뢰하지 않습니다(정가상품과 동일한 원칙).
 * @param int|null $member_id
 * @param string|null $guest_token
 * @return array{items: array, subtotal: float, has_sold_out: bool}
 */
function mall_fresh_cart_get_summary($member_id, $guest_token) {
    if (!$member_id && !$guest_token) {
        return ['items' => [], 'subtotal' => 0.0, 'has_sold_out' => false];
    }
    [$owner_sql, $owner_type, $owner_value] = mall_fresh_cart_owner_clause($member_id, $guest_token);
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        "SELECT ci.id AS cart_item_id, ci.mall_fresh_product_id, ci.weight_g, ci.quantity,
                COALESCE(mfp.display_name_override, mfp.name_ko) AS name_ko,
                COALESCE(mfp.display_name_en_override, mfp.name_en) AS name_en,
                mfp.sale_type, mfp.price_per_100g, mfp.selling_price_override, mfp.selling_weight_reference_g,
                mfp.status, mfp.is_sold_out, mfp.image_url
         FROM mall_fresh_cart_items ci
         INNER JOIN mall_fresh_products mfp ON mfp.id = ci.mall_fresh_product_id
         WHERE ci.{$owner_sql}
         ORDER BY ci.created_at DESC"
    );
    $stmt->bind_param($owner_type, $owner_value);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    $items = [];
    $subtotal = 0.0;
    $has_sold_out = false;

    foreach ($rows as $row) {
        $requires_readd = $row['weight_g'] !== null || (int)$row['quantity'] <= 0;
        $sold_out = (int)$row['is_sold_out'] === 1 || $row['status'] !== 'active' || $requires_readd;
        if ($sold_out) {
            $has_sold_out = true;
        }
        $unit_price = mall_fresh_sale_unit_price($row);
        $estimated_price = $requires_readd ? 0.0 : round($unit_price * (int)$row['quantity'], 2);
        $items[] = array_merge($row, [
            'unit_price' => $unit_price,
            'sale_type' => 'piece',
            'requires_readd' => $requires_readd,
            'estimated_price' => $estimated_price,
            'sold_out' => $sold_out,
        ]);
        if (!$sold_out) {
            $subtotal += $estimated_price;
        }
    }

    return ['items' => $items, 'subtotal' => round($subtotal, 2), 'has_sold_out' => $has_sold_out];
}

/**
 * 로그인 직전까지 게스트로 담아둔 신선상품 장바구니를 로그인한 회원 장바구니로 병합합니다.
 * mall_cart_merge_guest_into_member()와 동일한 호출 시점 주의사항 적용(로그인 "전" 토큰 필요).
 * @param int $member_id
 * @param string|null $guest_token
 * @return void
 */
function mall_fresh_cart_merge_guest_into_member($member_id, $guest_token) {
    if (!$member_id || !$guest_token) {
        return;
    }

    $conn = mall_get_db_connection();
    $stmt = $conn->prepare('SELECT id, mall_fresh_product_id, weight_g, quantity FROM mall_fresh_cart_items WHERE guest_token = ?');
    $stmt->bind_param('s', $guest_token);
    $stmt->execute();
    $guest_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // mall_fresh_cart_add()는 "최종값 지정" 방식(무게 스테퍼 UX에 맞춘 설계)이라 그대로 쓰면 병합 시
    // 회원이 이미 담아둔 값을 덮어써 버린다. mall_cart_add()의 additive 병합과 사용자 기대를 맞추기
    // 위해, 같은 상품이 이미 회원 장바구니에 있으면 값을 더해서 넘긴다.
    $member_stmt = $conn->prepare('SELECT weight_g, quantity FROM mall_fresh_cart_items WHERE member_id = ? AND mall_fresh_product_id = ?');
    foreach ($guest_items as $item) {
        $product_id = (int)$item['mall_fresh_product_id'];
        $weight_g = $item['weight_g'] !== null ? (int)$item['weight_g'] : null;
        $quantity = $item['quantity'] !== null ? (int)$item['quantity'] : null;

        $member_stmt->bind_param('ii', $member_id, $product_id);
        $member_stmt->execute();
        $existing = $member_stmt->get_result()->fetch_assoc();
        if ($existing) {
            if ($weight_g !== null && $existing['weight_g'] !== null) {
                $weight_g += (int)$existing['weight_g'];
            }
            if ($quantity !== null && $existing['quantity'] !== null) {
                $quantity += (int)$existing['quantity'];
            }
        }

        $result = mall_fresh_cart_add($member_id, null, $product_id, $weight_g, $quantity);
        if ($result['success']) {
            mall_fresh_cart_remove(null, $guest_token, (int)$item['id']);
        }
    }
    $member_stmt->close();
    $conn->close();
}
