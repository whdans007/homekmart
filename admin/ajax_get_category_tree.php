<?php
/**
 * 계층형 카테고리 트리 데이터 조회 API
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    // 모든 카테고리를 조회하여 트리 구조로 변환
    $stmt = $conn->query("
        SELECT 
            id, 
            name, 
            name_en, 
            parent_id,
            (SELECT COUNT(*) FROM categories c2 WHERE c2.parent_id = categories.id) as child_count
        FROM categories 
        ORDER BY parent_id ASC, name ASC
    ");
    
    $categories = [];
    while ($row = $stmt->fetch_assoc()) {
        $categories[] = $row;
    }
    
    // 트리 구조로 변환
    function buildCategoryTree($categories, $parentId = null) {
        $tree = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $children = buildCategoryTree($categories, $category['id']);
                if (!empty($children)) {
                    $category['children'] = $children;
                }
                $tree[] = $category;
            }
        }
        return $tree;
    }
    
    $categoryTree = buildCategoryTree($categories);
    
    // 통계 정보 추가
    $stats = [
        'total' => count($categories),
        'main_categories' => count(array_filter($categories, function($cat) { return $cat['parent_id'] === null; })),
        'sub_categories' => count(array_filter($categories, function($cat) { return $cat['parent_id'] !== null; }))
    ];
    
    echo json_encode([
        'success' => true,
        'data' => $categoryTree,
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '카테고리 데이터를 불러오는 중 오류가 발생했습니다: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>