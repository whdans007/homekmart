<?php
// 모든 카테고리를 가져오는 AJAX 엔드포인트
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

ob_clean();
header('Content-Type: application/json');

// 권한 체크
if (!is_logged_in() || (!has_permission('product_management') && !has_permission('purchase_management'))) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 하위 카테고리만 가져오기 (parent_id가 NULL이 아닌 것)
    // 또한 parent_id 정보도 함께 가져와서 그룹 분류에 활용
    $sql = "SELECT c.id, c.name, c.name_en, c.parent_id, 
                   p.name as parent_name, p.name_en as parent_name_en 
            FROM categories c
            LEFT JOIN categories p ON c.parent_id = p.id
            WHERE c.parent_id IS NOT NULL
            ORDER BY c.parent_id, c.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 카테고리를 parent_name 기준으로 그룹별로 분류
    $grouped_categories = [];

    // 카테고리를 부모 카테고리별로 그룹화
    foreach ($categories as $cat) {
        $parent_name = $cat['parent_name'] ?? '기타';
        $parent_name_en = $cat['parent_name_en'] ?? 'Others';
        
        // 그룹 키를 한글명으로 사용하되, 영문명도 저장
        $group_key = $parent_name;
        
        // 그룹이 없으면 생성
        if (!isset($grouped_categories[$group_key])) {
            $grouped_categories[$group_key] = [
                'name_en' => $parent_name_en,
                'items' => []
            ];
        }
        
        // 해당 그룹에 카테고리 추가
        $grouped_categories[$group_key]['items'][] = [
            'id' => $cat['id'],
            'name' => $cat['name'],
            'name_en' => $cat['name_en']
        ];
    }
    
    // 그룹 순서 정렬 (원하는 순서대로 표시하기 위해)
    $ordered_groups = [];
    $preferred_order = ['신선식품', '가공식품', '간식/음료', '생활용품', '주방/가정용품', '기타'];
    
    foreach ($preferred_order as $group_name) {
        if (isset($grouped_categories[$group_name])) {
            $ordered_groups[$group_name] = $grouped_categories[$group_name];
            unset($grouped_categories[$group_name]);
        }
    }
    
    // 남은 그룹들 추가
    foreach ($grouped_categories as $group_name => $group_data) {
        $ordered_groups[$group_name] = $group_data;
    }
    
    $grouped_categories = $ordered_groups;

    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'grouped' => $grouped_categories
    ]);

} catch (PDOException $e) {
    error_log("카테고리 조회 오류: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '카테고리 조회 중 오류가 발생했습니다.'
    ]);
}
?>