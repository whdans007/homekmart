<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 대용량 엑셀 파일 처리를 위해 메모리 제한 증가
ini_set('memory_limit', '512M');

$page_title = "POS 마스터 엑셀 임포트";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 상품관리 권한 체크
require_permission('product_management', 'login.php');

// 현재 로그인한 사용자의 점포 ID 사용 (header.php에서 설정됨)
$target_store_id = $current_store_id;

$message = '';
$excel_data = [];
$headers = [];
$save_result = '';
$preview_data = [];

// 세션에서 이전 저장 결과 가져오기
if (isset($_SESSION['import_result'])) {
    $save_result = $_SESSION['import_result'];
    unset($_SESSION['import_result']);
}

// 데이터 저장 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_data']) && isset($_POST['excel_data_json'])) {
    $excel_data_json = json_decode($_POST['excel_data_json'], true);

    if ($excel_data_json && is_array($excel_data_json)) {
        try {
            $conn = get_db_connection();
            $conn->autocommit(false); // 트랜잭션 시작

            $success_count = 0;
            $error_count = 0;
            $new_products = 0;
            $updated_prices = 0;
            $processed_items = [];

            // 기본 카테고리 ID 가져오기
            $default_category_stmt = $conn->prepare("SELECT id FROM categories ORDER BY id LIMIT 1");
            $default_category_stmt->execute();
            $default_category_result = $default_category_stmt->get_result();
            $default_category_id = $default_category_result->num_rows > 0 ? $default_category_result->fetch_assoc()['id'] : 1;
            $default_category_stmt->close();

            foreach ($excel_data_json as $index => $row) {
                // 연관 배열로 접근 (키 이름 사용)
                $sku = trim($row['sku'] ?? '');
                $name_en = trim($row['name_en'] ?? '');
                $cost_price = floatval($row['cost_price'] ?? 0);
                $selling_price = floatval($row['selling_price'] ?? 0);

                // 데이터 검증
                $validation_errors = [];
                if (empty($sku)) $validation_errors[] = "SKU 없음";
                if (empty($name_en)) $validation_errors[] = "상품명 없음";
                if ($cost_price < 0) $validation_errors[] = "원가가 음수";
                if ($selling_price < 0) $validation_errors[] = "판매가가 음수";
                // 원가, 판매가 없으면 0으로 처리 (검증 오류 아님)

                if (!empty($validation_errors)) {
                    $error_count++;
                    // 처음 5개 행의 오류는 자세히 기록
                    if ($error_count <= 5) {
                        $processed_items[] = "행 " . ($index + 2) . ": 필수 데이터 누락 (" . implode(", ", $validation_errors) . ")";
                        $processed_items[] = "   → 읽혀진 값: SKU='$sku', 상품명='$name_en', 원가=$cost_price, 판매가=$selling_price";
                    }
                    continue;
                }

                try {
                    // 1. 기존 상품 확인
                    $check_stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
                    $check_stmt->bind_param("s", $sku);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();

                    $product_id = null;

                    if ($check_result->num_rows > 0) {
                        // 기존 상품 (업데이트 아님 - 가격만 inventory에서 처리)
                        $existing = $check_result->fetch_assoc();
                        $product_id = $existing['id'];
                        $processed_items[] = "행 " . ($index + 2) . ": SKU {$sku} (기존 상품)";
                    } else {
                        // 새 상품 생성
                        $name_ko = $name_en; // 한글명이 없으면 영문명 사용

                        $insert_stmt = $conn->prepare("
                            INSERT INTO products (sku, name_ko, name_en, category_id, is_active, last_modified_by_user_id, created_at, updated_at)
                            VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())
                        ");
                        $insert_stmt->bind_param("sssii", $sku, $name_ko, $name_en, $default_category_id, $_SESSION['user_id']);
                        $insert_stmt->execute();
                        $product_id = $insert_stmt->insert_id;
                        $insert_stmt->close();

                        $new_products++;
                        $processed_items[] = "행 " . ($index + 2) . ": SKU {$sku} 신규 상품 생성";
                    }
                    $check_stmt->close();

                    // 2. 현재 점포 가격 정보 저장/업데이트
                    if ($product_id) {
                        $inventory_stmt = $conn->prepare("
                            INSERT INTO inventory (product_id, store_id, selling_price, cost_price, quantity)
                            VALUES (?, ?, ?, ?, 0)
                            ON DUPLICATE KEY UPDATE
                                selling_price = VALUES(selling_price),
                                cost_price = VALUES(cost_price)
                        ");
                        $inventory_stmt->bind_param("iidd", $product_id, $target_store_id, $selling_price, $cost_price);
                        $inventory_stmt->execute();
                        $inventory_stmt->close();

                        $updated_prices++;
                        $processed_items[] = "   → 가격 저장: 원가 " . number_format($cost_price, 2) . "원, 판매가 " . number_format($selling_price, 2) . "원";
                    }

                    $success_count++;

                } catch (Exception $e) {
                    $error_count++;
                    $processed_items[] = "행 " . ($index + 2) . ": 오류 - " . $e->getMessage();
                }
            }

            // 성공한 데이터가 있으면 커밋, 모두 실패하면 롤백
            if ($success_count > 0) {
                $conn->commit();
                $save_result = "✅ 데이터 저장 완료!\n";
                $save_result .= "• 신규 상품: {$new_products}개\n";
                $save_result .= "• 가격 업데이트: {$updated_prices}개\n";
                $save_result .= "• 성공: {$success_count}개";
                if ($error_count > 0) {
                    $save_result .= "\n• 실패: {$error_count}개 (오류 내용 아래 참고)";
                }
            } else {
                $conn->rollback();
                $save_result = "❌ 모든 데이터 저장에 실패했습니다. (실패: {$error_count}개)\n\n";
                $save_result .= "⚠️ 원인 확인:\n";
                $save_result .= "• A열(ITEMCODE/SKU): 데이터가 있는지 확인\n";
                $save_result .= "• B열(ITEMNAME/상품명): 값이 있는지 확인\n";
                $save_result .= "• C열(UNITPRICE/원가): 음수가 아닌지 확인\n";
                $save_result .= "• D열(SELLING_PRICE/판매가): 음수가 아닌지 확인\n\n";
                $save_result .= "아래 처리 내역에서 실패 이유를 확인하세요:";
            }

            if (!empty($processed_items)) {
                $save_result .= "\n\n처리 내역:\n" . implode("\n", $processed_items);
            }

            $_SESSION['import_result'] = $save_result;
            $conn->close();

        } catch (Exception $e) {
            $save_result = "데이터 저장 오류: " . $e->getMessage();
            $_SESSION['import_result'] = $save_result;
        }
    } else {
        $save_result = "저장할 데이터가 없습니다.";
        $_SESSION['import_result'] = $save_result;
    }

    // POST 요청 후 리다이렉트하여 새로고침 문제 방지
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// NAS 폴더 임포트 폴더 경로 정의 — PHP가 직접 생성하여 경로 불일치 방지
$nas_import_dir_raw = dirname(__DIR__) . '/uploads/pos_import';
if (!is_dir($nas_import_dir_raw)) {
    mkdir($nas_import_dir_raw, 0777, true);
}
$nas_import_dir = rtrim($nas_import_dir_raw, '/') . '/';

// SMB 경로 계산: /volume1/web/ → \\192.168.1.116\web\
$smb_hint = str_replace('/', '\\', preg_replace('#^/volume\d+/web/#', '\\\\\\\\192.168.1.116\\\\web\\\\', dirname(__DIR__)));
$smb_import_path = $smb_hint . '\\uploads\\pos_import\\';

// ── NAS 폴더 파일 처리 ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nas_file'])) {
    $nas_file = basename($_POST['nas_file']); // 경로 조작 방지
    $nas_file_path = $nas_import_dir . $nas_file;

    // 경로 안전 확인
    $real_path = realpath($nas_file_path);

    if (!$real_path || !file_exists($real_path)) {
        $message = "❌ 파일을 찾을 수 없습니다: {$nas_file}\n서버 경로: {$nas_file_path}\n폴더 내 파일 목록: " . implode(', ', array_map('basename', glob($nas_import_dir . '*') ?: []));
    } elseif (!is_readable($real_path)) {
        // 읽기 권한 없으면 chmod 시도 후 재확인
        @chmod($real_path, 0644);
        if (!is_readable($real_path)) {
            $message = "❌ 파일 읽기 권한이 없습니다. Synology NAS에서 파일 권한을 확인하세요.\n경로: {$real_path}";
        }
    }

    if (empty($message)) {
        $nas_file_path = $real_path; // realpath로 교체
        $file_extension = strtolower(pathinfo($nas_file_path, PATHINFO_EXTENSION));
        if (!in_array($file_extension, ['xls', 'xlsx', 'csv'])) {
            $message = "❌ xlsx, csv, xls 파일만 처리 가능합니다.";
        } else {
            try {
                require_once __DIR__ . '/../vendor/autoload.php';

                // ZipArchive 경로 문제 우회: PHP 임시 폴더로 복사 후 처리
                $temp_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_import_' . time() . '.' . $file_extension;
                if (!copy($nas_file_path, $temp_path)) {
                    throw new Exception("파일 복사 실패. 원본 경로: {$nas_file_path}");
                }
                $process_path = $temp_path;
                error_log("NAS POS 파일 처리 시작: {$nas_file} → temp: {$temp_path}");

                if ($file_extension === 'xlsx') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                    $reader->setReadDataOnly(true);
                } elseif ($file_extension === 'csv') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                } else {
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($process_path);
                }

                $spreadsheet = $reader->load($process_path);
                $worksheet  = $spreadsheet->getActiveSheet();
                $actualMaxRow = $worksheet->getHighestDataRow();

                $excel_data = [];
                $raw_data   = [];

                for ($row = 2; $row <= $actualMaxRow; $row++) {
                    $sku           = trim($worksheet->getCell('A' . $row)->getValue() ?? '');
                    $name_en       = trim($worksheet->getCell('B' . $row)->getValue() ?? '');
                    $cost_price    = floatval($worksheet->getCell('C' . $row)->getValue() ?? 0);
                    $selling_price = floatval($worksheet->getCell('D' . $row)->getValue() ?? 0);

                    if ($row <= 21) {
                        $raw_row = [];
                        for ($c = 0; $c < 20; $c++) {
                            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1);
                            $raw_row[$col] = trim((string)($worksheet->getCell($col . $row)->getValue() ?? ''));
                        }
                        $raw_data[] = $raw_row;
                    }

                    if (!empty($sku)) {
                        $excel_data[] = [
                            'sku'           => $sku,
                            'name_en'       => $name_en,
                            'selling_price' => $selling_price,
                            'cost_price'    => $cost_price,
                        ];
                    }
                }

                if (count($excel_data) > 0) {
                    $file_size_mb  = round(filesize($nas_file_path) / 1024 / 1024, 1);
                    $message       = "✅ NAS 파일 분석 완료: {$nas_file} ({$file_size_mb}MB) — " . count($excel_data) . "개 데이터 행";
                    $preview_data  = array_slice($excel_data, 0, 10);
                } else {
                    $message = "⚠️ 유효한 데이터가 없습니다. A열(SKU)을 확인하세요.";
                }
            } catch (Exception $e) {
                $message = "❌ 파일 처리 오류: " . $e->getMessage() . "\n원본 경로: {$nas_file_path}";
                error_log("NAS 파일 처리 오류: " . $e->getMessage());
            } finally {
                // 임시 파일 삭제
                if (!empty($temp_path) && file_exists($temp_path)) {
                    @unlink($temp_path);
                }
            }
        }
    }
}

