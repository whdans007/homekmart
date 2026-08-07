<?php
/**
 * 도매판매 가격 참고정보 헬퍼
 * Design Ref: wholesale-cost-price-fix.design.md §4.2 — 정상도매가/기존판매가/도매등록가 계산을 한 곳에 모아
 * admin/ajax_get_wholesale_product_by_barcode.php, admin/ajax_search_wholesale_products.php 양쪽에서 공유
 */

/**
 * 정상도매가 계산 (원가 + 마진율, 원가가 0이면 판매가의 7% 할인가로 폴백)
 * Design Ref: wholesale-cost-price-fix.design.md §3.1
 * @param float $costBox 박스 원가
 * @param float $costPiece 낱개 원가
 * @param float $sellingPrice 소매 판매가 (원가가 0일 때 폴백 기준)
 * @param float $marginRate 마진율(%)
 * @return array ['box' => float, 'piece' => float]
 */
function wp_calc_normal_price($costBox, $costPiece, $sellingPrice, $marginRate) {
    $costBox = (float)$costBox;
    $costPiece = (float)$costPiece;
    $sellingPrice = (float)$sellingPrice;
    $marginRate = (float)$marginRate;

    $box = $costBox > 0
        ? ceil($costBox * (1 + $marginRate / 100))
        : ($sellingPrice > 0 ? ceil($sellingPrice * 0.93) : 0);

    $piece = $costPiece > 0
        ? ceil($costPiece * (1 + $marginRate / 100))
        : ($sellingPrice > 0 ? ceil($sellingPrice * 0.93) : 0);

    return ['box' => (float)$box, 'piece' => (float)$piece];
}

/**
 * 이 거래처에 대한 기존판매가 (판매단위별로 각각 최신 1건, 취소 제외)
 * Design Ref: wholesale-cost-price-fix.design.md §3.1, §4.2 — 판매단위가 일치하는 이력만 참고
 * @param PDO $pdo
 * @param int $productId
 * @param int $customerId
 * @return array ['box' => ?float, 'piece' => ?float, 'sale_date' => ?string (더 최근 이력 기준)]
 */
function wp_get_existing_sale_price(PDO $pdo, $productId, $customerId) {
    $result = ['box' => null, 'piece' => null, 'sale_date' => null];

    $productId = (int)$productId;
    $customerId = (int)$customerId;
    if ($productId <= 0 || $customerId <= 0) {
        return $result;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT wsi.sale_unit, wsi.unit_price, ws.sale_date
            FROM wholesale_sale_items wsi
            JOIN wholesale_sales ws ON wsi.sale_id = ws.id
            WHERE wsi.product_id = ? AND ws.customer_id = ? AND ws.status != 'cancelled'
            ORDER BY ws.sale_date DESC, ws.id DESC
        ");
        $stmt->execute([$productId, $customerId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $unit = ($row['sale_unit'] === 'piece') ? 'piece' : 'box';
            if ($result[$unit] === null) {
                $result[$unit] = (float)$row['unit_price'];
            }
            if ($result['sale_date'] === null) {
                $result['sale_date'] = $row['sale_date'];
            }
            if ($result['box'] !== null && $result['piece'] !== null) {
                break;
            }
        }
    } catch (PDOException $e) {
        error_log('wp_get_existing_sale_price error: ' . $e->getMessage());
    }

    return $result;
}

/**
 * 도매등록가 (wholesale_products 기준, 미등록이면 둘 다 null)
 * Design Ref: wholesale-cost-price-fix.design.md §3.1
 * @param float|null $wholesalePrice
 * @param float|null $wholesalePricePiece
 * @return array ['box' => ?float, 'piece' => ?float]
 */
function wp_get_registered_price($wholesalePrice, $wholesalePricePiece) {
    return [
        'box' => ($wholesalePrice !== null && (float)$wholesalePrice > 0) ? (float)$wholesalePrice : null,
        'piece' => ($wholesalePricePiece !== null && (float)$wholesalePricePiece > 0) ? (float)$wholesalePricePiece : null,
    ];
}

/**
 * 3종 가격을 하나의 price_ref 구조로 묶어 반환 (AJAX 응답용)
 * Design Ref: wholesale-cost-price-fix.design.md §3.1 응답 페이로드
 * @return array ['normal' => [...], 'existing' => [...], 'registered' => [...]]
 */
function wp_build_price_ref(PDO $pdo, $productId, $customerId, $costBox, $costPiece, $sellingPrice, $marginRate, $wholesalePrice, $wholesalePricePiece) {
    return [
        'normal' => wp_calc_normal_price($costBox, $costPiece, $sellingPrice, $marginRate),
        'existing' => wp_get_existing_sale_price($pdo, $productId, $customerId),
        'registered' => wp_get_registered_price($wholesalePrice, $wholesalePricePiece),
    ];
}
