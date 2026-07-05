<?php
/**
 * 외상거래처 빠른 등록 (credit_customers)
 * 외상거래 입력 화면의 "신규 거래처 등록" 모달에서 사용.
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

$name    = trim($_POST['name'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$memo    = trim($_POST['memo'] ?? '');

$store_id = $_SESSION['store_id'] ?? null;
// super_admin이고 점포가 없으면 기본 점포(1) 사용 (header.php와 동일한 정책)
if (empty($store_id) && ($_SESSION['role'] ?? '') === 'super_admin') {
    $store_id = 1;
}

if ($name === '') {
    echo json_encode(['success' => false, 'message' => '업체명을 입력해주세요.']);
    exit;
}

if (empty($store_id)) {
    echo json_encode(['success' => false, 'message' => '점포 정보가 없어 등록할 수 없습니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 동일 점포 내 중복 거래처명 확인
    $check = $pdo->prepare("SELECT id, name, phone, address FROM credit_customers WHERE name = ? AND store_id = ? AND is_active = 1");
    $check->execute([$name, $store_id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // 이미 있으면 그 거래처를 그대로 반환 (중복 등록 방지)
        echo json_encode(['success' => true, 'customer' => $existing, 'existed' => true]);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO credit_customers (store_id, name, phone, address, memo, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$store_id, $name, $phone, $address, $memo]);
    $id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'customer' => [
            'id' => $id,
            'name' => $name,
            'phone' => $phone,
            'address' => $address
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
