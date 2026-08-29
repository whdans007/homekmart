<?php
/**
 * 회원×상품 → 최종 판매가 계산 (단일 경로)
 * Design Ref: shopping-mall.design.md §9 Domain Layer, §1.2 단일 가격 계산 경로 원칙
 *
 * 규칙(반드시 지킬 것):
 * - 어떤 페이지도 이 파일을 거치지 않고 inventory.selling_price / wholesale_products.wholesale_price를
 *   직접 조회해 화면에 출력하지 않는다.
 * - 도매가는 호출 시점에 wholesale_status === 'approved'를 서버에서 재검증한다(세션 캐시 신뢰 금지).
 * - 미승인 도매 회원 및 소매 회원이 도매 채널을 요청해도 도매가를 절대 반환하지 않고
 *   소매가(일반 등급 취급)로 대체 반환한다(§5.4/§7 정책).
 * - 원가/합계는 소숫점 둘째자리까지 반환한다(CLAUDE.md 규칙).
 *
 * 이 파일은 순수 계산/조회 함수만 포함하며 config/db_config.php 외 다른 mall/lib 파일에 의존하지 않는다(§9.3).
 */

require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../../config/db_config.php';

/**
 * 상품의 소매 기본가를 조회합니다. 상품 큐레이션 화면에서 스페셜 가격
 * (mall_products.selling_price_override)을 지정했으면 그 값을 우선하고,
 * 없으면 매장 재고의 실제 판매가(inventory.selling_price, MALL_STORE_ID 기준)를 사용합니다.
 * @param int $product_id
 * @return float|null 재고 행도 override도 없으면 null
 */
function mall_get_retail_base_price($product_id) {
    $conn = mall_get_db_connection();
    $store_id = MALL_STORE_ID;

    $override_stmt = $conn->prepare('SELECT selling_price_override FROM mall_products WHERE product_id = ? AND store_id = ?');
    $override_stmt->bind_param('ii', $product_id, $store_id);
    $override_stmt->execute();
    $override_row = $override_stmt->get_result()->fetch_assoc();
    $override_stmt->close();
    if ($override_row && $override_row['selling_price_override'] !== null) {
        $conn->close();
        return (float)$override_row['selling_price_override'];
    }

    $stmt = $conn->prepare('SELECT selling_price FROM inventory WHERE product_id = ? AND store_id = ?');
    $stmt->bind_param('ii', $product_id, $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row && $row['selling_price'] !== null ? (float)$row['selling_price'] : null;
}

// 실재고와 무관하게 관리자가 켜고 끄는 품절 처리일 때, "품절 아님"에 해당하는 재고량으로 취급할 값
// (장바구니 수량 스테퍼 등에 상한이 있어야 해서 무한대 대신 넉넉한 값을 쓴다).
const MALL_UNLIMITED_STOCK = 9999;

/**
 * 상품의 "판매 가능 수량"(MALL_STORE_ID 기준)을 조회합니다.
 * 실제 재고(inventory.quantity)는 발주/이동/유통기한 로트로 관리되어 정확하지 않거나 몰 판매 시점과
 * 안 맞을 수 있어, 몰의 품절 여부는 실재고를 보지 않고 관리자가 켜고 끄는 스위치
 * (mall_products.is_sold_out)로만 정한다: 켜져 있으면 품절(0), 꺼져 있으면 판매 가능(MALL_UNLIMITED_STOCK).
 * @param int $product_id
 * @return int
 */
function mall_get_stock_quantity($product_id) {
    $conn = mall_get_db_connection();
    $store_id = MALL_STORE_ID;

    $stmt = $conn->prepare('SELECT is_sold_out FROM mall_products WHERE product_id = ? AND store_id = ?');
    $stmt->bind_param('ii', $product_id, $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if ($row && (int)$row['is_sold_out'] === 1) {
        return 0;
    }
    return MALL_UNLIMITED_STOCK;
}

/**
 * 상품이 해당 채널(retail|wholesale)로 몰에 노출 중인지 확인합니다.
 * 장바구니 담기 등에서 노출/큐레이션되지 않은 상품이 임의로 추가되는 것을 막는 데 사용합니다.
 * @param int $product_id
 * @param string $channel retail|wholesale
 * @return bool
 */
function mall_is_product_eligible_for_channel($product_id, $channel) {
    if ($channel === 'wholesale') {
        return mall_get_wholesale_base_price($product_id) !== null;
    }

    $conn = mall_get_db_connection();
    $store_id = MALL_STORE_ID;
    $stmt = $conn->prepare('SELECT id FROM mall_products WHERE product_id = ? AND store_id = ? AND is_active = 1');
    $stmt->bind_param('ii', $product_id, $store_id);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $found;
}

/**
 * 소매 등급별 할인율(%)을 조회합니다. 규칙이 없으면 0을 반환합니다.
 * @param string $tier general|discount|vip
 * @return float
 */
function mall_get_retail_discount_rate($tier) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare('SELECT discount_rate FROM mall_retail_discount_rules WHERE tier = ?');
    $stmt->bind_param('s', $tier);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row ? (float)$row['discount_rate'] : 0.0;
}

/**
 * 상품의 도매 기본가(wholesale_products.wholesale_price, MALL_STORE_ID 기준)를 조회합니다.
 * 이 함수는 pricing.php 내부에서 승인 여부 재검증 후에만 호출해야 합니다.
 * @param int $product_id
 * @return float|null 도매 등록 상품이 아니면 null
 */
function mall_get_wholesale_base_price($product_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        'SELECT wp.wholesale_price
         FROM wholesale_products wp
         INNER JOIN mall_wholesale_visibility mwv ON mwv.wholesale_product_id = wp.id
         WHERE wp.product_id = ? AND wp.store_id = ? AND wp.is_active = 1 AND mwv.is_visible = 1'
    );
    $store_id = MALL_STORE_ID;
    $stmt->bind_param('ii', $product_id, $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row && $row['wholesale_price'] !== null ? (float)$row['wholesale_price'] : null;
}

/**
 * 장바구니(또는 주문) 금액 구간에 해당하는 도매 즉석할인율을 조회합니다.
 * 활성화된 구간 중 min_order_amount가 주문금액 이하인 것 중 가장 큰 구간을 적용합니다.
 * @param float $order_amount
 * @return float 해당 구간이 없으면 0
 */
function mall_get_wholesale_instant_discount_rate($order_amount) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        'SELECT discount_rate FROM mall_wholesale_instant_discount_tiers
         WHERE is_active = 1 AND min_order_amount <= ?
         ORDER BY min_order_amount DESC LIMIT 1'
    );
    $stmt->bind_param('d', $order_amount);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row ? (float)$row['discount_rate'] : 0.0;
}

