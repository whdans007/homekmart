<?php
/**
 * 리뷰 조회 공용화 — mall/product.php(미리보기)와 mall/product_reviews.php(전체보기)가 함께 재사용한다.
 * 원래 product.php에 있던 쿼리를 그대로 추출했다(로직 변경 없음).
 */

/**
 * 평점 통계 — 평균 평점 + 별점별 개수(1~5, 막대 그래프용).
 * @return array{avg:float, count:int, breakdown: array<int,int>}
 */
function mall_get_product_review_stats($product_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare('SELECT rating FROM mall_reviews WHERE product_id = ?');
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $ratings = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'rating');
    $stmt->close();
    $conn->close();

    $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach ($ratings as $r) {
        $r = (int)$r;
        if (isset($breakdown[$r])) {
            $breakdown[$r]++;
        }
    }
    $count = count($ratings);
    $avg = $count > 0 ? round(array_sum($ratings) / $count, 1) : 0.0;

    return ['avg' => $avg, 'count' => $count, 'breakdown' => $breakdown];
}

/**
 * 리뷰 목록.
 * @param string $sort 'recent'(최신순, 기본) | 'rating_desc'(평점높은순)
 */
function mall_get_product_reviews($product_id, $sort = 'recent', $limit = 50) {
    $order = ($sort === 'rating_desc') ? 'rv.rating DESC, rv.created_at DESC' : 'rv.created_at DESC';
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        "SELECT rv.rating, rv.comment, rv.created_at, m.name AS member_name
         FROM mall_reviews rv INNER JOIN mall_members m ON m.id = rv.member_id
         WHERE rv.product_id = ? ORDER BY {$order} LIMIT ?"
    );
    $stmt->bind_param('ii', $product_id, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * 로그인 회원이 이 상품에 리뷰를 작성할 수 있는지(구매 완료 + 미작성) 확인.
 * @return int|null 작성 가능하면 사용할 order_id, 아니면 null
 */
function mall_get_reviewable_order_id($member_id, $product_id) {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare(
        "SELECT o.id FROM mall_orders o
         INNER JOIN mall_order_items oi ON oi.order_id = o.id
         LEFT JOIN mall_reviews rv ON rv.order_id = o.id AND rv.product_id = oi.product_id AND rv.member_id = o.member_id
         WHERE o.member_id = ? AND o.status = 'completed' AND oi.product_id = ? AND rv.id IS NULL
         LIMIT 1"
    );
    $stmt->bind_param('ii', $member_id, $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row['id'] ?? null;
}

/**
 * 리뷰 작성자 이름을 마스킹(첫 글자만 노출)해서 반환.
 */
function mall_mask_reviewer_name($name) {
    return mb_substr($name, 0, 1) . str_repeat('*', max(0, mb_strlen($name) - 1));
}