// POST 요청인데 $_FILES가 비어있으면 파일 크기 초과로 판단
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_data']) && !isset($_POST['nas_file']) && empty($_FILES)) {
    $content_length = intval($_SERVER['CONTENT_LENGTH'] ?? 0);
    $post_max = ini_get('post_max_size');
    $message = "❌ 파일 크기 초과: 서버 허용 용량({$post_max})을 초과했습니다. 아래 'NAS 폴더 방식'을 사용하세요.";
}

// ── HTTP 업로드 파일 처리 ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $uploaded_file = $_FILES['excel_file'];

    if ($uploaded_file['error'] === UPLOAD_ERR_OK) {
        $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));

        if (in_array($file_extension, ['xls', 'xlsx', 'csv'])) {
            try {
                // PhpSpreadsheet 라이브러리 로드
                require_once __DIR__ . '/../vendor/autoload.php';

                error_log("POS Excel file processing started: " . $uploaded_file['name']);

                // 파일 형식별 리더 생성
                if ($file_extension === 'xlsx') {
                    // XML 엔티티 오류 방지
                    $libxml_disable_entity_loader = libxml_disable_entity_loader(true);

                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                    try {
                        if (method_exists($reader, 'setReadDataOnly')) {
                            $reader->setReadDataOnly(true);
                        }
                        if (method_exists($reader, 'setReadEmptyCells')) {
                            $reader->setReadEmptyCells(false);
                        }
                    } catch (Exception $e) {
                        error_log("XLSX reader configuration warning: " . $e->getMessage());
                    }

                    libxml_disable_entity_loader($libxml_disable_entity_loader);
                } elseif ($file_extension === 'csv') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                } else {
                    try {
                        // XML 엔티티 오류 방지
                        $libxml_disable_entity_loader = libxml_disable_entity_loader(true);

                        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($uploaded_file['tmp_name']);
                        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Xlsx) {
                            try {
                                if (method_exists($reader, 'setReadDataOnly')) {
                                    $reader->setReadDataOnly(true);
                                }
                                if (method_exists($reader, 'setReadEmptyCells')) {
                                    $reader->setReadEmptyCells(false);
                                }
                            } catch (Exception $e) {
                                error_log("XLS reader configuration warning: " . $e->getMessage());
                            }
                        }

                        libxml_disable_entity_loader($libxml_disable_entity_loader);
                    } catch (Exception $e) {
                        throw new Exception("XLS 파일 형식을 읽을 수 없습니다. 파일을 XLSX 형식으로 저장하여 다시 시도해주세요. 오류: " . $e->getMessage());
                    }
                }

                $spreadsheet = $reader->load($uploaded_file['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();

                error_log("Worksheet loaded successfully");

                // 데이터 읽기 - ANSI POS 형식 (A, C, E, J 컬럼)
                $columnLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

                // 최대 행 수 확인
                $actualMaxRow = 1;
                $maxCheckRow = 50000;

                try {
                    if (method_exists($worksheet, 'getHighestDataRow')) {
                        $actualMaxRow = $worksheet->getHighestDataRow();
                        error_log("Using getHighestDataRow: " . $actualMaxRow);
                    } else {
                        $emptyRowCount = 0;
                        for ($checkRow = 2; $checkRow <= $maxCheckRow; $checkRow++) {
                            $hasDataInRow = false;
                            for ($colIndex = 0; $colIndex < 10; $colIndex++) {
                                $colLetter = $columnLetters[$colIndex];
                                try {
                                    $cell = $worksheet->getCell($colLetter . $checkRow);
                                    $cellValue = $cell->getValue();
                                    if (!empty(trim($cellValue ?? ''))) {
                                        $hasDataInRow = true;
                                        break;
                                    }
                                } catch (Exception $e) {
                                    // 셀 접근 불가
                                }
                            }
                            if ($hasDataInRow) {
                                $actualMaxRow = $checkRow;
                                $emptyRowCount = 0;
                            } else {
                                $emptyRowCount++;
                                if ($emptyRowCount >= 50) {
                                    break;
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error finding max row: " . $e->getMessage());
                }

                error_log("Actual data found up to row: " . $actualMaxRow);

                // 실제 데이터 읽기 - ANSI POS 형식 (A, C, E, J 컬럼)
                $excel_data = [];
                $raw_data = []; // 원본 데이터 (모든 컬럼) - 디버깅용

                for ($row = 2; $row <= $actualMaxRow; $row++) {
                    try {
                        // 모든 컬럼 읽기 (디버깅용)
                        $raw_row = [];
                        for ($col = 0; $col < 20; $col++) {
                            $colLetter = $columnLetters[$col];
                            try {
                                $cellValue = $worksheet->getCell($colLetter . $row)->getValue() ?? '';
                                $raw_row[$colLetter] = trim((string)$cellValue);
                            } catch (Exception $e) {
                                $raw_row[$colLetter] = '';
                            }
                        }

                        // 처음 20행의 원본 데이터 저장
                        if ($row <= 21) {
                            $raw_data[] = $raw_row;
                        }

                        // POS 마스터 파일 컬럼 매핑
                        // A열: ITEMCODE(SKU), B열: ITEMNAME(상품명), C열: UNITPRICE(원가), D열: SELLING_PRICE(판매가)
                        $sku = trim($worksheet->getCell('A' . $row)->getValue() ?? '');
                        $name_en = trim($worksheet->getCell('B' . $row)->getValue() ?? '');
                        $cost_price = floatval($worksheet->getCell('C' . $row)->getValue() ?? 0);
                        $selling_price = floatval($worksheet->getCell('D' . $row)->getValue() ?? 0);

                        // SKU가 있으면 유효한 행으로 간주
                        if (!empty($sku)) {
                            $excel_data[] = [
                                'sku' => $sku,
                                'name_en' => $name_en,
                                'selling_price' => $selling_price,
                                'cost_price' => $cost_price
                            ];
                        }
                    } catch (Exception $e) {
                        error_log("Error reading row {$row}: " . $e->getMessage());
                    }
                }

                error_log("Total data rows found: " . count($excel_data));

                if (count($excel_data) > 0) {
                    $message = "✅ 파일 분석 완료: " . count($excel_data) . "개의 데이터 행을 찾았습니다.";

                    // 미리보기 준비 (처음 10개만)
                    $preview_data = array_slice($excel_data, 0, 10);
                } else {
                    $message = "⚠️ 유효한 데이터를 찾을 수 없습니다. 아래 '원본 데이터' 섹션에서 엑셀 파일의 구조를 확인하세요.";
                }

            } catch (Exception $e) {
                $message = "❌ 파일 처리 중 오류가 발생했습니다: " . $e->getMessage();
                error_log("File processing error: " . $e->getMessage());
            }
        } else {
            $message = "엑셀 파일(.xlsx) 또는 CSV 파일(.csv)만 업로드 가능합니다.";
        }
    } else {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => '업로드된 파일이 너무 큽니다.',
            UPLOAD_ERR_FORM_SIZE => '업로드된 파일이 너무 큽니다.',
            UPLOAD_ERR_PARTIAL => '파일이 부분적으로만 업로드되었습니다.',
            UPLOAD_ERR_NO_FILE => '파일이 업로드되지 않았습니다.',
            UPLOAD_ERR_NO_TMP_DIR => '임시 디렉토리가 없습니다.',
            UPLOAD_ERR_CANT_WRITE => '디스크에 파일을 쓸 수 없습니다.',
            UPLOAD_ERR_EXTENSION => '파일 업로드가 확장에 의해 중단되었습니다.'
        ];
        $message = "❌ 파일 업로드 실패: " . ($upload_errors[$uploaded_file['error']] ?? "알 수 없는 오류");
    }
}
?>

<!-- Page header -->
<div class="mb-8">
    <a href="product_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i>
        상품관리로 돌아가기
    </a>
    <h1 class="text-3xl font-bold text-gray-900">POS 마스터 엑셀 임포트</h1>
    <p class="mt-2 text-gray-600">POS 마스터 파일을 업로드하여 <strong><?php echo htmlspecialchars($current_store_name); ?></strong> 점포의 상품정보와 가격을 저장합니다.</p>
</div>

<!-- 메시지 표시 -->
<?php if (!empty($message)): ?>
    <div class="mb-6 p-4 rounded-md <?php echo strpos($message, '오류') !== false ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- 저장 결과 표시 -->
<?php if (!empty($save_result)): ?>
    <div class="mb-6 p-4 rounded-md <?php echo strpos($save_result, '오류') !== false || strpos($save_result, '실패') !== false ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-blue-50 text-blue-700 border border-blue-200'; ?>">
        <h4 class="font-medium mb-2">
            <i class="fas fa-database mr-2"></i>데이터 저장 결과
        </h4>
        <pre class="whitespace-pre-wrap text-sm"><?php echo htmlspecialchars($save_result); ?></pre>
    </div>
<?php endif; ?>

<!-- ★ NAS 폴더 방식 (대용량 파일 권장) -->
<div class="bg-blue-50 border border-blue-300 rounded-lg p-6 mb-6">
    <h2 class="text-lg font-semibold text-blue-900 mb-1">
        <i class="fas fa-folder-open mr-2"></i>NAS 폴더 직접 처리 <span class="text-sm font-normal text-blue-700">(대용량 파일 권장)</span>
    </h2>
    <p class="text-sm text-blue-800 mb-2">
        파일을 아래 NAS 공유 폴더에 복사한 후 처리하면 파일 크기 제한 없이 임포트할 수 있습니다.
    </p>
    <div class="mb-3 bg-yellow-50 border border-yellow-300 rounded p-3 text-sm">
        <div class="font-bold text-yellow-900 mb-1">Windows 탐색기에서 복사할 경로:</div>
        <code class="text-yellow-800 text-xs break-all"><?php echo htmlspecialchars($smb_import_path); ?></code>
        <button onclick="navigator.clipboard.writeText('<?php echo addslashes($smb_import_path); ?>')"
            class="ml-2 text-xs px-2 py-0.5 bg-yellow-200 rounded hover:bg-yellow-300">복사</button>
    </div>
    <div class="text-xs text-gray-500 mb-4 bg-white border border-gray-200 rounded p-2 space-y-0.5">
        <div>서버 경로: <code><?php echo htmlspecialchars($nas_import_dir); ?></code>
            <span class="<?php echo is_dir($nas_import_dir) ? 'text-green-600' : 'text-red-600'; ?>">
                <?php echo is_dir($nas_import_dir) ? '✅ 폴더 존재' : '❌ 폴더 없음'; ?>
            </span>
        </div>
    </div>

    <?php
    // NAS 폴더 파일 목록
    $nas_files = [];
    if (is_dir($nas_import_dir)) {
        foreach (glob($nas_import_dir . '*.{xlsx,xls,csv}', GLOB_BRACE) as $f) {
            $nas_files[] = [
                'name'     => basename($f),
                'size'     => round(filesize($f) / 1024 / 1024, 1),
                'modified' => date('Y-m-d H:i', filemtime($f)),
            ];
        }
        usort($nas_files, fn($a, $b) => strcmp($b['modified'], $a['modified']));
    }
    ?>

    <?php if (empty($nas_files)): ?>
        <div class="text-sm text-blue-700 bg-white border border-blue-200 rounded p-3">
            <i class="fas fa-info-circle mr-1"></i>
            폴더에 파일이 없습니다. 위 경로에 xlsx 파일을 복사하고 이 페이지를 새로고침하세요.
        </div>
    <?php else: ?>
        <form method="POST" class="space-y-2">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm bg-white border border-blue-200 rounded">
                    <thead class="bg-blue-100">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs text-blue-700">선택</th>
                            <th class="px-4 py-2 text-left text-xs text-blue-700">파일명</th>
                            <th class="px-4 py-2 text-right text-xs text-blue-700">크기</th>
                            <th class="px-4 py-2 text-left text-xs text-blue-700">수정일시</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($nas_files as $i => $nf): ?>
                        <tr class="<?php echo $i % 2 === 0 ? 'bg-white' : 'bg-blue-50'; ?> hover:bg-yellow-50">
                            <td class="px-4 py-2">
                                <input type="radio" name="nas_file" value="<?php echo htmlspecialchars($nf['name']); ?>"
                                    <?php echo $i === 0 ? 'checked' : ''; ?>>
                            </td>
                            <td class="px-4 py-2 font-medium text-gray-800"><?php echo htmlspecialchars($nf['name']); ?></td>
                            <td class="px-4 py-2 text-right text-gray-600"><?php echo $nf['size']; ?> MB</td>
                            <td class="px-4 py-2 text-gray-500"><?php echo $nf['modified']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit"
                class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700">
                <i class="fas fa-cog mr-2"></i>선택한 파일 분석하기
            </button>
        </form>
    <?php endif; ?>
</div>

<!-- 서버 업로드 설정 안내 -->
<div class="mb-4 bg-gray-50 border border-gray-200 rounded-lg p-3 text-xs text-gray-600">
    <i class="fas fa-server mr-1"></i>
    <strong>서버 업로드 설정:</strong>
    파일 최대 크기: <strong><?php echo ini_get('upload_max_filesize'); ?></strong> |
    POST 최대 크기: <strong><?php echo ini_get('post_max_size'); ?></strong> |
    메모리: <strong><?php echo ini_get('memory_limit'); ?></strong>
</div>

<!-- 파일 업로드 폼 -->
<div class="bg-white shadow rounded-lg p-6 mb-8">
    <h2 class="text-lg font-medium text-gray-900 mb-4">
        <i class="fas fa-upload mr-2"></i>1단계: POS 마스터 파일 업로드
    </h2>

    <form id="uploadForm" method="POST" enctype="multipart/form-data" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                파일 선택 (.xlsx, .csv)
            </label>
            <div class="mb-2 text-sm text-gray-600 bg-blue-50 border border-blue-200 rounded-lg p-3">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>엑셀 형식:</strong> A열(ITEMCODE/SKU) | B열(ITEMNAME/상품명) | C열(UNITPRICE/원가) | D열(SELLING_PRICE/판매가)
            </div>

            <!-- 커스텀 파일 선택 영역 -->
            <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-blue-400 transition-colors" id="dropZone">
                <i class="fas fa-file-excel text-4xl text-gray-400 mb-3"></i>
                <p class="text-sm text-gray-600 mb-3">파일을 여기에 드래그하거나 아래 버튼을 클릭하세요</p>
                <label for="excel_file" class="cursor-pointer inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    <i class="fas fa-folder-open mr-2"></i>
                    파일 선택하기
                </label>
                <input
                    type="file"
                    id="excel_file"
                    name="excel_file"
                    accept=".xlsx,.csv,.xls"
                    class="hidden"
                >
                <!-- 선택된 파일 표시 -->
                <div id="fileSelected" class="hidden mt-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                    <i class="fas fa-check-circle text-green-600 mr-2"></i>
                    <span id="fileName" class="text-sm font-medium text-green-700"></span>
                    <span id="fileSize" class="text-xs text-green-600 ml-2"></span>
                </div>
                <div id="noFileMsg" class="mt-2 text-xs text-gray-500">아직 파일이 선택되지 않았습니다.</div>
            </div>
        </div>

        <div>
            <button
                type="button"
                id="uploadBtn"
                onclick="submitUpload()"
                class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
                <i class="fas fa-upload mr-2"></i>
                파일 업로드 및 분석
            </button>
        </div>
    </form>
</div>

<!-- 원본 데이터 미리보기 (디버깅용) -->
<?php if (!empty($raw_data)): ?>
<div class="bg-white shadow rounded-lg overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-medium text-gray-900">
            <i class="fas fa-bug mr-2"></i>📋 원본 데이터 구조 확인 (모든 컬럼)
        </h2>
        <p class="mt-1 text-sm text-gray-500">
            엑셀 파일의 실제 데이터 위치를 확인하세요. 어느 컬럼에 데이터가 있는지 파악하는 데 도움됩니다.
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-gray-500">행</th>
                    <?php for ($i = 0; $i < 20; $i++): ?>
                    <th class="px-2 py-2 text-left font-medium text-gray-500 bg-<?php echo ($i == 0 || $i == 1 || $i == 2 || $i == 3) ? 'yellow-50' : 'gray-50'; ?>">
                        <?php echo $columnLetters[$i]; ?>
                    </th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach (array_slice($raw_data, 0, 10) as $idx => $row): ?>
                <tr class="<?php echo ($idx % 2 == 0) ? 'bg-white' : 'bg-gray-50'; ?>">
                    <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo ($idx + 2); ?></td>
                    <?php for ($i = 0; $i < 20; $i++): ?>
                    <td class="px-2 py-2 whitespace-nowrap text-xs <?php echo ($i == 0 || $i == 1 || $i == 2 || $i == 3) ? 'bg-yellow-100 font-medium' : ''; ?>">
                        <?php echo htmlspecialchars($row[$columnLetters[$i]] ?? ''); ?>
                    </td>
                    <?php endfor; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="px-6 py-3 bg-yellow-50 border-t border-yellow-200">
        <p class="text-xs text-yellow-800">
            <strong>💡 팁:</strong> 노란색 컬럼(A=ITEMCODE, B=ITEMNAME, C=UNITPRICE, D=SELLING_PRICE)에 데이터가 있어야 합니다.<br>
            A열(SKU)이 비어있는 행은 무시됩니다.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- 미리보기 테이블 -->
<?php if (!empty($preview_data)): ?>
<div class="bg-white shadow rounded-lg overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-medium text-gray-900">
            <i class="fas fa-table mr-2"></i>2단계: 데이터 미리보기 (처음 10개)
        </h2>
        <p class="mt-1 text-sm text-gray-500">
            <?php echo htmlspecialchars($current_store_name); ?> 점포에 저장될 상품정보 및 가격
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">행번호</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">상품명 (영문)</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">원가</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">판매가</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">상태</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($preview_data as $index => $row): ?>
                <?php
                    // DB 조회로 기존 상품 여부 확인
                    $is_new = true;
                    try {
                        $conn = get_db_connection();
                        $check = $conn->prepare("SELECT id FROM products WHERE sku = ?");
                        $check->bind_param("s", $row['sku']);
                        $check->execute();
                        if ($check->get_result()->num_rows > 0) {
                            $is_new = false;
                        }
                        $check->close();
                        $conn->close();
                    } catch (Exception $e) {
                        // 오류 무시
                    }

                    $status_class = $is_new ? 'bg-green-50 text-green-700' : 'bg-yellow-50 text-yellow-700';
                    $status_text = $is_new ? '🟢 신규 상품' : '🟡 기존 상품';
                ?>
                <tr class="<?php echo $index % 2 == 0 ? 'bg-white' : 'bg-gray-50'; ?>">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $index + 1; ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <code class="bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($row['sku']); ?></code>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <?php echo htmlspecialchars($row['name_en']); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                        <span class="font-medium"><?php echo number_format($row['cost_price'], 2); ?></span> 원
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                        <span class="font-medium"><?php echo number_format($row['selling_price'], 2); ?></span> 원
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo $status_class; ?>">
                            <?php echo $status_text; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (count($excel_data) > 10): ?>
    <p class="px-6 py-4 text-sm text-gray-600 bg-gray-50 border-t border-gray-200">
        <i class="fas fa-info-circle mr-1"></i>
        총 <?php echo count($excel_data); ?>개 데이터 중 처음 10개만 표시됩니다. 실제 저장 시에는 모든 데이터가 처리됩니다.
    </p>
    <?php endif; ?>
</div>

<!-- 저장 영역 -->
<div class="bg-green-50 border border-green-200 rounded-lg p-6">
    <h2 class="text-lg font-medium text-green-900 mb-4">
        <i class="fas fa-database mr-2"></i>3단계: <?php echo htmlspecialchars($current_store_name); ?> 가격정보 저장하기
    </h2>

    <div class="mb-4 bg-white border border-gray-300 rounded-lg p-4">
        <h5 class="font-medium text-gray-900 mb-3">
            <i class="fas fa-check-circle mr-2"></i>저장 처리 방식
        </h5>
        <div class="space-y-2 text-sm text-gray-700">
            <div class="flex items-start">
                <i class="fas fa-plus-circle text-green-600 mr-3 mt-0.5"></i>
                <div>
                    <strong>신규 상품:</strong> products 테이블에 새로 생성됨<br>
                    <span class="text-xs text-gray-600">(name_ko, name_en 모두 영문명으로 설정)</span>
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-pencil-alt text-blue-600 mr-3 mt-0.5"></i>
                <div>
                    <strong>모든 상품:</strong> <?php echo htmlspecialchars($current_store_name); ?> 점포의 inventory 테이블에 가격정보 저장<br>
                    <span class="text-xs text-gray-600">(원가, 판매가 저장 또는 업데이트)</span>
                </div>
            </div>
        </div>
    </div>

    <form id="saveDataForm" method="POST" class="space-y-4">
        <input type="hidden" name="save_data" value="1">
        <input type="hidden" id="excel_data_input" name="excel_data_json" value="">

        <div class="flex items-center justify-between p-4 bg-white border border-gray-300 rounded-lg">
            <div>
                <div class="text-sm text-gray-600">
                    <i class="fas fa-info-circle mr-1"></i>
                    <strong>저장 대상 점포:</strong> <?php echo htmlspecialchars($current_store_name); ?>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    총 <?php echo count($excel_data); ?>개 상품 데이터를 처리합니다.
                </div>
            </div>
            <button
                type="submit"
                onclick="return confirmSave()"
                class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
            >
                <i class="fas fa-save mr-2"></i>
                데이터 저장하기
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- 사용법 안내 -->
<div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
    <h3 class="text-lg font-medium text-blue-900 mb-4">
        <i class="fas fa-book mr-2"></i>사용법 안내
    </h3>
    <div class="text-sm text-blue-800 space-y-3">
        <div>
            <strong>📋 POS 마스터 파일 형식:</strong>
            <ul class="list-disc pl-6 mt-1 space-y-1">
                <li><strong>A열 (ITEMCODE):</strong> SKU/바코드 코드</li>
                <li><strong>B열 (ITEMNAME):</strong> 상품명</li>
                <li><strong>C열 (UNITPRICE):</strong> 원가(매입가)</li>
                <li><strong>D열 (SELLING_PRICE):</strong> 판매가</li>
                <li>1행은 헤더로 자동 건너뜀 (2행부터 데이터로 인식)</li>
            </ul>
        </div>
        <div>
            <strong>⚙️ 처리 방식:</strong>
            <ul class="list-disc pl-6 mt-1 space-y-1">
                <li><strong>신규 SKU:</strong> products 테이블에 새 상품 생성</li>
                <li><strong>기존 SKU:</strong> products는 유지, inventory만 가격 업데이트</li>
                <li><strong>점포:</strong> <?php echo htmlspecialchars($current_store_name); ?> 점포에만 저장</li>
                <li>모든 데이터를 한번에 처리 (개수 제한 없음)</li>
            </ul>
        </div>
        <div>
            <strong>✅ 저장 후 확인:</strong>
            <ul class="list-disc pl-6 mt-1 space-y-1">
                <li>상품관리에서 새 상품 등록 확인</li>
                <li><?php echo htmlspecialchars($current_store_name); ?> 점포 가격정보 확인</li>
                <li>같은 파일 재업로드 시 가격만 업데이트 (중복 상품 생성 없음)</li>
            </ul>
        </div>
    </div>
</div>

<script>
// 파일 선택 감지
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('excel_file');
    const fileSelected = document.getElementById('fileSelected');
    const noFileMsg = document.getElementById('noFileMsg');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    const dropZone = document.getElementById('dropZone');

    function handleFile(file) {
        if (!file) return;
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'csv', 'xls'].includes(ext)) {
            alert('xlsx, csv, xls 파일만 업로드 가능합니다.');
            fileInput.value = '';
            return;
        }
        fileName.textContent = file.name;
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        fileSize.textContent = '(' + sizeMB + ' MB)';
        fileSelected.classList.remove('hidden');
        noFileMsg.classList.add('hidden');
        dropZone.classList.add('border-green-400', 'bg-green-50');
        dropZone.classList.remove('border-gray-300');
    }

    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            handleFile(this.files[0]);
        }
    });

    // 드래그앤드롭 지원
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.classList.add('border-blue-400', 'bg-blue-50');
    });
    dropZone.addEventListener('dragleave', function() {
        dropZone.classList.remove('border-blue-400', 'bg-blue-50');
    });
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.classList.remove('border-blue-400', 'bg-blue-50');
        const file = e.dataTransfer.files[0];
        if (file) {
            // DataTransfer로 받은 파일을 input에 설정
            const dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
            handleFile(file);
        }
    });
});

