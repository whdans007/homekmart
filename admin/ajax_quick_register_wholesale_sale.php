<?php
// Design Ref: office/sales/daily_entry.php §4 POS 버튼과 동일한 취지 —
// 품목 없이 거래처+금액만으로 Whole Sale 판매를 빠르게 등록한다 (wholesale_sale_items 없음).
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

$customer_id = (int)($_POST['customer_id'] ?? 0);
$sale_date   = $_POST['sale_date'] ?? date('Y-m-d');
$amount      = (float)($_POST['amount'] ?? 0);

if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => '거래처를 선택해주세요.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sale_date)) {
    echo json_encode(['success' => false, 'message' => '판매 날짜가 올바르지 않습니다.']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => '금액을 입력해주세요.']);
    exit;
}

// 접속자 점포로 고정 (wholesale_sales.php 신규 등록과 동일 규칙). super_admin은 CLARK HILLS(1) 기본값.
$current_store_id = $_SESSION['store_id'] ?? null;
if (($_SESSION['role'] ?? '') === 'super_admin' && empty($current_store_id)) {
    $current_store_id = 1;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 거래처 유효성 확인 (super_admin이 아니면 자기 점포 거래처만 허용)
    $cust_sql = "SELECT id FROM wholesale_customers WHERE id = ? AND is_active = 1";
    $cust_params = [$customer_id];
    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        $cust_sql .= " AND store_id = ?";
        $cust_params[] = $current_store_id;
    }
    $cust_stmt = $pdo->prepare($cust_sql);
    $cust_stmt->execute($cust_params);
    if (!$cust_stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '유효하지 않은 거래처입니다.']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO wholesale_sales (customer_id, store_id, user_id, sale_date, total_amount, final_amount, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'confirmed', NOW())
    ");
    $stmt->execute([$customer_id, $current_store_id, $_SESSION['user_id'], $sale_date, $amount, $amount]);
    $sale_id = $pdo->lastInsertId();

    echo json_encode(['success' => true, 'sale_id' => $sale_id]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
