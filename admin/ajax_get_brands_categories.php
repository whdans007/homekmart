<?php
// 브랜드와 카테고리 목록을 가져오는 AJAX 엔드포인트
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

// 로그인 확인
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

try {
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    // 브랜드 목록 가져오기
    $brands_sql = "SELECT id, name_ko, name_en FROM brands ORDER BY name_ko";
    $brands_result = $conn->query($brands_sql);
    $brands = [];
    
    if ($brands_result) {
        while ($row = $brands_result->fetch_assoc()) {
            $brands[] = [
                'id' => $row['id'],
                'name' => $row['name_ko'] ?: $row['name_en']
            ];
        }
    }
    
    // 카테고리 목록 가져오기
    $categories_sql = "SELECT id, name FROM categories ORDER BY name";
    $categories_result = $conn->query($categories_sql);
    $categories = [];
    
    if ($categories_result) {
        while ($row = $categories_result->fetch_assoc()) {
            $categories[] = [
                'id' => $row['id'],
                'name' => $row['name']
            ];
        }
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'brands' => $brands,
        'categories' => $categories
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching brands and categories: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '데이터를 가져오는 중 오류가 발생했습니다.'
    ]);
}
?>