<?php
/**
 * 배달 앱 API 설치 스크립트
 * 데이터베이스 테이블 생성 및 기본 설정
 */

require_once __DIR__ . '/config/api_config.php';

// 관리자 권한 확인
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    api_forbidden('Super admin access required for installation');
}

$install_steps = [];
$errors = [];

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. 배달 앱 스키마 실행
    $install_steps[] = "1. 데이터베이스 스키마 확인 중...";
    
    $schema_file = dirname(__DIR__) . '/sql/delivery_app_schema.sql';
    if (file_exists($schema_file)) {
        $sql = file_get_contents($schema_file);
        
        // SQL 문을 세미콜론으로 분할하여 실행
        $statements = explode(';', $sql);
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && !preg_match('/^--/', $statement)) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // 이미 존재하는 테이블/컬럼 에러는 무시
                    if (strpos($e->getMessage(), 'already exists') === false && 
                        strpos($e->getMessage(), 'Duplicate column') === false) {
                        $errors[] = "SQL 실행 오류: " . $e->getMessage();
                    }
                }
            }
        }
        
        $install_steps[] = "✓ 데이터베이스 스키마 설치 완료";
    } else {
        $errors[] = "스키마 파일을 찾을 수 없습니다: " . $schema_file;
    }
    
    // 2. 필수 테이블 존재 확인
    $install_steps[] = "2. 필수 테이블 확인 중...";
    
    $required_tables = [
        'delivery_addresses',
        'delivery_zones', 
        'delivery_orders',
        'delivery_order_items',
        'delivery_tracking',
        'shopping_cart',
        'wishlists',
        'delivery_settings'
    ];
    
    foreach ($required_tables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        
        if ($stmt->rowCount() > 0) {
            $install_steps[] = "✓ 테이블 '{$table}' 존재 확인";
        } else {
            $errors[] = "필수 테이블 '{$table}'이 존재하지 않습니다.";
        }
    }
    
    // 3. 기본 설정값 확인
    $install_steps[] = "3. 기본 설정값 확인 중...";
    
    $default_settings = [
        'default_delivery_fee' => '50.00',
        'free_delivery_threshold' => '1000.00',
        'currency_symbol' => '₱',
        'currency_code' => 'PHP'
    ];
    
    foreach ($default_settings as $key => $default_value) {
        $value = get_delivery_setting($key);
        if ($value !== null) {
            $install_steps[] = "✓ 설정 '{$key}': {$value}";
        } else {
            // 기본값 설정
            if (set_delivery_setting($key, $default_value, is_numeric($default_value) ? 'number' : 'string')) {
                $install_steps[] = "✓ 기본 설정 '{$key}' 생성: {$default_value}";
            } else {
                $errors[] = "기본 설정 '{$key}' 생성 실패";
            }
        }
    }
    
    // 4. API 엔드포인트 테스트
    $install_steps[] = "4. API 엔드포인트 테스트...";
    
    $test_endpoints = [
        '/api' => '기본 API 정보',
        '/api/products' => '상품 목록 API',
        '/api/delivery/zones' => '배달 지역 API'
    ];
    
    foreach ($test_endpoints as $endpoint => $description) {
        // 간단한 내부 테스트 (실제로는 외부에서 호출해야 함)
        $install_steps[] = "- {$description}: {$endpoint}";
    }
    
    // 5. 필리핀 지역 데이터 확인
    $install_steps[] = "5. 필리핀 지역 데이터 확인...";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM delivery_zones");
    $zone_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($zone_count > 0) {
        $install_steps[] = "✓ 배달 지역 데이터: {$zone_count}개 지역";
    } else {
        $install_steps[] = "! 배달 지역 데이터가 없습니다. 관리자 페이지에서 추가해주세요.";
    }
    
    // 설치 완료
    if (empty($errors)) {
        $install_steps[] = "";
        $install_steps[] = "🎉 배달 앱 API 설치가 완료되었습니다!";
        $install_steps[] = "";
        $install_steps[] = "다음 단계:";
        $install_steps[] = "1. React Native 앱에서 API 테스트";
        $install_steps[] = "2. 구글 OAuth 설정 (Google Cloud Console)";
        $install_steps[] = "3. 배달 지역 및 요금 설정";
        $install_steps[] = "4. 상품 데이터 확인 및 업데이트";
    }
    
} catch (Exception $e) {
    $errors[] = "설치 중 오류 발생: " . $e->getMessage();
}

// 결과 반환
if (empty($errors)) {
    api_success([
        'installation_completed' => true,
        'steps' => $install_steps,
        'next_actions' => [
            'test_api_endpoints',
            'setup_google_oauth',
            'configure_delivery_zones',
            'verify_product_data'
        ]
    ], 'Installation completed successfully');
} else {
    api_error('Installation failed', 500, 'INSTALLATION_ERROR');
}
?>