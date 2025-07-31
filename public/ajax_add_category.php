<?php
// AJAX 요청을 위한 응답 헤더 설정
header('Content-Type: application/json');

require_once __DIR__ . '/../lib/session_helper.php';
ensure_logged_in();

// 총괄관리자만 접근 가능
if ($_SESSION['role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$response = ['success' => false, 'message' => '잘못된 요청입니다.'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $category_name = trim($_POST['name'] ?? '');

    if (empty($category_name)) {
        $response['message'] = '카테고리명을 입력해주세요.';
    } else {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 이미 존재하는지 확인
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $stmt->execute([$category_name]);
            
            if ($stmt->fetch()) {
                $response['message'] = '이미 존재하는 카테고리명입니다.';
            } else {
                // 새 카테고리 추가
                $insert_stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
                $insert_stmt->execute([$category_name]);
                $new_id = $pdo->lastInsertId();

                $response = [
                    'success' => true, 
                    'message' => '새로운 카테고리가 추가되었습니다.',
                    'data' => [
                        'id' => $new_id,
                        'name' => $category_name
                    ]
                ];
            }
        } catch (PDOException $e) {
            $response['message'] = '데이터베이스 오류: ' . $e->getMessage();
        }
    }
}

echo json_encode($response);
exit;
?>
