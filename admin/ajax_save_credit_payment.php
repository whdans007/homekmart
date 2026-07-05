<?php
/**
 * 수금(입금) 저장 (credit_payments)
 * 외상거래 목록 화면의 "수금 입력" 모달에서 사용.
 */
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if (!has_permission('wholesale_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

$customer_id   = (int)($_POST['customer_id'] ?? 0);
$payment_date  = $_POST['payment_date'] ?? date('Y-m-d');
$amount        = (float)($_POST['amount'] ?? 0);
$method        = trim($_POST['method'] ?? '');
$notes         = trim($_POST['notes'] ?? '');

if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => '거래처를 선택해주세요.']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => '수금 금액을 올바르게 입력해주세요.']);
    exit;
}
// 날짜 형식 검증
$d = DateTime::createFromFormat('Y-m-d', $payment_date);
if (!$d || $d->format('Y-m-d') !== $payment_date) {
    $payment_date = date('Y-m-d');
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 거래처 확인 및 점포 권한 체크
    $cust_stmt = $pdo->prepare("SELECT id, store_id FROM credit_customers WHERE id = ? AND is_active = 1");
    $cust_stmt->execute([$customer_id]);
    $customer = $cust_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        echo json_encode(['success' => false, 'message' => '거래처를 찾을 수 없습니다.']);
        exit;
    }

    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        $store_id = $_SESSION['store_id'] ?? null;
        if ($store_id != $customer['store_id']) {
            echo json_encode(['success' => false, 'message' => '해당 거래처에 대한 권한이 없습니다.']);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO credit_payments (customer_id, store_id, user_id, payment_date, amount, method, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $customer_id,
        $customer['store_id'],
        $_SESSION['user_id'],
        $payment_date,
        $amount,
        $method ?: null,
        $notes ?: null
    ]);

    echo json_encode(['success' => true, 'payment_id' => $pdo->lastInsertId()]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
