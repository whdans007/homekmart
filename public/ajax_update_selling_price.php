<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// 세션 시작 (세션이 시작되지 않은 경우)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

$product_id = $_POST['product_id'] ?? 0;
$selling_price = $_POST['selling_price'] ?? 0;
$store_id = $_POST['store_id'] ?? null;

if (empty($product_id) || empty($selling_price)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
    exit;
}

// 입력값 검증
if (!is_numeric($selling_price) || $selling_price <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '올바른 판매가를 입력해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($store_id && is_numeric($store_id)) {
        // 점포별 판매가 설정 (inventory 테이블에 selling_price 컬럼이 있는지 확인)
        $column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'selling_price'");
        $column_check->execute();
        $has_selling_price_column = $column_check->fetch();
        
        if ($has_selling_price_column) {
            // inventory 테이블에 selling_price 컬럼이 있는 경우
            $stmt = $pdo->prepare("
                UPDATE inventory 
                SET selling_price = ?, updated_at = NOW()
                WHERE product_id = ? AND store_id = ?
            ");
            $stmt->execute([$selling_price, $product_id, $store_id]);
            
            if ($stmt->rowCount() == 0) {
                // 재고 레코드가 없으면 생성
                $stmt = $pdo->prepare("
                    INSERT INTO inventory (product_id, store_id, quantity, selling_price, created_at, updated_at)
                    VALUES (?, ?, 0, ?, NOW(), NOW())
                ");
                $stmt->execute([$product_id, $store_id, $selling_price]);
            }
            
            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => '지점별 판매가가 성공적으로 설정되었습니다.',
                'selling_price' => number_format($selling_price)
            ]);
        } else {
            // inventory 테이블에 selling_price 컬럼이 없는 경우 기본 판매가로 설정
            $stmt = $pdo->prepare("
                UPDATE products 
                SET selling_price = ?, updated_at = NOW(), last_modified_by_user_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$selling_price, $_SESSION['user_id'], $product_id]);

            if ($stmt->rowCount() == 0) {
                ob_clean();
                echo json_encode(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
                exit;
            }

            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => '기본 판매가가 성공적으로 설정되었습니다.',
                'selling_price' => number_format($selling_price)
            ]);
        }
    } else {
        // 기본 판매가 설정 (products 테이블)
        $stmt = $pdo->prepare("
            UPDATE products 
            SET selling_price = ?, updated_at = NOW(), last_modified_by_user_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$selling_price, $_SESSION['user_id'], $product_id]);

        if ($stmt->rowCount() == 0) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
            exit;
        }

        ob_clean();
        echo json_encode([
            'success' => true, 
            'message' => '기본 판매가가 성공적으로 설정되었습니다.',
            'selling_price' => number_format($selling_price)
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
?>