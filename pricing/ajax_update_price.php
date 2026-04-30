<?php
// 판매가 변경 - 인증 불필요 (독립형 도구)
require_once __DIR__ . '/../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

$product_id    = (int)($_POST['product_id'] ?? 0);
$selling_price = $_POST['selling_price'] ?? '';
$store_id      = (int)($_POST['store_id'] ?? 0);

if (!$product_id || $selling_price === '' || !$store_id) {
    echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
    exit;
}

if (!is_numeric($selling_price) || (float)$selling_price < 0) {
    echo json_encode(['success' => false, 'message' => '올바른 판매가를 입력해주세요.']);
    exit;
}

$selling_price = (float)$selling_price;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 상품 존재 확인
    $stmt = $pdo->prepare("SELECT id, name_en, name_ko FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => '존재하지 않는 상품입니다.']);
        exit;
    }

    // inventory 테이블에 selling_price 컬럼이 있는지 확인
    $hasInvPrice = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory' AND column_name='selling_price'"
    )->fetchColumn();

    if ($hasInvPrice) {
        // inventory 행이 있으면 UPDATE, 없으면 INSERT (점포별 가격)
        $check = $pdo->prepare("SELECT id FROM inventory WHERE product_id = ? AND store_id = ?");
        $check->execute([$product_id, $store_id]);

        if ($check->fetch()) {
            $upd = $pdo->prepare("UPDATE inventory SET selling_price = ?, updated_at = NOW() WHERE product_id = ? AND store_id = ?");
            $upd->execute([$selling_price, $product_id, $store_id]);
        } else {
            $ins = $pdo->prepare("INSERT INTO inventory (product_id, store_id, selling_price, updated_at) VALUES (?, ?, ?, NOW())");
            $ins->execute([$product_id, $store_id, $selling_price]);
        }
    } else {
        // inventory에 selling_price 없으면 products 테이블 업데이트
        $upd = $pdo->prepare("UPDATE products SET selling_price = ? WHERE id = ?");
        $upd->execute([$selling_price, $product_id]);
    }

    echo json_encode([
        'success'       => true,
        'message'       => '판매가가 변경되었습니다.',
        'selling_price' => number_format($selling_price, 0),
        'data' => [
            'product_id'    => $product_id,
            'store_id'      => $store_id,
            'selling_price' => $selling_price,
        ]
    ]);

} catch (Throwable $e) {
    error_log('pricing/ajax_update_price.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
}
