<?php
// 모든 에러 출력 차단
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 출력 버퍼링 시작
ob_start();

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/partials/session_check.php';

// 버퍼 내용 삭제하고 헤더 설정
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// 상품 관리 권한 확인
if (!has_permission('product_management')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Permission denied'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

if ($project_id <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid project ID'
    ]);
    exit;
}

try {
    $conn = get_db_connection();
    
    // 현재 사용자의 점포 ID 가져오기
    $current_store_id = $_SESSION['store_id'] ?? 0;
    
    // 프로젝트 정보 조회
    $project_sql = "SELECT 
                        p.id,
                        p.store_id,
                        p.created_by,
                        p.created_at,
                        p.updated_at,
                        u.username as creator_name
                    FROM price_label_projects p
                    LEFT JOIN users u ON p.created_by = u.id
                    WHERE p.id = ? AND p.store_id = ?";
    
    $project_stmt = $conn->prepare($project_sql);
    $project_stmt->bind_param("ii", $project_id, $current_store_id);
    $project_stmt->execute();
    $project_result = $project_stmt->get_result();
    
    if ($project_result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Project not found or access denied'
        ]);
        exit;
    }
    
    $project = $project_result->fetch_assoc();
    
    // 프로젝트에 포함된 상품들 조회
    $items_sql = "SELECT 
                      pi.product_id,
                      pi.quantity,
                      pi.remarks,
                      p.sku,
                      p.name_en,
                      p.name_ko,
                      p.pieces_per_box,
                      COALESCE(i.selling_price, p.selling_price, 0) as selling_price,
                      COALESCE(i.cost_price, p.cost_price, 0) as cost_price,
                      COALESCE(i.stock, 0) as stock,
                      b.name as brand_name,
                      c.name_ko as category_name
                  FROM price_label_project_items pi
                  JOIN products p ON pi.product_id = p.id
                  LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
                  LEFT JOIN brands b ON p.brand_id = b.id
                  LEFT JOIN categories c ON p.category_id = c.id
                  WHERE pi.project_id = ?
                  ORDER BY p.name_en, p.name_ko";
    
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("ii", $current_store_id, $project_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    $items = [];
    while ($item_row = $items_result->fetch_assoc()) {
        $items[] = [
            'product_id' => (int)$item_row['product_id'],
            'sku' => $item_row['sku'],
            'name_en' => $item_row['name_en'],
            'name_ko' => $item_row['name_ko'],
            'selling_price' => (float)$item_row['selling_price'],
            'cost_price' => (float)$item_row['cost_price'],
            'stock' => (int)$item_row['stock'],
            'pieces_per_box' => (int)$item_row['pieces_per_box'],
            'quantity' => (int)$item_row['quantity'],
            'remarks' => $item_row['remarks'],
            'brand_name' => $item_row['brand_name'],
            'category_name' => $item_row['category_name']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'project' => [
            'id' => (int)$project['id'],
            'creator_name' => $project['creator_name'],
            'created_at' => $project['created_at'],
            'updated_at' => $project['updated_at']
        ],
        'items' => $items,
        'total_items' => count($items),
        'total_quantity' => array_sum(array_column($items, 'quantity'))
    ]);
    
} catch (Exception $e) {
    error_log("Error in ajax_load_price_label_project.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>