<?php
// Design Ref: office/sales/daily_entry.php §4 POS 버튼과 동일한 취지 —
// 품목 없이 거래처+금액만으로 Whole Sale 판매를 빠르게 등록한다 (wholesale_sale_items 없음).
// 메뉴얼 DR(빠른등록)은 스캔/사진 첨부파일이 반드시 있어야 저장 가능하다.
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
$cost_amount = (float)($_POST['cost_amount'] ?? 0);
$cost_param  = $cost_amount > 0 ? $cost_amount : null;

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
if (empty($_FILES['scan_image']['name']) || ($_FILES['scan_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => '스캔 파일을 첨부해주세요.']);
    exit;
}

$allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $_FILES['scan_image']['tmp_name']);
finfo_close($finfo);

if (!isset($allowed_mimes[$mime])) {
    echo json_encode(['success' => false, 'message' => 'JPG/PNG/WEBP 이미지만 첨부할 수 있습니다.']);
    exit;
}
if ($_FILES['scan_image']['size'] > 8 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => '스캔 파일 크기는 8MB 이하여야 합니다.']);
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

    // cost_amount / scan_image_path 컬럼 존재 여부 확인 (마이그레이션 전 하위 호환)
    $has_cost_amount = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'cost_amount'")->rowCount() > 0;
    $has_scan_image  = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'scan_image_path'")->rowCount() > 0;

    // 첨부파일이 필수인데 컬럼이 없으면(마이그레이션 미실행) 저장 자체를 막는다.
    if (!$has_scan_image) {
        echo json_encode(['success' => false, 'message' => '시스템 업데이트가 필요합니다. 관리자에게 문의해주세요. (scan_image_path 마이그레이션 미실행)']);
        exit;
    }

    // 파일 저장 (검증 통과 후, DB 삽입 직전에 실제 이동)
    $upload_dir = __DIR__ . '/../uploads/wholesale_sales/' . date('Y/m') . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed_mimes[$mime];
    $dest = $upload_dir . $filename;

    if (!move_uploaded_file($_FILES['scan_image']['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'message' => '스캔 파일 저장에 실패했습니다.']);
        exit;
    }
    $scan_image_path = 'uploads/wholesale_sales/' . date('Y/m') . '/' . $filename;

    $columns = ['customer_id', 'store_id', 'user_id', 'sale_date', 'total_amount', 'final_amount', 'scan_image_path'];
    $values  = [$customer_id, $current_store_id, $_SESSION['user_id'], $sale_date, $amount, $amount, $scan_image_path];

    if ($has_cost_amount) {
        $columns[] = 'cost_amount';
        $values[]  = $cost_param;
    }
    $columns[] = 'status';
    $columns[] = 'created_at';

    $placeholders = implode(', ', array_fill(0, count($values), '?'));
    $placeholders .= ", 'confirmed', NOW()";

    $stmt = $pdo->prepare("INSERT INTO wholesale_sales (" . implode(', ', $columns) . ") VALUES ({$placeholders})");
    $stmt->execute($values);
    $sale_id = $pdo->lastInsertId();

    echo json_encode(['success' => true, 'sale_id' => $sale_id]);

} catch (PDOException $e) {
    if (isset($dest) && is_file($dest)) {
        @unlink($dest);
    }
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
