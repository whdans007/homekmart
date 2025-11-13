<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/lang_helper.php';

header('Content-Type: application/json');

// 인증 확인
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => t('common.access_denied')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'quick_register') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$conn = get_db_connection();

try {
    $sku = trim($_POST['sku'] ?? '');
    $name_ko = trim($_POST['name_ko'] ?? '');
    $name_en = trim($_POST['name_en'] ?? '');
    $cost_price = isset($_POST['cost_price']) && $_POST['cost_price'] !== '' ? floatval($_POST['cost_price']) : null;
    $selling_price = isset($_POST['selling_price']) && $_POST['selling_price'] !== '' ? floatval($_POST['selling_price']) : null;
    $store_id = isset($_POST['store_id']) && $_POST['store_id'] !== '' ? intval($_POST['store_id']) : null;

    // 유효성 검사
    if (empty($sku)) {
        throw new Exception(t('product.sku_required'));
    }

    if (empty($name_ko) && empty($name_en)) {
        throw new Exception(t('barcode_generate.please_enter_product_name'));
    }

    // SKU 중복 확인
    $check_stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
    $check_stmt->bind_param("s", $sku);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        throw new Exception(t('product.sku_exists'));
    }
    $check_stmt->close();

    $conn->begin_transaction();

    // 상품 등록
    $stmt = $conn->prepare("INSERT INTO products (sku, name_ko, name_en, status) VALUES (?, ?, ?, 'active')");
    $stmt->bind_param("sss", $sku, $name_ko, $name_en);

    if (!$stmt->execute()) {
        throw new Exception('Product insertion failed');
    }

    $product_id = $conn->insert_id;
    $stmt->close();

    // 재고 정보 등록 (store_id가 있는 경우)
    if ($store_id && ($cost_price !== null || $selling_price !== null)) {
        $inv_stmt = $conn->prepare("INSERT INTO inventory (product_id, store_id, quantity, cost_price, selling_price) VALUES (?, ?, 0, ?, ?) ON DUPLICATE KEY UPDATE cost_price = VALUES(cost_price), selling_price = VALUES(selling_price)");
        $inv_stmt->bind_param("iidd", $product_id, $store_id, $cost_price, $selling_price);

        if (!$inv_stmt->execute()) {
            throw new Exception('Inventory insertion failed');
        }
        $inv_stmt->close();
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'product_id' => $product_id,
        'message' => t('barcode_generate.product_registered_success')
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
