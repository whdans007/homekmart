<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if (!has_office_permission()) {
    echo json_encode(['success'=>false,'error'=>'You do not have permission to edit deferred entries. Please contact an administrator.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false]); exit; }

$store_id    = get_office_store_id();
$id          = (int)($_POST['id'] ?? 0);
$date        = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['entry_date']??'') ? $_POST['entry_date'] : null;
$supplier    = trim(office_b64_decode($_POST['supplier'] ?? ''));
$amount      = max(0.01, (float)($_POST['amount'] ?? 0));
$notes       = trim(office_b64_decode($_POST['notes'] ?? ''));
$status      = in_array($_POST['status']??'', ['pending','paid']) ? $_POST['status'] : 'pending';
$remove_file = ($_POST['remove_file'] ?? '0') === '1';

if (!$id || !$date || !$supplier) {
    echo json_encode(['success'=>false,'error'=>'Invalid params']); exit;
}

$conn = get_db_connection();

$res = $conn->query("SELECT file_path, file_mime FROM deferred_entries WHERE id=$id AND store_id=$store_id");
$row = $res ? $res->fetch_assoc() : null;
if (!$row) { $conn->close(); echo json_encode(['success'=>false,'error'=>'Not found']); exit; }

$file_path   = $row['file_path'];
$file_mime   = $row['file_mime'];
$update_file = false;

// 새 파일 업로드
if (!empty($_FILES['dtr_file']['name'])) {
    if ($_FILES['dtr_file']['error'] !== UPLOAD_ERR_OK) {
        $conn->close(); echo json_encode(['success'=>false,'error'=>'File upload error.']); exit;
    }
    $allowed  = ['image/jpeg','image/png','application/pdf'];
    $tmp      = $_FILES['dtr_file']['tmp_name'];
    $size     = $_FILES['dtr_file']['size'];
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = strtok(finfo_file($finfo, $tmp) ?: '', ';');
    finfo_close($finfo);
    if ($realMime === 'application/x-pdf') $realMime = 'application/pdf';
    if ($realMime !== 'application/pdf') {
        $fh = fopen($tmp, 'rb');
        if ($fh) {
            $h = fread($fh, 16); fclose($fh);
            while (substr($h, 0, 3) === "\xEF\xBB\xBF") { $h = substr($h, 3); }
            if (substr($h, 0, 4) === '%PDF') $realMime = 'application/pdf';
        }
    }
    if (!in_array($realMime, $allowed)) {
        $conn->close(); echo json_encode(['success'=>false,'error'=>'JPG, PNG, PDF only.']); exit;
    }
    if ($size > 10 * 1024 * 1024) {
        $conn->close(); echo json_encode(['success'=>false,'error'=>'File must be under 10MB.']); exit;
    }

    $extMap  = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
    $dir     = __DIR__ . '/../../uploads/deferred/' . date('Y/m') . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname   = uniqid('dtr_', true) . '.' . $extMap[$realMime];
    $content = file_get_contents($tmp);
    if ($realMime === 'application/pdf') {
        while (substr($content, 0, 3) === "\xEF\xBB\xBF") { $content = substr($content, 3); }
    }
    if (file_put_contents($dir . $fname, $content) !== false) {
        // 기존 파일 삭제
        if ($file_path) {
            $old = __DIR__ . '/../../' . ltrim($file_path, '/');
            if (file_exists($old)) @unlink($old);
        }
        $file_path   = 'uploads/deferred/' . date('Y/m') . '/' . $fname;
        $file_mime   = $realMime;
        $update_file = true;
    } else {
        $conn->close(); echo json_encode(['success'=>false,'error'=>'Failed to save file.']); exit;
    }
}

// 파일 제거
if ($remove_file && !$update_file) {
    if ($file_path) {
        $old = __DIR__ . '/../../' . ltrim($file_path, '/');
        if (file_exists($old)) @unlink($old);
    }
    $file_path   = null;
    $file_mime   = null;
    $update_file = true;
}

if ($update_file) {
    $stmt = $conn->prepare(
        "UPDATE deferred_entries
         SET entry_date=?, supplier=?, amount=?, notes=?, status=?, file_path=?, file_mime=?
         WHERE id=? AND store_id=?"
    );
    $stmt->bind_param('ssdssssii', $date, $supplier, $amount, $notes, $status, $file_path, $file_mime, $id, $store_id);
} else {
    $stmt = $conn->prepare(
        "UPDATE deferred_entries
         SET entry_date=?, supplier=?, amount=?, notes=?, status=?
         WHERE id=? AND store_id=?"
    );
    $stmt->bind_param('ssdssii', $date, $supplier, $amount, $notes, $status, $id, $store_id);
}

$ok = $stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success'=>$ok]);
