<?php
/**
 * API 디버깅 파일
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$response = ['debug' => []];

try {
    // 1. PHP 버전 확인
    $response['debug']['php_version'] = phpversion();
    $response['debug']['timestamp'] = date('Y-m-d H:i:s');
    
    // 2. config 파일 존재 확인
    $config_path = '../../config/db_config.php';
    if (file_exists($config_path)) {
        $response['debug']['config_file'] = 'exists';
        
        // 3. config 파일 로드 시도
        try {
            require_once $config_path;
            $response['debug']['config_loaded'] = 'success';
            
            // 4. 함수 존재 확인
            if (function_exists('get_db_connection')) {
                $response['debug']['db_function'] = 'exists';
                
                // 5. 데이터베이스 연결 시도
                try {
                    $conn = get_db_connection();
                    $response['debug']['db_connection'] = 'success';
                    
                    // 6. 테이블 존재 확인
                    $tables_check = [
                        'products' => false,
                        'categories' => false,
                        'inventory' => false
                    ];
                    
                    foreach ($tables_check as $table => $exists) {
                        try {
                            $stmt = $conn->query("SELECT 1 FROM `{$table}` LIMIT 1");
                            $tables_check[$table] = true;
                        } catch (Exception $e) {
                            $tables_check[$table] = 'error: ' . $e->getMessage();
                        }
                    }
                    $response['debug']['tables'] = $tables_check;
                    
                    // 7. 간단한 쿼리 테스트
                    try {
                        $stmt = $conn->query("SELECT COUNT(*) as count FROM products");
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        $response['debug']['products_count'] = $result['count'];
                    } catch (Exception $e) {
                        $response['debug']['products_query_error'] = $e->getMessage();
                    }
                    
                    try {
                        $stmt = $conn->query("SELECT COUNT(*) as count FROM categories");
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        $response['debug']['categories_count'] = $result['count'];
                    } catch (Exception $e) {
                        $response['debug']['categories_query_error'] = $e->getMessage();
                    }
                    
                } catch (Exception $e) {
                    $response['debug']['db_connection'] = 'failed: ' . $e->getMessage();
                }
                
            } else {
                $response['debug']['db_function'] = 'not_found';
            }
            
        } catch (Exception $e) {
            $response['debug']['config_loaded'] = 'failed: ' . $e->getMessage();
        }
        
    } else {
        $response['debug']['config_file'] = 'not_found at: ' . realpath('../../config/');
    }
    
    $response['status'] = 'success';
    
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>