<?php
/**
 * 카탈로그 조회 공용화 (Domain Layer)
 * Design Ref: mall-home-layout.design.md §1.2, §9 — index.php에 있던 UNION 조회 로직을 추출.
 * category.php(카테고리 탐색)와 home_layout.php의 슬롯 렌더 함수(오늘의특가/새상품)가 함께 재사용한다.
 * pricing.php에만 의존하며, 다른 mall/lib 파일을 require하지 않는다(shopping-mall §9.3 원칙 계승).
 */
require_once __DIR__ . '/pricing.php';

/**
 * 채널/카테고리/검색어 조건에 맞는 노출 대상 상품을 조회합니다.
 * 소매는 mall_products 큐레이션만, 도매는 여기에 mall_wholesale_visibility로 노출 켠
 * wholesale_products도 UNION으로 함께 포함한다(FR-05, shopping-mall Check phase에서 확립).
 *
 * @param string $channel retail|wholesale
 * @param int|null $category_id
 * @param string $search
 * @param int $limit
 * @param string $order_by 'default'(큐레이션 display_order 우선) | 'newest'(등록일 최신순).
 *        가격순 정렬은 여기서 하지 않는다 — 할인/등급 적용 최종가는 mall_calculate_price()에서만
 *        계산되므로, 가격순이 필요한 화면은 mall_build_product_cards() 결과를 받아 price.final_price로
 *        직접 정렬한다(search.php 참고).
 * @return array<int, array{product_id:int, display_name:string, display_name_en:?string, image_path:?string, created_at:string}>
 */