/**
 * 현재 주문금액 기준으로 다음(더 높은) 도매 즉석할인 구간을 조회합니다. 장바구니 UI의
 * "다음 구간까지 남은 금액" 안내에 사용합니다.
 * @param float $order_amount
 * @return array{min_order_amount: float, discount_rate: float, amount_remaining: float}|null 다음 구간이 없으면 null
 */
function mall_get_next_wholesale_instant_tier($order_amount) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        'SELECT min_order_amount, discount_rate FROM mall_wholesale_instant_discount_tiers
         WHERE is_active = 1 AND min_order_amount > ?
         ORDER BY min_order_amount ASC LIMIT 1'
    );
    $stmt->bind_param('d', $order_amount);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row) {
        return null;
    }

    return [
        'min_order_amount' => round((float)$row['min_order_amount'], 2),
        'discount_rate' => round((float)$row['discount_rate'], 2),
        'amount_remaining' => round((float)$row['min_order_amount'] - $order_amount, 2),
    ];
}

/**
 * 회원의 누적실적 등급에 따른 도매 추가 할인율을 조회합니다(월배치로 갱신된 mall_member_stats 기준).
 * @param int $member_id
 * @return float 등급이 없으면 0
 */
function mall_get_wholesale_cumulative_discount_rate($member_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        'SELECT t.additional_discount_rate
         FROM mall_member_stats s
         INNER JOIN mall_wholesale_cumulative_tiers t ON t.id = s.current_wholesale_tier_id
         WHERE s.member_id = ?'
    );
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row ? (float)$row['additional_discount_rate'] : 0.0;
}

