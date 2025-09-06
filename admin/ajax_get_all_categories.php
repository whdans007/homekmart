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

    // 모든 카테고리 가져오기
    $sql = "SELECT id, name, name_en FROM categories ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 카테고리를 그룹별로 분류
    $grouped_categories = [
        "신선식품" => [],
        "가공식품" => [],
        "간식/음료" => [],
        "생활용품" => [],
        "주방/가정용품" => [],
        "기타" => []
    ];

    // 카테고리를 그룹에 할당
    foreach ($categories as $cat) {
        $name_lower = mb_strtolower($cat['name'], 'UTF-8');
        
        if (strpos($name_lower, '과일') !== false || strpos($name_lower, '채소') !== false || 
            strpos($name_lower, '정육') !== false || strpos($name_lower, '수산') !== false || 
            strpos($name_lower, '계란') !== false || strpos($name_lower, '유제품') !== false || 
            strpos($name_lower, '김치') !== false || strpos($name_lower, '베이커리') !== false) {
            $grouped_categories["신선식품"][] = $cat;
        } elseif (strpos($name_lower, '쌀') !== false || strpos($name_lower, '라면') !== false || 
                  strpos($name_lower, '통조림') !== false || strpos($name_lower, '장류') !== false || 
                  strpos($name_lower, '오일') !== false || strpos($name_lower, '냉동') !== false || 
                  strpos($name_lower, '냉장') !== false || strpos($name_lower, '간편식') !== false || 
                  strpos($name_lower, '아이스크림') !== false || strpos($name_lower, '건어물') !== false) {
            $grouped_categories["가공식품"][] = $cat;
        } elseif (strpos($name_lower, '과자') !== false || strpos($name_lower, '초콜릿') !== false || 
                  strpos($name_lower, '커피') !== false || strpos($name_lower, '음료') !== false || 
                  strpos($name_lower, '주류') !== false) {
            $grouped_categories["간식/음료"][] = $cat;
        } elseif (strpos($name_lower, '세제') !== false || strpos($name_lower, '화장지') !== false || 
                  strpos($name_lower, '구강') !== false || strpos($name_lower, '헤어') !== false || 
                  strpos($name_lower, '화장품') !== false) {
            $grouped_categories["생활용품"][] = $cat;
        } elseif (strpos($name_lower, '주방') !== false || strpos($name_lower, '가정') !== false || 
                  strpos($name_lower, '청소') !== false) {
            $grouped_categories["주방/가정용품"][] = $cat;
        } else {
            $grouped_categories["기타"][] = $cat;
        }
    }

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