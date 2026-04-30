<?php
// 마스터 파일 업로드 (엑셀/CSV) - 인증 불필요 (독립형 도구)
ini_set('memory_limit', '512M');
// PHP 경고가 JSON 앞에 출력되는 것을 방지
ob_start();

require_once __DIR__ . '/../config/db_config.php';

// 응답 전송 함수 (출력 버퍼 정리 후 JSON 출력)
function sendJson(array $data): void {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'import');

// ── NAS 폴더 파일 목록 반환 ──────────────────────────────────
if ($action === 'list_nas') {
    $nas_dir = dirname(__DIR__) . '/uploads/pos_import/';
    if (!is_dir($nas_dir)) {
        sendJson(['success' => false, 'message' => 'NAS 폴더가 없습니다: ' . $nas_dir]);
    }
    $files = [];
    foreach (scandir($nas_dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) continue;
        $fp = $nas_dir . $f;
        if (!is_file($fp)) continue;
        $files[] = [
            'name'     => $f,
            'size_kb'  => round(filesize($fp) / 1024, 1),
            'modified' => date('Y-m-d H:i:s', filemtime($fp)),
        ];
    }
    // 최신 수정일 순
    usort($files, fn($a, $b) => strcmp($b['modified'], $a['modified']));
    sendJson(['success' => true, 'files' => $files]);
}

// ── NAS 파일 삭제 ────────────────────────────────────────────────
if ($action === 'delete_nas') {
    $nas_dir  = dirname(__DIR__) . '/uploads/pos_import/';
    $filename = basename($_POST['nas_filename'] ?? '');
    if (!$filename) {
        sendJson(['success' => false, 'message' => '파일명이 없습니다.']);
    }
    $target = realpath($nas_dir . $filename);
    // realpath로 NAS 폴더 밖 경로 조작 방지
    if (!$target || strpos($target, realpath($nas_dir)) !== 0 || !is_file($target)) {
        sendJson(['success' => false, 'message' => '파일을 찾을 수 없습니다.']);
    }
    if (!unlink($target)) {
        sendJson(['success' => false, 'message' => '파일 삭제 실패. 서버 권한을 확인하세요.']);
    }
    sendJson(['success' => true, 'message' => "삭제 완료: {$filename}"]);
}

// ── NAS로 파일 업로드 ────────────────────────────────────────────
if ($action === 'upload_to_nas') {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => '파일이 너무 큽니다 (서버 업로드 제한 초과).',
        UPLOAD_ERR_FORM_SIZE  => '파일이 너무 큽니다.',
        UPLOAD_ERR_PARTIAL    => '파일이 부분적으로만 업로드되었습니다.',
        UPLOAD_ERR_NO_FILE    => '파일이 선택되지 않았습니다.',
        UPLOAD_ERR_NO_TMP_DIR => '서버 임시 디렉토리가 없습니다.',
        UPLOAD_ERR_CANT_WRITE => '서버 디스크 쓰기 실패.',
    ];

    if (!isset($_FILES['nas_upload_file']) || $_FILES['nas_upload_file']['error'] !== UPLOAD_ERR_OK) {
        $err_code = $_FILES['nas_upload_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        sendJson(['success' => false, 'message' => $upload_errors[$err_code] ?? '업로드 오류 (' . $err_code . ')']);
    }

    $orig_name = basename($_FILES['nas_upload_file']['name']);
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        sendJson(['success' => false, 'message' => '.xlsx / .xls / .csv 파일만 업로드 가능합니다.']);
    }

    $nas_dir = dirname(__DIR__) . '/uploads/pos_import/';
    if (!is_dir($nas_dir)) {
        mkdir($nas_dir, 0777, true);
    }

    // 파일명 안전 처리 (경로 조작 방지)
    $safe_name = preg_replace('/[^\w가-힣\-\.]/u', '_', $orig_name);
    $dest = $nas_dir . $safe_name;

    // 동일 파일명 존재 시 타임스탬프 추가
    if (file_exists($dest)) {
        $base  = pathinfo($safe_name, PATHINFO_FILENAME);
        $safe_name = $base . '_' . date('YmdHis') . '.' . $ext;
        $dest  = $nas_dir . $safe_name;
    }

    if (!move_uploaded_file($_FILES['nas_upload_file']['tmp_name'], $dest)) {
        sendJson(['success' => false, 'message' => 'NAS 폴더에 파일 저장 실패. 서버 쓰기 권한을 확인하세요.']);
    }

    $size_kb = round(filesize($dest) / 1024, 1);
    sendJson(['success' => true, 'message' => "✅ 업로드 완료: {$safe_name} ({$size_kb} KB)"]);
}

// ── 가져오기 (import) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'message' => '잘못된 요청입니다.']);
}

