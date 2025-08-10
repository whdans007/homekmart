<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !has_permission('purchase_management')) {
    echo json_encode([
        'success' => false,
        'message' => t('messages.permission_denied')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => t('common.invalid_request')
    ]);
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$sku = trim($_POST['sku'] ?? '');
$name_en = trim($_POST['name_en'] ?? '');
$name_ko = trim($_POST['name_ko'] ?? '');
$barcode = trim($_POST['barcode'] ?? '');
$pieces_per_box = (int)($_POST['pieces_per_box'] ?? 1);
$category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
$brand_id = !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null;
$description = trim($_POST['description'] ?? '');
$is_active = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;

if (!$product_id) {
    echo json_encode([
        'success' => false,
        'message' => t('product.invalid_product_id')
    ]);
    exit;
}

if (empty($name_ko)) {
    echo json_encode([
        'success' => false,
        'message' => t('product.name_required')
    ]);
    exit;
}

if ($pieces_per_box < 1) {
    echo json_encode([
        'success' => false,
        'message' => t('product.pieces_per_box_min')
    ]);
    exit;
}

$conn = get_db_connection();

try {
    // 트랜잭션 시작
    $conn->autocommit(false);
    
    // SKU 중복 체크 (현재 상품 제외)
    if (!empty($sku)) {
        $sku_check_sql = "SELECT id FROM products WHERE sku = ? AND id != ?";
        $sku_stmt = $conn->prepare($sku_check_sql);
        $sku_stmt->bind_param("si", $sku, $product_id);
        $sku_stmt->execute();
        $sku_result = $sku_stmt->get_result();
        
        if ($sku_result->num_rows > 0) {
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'message' => t('product.sku_exists')
            ]);
            exit;
        }
        $sku_stmt->close();
    }
    
    // 바코드 중복 체크 (현재 상품 제외)
    if (!empty($barcode)) {
        $barcode_check_sql = "SELECT id FROM products WHERE barcode = ? AND id != ?";
        $barcode_stmt = $conn->prepare($barcode_check_sql);
        $barcode_stmt->bind_param("si", $barcode, $product_id);
        $barcode_stmt->execute();
        $barcode_result = $barcode_stmt->get_result();
        
        if ($barcode_result->num_rows > 0) {
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'message' => t('product.barcode_exists')
            ]);
            exit;
        }
        $barcode_stmt->close();
    }
    
    // 상품 정보 업데이트
    $update_sql = "
        UPDATE products 
        SET sku = ?, name_en = ?, name_ko = ?, barcode = ?, 
            pieces_per_box = ?, category_id = ?, brand_id = ?, 
            description = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?
    ";
    
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param(
        "ssssiiisii", 
        $sku, $name_en, $name_ko, $barcode,
        $pieces_per_box, $category_id, $brand_id,
        $description, $is_active, $product_id
    );
    
    if (!$update_stmt->execute()) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => t('product.update_failed') . ': ' . $update_stmt->error
        ]);
        exit;
    }
    
    // 변경 내역 로그 기록 (선택적)
    $log_sql = "
        INSERT INTO product_change_log 
        (product_id, user_id, change_type, change_details, created_at)
        VALUES (?, ?, 'update', ?, NOW())
    ";
    
    $change_details = json_encode([
        'sku' => $sku,
        'name_en' => $name_en,
        'name_ko' => $name_ko,
        'barcode' => $barcode,
        'pieces_per_box' => $pieces_per_box,
        'category_id' => $category_id,
        'brand_id' => $brand_id,
        'description' => $description,
        'is_active' => $is_active
    ], JSON_UNESCAPED_UNICODE);
    
    // product_change_log 테이블이 존재하는지 확인
    $check_table = $conn->query("SHOW TABLES LIKE 'product_change_log'");
    if ($check_table->num_rows > 0) {
        $log_stmt = $conn->prepare($log_sql);
        $log_stmt->bind_param("iis", $product_id, $_SESSION['user_id'], $change_details);
        $log_stmt->execute();
        $log_stmt->close();
    }
    
    $update_stmt->close();
    
    // 트랜잭션 커밋
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => t('product.update_success')
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Purchase product update error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => t('product.update_error') . ': ' . $e->getMessage()
    ]);
} finally {
    $conn->autocommit(true);
    $conn->close();
}
?>