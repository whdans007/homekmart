<?php
// 출력 버퍼링 시작하여 예기치 않은 출력 방지
ob_start();

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

// 권한 체크
if (!is_logged_in() || !has_permission('product_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

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

    // 카테고리 목록 조회 (status 컬럼이 없을 수도 있으므로 먼저 확인)
    $check_column_sql = "SHOW COLUMNS FROM categories LIKE 'status'";
    $check_stmt = $pdo->prepare($check_column_sql);
    $check_stmt->execute();
    $has_status_column = $check_stmt->fetch();

    if ($has_status_column) {
        // status 컬럼이 있는 경우 활성화된 카테고리만 조회
        $sql = "SELECT id, name_ko, name_en, description 
                FROM categories 
                WHERE status = 'active' 
                ORDER BY name_ko ASC";
    } else {
        // status 컬럼이 없는 경우 모든 카테고리 조회
        $sql = "SELECT id, name_ko, name_en, description 
                FROM categories 
                ORDER BY name_ko ASC";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

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