<?php
// 메뉴얼 DR(빠른등록) 판매에 첨부된 스캔/사진 파일을 서빙한다 (직접 URL 접근 차단 우회).
ob_start();
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';
ob_end_clean();

if (!has_permission('wholesale_management')) {
    http_response_code(403);
    exit('Forbidden.');
}

$sale_id = (int)($_GET['id'] ?? 0);
if ($sale_id <= 0) {
    http_response_code(404);
    exit('Not found.');
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $has_scan_image = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'scan_image_path'")->rowCount() > 0;
    if (!$has_scan_image) {
        http_response_code(404);
        exit('Not found.');
    }

    $sql = "SELECT scan_image_path, store_id FROM wholesale_sales WHERE id = ?";
    $params = [$sale_id];
    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        $sql .= " AND store_id = ?";
        $params[] = $_SESSION['store_id'] ?? 0;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Server error.');
}

if (!$row || !$row['scan_image_path']) {
    http_response_code(404);
    exit('Not found.');
}

$full_path = __DIR__ . '/../' . ltrim($row['scan_image_path'], '/');
if (!is_file($full_path)) {
    http_response_code(404);
    exit('Not found.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $full_path) ?: 'application/octet-stream';
finfo_close($finfo);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full_path));
header('Content-Disposition: inline; filename="scan_' . $sale_id . '"');
header('Cache-Control: private, max-age=0, no-cache');
readfile($full_path);
exit;
