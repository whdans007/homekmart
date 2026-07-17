<?php
// 빠른등록(품목 없는) Whole Sale 판매의 원가를 이익 계산용으로 입력/수정한다.
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

$sale_id     = (int)($_POST['sale_id'] ?? 0);
$cost_amount = (float)($_POST['cost_amount'] ?? 0);
$cost_param  = $cost_amount > 0 ? $cost_amount : null;

if ($sale_id <= 0) {
    echo json_encode(['success' => false, 'message' => '잘못된 판매 건입니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $has_cost_amount = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'cost_amount'")->rowCount() > 0;
    if (!$has_cost_amount) {
        echo json_encode(['success' => false, 'message' => '마이그레이션이 아직 적용되지 않았습니다.']);
        exit;
    }

    // 권한 확인 (super_admin이 아니면 자기 점포 판매만 허용)
    $check_sql = "SELECT id FROM wholesale_sales WHERE id = ?";
    $check_params = [$sale_id];
    $current_store_id = $_SESSION['store_id'] ?? null;
    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        $check_sql .= " AND store_id = ?";
        $check_params[] = $current_store_id;
    }
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute($check_params);
    if (!$check_stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '유효하지 않은 판매 건입니다.']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE wholesale_sales SET cost_amount = ? WHERE id = ?");
    $stmt->execute([$cost_param, $sale_id]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
