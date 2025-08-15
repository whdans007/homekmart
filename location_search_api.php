<?php
/**
 * 필리핀 위치 검색 API
 * Google Places API 연동 준비
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $query = $_GET['q'] ?? '';
    $type = $_GET['type'] ?? 'all'; // all, barangay, city, province
    $limit = isset($_GET['limit']) ? min(20, max(1, (int)$_GET['limit'])) : 10;
    
    if (empty($query) || strlen($query) < 2) {
        throw new Exception('Search query must be at least 2 characters long');
    }
    
    // 필리핀 주요 위치 데이터 (실제로는 Google Places API 또는 별도 DB 사용)
    $philippines_locations = [
        // Metro Manila
        ['name' => 'Makati City', 'type' => 'city', 'province' => 'Metro Manila', 'region' => 'NCR'],
        ['name' => 'Quezon City', 'type' => 'city', 'province' => 'Metro Manila', 'region' => 'NCR'],
        ['name' => 'Manila', 'type' => 'city', 'province' => 'Metro Manila', 'region' => 'NCR'],
        ['name' => 'Pasig City', 'type' => 'city', 'province' => 'Metro Manila', 'region' => 'NCR'],
        ['name' => 'Taguig City', 'type' => 'city', 'province' => 'Metro Manila', 'region' => 'NCR'],
        ['name' => 'Mandaluyong City', 'type' => 'city', 'province' => 'Metro Manila', 'region' => 'NCR'],
        
        // Makati Barangays
        ['name' => 'Poblacion', 'type' => 'barangay', 'city' => 'Makati City', 'province' => 'Metro Manila'],
        ['name' => 'San Antonio', 'type' => 'barangay', 'city' => 'Makati City', 'province' => 'Metro Manila'],
        ['name' => 'Bel-Air', 'type' => 'barangay', 'city' => 'Makati City', 'province' => 'Metro Manila'],
        ['name' => 'San Lorenzo', 'type' => 'barangay', 'city' => 'Makati City', 'province' => 'Metro Manila'],
        ['name' => 'Urdaneta', 'type' => 'barangay', 'city' => 'Makati City', 'province' => 'Metro Manila'],
        
        // Cebu
        ['name' => 'Cebu City', 'type' => 'city', 'province' => 'Cebu', 'region' => 'Central Visayas'],
        ['name' => 'Mandaue City', 'type' => 'city', 'province' => 'Cebu', 'region' => 'Central Visayas'],
        ['name' => 'Lapu-Lapu City', 'type' => 'city', 'province' => 'Cebu', 'region' => 'Central Visayas'],
        
        // Davao
        ['name' => 'Davao City', 'type' => 'city', 'province' => 'Davao del Sur', 'region' => 'Davao Region'],
        ['name' => 'Tagum City', 'type' => 'city', 'province' => 'Davao del Norte', 'region' => 'Davao Region'],
        
        // Provinces
        ['name' => 'Metro Manila', 'type' => 'province', 'region' => 'NCR'],
        ['name' => 'Cebu', 'type' => 'province', 'region' => 'Central Visayas'],
        ['name' => 'Davao del Sur', 'type' => 'province', 'region' => 'Davao Region'],
        ['name' => 'Laguna', 'type' => 'province', 'region' => 'CALABARZON'],
        ['name' => 'Rizal', 'type' => 'province', 'region' => 'CALABARZON'],
    ];
    
    // 검색 실행
    $results = [];
    $query_lower = strtolower($query);
    
    foreach ($philippines_locations as $location) {
        $name_lower = strtolower($location['name']);
        
        // 타입 필터
        if ($type !== 'all' && $location['type'] !== $type) {
            continue;
        }
        
        // 이름 매칭
        if (strpos($name_lower, $query_lower) !== false) {
            $result = [
                'id' => md5($location['name'] . $location['type']),
                'name' => $location['name'],
                'type' => $location['type'],
                'full_address' => $location['name'],
                'delivery_available' => true,
                'coordinates' => [
                    'lat' => 14.5995 + (rand(-100, 100) / 1000), // 임시 좌표
                    'lng' => 120.9842 + (rand(-100, 100) / 1000)
                ]
            ];
            
            // 상세 주소 구성
            switch ($location['type']) {
                case 'barangay':
                    $result['city'] = $location['city'];
                    $result['province'] = $location['province'];
                    $result['full_address'] = $location['name'] . ', ' . $location['city'] . ', ' . $location['province'];
                    break;
                    
                case 'city':
                    $result['province'] = $location['province'];
                    $result['region'] = $location['region'];
                    $result['full_address'] = $location['name'] . ', ' . $location['province'];
                    break;
                    
                case 'province':
                    $result['region'] = $location['region'];
                    $result['full_address'] = $location['name'] . ', ' . $location['region'];
                    break;
            }
            
            $results[] = $result;
            
            if (count($results) >= $limit) {
                break;
            }
        }
    }
    
    // 관련성에 따라 정렬
    usort($results, function($a, $b) use ($query_lower) {
        $a_pos = strpos(strtolower($a['name']), $query_lower);
        $b_pos = strpos(strtolower($b['name']), $query_lower);
        
        if ($a_pos === $b_pos) {
            return strcmp($a['name'], $b['name']);
        }
        
        return $a_pos - $b_pos;
    });
    
    $response = [
        'success' => true,
        'message' => 'Location search completed',
        'data' => [
            'results' => $results,
            'count' => count($results),
            'query' => $query,
            'type_filter' => $type,
            'search_suggestions' => [
                'Try searching for: "Makati", "Quezon City", "Cebu", "Davao"',
                'Use specific barangay names for more accurate results',
                'Filter by type: barangay, city, province'
            ]
        ],
        'google_maps_integration' => [
            'note' => 'This is a sample API. Integrate with Google Places API for production use.',
            'api_endpoint' => 'https://maps.googleapis.com/maps/api/place/autocomplete/json',
            'required_parameters' => ['input', 'key', 'components=country:ph']
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
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