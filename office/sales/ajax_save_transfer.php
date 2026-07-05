<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$store_id         = get_office_store_id();
$date             = post_date('transfer_date');
$direction        = 'in';
$other_store_id   = (int)($_POST['other_store_id'] ?? 0);
$other_store_name = post_str('other_store_name');
$amount           = post_float('amount');
$notes            = post_str('notes');
$category         = in_array($_POST['category']??'', ['grocery','meat','seafood','fruit'])
                    ? $_POST['category'] : 'grocery';
$by               = (int)($_SESSION['user_id'] ?? 0) ?: null;

$errors = [];
if (!$date)           $errors[] = 'Date is required.';
if (!$other_store_id) $errors[] = 'Store is required.';
if ($amount <= 0)     $errors[] = 'Amount must be greater than 0.';

$file_path = null;
$file_orig = null;
$file_mime = null;

if (!empty($_FILES['transfer_file']['name'])) {
    if ($_FILES['transfer_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload error.';
    } else {
        $allowed  = ['image/jpeg','image/png','application/pdf'];
        $tmp      = $_FILES['transfer_file']['tmp_name'];
        $orig     = $_FILES['transfer_file']['name'];
        $size     = $_FILES['transfer_file']['size'];
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
            $errors[] = 'Only JPG, PNG, PDF files are allowed.';
        } elseif ($size > 10 * 1024 * 1024) {
            $errors[] = 'File must be under 10MB.';
        } else {
            $extMap   = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
            $dir      = __DIR__ . '/../../uploads/transfers/' . date('Y/m') . '/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname    = uniqid('tr_', true) . '.' . $extMap[$realMime];
            $content  = file_get_contents($tmp);
            if ($realMime === 'application/pdf') {
                while (substr($content, 0, 3) === "\xEF\xBB\xBF") { $content = substr($content, 3); }
            }
            if (file_put_contents($dir . $fname, $content) !== false) {
                $file_path = 'uploads/transfers/' . date('Y/m') . '/' . $fname;
                $file_orig = htmlspecialchars($orig, ENT_QUOTES, 'UTF-8');
                $file_mime = $realMime;
            } else {
                $errors[] = 'Failed to save file.';
            }
        }
    }
}

if ($errors) { echo json_encode(['success'=>false,'errors'=>$errors]); exit; }

$conn = get_db_connection();

// category 컬럼 없으면 자동 추가
$chk_cat = $conn->query("SHOW COLUMNS FROM sales_transfers LIKE 'category'");
if ($chk_cat && $chk_cat->num_rows === 0) {
    $conn->query("ALTER TABLE sales_transfers ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'grocery' AFTER notes");
}

// Check if file columns exist (migration may not have run)
$chk = $conn->query("SHOW COLUMNS FROM sales_transfers LIKE 'file_path'");
$has_file_cols = ($chk && $chk->num_rows > 0);

if ($has_file_cols) {
    $stmt = $conn->prepare(
        "INSERT INTO sales_transfers
         (store_id, transfer_date, direction, other_store_id, other_store_name,
          amount, notes, category, file_path, file_original_name, file_mime, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('issisdsssssi',
        $store_id, $date, $direction, $other_store_id, $other_store_name,
        $amount, $notes, $category, $file_path, $file_orig, $file_mime, $by
    );
} else {
    $stmt = $conn->prepare(
        "INSERT INTO sales_transfers
         (store_id, transfer_date, direction, other_store_id, other_store_name,
          amount, notes, category, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('issisdssi',
        $store_id, $date, $direction, $other_store_id, $other_store_name,
        $amount, $notes, $category, $by
    );
}

$stmt->execute();
$id = $conn->insert_id;
$stmt->close();
$conn->close();

echo json_encode(['success'=>true, 'id'=>$id]);
