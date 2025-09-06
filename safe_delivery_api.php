<?php
/**
 * 안전한 배달 구역 및 위치 관리 API
 * Google Maps 연동 준비, 필리핀 지역 특화
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$request_method = $_SERVER['REQUEST_METHOD'];

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $action = $_GET['action'] ?? 'zones';
    
    switch ($action) {
        case 'zones':
            $response = handleDeliveryZones($pdo);
            break;
        case 'coverage':
            $response = handleCoverageCheck($pdo);
            break;
        case 'nearby':
            $response = handleNearbyZones($pdo);
            break;
        default:
            $response = handleDeliveryZones($pdo);
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function handleDeliveryZones($pdo) {
    // delivery_zones 테이블 존재 확인
    $stmt = $pdo->query("SHOW TABLES LIKE 'delivery_zones'");
    if ($stmt->rowCount() === 0) {
        return getSampleDeliveryZones();
    }
    
    // 테이블 구조 분석
    $stmt = $pdo->query("DESCRIBE delivery_zones");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 안전한 컬럼 선택
    $safe_columns = ['id'];
    $zone_columns = [
        'zone_name' => 'zone_name',
        'zone_type' => 'zone_type',
        'delivery_fee' => 'delivery_fee',
        'minimum_order' => 'minimum_order',
        'delivery_time_min' => 'delivery_time_min',
        'delivery_time_max' => 'delivery_time_max',
        'coverage_areas' => 'coverage_areas',
        'center_lat' => 'center_lat',
        'center_lng' => 'center_lng',
        'is_active' => 'is_active'
    ];
    
    foreach ($zone_columns as $db_col => $api_col) {
        if (in_array($db_col, $columns)) {
            $safe_columns[] = $db_col;
        }
    }
    
    $select_clause = implode(', ', $safe_columns);
    
    $where_conditions = ['1=1'];
    if (in_array('is_active', $columns)) {
        $where_conditions[] = 'is_active = 1';
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $sql = "SELECT $select_clause FROM delivery_zones WHERE $where_clause ORDER BY zone_name ASC";
    
    $stmt = $pdo->query($sql);
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 데이터 가공
    $processed_zones = [];
    foreach ($zones as $zone) {
        $item = [
            'id' => (int)$zone['id'],
            'zone_name' => $zone['zone_name'] ?? 'Zone ' . $zone['id'],
            'zone_type' => $zone['zone_type'] ?? 'standard',
            'delivery_fee' => (float)($zone['delivery_fee'] ?? 50.00),
            'minimum_order' => (float)($zone['minimum_order'] ?? 500.00),
            'delivery_time_min' => (int)($zone['delivery_time_min'] ?? 30),
            'delivery_time_max' => (int)($zone['delivery_time_max'] ?? 60),
            'currency' => 'PHP',
            'is_active' => isset($zone['is_active']) ? (bool)$zone['is_active'] : true
        ];
        
        $item['formatted_fee'] = '₱' . number_format($item['delivery_fee'], 2);
        $item['formatted_minimum'] = '₱' . number_format($item['minimum_order'], 2);
        
        // coverage_areas 처리
        if (isset($zone['coverage_areas']) && !empty($zone['coverage_areas'])) {
            $item['coverage_areas'] = explode(',', $zone['coverage_areas']);
        } else {
            $item['coverage_areas'] = ['Metro Manila', 'Makati', 'Quezon City'];
        }
        
        // 좌표 정보
        $item['coordinates'] = [
            'lat' => (float)($zone['center_lat'] ?? 14.5995),
            'lng' => (float)($zone['center_lng'] ?? 120.9842)
        ];
        
        $processed_zones[] = $item;
    }
    
    return [
        'success' => true,
        'message' => 'Delivery zones retrieved successfully',
        'data' => [
            'zones' => $processed_zones,
            'count' => count($processed_zones)
        ],
        'debug_info' => [
            'table_exists' => true,
            'columns_detected' => $columns,
            'sql_query' => $sql
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function handleCoverageCheck($pdo) {
    $city = $_GET['city'] ?? '';
    $barangay = $_GET['barangay'] ?? '';
    
    if (empty($city)) {
        throw new Exception('City parameter is required');
    }
    
    // delivery_zones 테이블 존재 확인
    $stmt = $pdo->query("SHOW TABLES LIKE 'delivery_zones'");
    if ($stmt->rowCount() === 0) {
        return getSampleCoverageCheck($city, $barangay);
    }
    
    // 테이블 구조 확인
    $stmt = $pdo->query("DESCRIBE delivery_zones");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 커버리지 검사를 위한 쿼리
    if (in_array('coverage_areas', $columns) && in_array('is_active', $columns)) {
        $search_terms = [$city];
        if (!empty($barangay)) {
            $search_terms[] = $barangay;
        }
        
        $where_conditions = ['is_active = 1'];
        $params = [];
        
        foreach ($search_terms as $i => $term) {
            $where_conditions[] = "coverage_areas LIKE :term$i";
            $params[":term$i"] = "%$term%";
        }
        
        $sql = "SELECT * FROM delivery_zones WHERE " . implode(' AND ', $where_conditions) . " ORDER BY delivery_fee ASC LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $zone = null;
    }
    
    if ($zone) {
        $response_data = [
            'is_deliverable' => true,
            'zone_id' => (int)$zone['id'],
            'zone_name' => $zone['zone_name'],
            'delivery_fee' => (float)$zone['delivery_fee'],
            'minimum_order' => (float)($zone['minimum_order'] ?? 500.00),
            'estimated_time' => ($zone['delivery_time_min'] ?? 30) . '-' . ($zone['delivery_time_max'] ?? 60) . ' minutes',
            'currency' => 'PHP',
            'formatted_fee' => '₱' . number_format($zone['delivery_fee'], 2),
            'formatted_minimum' => '₱' . number_format($zone['minimum_order'] ?? 500.00, 2)
        ];
        
        $message = 'Delivery available in this area';
    } else {
        // 필리핀 주요 도시 확인 (하드코딩된 커버리지)
        $major_cities = [
            'Manila', 'Quezon City', 'Makati', 'Pasig', 'Taguig', 'Mandaluyong',
            'San Juan', 'Marikina', 'Pasay', 'Las Piñas', 'Muntinlupa', 'Parañaque',
            'Cebu City', 'Mandaue', 'Lapu-Lapu', 'Davao City', 'Cagayan de Oro'
        ];
        
        $is_major_city = false;
        foreach ($major_cities as $major_city) {
            if (stripos($city, $major_city) !== false || stripos($major_city, $city) !== false) {
                $is_major_city = true;
                break;
            }
        }
        
        if ($is_major_city) {
            $response_data = [
                'is_deliverable' => true,
                'zone_id' => 1,
                'zone_name' => 'Metro Manila Zone',
                'delivery_fee' => 50.00,
                'minimum_order' => 500.00,
                'estimated_time' => '30-60 minutes',
                'currency' => 'PHP',
                'formatted_fee' => '₱50.00',
                'formatted_minimum' => '₱500.00',
                'note' => 'Covered by default delivery zone'
            ];
            $message = 'Delivery available in major city area';
        } else {
            $response_data = [
                'is_deliverable' => false,
                'alternative_zones' => [],
                'message' => 'Currently not available in this area',
                'suggestion' => 'Try nearby major cities like Manila, Makati, or Quezon City'
            ];
            $message = 'Delivery not available in this area';
        }
    }
    
    return [
        'success' => true,
        'message' => $message,
        'data' => $response_data,
        'search_info' => [
            'city' => $city,
            'barangay' => $barangay,
            'table_exists' => $stmt->rowCount() > 0
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function handleNearbyZones($pdo) {
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 0;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : 0;
    
    if ($lat == 0 || $lng == 0) {
        // 기본 좌표 (마닐라)
        $lat = 14.5995;
        $lng = 120.9842;
    }
    
    // delivery_zones 테이블 존재 확인
    $stmt = $pdo->query("SHOW TABLES LIKE 'delivery_zones'");
    if ($stmt->rowCount() === 0) {
        return getSampleNearbyZones($lat, $lng);
    }
    
    // 테이블 구조 확인
    $stmt = $pdo->query("DESCRIBE delivery_zones");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('center_lat', $columns) && in_array('center_lng', $columns)) {
        // 거리 계산 쿼리 (Haversine 공식)
        $sql = "SELECT *, 
                (6371 * acos(cos(radians($lat)) * cos(radians(center_lat)) * 
                cos(radians(center_lng) - radians($lng)) + 
                sin(radians($lat)) * sin(radians(center_lat)))) AS distance 
                FROM delivery_zones 
                WHERE is_active = 1 
                HAVING distance < 50 
                ORDER BY distance ASC 
                LIMIT 5";
        
        $stmt = $pdo->query($sql);
        $nearby_zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $nearby_zones = [];
    }
    
    if (empty($nearby_zones)) {
        return getSampleNearbyZones($lat, $lng);
    }
    
    $processed_nearby = [];
    foreach ($nearby_zones as $zone) {
        $processed_nearby[] = [
            'id' => (int)$zone['id'],
            'zone_name' => $zone['zone_name'],
            'distance_km' => round($zone['distance'], 2),
            'delivery_fee' => (float)$zone['delivery_fee'],
            'formatted_fee' => '₱' . number_format($zone['delivery_fee'], 2),
            'estimated_time' => ($zone['delivery_time_min'] ?? 30) . '-' . ($zone['delivery_time_max'] ?? 60) . ' minutes'
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Nearby delivery zones found',
        'data' => [
            'zones' => $processed_nearby,
            'count' => count($processed_nearby),
            'search_location' => ['lat' => $lat, 'lng' => $lng]
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function getSampleDeliveryZones() {
    $sample_zones = [
        [
            'id' => 1,
            'zone_name' => 'Metro Manila Central',
            'zone_type' => 'premium',
            'delivery_fee' => 50.00,
            'minimum_order' => 500.00,
            'delivery_time_min' => 30,
            'delivery_time_max' => 60,
            'coverage_areas' => ['Makati', 'Taguig', 'BGC', 'Ortigas'],
            'coordinates' => ['lat' => 14.5547, 'lng' => 121.0244],
            'is_active' => true
        ],
        [
            'id' => 2,
            'zone_name' => 'Quezon City Zone',
            'zone_type' => 'standard',
            'delivery_fee' => 60.00,
            'minimum_order' => 600.00,
            'delivery_time_min' => 45,
            'delivery_time_max' => 75,
            'coverage_areas' => ['Quezon City', 'Marikina', 'San Juan'],
            'coordinates' => ['lat' => 14.6760, 'lng' => 121.0437],
            'is_active' => true
        ],
        [
            'id' => 3,
            'zone_name' => 'Cebu Metro Zone',
            'zone_type' => 'standard',
            'delivery_fee' => 70.00,
            'minimum_order' => 700.00,
            'delivery_time_min' => 45,
            'delivery_time_max' => 90,
            'coverage_areas' => ['Cebu City', 'Mandaue', 'Lapu-Lapu'],
            'coordinates' => ['lat' => 10.3157, 'lng' => 123.8854],
            'is_active' => true
        ]
    ];
    
    $processed_zones = [];
    foreach ($sample_zones as $zone) {
        $zone['currency'] = 'PHP';
        $zone['formatted_fee'] = '₱' . number_format($zone['delivery_fee'], 2);
        $zone['formatted_minimum'] = '₱' . number_format($zone['minimum_order'], 2);
        
        $processed_zones[] = $zone;
    }
    
    return [
        'success' => true,
        'message' => 'Sample delivery zones (table does not exist)',
        'data' => [
            'zones' => $processed_zones,
            'count' => count($processed_zones),
            'is_sample_data' => true
        ],
        'debug_info' => [
            'table_exists' => false,
            'using_sample_data' => true
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function getSampleCoverageCheck($city, $barangay) {
    // 필리핀 주요 도시 커버리지 체크
    $major_areas = [
        'Makati' => ['fee' => 50.00, 'min_order' => 500.00, 'time' => '30-45'],
        'Manila' => ['fee' => 55.00, 'min_order' => 500.00, 'time' => '35-50'],
        'Quezon City' => ['fee' => 60.00, 'min_order' => 600.00, 'time' => '40-60'],
        'Pasig' => ['fee' => 55.00, 'min_order' => 500.00, 'time' => '35-55'],
        'Taguig' => ['fee' => 50.00, 'min_order' => 500.00, 'time' => '30-50'],
        'Cebu City' => ['fee' => 70.00, 'min_order' => 700.00, 'time' => '45-75'],
        'Davao City' => ['fee' => 75.00, 'min_order' => 750.00, 'time' => '45-80']
    ];
    
    $city_lower = strtolower($city);
    $found_area = null;
    
    foreach ($major_areas as $area => $info) {
        if (stripos($area, $city) !== false || stripos($city, $area) !== false) {
            $found_area = ['name' => $area, 'info' => $info];
            break;
        }
    }
    
    if ($found_area) {
        $info = $found_area['info'];
        return [
            'success' => true,
            'message' => 'Delivery available in this area',
            'data' => [
                'is_deliverable' => true,
                'zone_id' => 1,
                'zone_name' => $found_area['name'] . ' Zone',
                'delivery_fee' => $info['fee'],
                'minimum_order' => $info['min_order'],
                'estimated_time' => $info['time'] . ' minutes',
                'currency' => 'PHP',
                'formatted_fee' => '₱' . number_format($info['fee'], 2),
                'formatted_minimum' => '₱' . number_format($info['min_order'], 2),
                'barangay_specific' => !empty($barangay) ? "Available in $barangay" : null
            ],
            'search_info' => [
                'city' => $city,
                'barangay' => $barangay,
                'matched_area' => $found_area['name']
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    } else {
        return [
            'success' => true,
            'message' => 'Delivery not available in this area',
            'data' => [
                'is_deliverable' => false,
                'alternative_zones' => [
                    'Metro Manila (Makati, Manila, Quezon City)',
                    'Cebu City',
                    'Davao City'
                ],
                'suggestion' => 'Try searching for nearby major cities'
            ],
            'search_info' => [
                'city' => $city,
                'barangay' => $barangay,
                'available_cities' => array_keys($major_areas)
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

function getSampleNearbyZones($lat, $lng) {
    // 샘플 근처 구역 (마닐라 기준)
    $sample_nearby = [
        [
            'id' => 1,
            'zone_name' => 'BGC-Taguig Zone',
            'distance_km' => 2.5,
            'delivery_fee' => 50.00,
            'estimated_time' => '25-40 minutes'
        ],
        [
            'id' => 2,
            'zone_name' => 'Ortigas Center Zone',
            'distance_km' => 4.8,
            'delivery_fee' => 60.00,
            'estimated_time' => '35-50 minutes'
        ],
        [
            'id' => 3,
            'zone_name' => 'Alabang Zone',
            'distance_km' => 8.2,
            'delivery_fee' => 80.00,
            'estimated_time' => '45-65 minutes'
        ]
    ];
    
    $processed_nearby = [];
    foreach ($sample_nearby as $zone) {
        $zone['formatted_fee'] = '₱' . number_format($zone['delivery_fee'], 2);
        $processed_nearby[] = $zone;
    }
    
    return [
        'success' => true,
        'message' => 'Sample nearby zones (table does not exist)',
        'data' => [
            'zones' => $processed_nearby,
            'count' => count($processed_nearby),
            'search_location' => ['lat' => $lat, 'lng' => $lng],
            'is_sample_data' => true
        ],
        'debug_info' => [
            'table_exists' => false,
            'using_sample_data' => true
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}
?>