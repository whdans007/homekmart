<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false]); exit; }

$store_id = get_office_store_id();
$date     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['entry_date']??'') ? $_POST['entry_date'] : null;
$supplier = trim($_POST['supplier'] ?? '');
$amount   = max(0.01, (float)($_POST['amount'] ?? 0));
$notes    = trim($_POST['notes'] ?? '');

if (!$date || !$supplier) {
    echo json_encode(['success'=>false,'error'=>'Date and Supplier are required.']); exit;
}

$file_path = null;
$file_mime = null;

if (!empty($_FILES['dtr_file']['name'])) {
    if ($_FILES['dtr_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success'=>false,'error'=>'File upload error.']); exit;
    }
    $allowed  = ['image/jpeg','image/png','application/pdf'];
    $tmp      = $_FILES['dtr_file']['tmp_name'];
    $size     = $_FILES['dtr_file']['size'];
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = strtok(finfo_file($finfo, $tmp) ?: '', ';');
    finfo_close($finfo);
    if ($realMime === 'application/x-pdf') $realMime = 'application/pdf';
    // BOM 제거 후 %PDF 확인 (다운로드 시 BOM이 오염된 파일 대응)
    if ($realMime !== 'application/pdf') {
        $fh = fopen($tmp, 'rb');
        if ($fh) {
            $h = fread($fh, 16); fclose($fh);
            while (substr($h, 0, 3) === "\xEF\xBB\xBF") { $h = substr($h, 3); }
            if (substr($h, 0, 4) === '%PDF') $realMime = 'application/pdf';
        }
    }

    if (!in_array($realMime, $allowed)) {
        echo json_encode(['success'=>false,'error'=>'JPG, PNG, PDF only.']); exit;
    }
    if ($size > 10 * 1024 * 1024) {
        echo json_encode(['success'=>false,'error'=>'File must be under 10MB.']); exit;
    }

    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
    $dir    = __DIR__ . '/../../uploads/deferred/' . date('Y/m') . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname   = uniqid('dtr_', true) . '.' . $extMap[$realMime];
    $content = file_get_contents($tmp);
    if ($realMime === 'application/pdf') {
        while (substr($content, 0, 3) === "\xEF\xBB\xBF") { $content = substr($content, 3); }
    }
    if (file_put_contents($dir . $fname, $content) !== false) {
        $file_path = 'uploads/deferred/' . date('Y/m') . '/' . $fname;
        $file_mime = $realMime;
    } else {
        echo json_encode(['success'=>false,'error'=>'Failed to save file.']); exit;
    }
}

$conn = get_db_connection();

// 등록된 업체만 허용 (수기 입력 차단) — suppliers 테이블에 존재하는 업체명만 통과
$chk_sup = $conn->prepare("SELECT 1 FROM suppliers WHERE name=? LIMIT 1");
$chk_sup->bind_param('s', $supplier);
$chk_sup->execute();
$exists = $chk_sup->get_result()->num_rows > 0;
$chk_sup->close();
if (!$exists) {
    $conn->close();
    echo json_encode(['success'=>false,'error'=>'Supplier is not registered. Please add it first using the "New" button.']); exit;
}

$conn->query("CREATE TABLE IF NOT EXISTS deferred_entries (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  supplier   VARCHAR(200) NOT NULL,
  amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes      TEXT,
  file_path  VARCHAR(500) DEFAULT NULL,
  file_mime  VARCHAR(100) DEFAULT NULL,
  status     ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// file 컬럼 없으면 자동 추가 (기존 테이블 대응)
foreach (['file_path VARCHAR(500) DEFAULT NULL','file_mime VARCHAR(100) DEFAULT NULL'] as $col_def) {
    $col = explode(' ', $col_def)[0];
    $chk = $conn->query("SHOW COLUMNS FROM deferred_entries LIKE '{$col}'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE deferred_entries ADD COLUMN {$col_def}");
    }
}

$stmt = $conn->prepare(
    "INSERT INTO deferred_entries (store_id, entry_date, supplier, amount, notes, file_path, file_mime)
     VALUES (?,?,?,?,?,?,?)"
);
$stmt->bind_param('issdsss', $store_id, $date, $supplier, $amount, $notes, $file_path, $file_mime);
$ok = $stmt->execute();
$id = $conn->insert_id;
$stmt->close();
$conn->close();

echo json_encode(['success'=>$ok, 'id'=>$id]);
