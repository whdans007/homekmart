<?php
/**
 * 배송비 계산 API
 * POST /api/delivery-zones/calculate-fee
 *
 * 요청 본문:
 * {
 *   "city": "Angeles City",
 *   "province": "Pampanga",
 *   "barangay": "Balibago" (optional),
 *   "order_amount": 1500.00
 * }
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

$data = getRequestBody();
validateRequired($data, ['city', 'province', 'order_amount']);

$city = $data['city'];
$province = $data['province'];
$barangay = $data['barangay'] ?? null;
$order_amount = floatval($data['order_amount']);

if ($order_amount < 0) {
    apiError(400, 'Order amount must be positive');
}

try {
    $pdo = getApiDbConnection();

    // 배송 구역 조회
    $where_clauses = ["city = ?", "province = ?", "is_active = TRUE"];
    $params = [$city, $province];

    if ($barangay) {
        $where_clauses[] = "(barangay = ? OR barangay IS NULL)";
        $params[] = $barangay;
    }

    $where_sql = implode(' AND ', $where_clauses);

    $sql = "
        SELECT
            zone_name,
            delivery_fee,
            min_order_amount,
            free_delivery_threshold
        FROM delivery_zones
        WHERE {$where_sql}
        ORDER BY barangay DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $zone = $stmt->fetch();

    // 구역을 찾지 못한 경우 기본 배송비 사용
    if (!$zone) {
        $delivery_fee = 50.00;
        $min_order_amount = 0;
        $free_delivery_threshold = 1000.00;
        $zone_name = 'Default Zone';
    } else {
        $delivery_fee = floatval($zone['delivery_fee']);
        $min_order_amount = floatval($zone['min_order_amount']);
        $free_delivery_threshold = floatval($zone['free_delivery_threshold']);
        $zone_name = $zone['zone_name'];
    }

    // 최소 주문 금액 확인
    if ($order_amount < $min_order_amount) {
        apiSuccess([
            'is_valid' => false,
            'zone_name' => $zone_name,
            'order_amount' => $order_amount,
            'min_order_amount' => $min_order_amount,
            'delivery_fee' => $delivery_fee,
            'message' => "Minimum order amount is PHP {$min_order_amount}"
        ]);
        return;
    }

    // 무료 배송 조건 확인
    $actual_delivery_fee = $delivery_fee;
    $is_free_delivery = false;

    if ($order_amount >= $free_delivery_threshold) {
        $actual_delivery_fee = 0;
        $is_free_delivery = true;
    }

    $total_amount = $order_amount + $actual_delivery_fee;

    apiSuccess([
        'is_valid' => true,
        'zone_name' => $zone_name,
        'order_amount' => $order_amount,
        'delivery_fee' => $actual_delivery_fee,
        'is_free_delivery' => $is_free_delivery,
        'free_delivery_threshold' => $free_delivery_threshold,
        'total_amount' => $total_amount,
        'message' => $is_free_delivery ? 'Free delivery applied!' : 'Delivery fee applied'
    ]);

} catch (PDOException $e) {
    error_log("Delivery fee calculation error: " . $e->getMessage());
    apiError(500, 'Failed to calculate delivery fee');
}
?>