function submitUpload() {
    const fileInput = document.getElementById('excel_file');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('파일을 먼저 선택해주세요.\n\n"파일 선택하기" 버튼을 클릭하여 xlsx 파일을 선택하세요.');
        return;
    }
    const btn = document.getElementById('uploadBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>업로드 중...';
    document.getElementById('uploadForm').submit();
}

// 엑셀 데이터를 JavaScript로 전달
<?php if (!empty($excel_data)): ?>
const excelData = <?php echo json_encode($excel_data); ?>; // 모든 데이터

document.addEventListener('DOMContentLoaded', function() {
    const dataInput = document.getElementById('excel_data_input');
    if (dataInput && excelData) {
        dataInput.value = JSON.stringify(excelData);
    }
});

function confirmSave() {
    const dataInput = document.getElementById('excel_data_input');

    if (!dataInput.value) {
        alert('저장할 엑셀 데이터가 없습니다. 먼저 엑셀 파일을 업로드해주세요.');
        return false;
    }

    const dataArray = JSON.parse(dataInput.value);
    const dataCount = dataArray.length;

    // 데이터 검증
    let validCount = 0;
    let newProductCount = 0;

    dataArray.forEach((row, index) => {
        const sku = (row.sku || '').toString().trim();
        const name = (row.name_en || '').toString().trim();
        const cost = parseFloat(row.cost_price || 0);
        const selling = parseFloat(row.selling_price || 0);

        if (sku && name && cost >= 0 && selling > 0) {
            validCount++;
        }
    });

    const storeName = <?php echo json_encode($current_store_name); ?>;
    const confirmMessage = `📊 ${storeName} 가격정보 저장 확인\n\n` +
        `• 총 상품: ${dataCount}개\n` +
        `• 저장할 상품: ${validCount}개\n` +
        `• 대상 점포: ${storeName}\n` +
        `• 저장 위치: products + inventory 테이블\n\n` +
        `처리 중 오류가 발생해도 유효한 데이터는 저장됩니다.\n\n` +
        `계속하시겠습니까?`;

    return confirm(confirmMessage);
}
<?php else: ?>
function confirmSave() {
    alert('저장할 엑셀 데이터가 없습니다. 먼저 엑셀 파일을 업로드해주세요.');
    return false;
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