function mall_get_eligible_products($channel, $category_id = null, $search = '', $limit = 60, $order_by = 'default') {
    $conn = mall_get_db_connection();

    if ($channel === 'wholesale') {
        $eligible_sql = 'SELECT product_id FROM mall_products WHERE store_id = ? AND is_active = 1
                          UNION
                          SELECT wp.product_id FROM wholesale_products wp
                          INNER JOIN mall_wholesale_visibility mwv ON mwv.wholesale_product_id = wp.id
                          WHERE wp.store_id = ? AND wp.is_active = 1 AND mwv.is_visible = 1';
        $base_params = [MALL_STORE_ID, MALL_STORE_ID];
        $base_types = 'ii';
    } else {
        $eligible_sql = 'SELECT product_id FROM mall_products WHERE store_id = ? AND is_active = 1';
        $base_params = [MALL_STORE_ID];
        $base_types = 'i';
    }

    $where = ['p.is_active = 1'];
    // 바인드 순서는 SQL 텍스트의 "?" 등장 순서와 정확히 일치해야 한다:
    // (1) 파생 테이블(eligible_sql)의 store_id들 → (2) LEFT JOIN mp.store_id → (3) WHERE절 조건들
    $params = array_merge($base_params, [MALL_STORE_ID]);
    $types = $base_types . 'i';

    if ($category_id) {
        // 카테고리 탭은 최상위 카테고리만 노출하므로(category_tabs.php), 하위 카테고리에 속한
        // 상품도 함께 보여주기 위해 자식 카테고리 id까지 포함해 필터링한다.
        $child_stmt = $conn->prepare('SELECT id FROM categories WHERE id = ? OR parent_id = ?');
        $child_stmt->bind_param('ii', $category_id, $category_id);
        $child_stmt->execute();
        $category_ids = array_column($child_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $child_stmt->close();
        if (empty($category_ids)) {
            $category_ids = [$category_id];
        }

        $cat_placeholders = implode(',', array_fill(0, count($category_ids), '?'));
        $where[] = "p.category_id IN ({$cat_placeholders})";
        foreach ($category_ids as $cid) {
            $params[] = $cid;
            $types .= 'i';
        }
    }
    if ($search !== '') {
        $where[] = '(p.name_ko LIKE ? OR p.name_en LIKE ? OR mp.display_name LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    $order_clause = ($order_by === 'newest') ? 'p.created_at DESC, d.product_id DESC' : 'display_order, d.product_id DESC';

    $sql = "SELECT d.product_id,
                   COALESCE(mp.display_name, p.name_ko) AS display_name,
                   COALESCE(mp.display_name_en, p.name_en) AS display_name_en,
                   COALESCE(mp.display_order, 999999) AS display_order,
                   p.created_at,
                   (SELECT image_path FROM mall_product_images WHERE product_id = d.product_id ORDER BY sort_order LIMIT 1) AS image_path
            FROM ({$eligible_sql}) d
            INNER JOIN products p ON p.id = d.product_id
            LEFT JOIN mall_products mp ON mp.product_id = d.product_id AND mp.store_id = ?
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$order_clause}
            LIMIT ?";

    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    return $rows;
}

/**
 * 카테고리 조건에 맞는 신선상품(mall_fresh_products)을 조회합니다.
 * Design Ref: mall-fresh-products.design.md §4.3.
 *
 * mall_get_eligible_products()와 같은 배열에 합치지 않고 별도 함수로 둔다 — mall_fresh_products.id는
 * products.id와 다른 id 공간이라, 한 배열에 섞으면 이후 mall_build_product_cards()가 product_id를
 * 기준으로 mall_calculate_price()/mall_get_stock_quantity()를 호출할 때 엉뚱한(또는 우연히 id가 같은
 * 다른) 정가상품을 조회하게 되는 사고가 날 수 있다. 화면(카테고리 그리드) 쪽에서 두 배열을 각각 받아
 * item_type으로 구분해 렌더링한다.
 *
 * @param int|null $category_id
 * @param string $search
 * @param int $limit
 * @return array<int, array{id:int, name_ko:string, name_en:?string, sale_type:string, price_per_100g:float, image_url:?string}>
 */
function mall_get_eligible_fresh_products($category_id = null, $search = '', $limit = 60) {
    require_once __DIR__ . '/fresh_pricing.php';
    $conn = mall_get_db_connection();

    $where = ["status = 'active'", 'is_sold_out = 0'];
    $params = [];
    $types = '';

    if ($category_id) {
        // 정가상품과 동일하게 대분류 선택 시 하위 소분류도 함께 포함한다.
        $child_stmt = $conn->prepare('SELECT id FROM categories WHERE id = ? OR parent_id = ?');
        $child_stmt->bind_param('ii', $category_id, $category_id);
        $child_stmt->execute();
        $category_ids = array_column($child_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $child_stmt->close();
        if (empty($category_ids)) {
            $category_ids = [$category_id];
        }
        $cat_placeholders = implode(',', array_fill(0, count($category_ids), '?'));
        $where[] = "category_id IN ({$cat_placeholders})";
        foreach ($category_ids as $cid) {
            $params[] = $cid;
            $types .= 'i';
        }
    }
    if ($search !== '') {
        $where[] = '(name_ko LIKE ? OR name_en LIKE ? OR code LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    // Display the same price per configured selling unit as detail/cart/checkout.
    $sql = "SELECT id,
                   COALESCE(display_name_override, name_ko) AS name_ko,
                   COALESCE(display_name_en_override, name_en) AS name_en,
                   sale_type, price_per_100g, selling_price_override, selling_weight_reference_g,
                   image_url, display_order
            FROM mall_fresh_products
            WHERE " . implode(' AND ', $where) . '
            ORDER BY display_order, id DESC
            LIMIT ?';
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    foreach ($rows as &$row) {
        $row['price_per_100g'] = mall_fresh_sale_unit_price($row);
        $row['sale_type'] = 'piece';
    }
    unset($row);
    return $rows;
}

/**
 * 관리자가 홈 "상품 리스트" 섹션에 수동으로 선택한 product_id 목록을 조회합니다.
 * mall_products 큐레이션 여부와 무관하게(홈 섹션 자체가 큐레이션 수단이므로) 지정한 순서 그대로 반환한다.
 *
 * @param array<int, int> $product_ids
 * @return array<int, array{product_id:int, display_name:string, display_name_en:?string, image_path:?string}>
 */
function mall_get_products_by_ids($product_ids) {
    $product_ids = array_values(array_filter(array_map('intval', $product_ids), fn($id) => $id > 0));
    if (empty($product_ids)) {
        return [];
    }

    $conn = mall_get_db_connection();
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $types = str_repeat('i', count($product_ids));

    $stmt = $conn->prepare(
        "SELECT p.id AS product_id,
                COALESCE(mp.display_name, p.name_ko) AS display_name,
                COALESCE(mp.display_name_en, p.name_en) AS display_name_en,
                (SELECT image_path FROM mall_product_images WHERE product_id = p.id ORDER BY sort_order LIMIT 1) AS image_path
         FROM products p
         LEFT JOIN mall_products mp ON mp.product_id = p.id AND mp.store_id = ?
         WHERE p.id IN ({$placeholders}) AND p.is_active = 1"
    );
    $bind_types = 'i' . $types;
    $bind_values = array_merge([MALL_STORE_ID], $product_ids);
    $stmt->bind_param($bind_types, ...$bind_values);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    // 관리자가 지정한 순서를 그대로 보존
    $by_id = [];
    foreach ($rows as $row) {
        $by_id[(int)$row['product_id']] = $row;
    }
    $ordered = [];
    foreach ($product_ids as $id) {
        if (isset($by_id[$id])) {
            $ordered[] = $by_id[$id];
        }
    }
    return $ordered;
}

/**
 * 상품 행 목록을 화면 카드 배열로 변환합니다(가격/재고 계산 포함).
 * product_card.php 파셜이 기대하는 형태를 만든다.
 *
 * @param array $product_rows mall_get_eligible_products()/mall_get_products_by_ids() 반환값
 * @param array|null $member
 * @param string $channel retail|wholesale
 * @param string $mall_lang ko|en
 * @return array<int, array>
 */
function mall_build_product_cards($product_rows, $member, $channel, $mall_lang) {
    $cards = [];
    foreach ($product_rows as $p) {
        $price = mall_calculate_price($p['product_id'], $member, $channel, 0.0);
        $display_name = ($mall_lang === 'en' && !empty($p['display_name_en'])) ? $p['display_name_en'] : ($p['display_name'] ?: '');
        $cards[] = [
            'product_id' => $p['product_id'],
            'display_name' => $display_name,
            'image_path' => $p['image_path'],
            'price' => $price,
            'stock' => mall_get_stock_quantity($p['product_id']),
        ];
    }
    return $cards;
}
