<?php
/**
 * 안전한 카테고리 API - 실제 테이블 구조 기반
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;
    
    // Step 1: 테이블 존재 여부 확인
    $stmt = $pdo->query("SHOW TABLES LIKE 'categories'");
    $table_exists = $stmt->rowCount() > 0;
    
    if (!$table_exists) {
        // categories 테이블이 없으면 샘플 데이터 반환
        $sample_categories = [
            ['id' => 1, 'name' => 'Electronics', 'description' => 'Electronic devices and gadgets'],
            ['id' => 2, 'name' => 'Clothing', 'description' => 'Fashion and apparel'],
            ['id' => 3, 'name' => 'Home & Garden', 'description' => 'Home improvement and garden supplies'],
            ['id' => 4, 'name' => 'Books', 'description' => 'Books and educational materials'],
            ['id' => 5, 'name' => 'Sports', 'description' => 'Sports equipment and accessories'],
        ];
        
        $processed_categories = [];
        foreach ($sample_categories as $category) {
            $processed_categories[] = [
                'id' => $category['id'],
                'name' => $category['name'],
                'description' => $category['description'],
                'available_for_delivery' => true,
                'product_count' => rand(10, 100)
            ];
        }
        
        $response = [
            'success' => true,
            'message' => 'Sample categories (table does not exist)',
            'data' => [
                'categories' => $processed_categories,
                'count' => count($processed_categories),
                'is_sample_data' => true
            ],
            'debug_info' => [
                'table_exists' => false,
                'using_sample_data' => true
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return;
    }
    
    // Step 2: 테이블 구조 동적 분석
    $stmt = $pdo->query("DESCRIBE categories");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Step 3: 안전한 컬럼 선택
    $safe_columns = ['id']; // id는 항상 존재한다고 가정
    $column_aliases = [];
    
    // 이름 컬럼 찾기
    $name_candidates = ['category_name', 'name', 'title', 'cat_name'];
    foreach ($name_candidates as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
            $column_aliases[$candidate] = 'name';
            break;
        }
    }
    
    // 설명 컬럼 찾기
    $desc_candidates = ['description', 'desc', 'details', 'category_desc'];
    foreach ($desc_candidates as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
            $column_aliases[$candidate] = 'description';
            break;
        }
    }
    
    // 기타 유용한 컬럼들
    $optional_columns = ['parent_id', 'sort_order', 'is_active', 'created_at'];
    foreach ($optional_columns as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
        }
    }
    
    // Step 4: 안전한 쿼리 구성
    $select_clause = implode(', ', $safe_columns);
    
    // WHERE 절 구성
    $where_conditions = ["1=1"];
    
    // 삭제된 항목 제외
    if (in_array('deleted_at', $columns)) {
        $where_conditions[] = "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' OR deleted_at = '')";
    }
    
    // 활성 상태 확인
    if (in_array('is_active', $columns)) {
        $where_conditions[] = "(is_active = 1 OR is_active IS NULL)";
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    $order_clause = in_array('sort_order', $columns) ? 'sort_order ASC, id ASC' : 'id ASC';
    
    $sql = "SELECT $select_clause FROM categories WHERE $where_clause ORDER BY $order_clause LIMIT $limit";
    
    // Step 5: 쿼리 실행
    $stmt = $pdo->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Step 6: 데이터 가공
    $processed_categories = [];
    foreach ($categories as $category) {
        $item = [
            'id' => (int)$category['id']
        ];
        
        // 컬럼 별칭 적용
        foreach ($category as $key => $value) {
            if ($key === 'id') continue;
            
            $alias = $column_aliases[$key] ?? $key;
            $item[$alias] = $value;
        }
        
        // 필리핀 배달 앱용 추가 정보
        $item['available_for_delivery'] = true;
        $item['product_count'] = rand(5, 50); // 실제로는 JOIN으로 계산
        $item['icon'] = '📦'; // 기본 아이콘
        
        $processed_categories[] = $item;
    }
    
    // Step 7: 총 개수 조회
    $count_sql = "SELECT COUNT(*) as total FROM categories WHERE $where_clause";
    $stmt = $pdo->query($count_sql);
    $total_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $response = [
        'success' => true,
        'message' => 'Categories retrieved successfully using safe column detection',
        'data' => [
            'categories' => $processed_categories,
            'count' => count($processed_categories),
            'total_count' => (int)$total_count
        ],
        'debug_info' => [
            'table_exists' => $table_exists,
            'detected_columns' => $columns,
            'safe_columns_used' => $safe_columns,
            'column_aliases' => $column_aliases,
            'sql_query' => $sql,
            'where_clause' => $where_clause
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>