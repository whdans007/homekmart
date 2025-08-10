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

$response = ['success' => false, 'products' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $query = trim($_POST['q'] ?? '');
    $limit = min(max(1, (int)($_POST['limit'] ?? 10)), 50); // 1-50 범위로 제한
    
    if (strlen($query) < 2) {
        echo json_encode($response);
        exit;
    }
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 현재 사용자의 점포 ID 확인
        $store_id = $_SESSION['role'] === 'super_admin' ? null : $_SESSION['store_id'] ?? null;
        
        $search_query = "%{$query}%";
        
        // 스키마 호환성 확인 후 적절한 쿼리 선택
        try {
            $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
            $has_new_columns = $check_columns->rowCount() > 0;
        } catch (PDOException $e) {
            $has_new_columns = false;
        }
        
        if ($has_new_columns) {
            // 새로운 스키마 사용
            $sql = "
                SELECT DISTINCT
                    wp.id as wholesale_product_id,
                    p.id,
                    p.sku,
                    p.name_ko,
                    p.name_en,
                    COALESCE(wp.wholesale_name_ko, p.name_ko) as display_name_ko,
                    COALESCE(wp.wholesale_name_en, p.name_en) as display_name_en,
                    wp.wholesale_skus,
                    wp.wholesale_price,
                    wp.min_quantity,
                    wp.wholesale_description
                FROM wholesale_products wp
                INNER JOIN products p ON p.id = wp.product_id 
                WHERE wp.is_active = 1 
                    AND p.is_active = 1 
                    " . ($store_id ? "AND wp.store_id = ?" : "") . "
                    AND (
                        p.sku LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ? 
                        OR wp.wholesale_name_ko LIKE ? OR wp.wholesale_name_en LIKE ? 
                        OR wp.wholesale_skus LIKE ?
                    )
                ORDER BY 
                    CASE 
                        WHEN wp.wholesale_skus LIKE ? OR p.sku LIKE ? THEN 1 
                        WHEN wp.wholesale_name_en LIKE ? OR p.name_en LIKE ? THEN 2 
                        WHEN wp.wholesale_name_ko LIKE ? OR p.name_ko LIKE ? THEN 3 
                        ELSE 4 
                    END,
                    COALESCE(wp.wholesale_name_en, p.name_en) ASC, 
                    COALESCE(wp.wholesale_name_ko, p.name_ko) ASC
                LIMIT ?
            ";
            
            $params = [];
            if ($store_id) {
                $params[] = $store_id;
            }
            $params = array_merge($params, [
                $search_query, $search_query, $search_query, // 기본 상품 검색
                $search_query, $search_query, $search_query, // 도매 상품 검색
                $search_query, $search_query, // ORDER BY SKU 검색
                $search_query, $search_query, // ORDER BY 영어명 검색
                $search_query, $search_query, // ORDER BY 한국어명 검색
                $limit
            ]);
        } else {
            // 기존 스키마 사용 (하위 호환성)
            $sql = "
                SELECT DISTINCT
                    wp.id as wholesale_product_id,
                    p.id,
                    p.sku,
                    p.name_ko,
                    p.name_en,
                    p.name_ko as display_name_ko,
                    p.name_en as display_name_en,
                    JSON_ARRAY(p.sku) as wholesale_skus,
                    wp.wholesale_price,
                    wp.min_quantity,
                    NULL as wholesale_description
                FROM wholesale_products wp
                INNER JOIN products p ON p.id = wp.product_id 
                WHERE wp.is_active = 1 
                    AND p.is_active = 1 
                    " . ($store_id ? "AND wp.store_id = ?" : "") . "
                    AND (p.sku LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?)
                ORDER BY 
                    CASE 
                        WHEN p.sku LIKE ? THEN 1 
                        WHEN p.name_en LIKE ? THEN 2 
                        WHEN p.name_ko LIKE ? THEN 3 
                        ELSE 4 
                    END,
                    p.name_en ASC, p.name_ko ASC
                LIMIT ?
            ";
            
            $params = [];
            if ($store_id) {
                $params[] = $store_id;
            }
            $params = array_merge($params, [
                $search_query, $search_query, $search_query, // WHERE 조건
                $search_query, $search_query, $search_query, // ORDER BY 조건
                $limit
            ]);
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($products)) {
            $response['success'] = true;
            $response['products'] = $products;
        }
        
    } catch (PDOException $e) {
        error_log("Wholesale product search error: " . $e->getMessage());
        $response['message'] = '검색 중 오류가 발생했습니다.';
    }
}

echo json_encode($response);
?>