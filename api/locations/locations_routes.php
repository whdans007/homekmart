<?php
/**
 * 위치 API 라우트
 * Google Maps 연동 및 지역 관리
 */

$location_path = $path_parts[1] ?? '';

switch ($method) {
    case 'GET':
        switch ($location_path) {
            case 'zones':
                // GET /api/locations/zones - 서비스 지역 목록
                require_once __DIR__ . '/service_zones.php';
                break;
                
            case 'provinces':
                // GET /api/locations/provinces - 주/지방 목록
                require_once __DIR__ . '/provinces.php';
                break;
                
            case 'cities':
                // GET /api/locations/cities - 시/도시 목록
                require_once __DIR__ . '/cities.php';
                break;
                
            case 'barangays':
                // GET /api/locations/barangays - 바랑가이 목록
                require_once __DIR__ . '/barangays.php';
                break;
                
            default:
                api_not_found('Location endpoint not found');
        }
        break;
        
    case 'POST':
        switch ($location_path) {
            case 'validate':
                // POST /api/locations/validate - 주소 유효성 검증
                require_once __DIR__ . '/validate_location.php';
                break;
                
            case 'geocode':
                // POST /api/locations/geocode - 주소 → GPS 좌표 변환
                require_once __DIR__ . '/geocode_address.php';
                break;
                
            case 'reverse-geocode':
                // POST /api/locations/reverse-geocode - GPS 좌표 → 주소 변환
                require_once __DIR__ . '/reverse_geocode.php';
                break;
                
            case 'check-coverage':
                // POST /api/locations/check-coverage - 배달 서비스 커버리지 확인
                require_once __DIR__ . '/check_coverage.php';
                break;
                
            default:
                api_not_found('Location endpoint not found');
        }
        break;
        
    default:
        api_error('Method not allowed', 405);
}
?>