/**
 * 상품별로 채널(소매/도매)에 따라 할인 규칙 적용이 허용되는지 확인합니다
 * (상품 큐레이션 화면에서 관리자가 채널별로 따로 설정).
 * mall_products에 등록되지 않은 상품(도매 전용 등)은 기본 허용으로 취급한다.
 * @param int $product_id
 * @param string $channel retail|wholesale
 * @return bool
 */
function mall_is_discount_allowed($product_id, $channel) {
    $column = $channel === 'wholesale' ? 'wholesale_discount_allowed' : 'retail_discount_allowed';

    $conn = mall_get_db_connection();
    $store_id = MALL_STORE_ID;
    $stmt = $conn->prepare("SELECT {$column} FROM mall_products WHERE product_id = ? AND store_id = ?");
    $stmt->bind_param('ii', $product_id, $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row === null || (int)$row[$column] === 1;
}

/**
 * 회원×상품×채널에 대한 최종 판매가를 계산합니다. 화면/API는 반드시 이 함수만 호출해야 합니다.
 *
 * @param int $product_id
 * @param array|null $member mall_current_member() 반환값 (비로그인/게스트는 null)
 * @param string $requested_channel 'retail' | 'wholesale' — 사용자가 보려는 채널
 * @param float $cart_subtotal 도매 즉석할인 구간 판정에 쓰이는 현재 장바구니(또는 계산 대상) 소계
 * @return array{
 *   base_price: float|null,
 *   discount_rate: float,
 *   final_price: float|null,
 *   channel_used: string,
 *   wholesale_pending_notice: bool
 * }
 */
function mall_calculate_price($product_id, $member, $requested_channel, $cart_subtotal = 0.0) {
    $is_wholesale_member = $member !== null && $member['member_type'] === 'wholesale';
    $is_wholesale_approved = $is_wholesale_member && $member['wholesale_status'] === 'approved';

    // 도매 채널 요청 + 승인된 도매 회원일 때만 도매가 경로를 탄다.
    // 그 외(미승인 도매/소매/게스트)는 전부 소매가 경로로 대체한다(§5.4/§7 정책).
    if ($requested_channel === 'wholesale' && $is_wholesale_approved) {
        $base_price = mall_get_wholesale_base_price($product_id);
        if ($base_price === null) {
            // 도매 미노출 상품이면 소매가로 폴백
            return mall_calculate_price($product_id, $member, 'retail', $cart_subtotal);
        }

        $discount_rate = 0.0;
        if (mall_is_discount_allowed($product_id, 'wholesale')) {
            $instant_rate = mall_get_wholesale_instant_discount_rate($cart_subtotal);
            $cumulative_rate = mall_get_wholesale_cumulative_discount_rate($member['id']);
            $discount_rate = round($instant_rate + $cumulative_rate, 2);
        }
        $final_price = round($base_price * (1 - $discount_rate / 100), 2);

        return [
            'base_price' => round($base_price, 2),
            'discount_rate' => $discount_rate,
            'final_price' => $final_price,
            'channel_used' => 'wholesale',
            'wholesale_pending_notice' => false,
        ];
    }

    // 소매 경로 (일반/할인/우수 등급 할인 적용, 미승인 도매회원은 '일반' 등급 취급)
    $base_price = mall_get_retail_base_price($product_id);
    if ($base_price === null) {
        return [
            'base_price' => null,
            'discount_rate' => 0.0,
            'final_price' => null,
            'channel_used' => 'retail',
            'wholesale_pending_notice' => $is_wholesale_member && !$is_wholesale_approved,
        ];
    }

    $tier = ($member !== null && $member['member_type'] === 'retail') ? $member['retail_tier'] : 'general';
    $discount_rate = 0.0;
    if (!($is_wholesale_member && !$is_wholesale_approved) && mall_is_discount_allowed($product_id, 'retail')) {
        $discount_rate = mall_get_retail_discount_rate($tier);
    }
    $final_price = round($base_price * (1 - $discount_rate / 100), 2);

    return [
        'base_price' => round($base_price, 2),
        'discount_rate' => round($discount_rate, 2),
        'final_price' => $final_price,
        'channel_used' => 'retail',
        'wholesale_pending_notice' => $is_wholesale_member && !$is_wholesale_approved,
    ];
}
