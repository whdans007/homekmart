<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../config/db_config.php';

$current_store_id = $_SESSION['store_id'] ?? 0;

header('Content-Type: application/json; charset=utf-8');

if (!has_permission('wholesale_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$name    = trim($_POST['name'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$memo    = trim($_POST['memo'] ?? '');

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => '업체명을 입력해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $check = $pdo->prepare("SELECT COUNT(*) FROM wholesale_customers WHERE name = ? AND store_id = ? AND is_active = 1");
    $check->execute([$name, $current_store_id]);

    if ($check->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => '이미 등록된 거래처명입니다.']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO wholesale_customers (name, phone, address, memo, store_id, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$name, $phone, $address, $memo, $current_store_id]);

    $new_id = $pdo->lastInsertId();

    echo json_encode([
        'success'  => true,
        'message'  => '거래처가 등록되었습니다.',
        'customer' => [
            'id'      => $new_id,
            'name'    => $name,
            'phone'   => $phone,
            'address' => $address,
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
