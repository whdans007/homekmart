<?php
/**
 * 배송 가능 구역 목록 조회 API
 * GET /api/delivery-zones?city={city}&province={province}
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Method not allowed');
}

$city = $_GET['city'] ?? null;
$province = $_GET['province'] ?? null;

try {
    $pdo = getApiDbConnection();

    // WHERE 조건 구성
    $where_clauses = ["is_active = TRUE"];
    $params = [];

    if ($city) {
        $where_clauses[] = "city LIKE ?";
        $params[] = "%{$city}%";
    }

    if ($province) {
        $where_clauses[] = "province LIKE ?";
        $params[] = "%{$province}%";
    }

    $where_sql = implode(' AND ', $where_clauses);

    // 배송 구역 조회
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
        ORDER BY province, city, zone_name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $zones = $stmt->fetchAll();

    // 숫자 필드 형변환
    foreach ($zones as &$zone) {
        $zone['id'] = intval($zone['id']);
        $zone['delivery_fee'] = floatval($zone['delivery_fee']);
        $zone['min_order_amount'] = floatval($zone['min_order_amount']);
        $zone['free_delivery_threshold'] = floatval($zone['free_delivery_threshold']);
        $zone['estimated_delivery_time'] = intval($zone['estimated_delivery_time']);
        $zone['max_delivery_time'] = intval($zone['max_delivery_time']);
    }

    apiSuccess(['zones' => $zones, 'total' => count($zones)]);

} catch (PDOException $e) {
    error_log("Delivery zones error: " . $e->getMessage());
    apiError(500, 'Failed to retrieve delivery zones');
}
?>
