<?php
/**
 * 배송 가능 여부 확인 API
 * POST /api/delivery-zones/check
 *
 * 요청 본문:
 * {
 *   "city": "Angeles City",
 *   "province": "Pampanga",
 *   "barangay": "Balibago" (optional)
 * }
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

$data = getRequestBody();
validateRequired($data, ['city', 'province']);

$city = $data['city'];
$province = $data['province'];
$barangay = $data['barangay'] ?? null;

try {
    $pdo = getApiDbConnection();

    // 배송 구역 조회 (정확한 매칭 우선)
    $where_clauses = ["city = ?", "province = ?", "is_active = TRUE"];
    $params = [$city, $province];

    if ($barangay) {
        $where_clauses[] = "(barangay = ? OR barangay IS NULL)";
        $params[] = $barangay;
    }

    $where_sql = implode(' AND ', $where_clauses);

    $sql = "
        SELECT
            id,
            zone_name,
            barangay,
            city,
            province,
            delivery_fee,
            min_order_amount,
            free_delivery_threshold,
            estimated_delivery_time,
            max_delivery_time,
            service_start_time,
            service_end_time
        FROM delivery_zones
        WHERE {$where_sql}
        ORDER BY barangay DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $zone = $stmt->fetch();

    if (!$zone) {
        apiSuccess([
            'is_deliverable' => false,
            'message' => 'Sorry, delivery is not available in your area yet'
        ]);
        return;
    }

    // 현재 시간이 서비스 시간 내인지 확인
    $current_time = date('H:i:s');
    $is_service_time = ($current_time >= $zone['service_start_time'] && $current_time <= $zone['service_end_time']);

    // 숫자 필드 형변환
    $zone['id'] = intval($zone['id']);
    $zone['delivery_fee'] = floatval($zone['delivery_fee']);
    $zone['min_order_amount'] = floatval($zone['min_order_amount']);
    $zone['free_delivery_threshold'] = floatval($zone['free_delivery_threshold']);
    $zone['estimated_delivery_time'] = intval($zone['estimated_delivery_time']);
    $zone['max_delivery_time'] = intval($zone['max_delivery_time']);

    apiSuccess([
        'is_deliverable' => true,
        'is_service_time' => $is_service_time,
        'zone' => $zone,
        'message' => $is_service_time ? 'Delivery is available now' : 'Delivery is available during service hours'
    ]);

} catch (PDOException $e) {
    error_log("Delivery check error: " . $e->getMessage());
    apiError(500, 'Failed to check delivery availability');
}
?>
