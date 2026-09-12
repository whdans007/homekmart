<?php
/**
 * 신선상품 전용 장바구니 유스케이스 (Application Layer)
 * Design Ref: mall-fresh-products.design.md §3.2, §9, §10 — mall/lib/cart.php와 대칭되는 함수형 헬퍼.
 *
 * 정가상품 mall_cart_items와 완전히 분리된 mall_fresh_cart_items를 다룬다. 신선상품은 채널(retail/
 * wholesale) 구분이 없는 단일가라 channel 파라미터가 없다. sale_type(weight/piece)에 따라 weight_g
 * 또는 quantity 중 하나만 채워진다.
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
    // 큐레이션 화면(mall/admin/products.php)의 "가격 설정" 모달은 낱개/무게 상품 모두 "수량/무게
    // 기준 총액"을 selling_price_override에 그대로 저장한다(사용자 확정, 2026-09-12) — 이 값은
    // 실제 몰 주문화면(고객이 개별 수량/무게를 직접 고르는 화면)의 단가 계산과 단위가 안 맞을 수
    // 있어, 스토어프론트가 수량 고정 판매로 바뀌기 전까지는 고객 결제에 반영하지 않는다(관리자
    // 화면 표시 전용). 진열명(display_name_override)은 텍스트라 단위 문제가 없어 그대로 반영한다.
    $stmt = $conn->prepare(
        "SELECT id, COALESCE(display_name_override, name_ko) AS name_ko,
                COALESCE(display_name_en_override, name_en) AS name_en, sale_type,
                price_per_100g, status, is_sold_out
         FROM mall_fresh_products WHERE id = ?"
    );
    $stmt->bind_param('i', $mall_fresh_product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

/**
 * 신선상품을 장바구니에 담습니다(최종값 지정 — 무게 스테퍼 UX에 맞춰 "더하기"가 아니라 이미 담긴
 * 값을 덮어씁니다). sale_type이 weight면 $weight_g(100의 배수)를, piece면 $quantity(정수)를 넘깁니다.
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

    if ($product['sale_type'] === 'weight') {
        $weight_g = (int)$weight_g;
        if ($weight_g <= 0 || $weight_g % 100 !== 0) {
            return ['success' => false, 'error' => 'VALIDATION_ERROR'];
        }
        $quantity = null;
    } else {
        $quantity = (int)$quantity;
        if ($quantity <= 0) {
            return ['success' => false, 'error' => 'VALIDATION_ERROR'];
        }
        $weight_g = null;
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

    $estimated_price = $product['sale_type'] === 'weight'
        ? round($weight_g / 100 * (float)$product['price_per_100g'], 2)
        : round((float)$product['price_per_100g'] * $quantity, 2);

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
                mfp.sale_type, mfp.price_per_100g,
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
        $sold_out = (int)$row['is_sold_out'] === 1 || $row['status'] !== 'active';
        if ($sold_out) {
            $has_sold_out = true;
        }
        $unit_price = (float)$row['price_per_100g'];
        // sale_type이 아니라 카트 행 자체에 실제로 채워진 컬럼으로 분기한다 — 담은 뒤 관리자가
        // 상품의 sale_type을 바꿔도(admin/edit_fresh_product.php) 이 장바구니 행은 담을 당시의
        // weight_g/quantity를 그대로 들고 있으므로, 현재 sale_type을 신뢰하면 반대쪽 컬럼이 NULL이라
        // 금액이 0으로 잘못 계산될 수 있다.
        if ($row['weight_g'] !== null) {
            $estimated_price = round((int)$row['weight_g'] / 100 * $unit_price, 2);
        } else {
            $estimated_price = round($unit_price * (int)$row['quantity'], 2);
        }
        $items[] = array_merge($row, [
            'unit_price' => $unit_price,
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

        mall_fresh_cart_add($member_id, null, $product_id, $weight_g, $quantity);
        mall_fresh_cart_remove(null, $guest_token, (int)$item['id']);
    }
    $member_stmt->close();
    $conn->close();
}
