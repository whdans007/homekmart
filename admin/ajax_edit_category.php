<?php
// AJAX 요청을 위한 응답 헤더 설정
header('Content-Type: application/json');

require_once __DIR__ . '/../lib/session_helper.php';
ensure_logged_in();

require_once __DIR__ . '/../lib/permission_helper.php';

// 카테고리 관리 권한 확인
if (!has_permission('category_management') && $_SESSION['role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'errors' => ['권한이 없습니다.']]);
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$errors = [];
$response = ['success' => false];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $category_id = (int)($_POST['category_id'] ?? 0);
    $category_name = trim($_POST['name'] ?? '');
    $category_name_en = trim($_POST['name_en'] ?? '');
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    // 유효성 검사
    if ($category_id <= 0) {
        $errors[] = '유효하지 않은 카테고리 ID입니다.';
    }
    
    if (empty($category_name)) {
        $errors[] = '카테고리명을 입력해주세요.';
    }
    
    if ($parent_id === $category_id) {
        $errors[] = '자기 자신을 상위 카테고리로 지정할 수 없습니다.';
    }

    if (empty($errors)) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 카테고리가 존재하는지 확인
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
            $stmt->execute([$category_id]);
            if (!$stmt->fetch()) {
                $errors[] = '카테고리를 찾을 수 없습니다.';
            } else {
                // 이름 중복 확인 (자기 자신 제외)
                $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
                $stmt->execute([$category_name, $category_id]);
                if ($stmt->fetch()) {
                    $errors[] = '이미 존재하는 카테고리명입니다.';
                } else {
                    // 카테고리 수정
                    $update_stmt = $pdo->prepare("UPDATE categories SET name = ?, name_en = ?, parent_id = ? WHERE id = ?");
                    $update_stmt->execute([$category_name, $category_name_en, $parent_id, $category_id]);
                    
                    $response['success'] = true;
                    $response['message'] = '카테고리가 성공적으로 수정되었습니다.';
                }
            }
        } catch (PDOException $e) {
            $errors[] = '데이터베이스 오류가 발생했습니다: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
} else {
    $response['errors'] = ['잘못된 요청입니다.'];
}

echo json_encode($response);
exit;
?>