<?php
// 출력 버퍼링 시작하여 예기치 않은 출력 방지
ob_start();
session_start();

try {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '필요한 라이브러리를 불러올 수 없습니다: ' . $e->getMessage()]);
    exit;
}

// 세션 시작 (세션이 시작되지 않은 경우)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 출력 버퍼 정리
ob_clean();
header('Content-Type: application/json');

// 권한 체크 - 매입관리 권한도 허용
if (!is_logged_in() || (!has_permission('product_management') && !has_permission('purchase_management'))) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

// 검색어 파라미터 처리
$search = $_GET['q'] ?? '';
$search = trim($search);

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // categories 테이블 존재 여부 확인
    $table_check_sql = "SHOW TABLES LIKE 'categories'";
    $table_check_stmt = $pdo->prepare($table_check_sql);
    $table_check_stmt->execute();
    $table_exists = $table_check_stmt->fetch();

    if (!$table_exists) {
        error_log("카테고리 테이블이 존재하지 않습니다.");
        echo json_encode([
            'success' => true,
            'categories' => [],
            'count' => 0,
            'warning' => 'categories 테이블이 존재하지 않습니다. 카테고리 없이 상품을 등록할 수 있습니다.'
        ]);
        exit;
    }

    // 테이블 컬럼 확인
    $columns_check = $pdo->query("SHOW COLUMNS FROM categories");
    $has_name = false;
    $has_name_ko = false;
    $has_name_en = false;
    
    while ($column = $columns_check->fetch(PDO::FETCH_ASSOC)) {
        if ($column['Field'] == 'name') $has_name = true;
        if ($column['Field'] == 'name_ko') $has_name_ko = true;
        if ($column['Field'] == 'name_en') $has_name_en = true;
    }
    
    // SELECT 필드 구성
    $select_fields = ["id"];
    if ($has_name_ko) $select_fields[] = "name_ko";
    if ($has_name_en) $select_fields[] = "name_en";
    if ($has_name) $select_fields[] = "name";
    
    $categories = [];
    
    if (!empty($search)) {
        // 검색어가 있는 경우
        $search_pattern = '%' . $search . '%';
        $search_start = $search . '%';
        
        // WHERE 조건 구성
        $where_conditions = [];
        $params = [];
        
        if ($has_name_ko) {
            $where_conditions[] = "name_ko LIKE ?";
            $params[] = $search_pattern;
        }
        if ($has_name_en) {
            $where_conditions[] = "name_en LIKE ?";
            $params[] = $search_pattern;
        }
        if ($has_name) {
            $where_conditions[] = "name LIKE ?";
            $params[] = $search_pattern;
        }
        
        // ORDER BY 절 구성
        $order_cases = [];
        $case_num = 1;
        if ($has_name_ko) {
            $order_cases[] = "WHEN name_ko LIKE ? THEN $case_num";
            $params[] = $search_start;
            $case_num++;
        }
        if ($has_name_en) {
            $order_cases[] = "WHEN name_en LIKE ? THEN $case_num";
            $params[] = $search_start;
            $case_num++;
        }
        if ($has_name) {
            $order_cases[] = "WHEN name LIKE ? THEN $case_num";
            $params[] = $search_start;
            $case_num++;
        }
        
        $order_field = $has_name_ko ? "name_ko" : ($has_name ? "name" : "id");
        
        $sql = "SELECT " . implode(", ", $select_fields) . " 
                FROM categories 
                WHERE " . implode(" OR ", $where_conditions) . "
                ORDER BY 
                    CASE " . implode(" ", $order_cases) . "
                        ELSE $case_num
                    END,
                    $order_field
                LIMIT 20";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        // 검색어가 없는 경우 상위 20개
        $order_field = $has_name_ko ? "name_ko" : ($has_name ? "name" : "id");
        $sql = "SELECT " . implode(", ", $select_fields) . " FROM categories ORDER BY $order_field LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    
    $result_array = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 결과 재구성
    foreach ($result_array as $row) {
        $category_name = "";
        if ($has_name_ko && !empty($row['name_ko'])) {
            $category_name = $row['name_ko'];
        } else if ($has_name && !empty($row['name'])) {
            $category_name = $row['name'];
        }
        
        $categories[] = [
            'id' => $row['id'],
            'name' => $category_name,
            'name_ko' => isset($row['name_ko']) ? $row['name_ko'] : null,
            'name_en' => isset($row['name_en']) ? $row['name_en'] : null
        ];
    }

    // 디버깅 로그
    error_log("카테고리 로드 성공: " . count($categories) . "개 카테고리 조회됨");

    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'count' => count($categories)
    ]);

} catch (PDOException $e) {
    error_log("카테고리 조회 PDO 오류: " . $e->getMessage());
    // 실패해도 빈 배열을 반환하여 계속 진행 가능하도록 함
    echo json_encode([
        'success' => true, 
        'categories' => [],
        'count' => 0,
        'warning' => '카테고리 로드에 실패했습니다. 카테고리 없이 상품을 등록할 수 있습니다.'
    ]);
} catch (Exception $e) {
    error_log("카테고리 조회 일반 오류: " . $e->getMessage());
    // 실패해도 빈 배열을 반환하여 계속 진행 가능하도록 함
    echo json_encode([
        'success' => true,
        'categories' => [],
        'count' => 0,
        'warning' => '카테고리 로드에 실패했습니다. 카테고리 없이 상품을 등록할 수 있습니다.'
    ]);
}
?>