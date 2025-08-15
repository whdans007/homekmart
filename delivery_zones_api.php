<?php
/**
 * 배달 구역 및 위치 관리 API
 * Google Maps 연동 준비
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$request_method = $_SERVER['REQUEST_METHOD'];

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    switch ($request_method) {
        case 'GET':
            $action = $_GET['action'] ?? 'zones';
            
            switch ($action) {
                case 'zones':
                    // 배달 구역 목록
                    $stmt = $pdo->query("
                        SELECT * FROM delivery_zones 
                        WHERE is_active = 1 
                        ORDER BY zone_name ASC
                    ");
                    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $processed_zones = [];
                    foreach ($zones as $zone) {
                        $processed_zones[] = [
                            'id' => (int)$zone['id'],
                            'zone_name' => $zone['zone_name'],
                            'zone_type' => $zone['zone_type'],
                            'delivery_fee' => (float)$zone['delivery_fee'],
                            'minimum_order' => (float)$zone['minimum_order'],
                            'delivery_time_min' => (int)$zone['delivery_time_min'],
                            'delivery_time_max' => (int)$zone['delivery_time_max'],
                            'currency' => 'PHP',
                            'formatted_fee' => '₱' . number_format($zone['delivery_fee'], 2),
                            'formatted_minimum' => '₱' . number_format($zone['minimum_order'], 2),
                            'coverage_areas' => explode(',', $zone['coverage_areas']),
                            'is_active' => (bool)$zone['is_active']
                        ];
                    }
                    
                    $response = [
                        'success' => true,
                        'message' => 'Delivery zones retrieved successfully',
                        'data' => [
                            'zones' => $processed_zones,
                            'count' => count($processed_zones)
                        ]
                    ];
                    break;
                    
                case 'coverage':
                    // 특정 지역의 배달 가능 여부 확인
                    $city = $_GET['city'] ?? '';
                    $barangay = $_GET['barangay'] ?? '';
                    
                    if (empty($city)) {
                        throw new Exception('City parameter is required');
                    }
                    
                    $stmt = $pdo->prepare("
                        SELECT * FROM delivery_zones 
                        WHERE is_active = 1 
                        AND (coverage_areas LIKE ? OR coverage_areas LIKE ?)
                        ORDER BY delivery_fee ASC
                        LIMIT 1
                    ");
                    $stmt->execute(["%$city%", "%$barangay%"]);
                    $zone = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($zone) {
                        $response = [
                            'success' => true,
                            'message' => 'Delivery available in this area',
                            'data' => [
                                'is_deliverable' => true,
                                'zone_id' => (int)$zone['id'],
                                'zone_name' => $zone['zone_name'],
                                'delivery_fee' => (float)$zone['delivery_fee'],
                                'minimum_order' => (float)$zone['minimum_order'],
                                'estimated_time' => $zone['delivery_time_min'] . '-' . $zone['delivery_time_max'] . ' minutes',
                                'currency' => 'PHP'
                            ]
                        ];
                    } else {
                        $response = [
                            'success' => true,
                            'message' => 'Delivery not available in this area',
                            'data' => [
                                'is_deliverable' => false,
                                'alternative_zones' => []
                            ]
                        ];
                    }
                    break;
                    
                case 'nearby':
                    // 근처 배달 구역 찾기 (Google Maps 연동 시 사용)
                    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 0;
                    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : 0;
                    
                    if ($lat == 0 || $lng == 0) {
                        throw new Exception('Latitude and longitude are required');
                    }
                    
                    // 간단한 거리 계산 (실제로는 Google Maps Distance Matrix API 사용 권장)
                    $stmt = $pdo->query("
                        SELECT *, 
                        (6371 * acos(cos(radians($lat)) * cos(radians(center_lat)) * 
                        cos(radians(center_lng) - radians($lng)) + 
                        sin(radians($lat)) * sin(radians(center_lat)))) AS distance 
                        FROM delivery_zones 
                        WHERE is_active = 1 
                        HAVING distance < 50 
                        ORDER BY distance ASC 
                        LIMIT 5
                    ");
                    $nearby_zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $processed_nearby = [];
                    foreach ($nearby_zones as $zone) {
                        $processed_nearby[] = [
                            'id' => (int)$zone['id'],
                            'zone_name' => $zone['zone_name'],
                            'distance_km' => round($zone['distance'], 2),
                            'delivery_fee' => (float)$zone['delivery_fee'],
                            'estimated_time' => $zone['delivery_time_min'] . '-' . $zone['delivery_time_max'] . ' minutes'
                        ];
                    }
                    
                    $response = [
                        'success' => true,
                        'message' => 'Nearby delivery zones found',
                        'data' => [
                            'zones' => $processed_nearby,
                            'count' => count($processed_nearby),
                            'search_location' => ['lat' => $lat, 'lng' => $lng]
                        ]
                    ];
                    break;
                    
                default:
                    throw new Exception('Invalid action parameter');
            }
            break;
            
        case 'POST':
            // 새 배달 구역 생성 (관리자용)
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }
            
            $required_fields = ['zone_name', 'zone_type', 'delivery_fee', 'coverage_areas'];
            foreach ($required_fields as $field) {
                if (!isset($input[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO delivery_zones 
                (zone_name, zone_type, delivery_fee, minimum_order, delivery_time_min, delivery_time_max, coverage_areas, center_lat, center_lng, is_active, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            
            $stmt->execute([
                $input['zone_name'],
                $input['zone_type'],
                $input['delivery_fee'],
                $input['minimum_order'] ?? 500.00,
                $input['delivery_time_min'] ?? 30,
                $input['delivery_time_max'] ?? 60,
                is_array($input['coverage_areas']) ? implode(',', $input['coverage_areas']) : $input['coverage_areas'],
                $input['center_lat'] ?? 14.5995, // Manila 기본값
                $input['center_lng'] ?? 120.9842,
            ]);
            
            $zone_id = $pdo->lastInsertId();
            
            $response = [
                'success' => true,
                'message' => 'Delivery zone created successfully',
                'data' => [
                    'zone_id' => $zone_id,
                    'zone_name' => $input['zone_name']
                ]
            ];
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
    $response['timestamp'] = date('Y-m-d H:i:s');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>