$store_id = (int)($_POST['store_id'] ?? 0);
if (!$store_id) {
    // store_id가 0이면 DB에서 첫 번째 유효한 점포를 자동 선택
    try {
        $tmp_dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $tmp_pdo = new PDO($tmp_dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $store_id = (int)($tmp_pdo->query("SELECT id FROM stores WHERE id > 0 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        unset($tmp_pdo);
    } catch (Throwable $e) {
        error_log('ajax_import_master.php: fallback store query failed: ' . $e->getMessage());
    }
    if (!$store_id) {
        sendJson(['success' => false, 'message' => '점포를 먼저 선택해주세요. (화면 새로고침 후 환경설정에서 점포 선택)']);
    }
}

// ── 컬럼 매핑 파라미터 ──
$col_sku   = strtoupper(trim($_POST['col_sku']   ?? 'A')) ?: 'A';
$col_name  = strtoupper(trim($_POST['col_name']  ?? 'B')) ?: 'B';
$col_cost  = strtoupper(trim($_POST['col_cost']  ?? 'C')) ?: 'C';
$col_price = strtoupper(trim($_POST['col_price'] ?? 'D')) ?: 'D';
$header_row = max(1, (int)($_POST['header_row'] ?? 1));
$data_start = $header_row + 1;

// ── 파일 소스 결정: upload(기본) 또는 nas ──
$source = $_POST['source'] ?? 'upload';
$tmpPath   = null;
$ext       = '';
$cleanup   = false; // 임시 파일 제거 여부

if ($source === 'nas') {
    $nas_dir      = dirname(__DIR__) . '/uploads/pos_import/';
    $nas_filename = basename($_POST['nas_filename'] ?? '');
    if (!$nas_filename) {
        sendJson(['success' => false, 'message' => '파일을 선택해주세요.']);
    }
    $nas_file_path = $nas_dir . $nas_filename;
    if (!is_file($nas_file_path)) {
        sendJson(['success' => false, 'message' => "NAS 폴더에서 파일을 찾을 수 없습니다: {$nas_filename}"]);
    }
    $ext = strtolower(pathinfo($nas_filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        sendJson(['success' => false, 'message' => '.xlsx / .xls / .csv 파일만 지원합니다.']);
    }
    // PhpSpreadsheet이 NAS/SMB 경로를 직접 열지 못하는 경우가 있으므로 시스템 temp로 복사
    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_import_' . time() . '.' . $ext;
    if (!copy($nas_file_path, $tmpPath)) {
        sendJson(['success' => false, 'message' => "파일 복사 실패. NAS 경로: {$nas_file_path}"]);
    }
    $cleanup = true;
} else {
    // HTTP 업로드
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => '파일이 너무 큽니다 (서버 업로드 제한 초과). NAS 폴더 방식을 사용하세요.',
            UPLOAD_ERR_FORM_SIZE  => '파일이 너무 큽니다.',
            UPLOAD_ERR_PARTIAL    => '파일이 부분적으로만 업로드되었습니다.',
            UPLOAD_ERR_NO_FILE    => '파일이 선택되지 않았습니다.',
            UPLOAD_ERR_NO_TMP_DIR => '서버 임시 디렉토리가 없습니다.',
            UPLOAD_ERR_CANT_WRITE => '서버 디스크 쓰기 실패.',
        ];
        $err_code = $_FILES['excel_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        sendJson(['success' => false, 'message' => $upload_errors[$err_code] ?? '업로드 오류 (' . $err_code . ')']);
    }

    $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        sendJson(['success' => false, 'message' => '.xlsx / .xls / .csv 파일만 업로드 가능합니다.']);
    }
    $tmpPath = $_FILES['excel_file']['tmp_name'];
}

try {
    require_once __DIR__ . '/../vendor/autoload.php';

    // libxml XML 파싱 경고를 내부적으로 처리 (JSON 오염 방지)
    libxml_use_internal_errors(true);

    if ($ext === 'csv') {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
    } elseif ($ext === 'xlsx') {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
    } else {
        // xls 등 기타
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
        } catch (Throwable $e) {
            if ($cleanup && file_exists($tmpPath)) @unlink($tmpPath);
            sendJson(['success' => false, 'message' => 'XLS 파일을 읽을 수 없습니다. XLSX로 저장 후 다시 시도하세요.']);
        }
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
    }

    // 파일 로드 — XLSX 로드 실패 시 재시도
    try {
        $spreadsheet = $reader->load($tmpPath);
    } catch (Throwable $e) {
        if ($ext === 'xlsx') {
            $reader2 = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $reader2->load($tmpPath);
        } else {
            throw $e;
        }
    }

    if ($cleanup && file_exists($tmpPath)) @unlink($tmpPath);

    $ws = $spreadsheet->getActiveSheet();

    // 최대 데이터 행 탐색
    $maxRow = $data_start;
    if (method_exists($ws, 'getHighestDataRow')) {
        $maxRow = $ws->getHighestDataRow();
    } else {
        $emptyRun = 0;
        for ($r = $data_start; $r <= 50000; $r++) {
            $v = trim((string)($ws->getCell($col_sku . $r)->getValue() ?? ''));
            if ($v !== '') { $maxRow = $r; $emptyRun = 0; }
            else { if (++$emptyRun >= 50) break; }
        }
    }

    // 데이터 추출 (설정된 컬럼 매핑 사용)
    $excel_data = [];
    for ($row = $data_start; $row <= $maxRow; $row++) {
        $sku = trim((string)($ws->getCell($col_sku . $row)->getValue() ?? ''));
        if ($sku === '') continue;
        $excel_data[] = [
            'sku'           => $sku,
            'name_en'       => trim((string)($ws->getCell($col_name . $row)->getValue() ?? '')),
            'cost_price'    => floatval(str_replace(',', '', (string)($ws->getCell($col_cost . $row)->getValue() ?? 0))),
            'selling_price' => floatval(str_replace(',', '', (string)($ws->getCell($col_price . $row)->getValue() ?? 0))),
        ];
    }

    if (empty($excel_data)) {
        sendJson(['success' => false, 'message' => "유효한 데이터가 없습니다.\n• {$col_sku}열에 SKU/바코드가 있는지 확인하세요.\n• 헤더 행이 {$header_row}행인지 확인하세요."]);
    }

    // DB 저장
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();

    // 점포명 확인
    $store_name_stmt = $pdo->prepare("SELECT name FROM stores WHERE id = ?");
    $store_name_stmt->execute([$store_id]);
    $store_name_str = $store_name_stmt->fetchColumn() ?: "점포 ID {$store_id}";

    // 기본 카테고리
    $default_cat = (int)($pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn() ?: 1);

    $success = $errors = $new_products = $updated = 0;
    $log = [];

    foreach ($excel_data as $row) {
        $sku           = $row['sku'];
        $name_en       = $row['name_en'];
        $selling_price = $row['selling_price'];
        $cost_price    = $row['cost_price'];

        if (empty($name_en)) { $errors++; continue; }
        if ($selling_price < 0 || $cost_price < 0) { $errors++; continue; }

        try {
            $chk = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
            $chk->execute([$sku]);
            $product_id = $chk->fetchColumn();

            if (!$product_id) {
                $ins = $pdo->prepare("INSERT INTO products (sku, name_ko, name_en, category_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
                $ins->execute([$sku, $name_en, $name_en, $default_cat]);
                $product_id = (int)$pdo->lastInsertId();
                $new_products++;
            }

            $inv = $pdo->prepare("
                INSERT INTO inventory (product_id, store_id, selling_price, cost_price, quantity)
                VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price), cost_price = VALUES(cost_price)
            ");
            $inv->execute([$product_id, $store_id, $selling_price, $cost_price]);
            $updated++;
            $success++;
        } catch (Throwable $e) {
            $errors++;
            if (count($log) < 5) $log[] = "SKU {$sku}: " . $e->getMessage();
        }
    }

    if ($success > 0) {
        $pdo->commit();
        $col_info = "SKU:{$col_sku} / 상품명:{$col_name} / 원가:{$col_cost} / 판매가:{$col_price} / 헤더:{$header_row}행";
        $msg  = "✅ 업로드 완료! [{$store_name_str}]\n";
        $msg .= "• 전체 행: " . count($excel_data) . "개\n";
        $msg .= "• 신규 상품: {$new_products}개\n";
        $msg .= "• 가격 업데이트: {$updated}개\n";
        $msg .= "• 성공: {$success}개";
        if ($errors > 0) $msg .= "\n• 실패: {$errors}개 (이름/가격 없는 행 제외)";
        $msg .= "\n\n컬럼 설정: {$col_info}";
    } else {
        $pdo->rollBack();
        $msg  = "❌ 저장 실패 (실패: {$errors}개)\n";
        $msg .= "{$col_sku}열(SKU/바코드), {$col_name}열(상품명)이 올바른지 확인하세요.";
    }
    if (!empty($log)) $msg .= "\n\n오류 내역:\n" . implode("\n", $log);

    sendJson(['success' => $success > 0, 'message' => $msg]);

} catch (Throwable $e) {
    if ($cleanup && isset($tmpPath) && file_exists($tmpPath)) @unlink($tmpPath);
    error_log('pricing/ajax_import_master.php error: ' . $e->getMessage());
    sendJson(['success' => false, 'message' => '파일 처리 오류: ' . $e->getMessage()]);
}
