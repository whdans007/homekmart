<?php
// 거래처(업체)별 도매가 예외 저장/삭제
// 박스/낱개 둘 다 비어있으면 예외 삭제(기본가 복원), 아니면 upsert.
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

header('Content-Type: application/json; charset=utf-8');
ensure_logged_in();

if (!has_permission('wholesale_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$customer_id = (int)($_POST['customer_id'] ?? 0);
$wp_id       = (int)($_POST['wholesale_product_id'] ?? 0);
$box_raw     = trim($_POST['wholesale_price'] ?? '');
$piece_raw   = trim($_POST['wholesale_price_piece'] ?? '');

if ($customer_id <= 0 || $wp_id <= 0) {
    echo json_encode(['success' => false, 'message' => '잘못된 파라미터']);
    exit;
}

$box   = ($box_raw === '')   ? null : (float)$box_raw;
$piece = ($piece_raw === '') ? null : (float)$piece_raw;
if (($box !== null && $box < 0) || ($piece !== null && $piece < 0)) {
    echo json_encode(['success' => false, 'message' => '가격은 0 이상이어야 합니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 도매상품 존재 + 점포 권한 확인
    $chk = $pdo->prepare("SELECT store_id FROM wholesale_products WHERE id = ?");
    $chk->execute([$wp_id]);
    $wp_store = $chk->fetchColumn();
    if ($wp_store === false) {
        echo json_encode(['success' => false, 'message' => '도매상품을 찾을 수 없습니다.']);
        exit;
    }
    if ($_SESSION['role'] !== 'super_admin') {
        $my_store = $_SESSION['store_id'] ?? null;
        if ($my_store !== null && (int)$wp_store !== (int)$my_store) {
            echo json_encode(['success' => false, 'message' => '다른 점포의 상품은 수정할 수 없습니다.']);
            exit;
        }
    }

    // 거래처 존재 확인
    $cc = $pdo->prepare("SELECT id FROM wholesale_customers WHERE id = ?");
    $cc->execute([$customer_id]);
    if (!$cc->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => '거래처를 찾을 수 없습니다.']);
        exit;
    }

    if ($box === null && $piece === null) {
        // 둘 다 비움 → 예외 삭제(기본가 복원)
        $del = $pdo->prepare("DELETE FROM wholesale_customer_prices WHERE customer_id = ? AND wholesale_product_id = ?");
        $del->execute([$customer_id, $wp_id]);
        echo json_encode(['success' => true, 'deleted' => true, 'has_override_box' => false, 'has_override_piece' => false]);
        exit;
    }

    $up = $pdo->prepare("
        INSERT INTO wholesale_customer_prices (customer_id, wholesale_product_id, wholesale_price, wholesale_price_piece)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            wholesale_price = VALUES(wholesale_price),
            wholesale_price_piece = VALUES(wholesale_price_piece),
            updated_at = NOW()
    ");
    $up->execute([$customer_id, $wp_id, $box, $piece]);

    echo json_encode([
        'success' => true,
        'deleted' => false,
        'has_override_box'   => $box !== null,
        'has_override_piece' => $piece !== null,
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '저장 중 오류: ' . $e->getMessage()]);
}
