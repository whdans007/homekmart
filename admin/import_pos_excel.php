<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 대용량 엑셀 파일 처리를 위해 메모리 제한 증가
ini_set('memory_limit', '512M');

$page_title = "ANSI POS 엑셀 임포트 (KIMS MALL)";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 상품관리 권한 체크
require_permission('product_management', 'login.php');

// KIMS MALL store_id
$kims_mall_store_id = 6;

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
                $sku = trim($row[0] ?? '');
                $name_en = trim($row[1] ?? '');
                $selling_price = floatval($row[2] ?? 0);
                $cost_price = floatval($row[3] ?? 0);

                // 데이터 검증
                $validation_errors = [];
                if (empty($sku)) $validation_errors[] = "SKU 없음";
                if (empty($name_en)) $validation_errors[] = "상품명 없음";
                if ($cost_price < 0) $validation_errors[] = "원가가 음수";
                if ($selling_price <= 0) $validation_errors[] = "판매가 없음 또는 0원";

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

                    // 2. KIMS MALL 점포 가격 정보 저장/업데이트
                    if ($product_id) {
                        $inventory_stmt = $conn->prepare("
                            INSERT INTO inventory (product_id, store_id, selling_price, cost_price, quantity)
                            VALUES (?, ?, ?, ?, 0)
                            ON DUPLICATE KEY UPDATE
                                selling_price = VALUES(selling_price),
                                cost_price = VALUES(cost_price)
                        ");
                        $inventory_stmt->bind_param("iidd", $product_id, $kims_mall_store_id, $selling_price, $cost_price);
                        $inventory_stmt->execute();
                        $inventory_stmt->close();

                        $updated_prices++;
                        $processed_items[] = "   → KIMS MALL 가격 저장: 원가 " . number_format($cost_price, 2) . "원, 판매가 " . number_format($selling_price, 2) . "원";
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
                $save_result .= "• A열(SKU): 데이터가 있는지 확인\n";
                $save_result .= "• C열(상품명): 영문명이 있는지 확인\n";
                $save_result .= "• E열(판매가): 0이 아닌 숫자인지 확인\n";
                $save_result .= "• J열(원가): 음수가 아닌지 확인\n\n";
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

// 엑셀 파일 처리
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

                        // 특정 컬럼에서 데이터 추출
                        $sku = trim($worksheet->getCell('A' . $row)->getValue() ?? '');
                        $name_en = trim($worksheet->getCell('C' . $row)->getValue() ?? '');
                        $selling_price = floatval($worksheet->getCell('E' . $row)->getValue() ?? 0);
                        $cost_price = floatval($worksheet->getCell('J' . $row)->getValue() ?? 0);

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
    <h1 class="text-3xl font-bold text-gray-900">ANSI POS 엑셀 임포트</h1>
    <p class="mt-2 text-gray-600">ANSI POS 시스템의 엑셀 파일을 업로드하여 KIMS MALL 점포의 상품정보와 가격을 저장합니다.</p>
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

<!-- 파일 업로드 폼 -->
<div class="bg-white shadow rounded-lg p-6 mb-8">
    <h2 class="text-lg font-medium text-gray-900 mb-4">
        <i class="fas fa-upload mr-2"></i>1단계: ANSI POS 엑셀 파일 업로드
    </h2>

    <form method="POST" enctype="multipart/form-data" class="space-y-4">
        <div>
            <label for="excel_file" class="block text-sm font-medium text-gray-700 mb-2">
                파일 선택 (.xlsx, .csv)
            </label>
            <div class="mb-2 text-sm text-gray-600 bg-blue-50 border border-blue-200 rounded-lg p-3">
                <i class="fas fa-info-circle mr-1"></i>
                <strong>엑셀 형식:</strong> A열(SKU) | C열(상품명 영문) | E열(판매가) | J열(원가)
            </div>
            <input
                type="file"
                id="excel_file"
                name="excel_file"
                accept=".xlsx,.csv,.xls"
                class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                required
            >
        </div>

        <div>
            <button
                type="submit"
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
                    <th class="px-2 py-2 text-left font-medium text-gray-500 bg-<?php echo ($i == 0 || $i == 2 || $i == 4 || $i == 9) ? 'yellow-50' : 'gray-50'; ?>">
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
                    <td class="px-2 py-2 whitespace-nowrap text-xs <?php echo ($i == 0 || $i == 2 || $i == 4 || $i == 9) ? 'bg-yellow-100 font-medium' : ''; ?>">
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
            <strong>💡 팁:</strong> 노란색 컬럼(A, C, E, J)에 데이터가 없으면 실패합니다.<br>
            만약 데이터가 다른 컬럼에 있다면, 엑셀 파일을 다시 저장하고 컬럼을 맞춰주세요.
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
            KIMS MALL (킴스몰)에 저장될 상품정보 및 가격
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
        <i class="fas fa-database mr-2"></i>3단계: KIMS MALL 가격정보 저장하기
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
                    <strong>모든 상품:</strong> KIMS MALL(킴스몰) 점포의 inventory 테이블에 가격정보 저장<br>
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
                    <strong>저장 대상 점포:</strong> KIMS MALL (킴스몰)
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
            <strong>📋 엑셀 파일 형식 (ANSI POS):</strong>
            <ul class="list-disc pl-6 mt-1 space-y-1">
                <li><strong>A열:</strong> SKU 코드 (예: 10001, ABC123)</li>
                <li><strong>C열:</strong> 상품명 (영문)</li>
                <li><strong>E열:</strong> 판매가</li>
                <li><strong>J열:</strong> 원가</li>
                <li>1행은 헤더로 자동 건너뜀 (2행부터 데이터로 인식)</li>
            </ul>
        </div>
        <div>
            <strong>⚙️ 처리 방식:</strong>
            <ul class="list-disc pl-6 mt-1 space-y-1">
                <li><strong>신규 SKU:</strong> products 테이블에 새 상품 생성</li>
                <li><strong>기존 SKU:</strong> products는 유지, inventory만 가격 업데이트</li>
                <li><strong>점포:</strong> KIMS MALL (킴스몰)에만 저장</li>
                <li>모든 데이터를 한번에 처리 (개수 제한 없음)</li>
            </ul>
        </div>
        <div>
            <strong>✅ 저장 후 확인:</strong>
            <ul class="list-disc pl-6 mt-1 space-y-1">
                <li>상품관리에서 새 상품 등록 확인</li>
                <li>KIMS MALL 점포 가격정보 확인</li>
                <li>같은 파일 재업로드 시 가격만 업데이트 (중복 상품 생성 없음)</li>
            </ul>
        </div>
    </div>
</div>

<script>
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

    const confirmMessage = `📊 KIMS MALL 가격정보 저장 확인\n\n` +
        `• 총 상품: ${dataCount}개\n` +
        `• 저장할 상품: ${validCount}개\n` +
        `• 대상 점포: KIMS MALL (킴스몰)\n` +
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
