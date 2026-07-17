<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

header('Content-Type: application/json; charset=utf-8');

if (!has_permission('wholesale_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$sale_ids = $_POST['sale_ids'] ?? [];
if (!is_array($sale_ids)) {
    echo json_encode(['success' => false, 'message' => '선택된 항목이 없습니다.']);
    exit;
}
$sale_ids = array_values(array_unique(array_filter(array_map('intval', $sale_ids), function ($v) {
    return $v > 0;
})));
if (empty($sale_ids)) {
    echo json_encode(['success' => false, 'message' => '선택된 항목이 없습니다.']);
    exit;
}

$payment_method = trim($_POST['payment_method'] ?? '');
$is_super_admin = ($_SESSION['role'] === 'super_admin');
$current_store_id = $_SESSION['store_id'] ?? 0;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $placeholders = implode(',', array_fill(0, count($sale_ids), '?'));
    $sql = "UPDATE wholesale_sales
            SET payment_status = 'paid', paid_at = NOW(), payment_method = ?, updated_at = NOW()
            WHERE id IN ({$placeholders})
              AND status != 'cancelled'
              AND COALESCE(payment_status, 'unpaid') <> 'paid'";
    $params = array_merge([$payment_method !== '' ? $payment_method : null], $sale_ids);

    if (!$is_super_admin) {
        $sql .= " AND store_id = ?";
        $params[] = $current_store_id;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $updated = $stmt->rowCount();

    echo json_encode([
        'success' => true,
        'message' => "{$updated}건이 결제완료로 처리되었습니다.",
        'updated' => $updated,
    ]);
} catch (PDOException $e) {
    error_log("Bulk wholesale pay error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
