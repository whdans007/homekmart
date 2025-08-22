<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 세션 및 권한 확인
ensure_logged_in();
if (!has_permission('wholesale_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $margin_rate = (float)($_POST['margin_rate'] ?? 15.0); // 기본 15% 마진
    $store_id = $_SESSION['role'] === 'super_admin' ? (int)($_POST['store_id'] ?? 0) : $_SESSION['store_id'] ?? null;
    
    // 입력값 검증
    if (empty($product_id)) {
        $response['message'] = '상품 ID가 필요합니다.';
        echo json_encode($response);
        exit;
    }
    
    if (empty($store_id)) {
        $response['message'] = '점포 정보가 필요합니다.';
        echo json_encode($response);
        exit;
    }
    
    if ($margin_rate < 0 || $margin_rate > 100) {
        $response['message'] = '마진율은 0-100% 사이여야 합니다.';
        echo json_encode($response);
        exit;
    }
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 상품 정보 조회
        $product_stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1");
        $product_stmt->execute([$product_id]);
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            $response['message'] = '상품을 찾을 수 없습니다.';
            echo json_encode($response);
            exit;
        }
        
        // 기존 도매상품 확인 (활성/비활성 모두)
        $check_stmt = $pdo->prepare("SELECT id, is_active FROM wholesale_products WHERE product_id = ? AND store_id = ?");
        $check_stmt->execute([$product_id, $store_id]);
        $existing_product = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_product) {
            if ($existing_product['is_active'] == 1) {
                $response['message'] = '이미 활성화된 도매상품으로 등록되어 있습니다.';
                echo json_encode($response);
                exit;
            } else {
                // 비활성화된 도매상품이 있으면 재활성화
                $reactivate_stmt = $pdo->prepare("
                    UPDATE wholesale_products 
                    SET is_active = 1, 
                        wholesale_price = ?, 
                        min_quantity = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                // 도매가 계산 (마진율 적용)
                $cost_price = (float)$product['cost_price'];
                $wholesale_price = round($cost_price * (1 + $margin_rate / 100));
                $min_quantity = max(1, (int)$product['pieces_per_box']);
                
                $reactivate_success = $reactivate_stmt->execute([
                    $wholesale_price,
                    $min_quantity,
                    $existing_product['id']
                ]);
                
                if ($reactivate_success) {
                    $response['success'] = true;
                    $response['message'] = '비활성화된 도매상품을 다시 활성화했습니다.';
                    $response['data'] = [
                        'wholesale_id' => $existing_product['id'],
                        'product_id' => $product_id,
                        'wholesale_price' => $wholesale_price,
                        'min_quantity' => $min_quantity,
                        'margin_rate' => $margin_rate,
                        'display_name_ko' => $product['name_ko'],
                        'display_name_en' => $product['name_en'],
                        'sku' => $product['sku']
                    ];
                    echo json_encode($response);
                    exit;
                } else {
                    $response['message'] = '도매상품 재활성화 중 오류가 발생했습니다.';
                    echo json_encode($response);
                    exit;
                }
            }
        }
        
        // 도매가 계산 (마진율 적용)
        $cost_price = (float)$product['cost_price'];
        $wholesale_price = round($cost_price * (1 + $margin_rate / 100));
        
        // 최소 주문수량 설정 (박스당 개수 또는 1)
        $min_quantity = max(1, (int)$product['pieces_per_box']);
        
        // 스키마 호환성 확인
        try {
            $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
            $has_new_columns = $check_columns->rowCount() > 0;
            
            // cost_price 컬럼 존재 확인
            $check_cost_column = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'cost_price'");
            $has_cost_price_column = $check_cost_column->rowCount() > 0;
        } catch (PDOException $e) {
            $has_new_columns = false;
            $has_cost_price_column = false;
        }
        
        if ($has_new_columns) {
            // 새로운 스키마 사용
            if ($has_cost_price_column) {
                // cost_price 컬럼이 있는 경우
                $stmt = $pdo->prepare("
                    INSERT INTO wholesale_products 
                    (product_id, store_id, wholesale_name_ko, wholesale_name_en, wholesale_skus, 
                     wholesale_price, cost_price, min_quantity, is_active, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $insert_success = $stmt->execute([
                    $product_id,
                    $store_id,
                    $product['name_ko'],
                    $product['name_en'],
                    json_encode([$product['sku']]),
                    $wholesale_price,
                    $cost_price,
                    $min_quantity,
                    1 // is_active 값
                ]);
            } else {
                // cost_price 컬럼이 없는 경우 (기존 방식)
                $stmt = $pdo->prepare("
                    INSERT INTO wholesale_products 
                    (product_id, store_id, wholesale_name_ko, wholesale_name_en, wholesale_skus, 
                     wholesale_price, min_quantity, is_active, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $insert_success = $stmt->execute([
                    $product_id,
                    $store_id,
                    $product['name_ko'],
                    $product['name_en'],
                    json_encode([$product['sku']]),
                    $wholesale_price,
                    $min_quantity,
                    1 // is_active 값
                ]);
            }
        } else {
            // 기존 스키마 사용
            $stmt = $pdo->prepare("
                INSERT INTO wholesale_products 
                (product_id, store_id, wholesale_price, min_quantity, is_active, created_at) 
                VALUES (?, ?, ?, ?, 1, NOW())
            ");
            
            $insert_success = $stmt->execute([
                $product_id,
                $store_id,
                $wholesale_price,
                $min_quantity
            ]);
        }
        
        if ($insert_success) {
            $wholesale_id = $pdo->lastInsertId();
            
            $response['success'] = true;
            $response['message'] = '도매상품으로 성공적으로 등록되었습니다.';
            $response['data'] = [
                'wholesale_id' => $wholesale_id,
                'product_id' => $product_id,
                'wholesale_price' => $wholesale_price,
                'min_quantity' => $min_quantity,
                'margin_rate' => $margin_rate,
                'display_name_ko' => $product['name_ko'],
                'display_name_en' => $product['name_en'],
                'sku' => $product['sku']
            ];
        } else {
            $response['message'] = '도매상품 등록 중 오류가 발생했습니다.';
        }
        
    } catch (PDOException $e) {
        error_log("Quick wholesale product registration error: " . $e->getMessage());
        error_log("Error details: " . print_r($e, true));
        $response['message'] = '데이터베이스 오류가 발생했습니다: ' . $e->getMessage();
    }
}

echo json_encode($response);
?>