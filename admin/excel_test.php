<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$page_title = "엑셀 파일 테스트";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'><strong class='font-bold'>접근 불가:</strong><span class='block sm:inline'> 이 페이지에 접근할 권한이 없습니다.</span></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$message = '';
$excel_data = [];
$headers = [];
$save_result = '';

// 데이터 저장 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_data']) && isset($_POST['excel_data_json'])) {
    $excel_data_json = json_decode($_POST['excel_data_json'], true);
    $store_id = $_POST['target_store_id'] ?? $_SESSION['store_id'] ?? 1;
    
    // 점포 접근 권한 확인
    $current_user_id = $_SESSION['user_id'] ?? null;
    $current_user_role = $_SESSION['role'] ?? '';
    $user_store_id = null;
    
    // 사용자의 실제 점포 ID 조회
    try {
        $auth_conn = get_db_connection();
        $auth_stmt = $auth_conn->prepare("SELECT store_id FROM users WHERE id = ?");
        $auth_stmt->bind_param("i", $current_user_id);
        $auth_stmt->execute();
        $auth_result = $auth_stmt->get_result();
        if ($auth_result->num_rows > 0) {
            $user_store_id = $auth_result->fetch_assoc()['store_id'];
        }
        $auth_stmt->close();
        $auth_conn->close();
    } catch (Exception $e) {
        error_log("Auth check error: " . $e->getMessage());
    }
    
    if ($current_user_role !== 'super_admin' && $store_id != $user_store_id) {
        $save_result = "❌ 권한 오류: 선택한 점포에 대한 접근 권한이 없습니다. (선택한 점포: {$store_id}, 사용자 점포: {$user_store_id})";
    } else {
    
    if ($excel_data_json && is_array($excel_data_json)) {
        try {
            $conn = get_db_connection();
            $conn->autocommit(false); // 트랜잭션 시작
            
            $success_count = 0;
            $error_count = 0;
            $processed_items = [];
            
            // 상품 카테고리 ID 가져오기 (기본 카테고리 사용)
            $default_category_stmt = $conn->prepare("SELECT id FROM categories ORDER BY id LIMIT 1");
            $default_category_stmt->execute();
            $default_category_result = $default_category_stmt->get_result();
            $default_category_id = $default_category_result->num_rows > 0 ? $default_category_result->fetch_assoc()['id'] : 1;
            $default_category_stmt->close();
            
            foreach ($excel_data_json as $index => $row) {
                // 모든 데이터 처리 (제한 없음)
                
                $sku = trim($row[0] ?? '');
                $name_en = trim($row[1] ?? '');
                $cost_price = floatval($row[2] ?? 0);
                $selling_price = floatval($row[3] ?? 0);
                
                // 원가가 없거나 0일 경우 0으로 설정 (음수는 0으로 변환)
                if ($cost_price < 0) $cost_price = 0;
                
                // 판매가가 없거나 0일 경우 0으로 설정 (음수는 0으로 변환)
                if ($selling_price < 0) $selling_price = 0;
                
                // 상세한 데이터 검증 (원가, 판매가 검증 제거 - SKU, 상품명만 검사)
                $validation_errors = [];
                if (empty($sku)) $validation_errors[] = "SKU 없음";
                if (empty($name_en)) $validation_errors[] = "상품명 없음";
                
                if (!empty($validation_errors)) {
                    $error_count++;
                    $processed_items[] = "행 " . ($index + 1) . ": 필수 데이터 누락 (" . implode(", ", $validation_errors) . ")";
                    $processed_items[] = "   → 데이터: SKU='$sku', 상품명='$name_en', 원가=$cost_price, 판매가=$selling_price";
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
                        // 기존 상품 업데이트
                        $existing = $check_result->fetch_assoc();
                        $product_id = $existing['id'];
                        
                        $update_stmt = $conn->prepare("
                            UPDATE products 
                            SET name_en = ?, cost_price = ?, selling_price = ?, last_modified_by_user_id = ?
                            WHERE id = ?
                        ");
                        $update_stmt->bind_param("sddii", $name_en, $cost_price, $selling_price, $_SESSION['user_id'], $product_id);
                        $update_stmt->execute();
                        $update_stmt->close();
                        
                        $processed_items[] = "행 " . ($index + 1) . ": SKU {$sku} 기존 상품 업데이트";
                    } else {
                        // 새 상품 생성
                        $name_ko = $name_en; // 한글명이 없으면 영문명 사용
                        
                        $insert_stmt = $conn->prepare("
                            INSERT INTO products (sku, name_ko, name_en, category_id, cost_price, selling_price, is_active, last_modified_by_user_id, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
                        ");
                        $insert_stmt->bind_param("sssiddi", $sku, $name_ko, $name_en, $default_category_id, $cost_price, $selling_price, $_SESSION['user_id']);
                        $insert_stmt->execute();
                        $product_id = $insert_stmt->insert_id;
                        $insert_stmt->close();
                        
                        $processed_items[] = "행 " . ($index + 1) . ": SKU {$sku} 신규 상품 생성 (ID: {$product_id})";
                    }
                    $check_stmt->close();
                    
                    // 2. 점포별 가격 정보 저장/업데이트 (inventory 테이블)
                    if ($product_id) {
                        $inventory_stmt = $conn->prepare("
                            INSERT INTO inventory (product_id, store_id, selling_price, quantity, cost_price) 
                            VALUES (?, ?, ?, 0, ?)
                            ON DUPLICATE KEY UPDATE 
                                selling_price = VALUES(selling_price),
                                cost_price = VALUES(cost_price)
                        ");
                        $inventory_stmt->bind_param("iidd", $product_id, $store_id, $selling_price, $cost_price);
                        $inventory_stmt->execute();
                        $inventory_stmt->close();
                        
                        // 점포명 가져오기
                        $store_name_stmt = $conn->prepare("SELECT name FROM stores WHERE id = ?");
                        $store_name_stmt->bind_param("i", $store_id);
                        $store_name_stmt->execute();
                        $store_name_result = $store_name_stmt->get_result();
                        $store_name = $store_name_result->num_rows > 0 ? $store_name_result->fetch_assoc()['name'] : "점포 ID {$store_id}";
                        $store_name_stmt->close();
                        
                        $processed_items[] = "   → {$store_name}에 가격정보 저장 (원가: " . number_format($cost_price) . "원, 판매가: " . number_format($selling_price) . "원)";
                    }
                    
                    $success_count++;
                    
                } catch (Exception $e) {
                    $error_count++;
                    $processed_items[] = "행 " . ($index + 1) . ": 오류 - " . $e->getMessage();
                }
            }
            
            // 성공한 데이터가 있으면 커밋, 모두 실패하면 롤백
            if ($success_count > 0) {
                $conn->commit();
                if ($error_count == 0) {
                    $save_result = "✅ 모든 데이터를 성공적으로 저장했습니다! ({$success_count}개)";
                } else {
                    $save_result = "⚠️ 부분 성공: {$success_count}개 저장됨, {$error_count}개 실패";
                }
            } else {
                $conn->rollback();
                $save_result = "❌ 모든 데이터 저장에 실패했습니다. (실패: {$error_count}개)";
            }
            
            $save_result .= "\n\n처리 내역:\n" . implode("\n", $processed_items);
            $conn->close();
            
        } catch (Exception $e) {
            $save_result = "데이터 저장 오류: " . $e->getMessage();
        }
    } else {
        $save_result = "저장할 데이터가 없습니다.";
    }
    } // 권한 체크 블록 종료
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
                
                error_log("Excel file processing started: " . $uploaded_file['name']);
                
                // 파일 형식별 리더 생성
                if ($file_extension === 'xlsx') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                    // 안전한 읽기 설정 (메서드가 존재할 때만)
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
                } elseif ($file_extension === 'csv') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                    // CSV는 기본 설정으로 읽기 (메서드 호출 없이)
                } else {
                    // xls 파일의 경우 자동 감지 시도
                    try {
                        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($uploaded_file['tmp_name']);
                        // 설정 메서드가 존재할 때만 호출
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
                    } catch (Exception $e) {
                        throw new Exception("XLS 파일 형식을 읽을 수 없습니다. 파일을 XLSX 형식으로 저장하여 다시 시도해주세요. 오류: " . $e->getMessage());
                    }
                }
                
                $spreadsheet = $reader->load($uploaded_file['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();
                
                error_log("Worksheet loaded successfully");
                
                // 기본적인 방법으로 데이터 읽기 - 고정 범위 사용
                $headers = [];
                $columnLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
                
                // 헤더 읽기 (1행)
                foreach ($columnLetters as $col) {
                    try {
                        $cell = $worksheet->getCell($col . '1');
                        $cellValue = $cell->getValue();
                        // null 값을 안전하게 처리
                        $cellValue = $cellValue ?? '';
                        if (!empty(trim($cellValue))) {
                            $headers[] = trim($cellValue);
                        } else {
                            break; // 빈 헤더가 나오면 중단
                        }
                    } catch (Exception $e) {
                        break; // 셀에 접근할 수 없으면 중단
                    }
                }
                
                error_log("Headers found: " . json_encode($headers));
                
                if (count($headers) > 0) {
                    // 워크시트의 실제 최대 행 수 확인 (PhpSpreadsheet 방법 사용)
                    $actualMaxRow = 1;
                    $maxCheckRow = 50000; // 최대 50,000행까지 체크
                    
                    // PhpSpreadsheet의 메서드를 사용해서 최대 행 찾기
                    try {
                        if (method_exists($worksheet, 'getHighestDataRow')) {
                            $actualMaxRow = $worksheet->getHighestDataRow();
                            error_log("Using getHighestDataRow: " . $actualMaxRow);
                        } else {
                            // 수동으로 데이터가 있는 마지막 행 찾기 (더 넓은 범위로)
                            $emptyRowCount = 0;
                            for ($checkRow = 2; $checkRow <= $maxCheckRow; $checkRow++) {
                                $hasDataInRow = false;
                                // 모든 컬럼을 체크 (최대 20개 컬럼까지)
                                for ($colIndex = 0; $colIndex < min(count($headers), 20); $colIndex++) {
                                    $colLetter = $columnLetters[$colIndex];
                                    try {
                                        $cell = $worksheet->getCell($colLetter . $checkRow);
                                        $cellValue = $cell->getValue();
                                        $cellValue = $cellValue ?? '';
                                        if (!empty(trim($cellValue))) {
                                            $hasDataInRow = true;
                                            break;
                                        }
                                    } catch (Exception $e) {
                                        // 셀 접근 불가
                                    }
                                }
                                if ($hasDataInRow) {
                                    $actualMaxRow = $checkRow;
                                    $emptyRowCount = 0; // 데이터가 있으면 빈 행 카운트 리셋
                                } else {
                                    $emptyRowCount++;
                                    if ($emptyRowCount >= 50) {
                                        // 연속으로 50행이 비어있으면 중단
                                        break;
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error finding max row: " . $e->getMessage());
                    }
                    
                    error_log("Actual data found up to row: " . $actualMaxRow);
                    
                    // 표시할 컬럼 인덱스 (1-based를 0-based로 변환)
                    $displayColumns = [1, 2, 6, 7]; // 컬럼2, 컬럼3, 컬럼7, 컬럼8 (0-based: 1,2,6,7)
                    $displayHeaders = [];
                    
                    // 선택된 컬럼의 헤더만 가져오기
                    foreach ($displayColumns as $colIndex) {
                        if (isset($headers[$colIndex])) {
                            $displayHeaders[] = $headers[$colIndex] . " (컬럼" . ($colIndex + 1) . ")";
                        }
                    }
                    
                    // 전체 데이터 개수 계산
                    $totalDataCount = $actualMaxRow - 1; // 헤더 제외
                    
                    // 데이터 읽기 (최대 10행까지만 표시)
                    $displayLimit = 10;
                    $displayRowCount = 0;
                    
                    for ($row = 2; $row <= $actualMaxRow && $displayRowCount < $displayLimit; $row++) {
                        $rowData = [];
                        $hasData = false;
                        
                        // 선택된 컬럼만 읽기
                        foreach ($displayColumns as $colIndex) {
                            if ($colIndex < count($columnLetters)) {
                                $colLetter = $columnLetters[$colIndex];
                                try {
                                    $cell = $worksheet->getCell($colLetter . $row);
                                    $cellValue = $cell->getValue();
                                    // null 값을 안전하게 처리
                                    $cellValue = $cellValue ?? '';
                                    
                                    // 과학적 표기법 처리 - 숫자인 경우 포맷팅
                                    if (is_numeric($cellValue) && $cellValue != 0) {
                                        // 과학적 표기법을 일반 숫자로 변환
                                        if (strpos(strtoupper($cellValue), 'E') !== false) {
                                            $cellValue = number_format($cellValue, 0, '', '');
                                        }
                                    }
                                    
                                    $rowData[] = $cellValue;
                                    if (!empty(trim($cellValue))) {
                                        $hasData = true;
                                    }
                                } catch (Exception $e) {
                                    $rowData[] = '';
                                }
                            } else {
                                $rowData[] = '';
                            }
                        }
                        
                        if ($hasData) {
                            $excel_data[] = $rowData;
                            $displayRowCount++;
                        }
                    }
                    
                    // 표시용 헤더를 원래 헤더 배열에 덮어쓰기
                    $headers = $displayHeaders;
                }
                
                $totalRows = isset($totalDataCount) ? $totalDataCount : count($excel_data);
                $displayedRows = count($excel_data);
                $message = "엑셀 파일이 성공적으로 읽어졌습니다. 전체 데이터: {$totalRows}행, 표시된 데이터: {$displayedRows}행 (컬럼2, 컬럼3, 컬럼7, 컬럼8만 최대 10행까지 표시)";
                
            } catch (Exception $e) {
                $message = "엑셀 파일 읽기 오류: " . $e->getMessage();
            }
        } else {
            $message = "엑셀 파일(.xlsx) 또는 CSV 파일(.csv)만 업로드 가능합니다. XLS 파일은 XLSX로 변환 후 업로드해주세요.";
        }
    } else {
        $message = "파일 업로드 오류가 발생했습니다.";
    }
}
?>

<!-- Page header -->
<div class="mb-8 sm:flex sm:items-center sm:justify-between">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">엑셀 파일 테스트</h1>
        <p class="mt-2 text-sm text-gray-700">
            엑셀 파일을 업로드하여 특정 컬럼 데이터를 확인하는 테스트 페이지입니다.
        </p>
        <p class="mt-1 text-xs text-blue-600">
            <i class="fas fa-info-circle mr-1"></i>
            컬럼2, 컬럼3, 컬럼7, 컬럼8 데이터를 최대 10행까지 표시합니다.
        </p>
    </div>
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
    <h2 class="text-lg font-medium text-gray-900 mb-4">엑셀 파일 업로드</h2>
    
    <form method="POST" enctype="multipart/form-data" class="space-y-4">
        <div>
            <label for="excel_file" class="block text-sm font-medium text-gray-700 mb-2">
                파일 선택 (.xlsx, .csv)
            </label>
            <div class="mb-2 text-sm text-gray-600">
                <i class="fas fa-info-circle mr-1"></i>
                지원 형식: CSV (권장), XLSX | XML 엔티티 오류 발생 시 Excel에서 CSV로 저장하여 업로드하세요.
            </div>
            <div class="mb-2 text-xs text-orange-600">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                XLSX 파일에서 오류가 발생하면: Excel → 파일 → 다른 이름으로 저장 → CSV (쉼표로 분리) 선택
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

<!-- 데이터 표시 -->
<?php if (!empty($excel_data) && !empty($headers)): ?>
<div class="bg-white shadow rounded-lg overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-medium text-gray-900">엑셀 데이터 분석 결과 (최대 10행)</h2>
        <p class="mt-1 text-sm text-gray-500">
            상품 정보 연결 예제: SKU, 상품명(영문), 원가, 판매가 데이터 분석
        </p>
        <div class="mt-2 grid grid-cols-4 gap-4 text-xs">
            <div class="bg-blue-50 p-2 rounded">
                <strong>컬럼2:</strong> SKU<br>
                <span class="text-gray-600">상품 식별코드</span>
            </div>
            <div class="bg-green-50 p-2 rounded">
                <strong>컬럼3:</strong> 상품명(영문)<br>
                <span class="text-gray-600">Product Name</span>
            </div>
            <div class="bg-yellow-50 p-2 rounded">
                <strong>컬럼7:</strong> 원가<br>
                <span class="text-gray-600">Cost Price (0원 허용)</span>
            </div>
            <div class="bg-red-50 p-2 rounded">
                <strong>컬럼8:</strong> 판매가<br>
                <span class="text-gray-600">Selling Price (0원 허용)</span>
            </div>
        </div>
    </div>
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <!-- 헤더 -->
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">행번호</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider border border-gray-300">
                        SKU<br><span class="text-xs text-gray-400 normal-case">(컬럼2)</span>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-green-600 uppercase tracking-wider border border-gray-300">
                        상품명(영문)<br><span class="text-xs text-gray-400 normal-case">(컬럼3)</span>
                    </th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-yellow-600 uppercase tracking-wider border border-gray-300">
                        원가<br><span class="text-xs text-gray-400 normal-case">(컬럼7)</span>
                    </th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-red-600 uppercase tracking-wider border border-gray-300">
                        판매가<br><span class="text-xs text-gray-400 normal-case">(컬럼8)</span>
                    </th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-purple-600 uppercase tracking-wider border border-gray-300">
                        데이터베이스 연결 상태
                    </th>
                </tr>
            </thead>
            
            <!-- 데이터 -->
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($excel_data as $rowIndex => $row): ?>
                    <?php
                    // 데이터 매핑
                    $sku = isset($row[0]) ? trim($row[0]) : '';
                    $product_name_en = isset($row[1]) ? trim($row[1]) : '';
                    $cost_price = isset($row[2]) ? trim($row[2]) : '';
                    $selling_price = isset($row[3]) ? trim($row[3]) : '';
                    
                    // 데이터베이스에서 기존 상품 검색 (SKU 기준)
                    $existing_product = null;
                    $inventory_status = '';
                    
                    if (!empty($sku)) {
                        try {
                            // 새 데이터베이스 연결 생성 (기존 연결이 닫혔을 수 있음)
                            $search_conn = get_db_connection();
                            $search_stmt = $search_conn->prepare("SELECT id, name_ko, name_en, cost_price, selling_price FROM products WHERE sku = ? LIMIT 1");
                            $search_stmt->bind_param("s", $sku);
                            $search_stmt->execute();
                            $search_result = $search_stmt->get_result();
                            
                            if ($search_result->num_rows > 0) {
                                $existing_product = $search_result->fetch_assoc();
                                $inventory_status = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-check-circle mr-1"></i>기존 상품 발견
                                </span>';
                            } else {
                                $inventory_status = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    <i class="fas fa-times-circle mr-1"></i>신규 상품
                                </span>';
                            }
                            $search_stmt->close();
                            $search_conn->close();
                        } catch (Exception $e) {
                            $inventory_status = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                <i class="fas fa-exclamation-triangle mr-1"></i>검색 오류
                            </span>';
                        }
                    } else {
                        $inventory_status = '<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                            <i class="fas fa-question-circle mr-1"></i>SKU 없음
                        </span>';
                    }
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 border border-gray-300">
                            <?php echo $rowIndex + 1; ?>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-sm text-blue-700 font-medium border border-gray-300">
                            <?php echo empty($sku) ? '<span class="text-gray-400 italic">빈값</span>' : htmlspecialchars($sku); ?>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-sm text-green-700 border border-gray-300">
                            <?php echo empty($product_name_en) ? '<span class="text-gray-400 italic">빈값</span>' : htmlspecialchars($product_name_en); ?>
                            <?php if ($existing_product): ?>
                                <br><small class="text-gray-500">DB: <?php echo htmlspecialchars($existing_product['name_en'] ?? ''); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-sm text-yellow-700 text-right border border-gray-300">
                            <?php if (empty($cost_price)): ?>
                                <span class="text-gray-400 italic">빈값</span>
                            <?php else: ?>
                                <span class="font-medium"><?php echo number_format((float)$cost_price); ?>원</span>
                                <?php if ($existing_product): ?>
                                    <br><small class="text-gray-500">DB: <?php echo number_format((float)$existing_product['cost_price']); ?>원</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-sm text-red-700 text-right border border-gray-300">
                            <?php if (empty($selling_price)): ?>
                                <span class="text-gray-400 italic">빈값</span>
                            <?php else: ?>
                                <span class="font-medium"><?php echo number_format((float)$selling_price); ?>원</span>
                                <?php if ($existing_product): ?>
                                    <br><small class="text-gray-500">DB: <?php echo number_format((float)$existing_product['selling_price']); ?>원</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-center border border-gray-300">
                            <?php echo $inventory_status; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 연결 분석 요약 -->
<div class="mt-6 bg-white shadow rounded-lg p-6">
    <h3 class="text-lg font-medium text-gray-900 mb-4">
        <i class="fas fa-chart-line mr-2 text-blue-600"></i>
        전체 데이터 상품 연결 분석 요약
    </h3>
    <p class="text-sm text-gray-600 mb-4">
        <i class="fas fa-info-circle mr-1"></i>
        엑셀 파일의 모든 데이터를 분석하여 데이터베이스와 연결 상태를 확인합니다.
    </p>
    
    <?php
    // 전체 엑셀 데이터에 대한 연결 분석 통계 계산 (표시된 데이터뿐만 아니라 전체 데이터)
    $total_rows = isset($totalDataCount) ? $totalDataCount : count($excel_data);
    $existing_products = 0;
    $new_products = 0;
    $invalid_data = 0;
    $invalid_data_details = [];
    $price_differences = [];
    
    // 전체 엑셀 데이터를 다시 읽어서 분석 (표시 제한 없이)
    if (isset($actualMaxRow)) {
        for ($analysis_row = 2; $analysis_row <= $actualMaxRow; $analysis_row++) {
            try {
                // 전체 데이터 행에서 필요한 컬럼 읽기
                $sku_cell = $worksheet->getCell($columnLetters[1] . $analysis_row); // 컬럼2 (B)
                $name_cell = $worksheet->getCell($columnLetters[2] . $analysis_row); // 컬럼3 (C) 
                $cost_cell = $worksheet->getCell($columnLetters[6] . $analysis_row); // 컬럼7 (G)
                $selling_cell = $worksheet->getCell($columnLetters[7] . $analysis_row); // 컬럼8 (H)
                
                $sku = trim($sku_cell->getValue() ?? '');
                $name_en = trim($name_cell->getValue() ?? '');
                $excel_cost = (float)($cost_cell->getValue() ?? 0);
                $excel_selling = (float)($selling_cell->getValue() ?? 0);
                
                // 원가가 음수이면 0으로 설정
                if ($excel_cost < 0) $excel_cost = 0;
                
                // 판매가가 음수이면 0으로 설정
                if ($excel_selling < 0) $excel_selling = 0;
                
                // 데이터 유효성 검사 및 무효 사유 수집 (SKU, 상품명만 검사)
                $invalid_reasons = [];
                if (empty($sku)) $invalid_reasons[] = "SKU 없음";
                if (empty($name_en)) $invalid_reasons[] = "상품명 없음";
                
                if (!empty($invalid_reasons)) {
                    $invalid_data++;
                    $invalid_data_details[] = [
                        'row' => $analysis_row,
                        'sku' => $sku,
                        'name' => $name_en,
                        'cost' => $excel_cost,
                        'selling' => $excel_selling,
                        'reasons' => $invalid_reasons
                    ];
                    continue;
                }
                
                // 데이터베이스에서 기존 상품 검색
                $analysis_conn = get_db_connection();
                $search_stmt = $analysis_conn->prepare("SELECT cost_price, selling_price FROM products WHERE sku = ? LIMIT 1");
                $search_stmt->bind_param("s", $sku);
                $search_stmt->execute();
                $search_result = $search_stmt->get_result();
                
                if ($search_result->num_rows > 0) {
                    $existing_products++;
                    $db_product = $search_result->fetch_assoc();
                    $db_cost = (float)$db_product['cost_price'];
                    $db_selling = (float)$db_product['selling_price'];
                    
                    if ($excel_cost != $db_cost || $excel_selling != $db_selling) {
                        $price_differences[] = [
                            'sku' => $sku,
                            'excel_cost' => $excel_cost,
                            'db_cost' => $db_cost,
                            'excel_selling' => $excel_selling,
                            'db_selling' => $db_selling
                        ];
                    }
                } else {
                    $new_products++;
                }
                $search_stmt->close();
                $analysis_conn->close();
                
            } catch (Exception $e) {
                error_log("Full analysis error at row {$analysis_row}: " . $e->getMessage());
                $invalid_data++;
                continue;
            }
        }
    } else {
        // fallback: 표시된 데이터만 분석
        foreach ($excel_data as $row_index => $row) {
            $sku = isset($row[0]) ? trim($row[0]) : '';
            $name_en = isset($row[1]) ? trim($row[1]) : '';
            $excel_cost = isset($row[2]) ? (float)trim($row[2]) : 0;
            $excel_selling = isset($row[3]) ? (float)trim($row[3]) : 0;
            
            // 원가가 음수이면 0으로 설정
            if ($excel_cost < 0) $excel_cost = 0;
            
            // 판매가가 음수이면 0으로 설정
            if ($excel_selling < 0) $excel_selling = 0;
            
            // 데이터 유효성 검사 및 무효 사유 수집 (SKU, 상품명만 검사)
            $invalid_reasons = [];
            if (empty($sku)) $invalid_reasons[] = "SKU 없음";
            if (empty($name_en)) $invalid_reasons[] = "상품명 없음";
            
            if (!empty($invalid_reasons)) {
                $invalid_data++;
                $invalid_data_details[] = [
                    'row' => $row_index + 2, // 표시 데이터는 2행부터 시작
                    'sku' => $sku,
                    'name' => $name_en,
                    'cost' => $excel_cost,
                    'selling' => $excel_selling,
                    'reasons' => $invalid_reasons
                ];
                continue;
            }
            
            if (!empty($sku)) {
                try {
                    // 새 데이터베이스 연결 생성
                    $analysis_conn = get_db_connection();
                    $search_stmt = $analysis_conn->prepare("SELECT cost_price, selling_price FROM products WHERE sku = ? LIMIT 1");
                    $search_stmt->bind_param("s", $sku);
                    $search_stmt->execute();
                    $search_result = $search_stmt->get_result();
                    
                    if ($search_result->num_rows > 0) {
                        $existing_products++;
                        $db_product = $search_result->fetch_assoc();
                        $db_cost = (float)$db_product['cost_price'];
                        $db_selling = (float)$db_product['selling_price'];
                        
                        if ($excel_cost != $db_cost || $excel_selling != $db_selling) {
                            $price_differences[] = [
                                'sku' => $sku,
                                'excel_cost' => $excel_cost,
                                'db_cost' => $db_cost,
                                'excel_selling' => $excel_selling,
                                'db_selling' => $db_selling
                            ];
                        }
                    } else {
                        $new_products++;
                    }
                    $search_stmt->close();
                    $analysis_conn->close();
                } catch (Exception $e) {
                    error_log("Analysis connection error: " . $e->getMessage());
                    // 연결 오류가 있어도 계속 진행
                }
            }
        }
    }
    ?>
    
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-blue-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-chart-bar text-2xl text-blue-600"></i>
                </div>
                <div class="ml-4">
                    <h4 class="text-lg font-medium text-blue-900">전체 데이터</h4>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $total_rows; ?>개</p>
                    <p class="text-sm text-blue-700">엑셀 파일의 상품 데이터</p>
                </div>
            </div>
        </div>
        
        <div class="bg-green-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-double text-2xl text-green-600"></i>
                </div>
                <div class="ml-4">
                    <h4 class="text-lg font-medium text-green-900">기존 상품</h4>
                    <p class="text-2xl font-bold text-green-600"><?php echo $existing_products; ?>개</p>
                    <p class="text-sm text-green-700">데이터베이스에 이미 존재</p>
                </div>
            </div>
        </div>
        
        <div class="bg-red-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-plus-circle text-2xl text-red-600"></i>
                </div>
                <div class="ml-4">
                    <h4 class="text-lg font-medium text-red-900">신규 상품</h4>
                    <p class="text-2xl font-bold text-red-600"><?php echo $new_products; ?>개</p>
                    <p class="text-sm text-red-700">새로 추가될 상품</p>
                </div>
            </div>
        </div>
        
        <div class="bg-yellow-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-2xl text-yellow-600"></i>
                </div>
                <div class="ml-4">
                    <h4 class="text-lg font-medium text-yellow-900">무효 데이터</h4>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $invalid_data; ?>개</p>
                    <p class="text-sm text-yellow-700">누락/오류 데이터</p>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (count($invalid_data_details) > 0): ?>
    <div class="mt-6">
        <h4 class="text-md font-medium text-red-900 mb-3">
            <i class="fas fa-times-circle mr-2"></i>
            무효 데이터 상세 정보 (총 <?php echo count($invalid_data_details); ?>개)
        </h4>
        <div class="bg-red-50 rounded-lg p-4 max-h-64 overflow-y-auto">
            <div class="space-y-3">
                <?php foreach (array_slice($invalid_data_details, 0, 20) as $invalid): ?>
                <div class="bg-white border border-red-200 rounded-lg p-3">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center mb-2">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    행 <?php echo $invalid['row']; ?>
                                </span>
                                <span class="ml-2 text-sm font-medium text-red-900">
                                    무효 사유: <?php echo implode(', ', $invalid['reasons']); ?>
                                </span>
                            </div>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                                <div>
                                    <span class="text-gray-600">SKU:</span>
                                    <span class="font-medium <?php echo empty($invalid['sku']) ? 'text-red-600 italic' : 'text-blue-600'; ?>">
                                        <?php echo empty($invalid['sku']) ? '없음' : htmlspecialchars($invalid['sku']); ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-600">상품명:</span>
                                    <span class="font-medium <?php echo empty($invalid['name']) ? 'text-red-600 italic' : 'text-green-600'; ?>">
                                        <?php echo empty($invalid['name']) ? '없음' : htmlspecialchars(mb_substr($invalid['name'], 0, 15) . (mb_strlen($invalid['name']) > 15 ? '...' : '')); ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-600">원가:</span>
                                    <span class="font-medium text-yellow-600">
                                        <?php echo number_format($invalid['cost']); ?>원
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-600">판매가:</span>
                                    <span class="font-medium <?php echo $invalid['selling'] <= 0 ? 'text-red-600' : 'text-purple-600'; ?>">
                                        <?php echo $invalid['selling'] <= 0 ? '없음/0원' : number_format($invalid['selling']) . '원'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (count($invalid_data_details) > 20): ?>
                <div class="text-center py-2">
                    <span class="text-sm text-gray-600">
                        <i class="fas fa-info-circle mr-1"></i>
                        처음 20개만 표시됨. 총 <?php echo count($invalid_data_details); ?>개의 무효 데이터가 있습니다.
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 무효 데이터 유형별 통계 -->
        <div class="mt-4 bg-gray-50 rounded-lg p-4">
            <h5 class="font-medium text-gray-900 mb-3">
                <i class="fas fa-chart-pie mr-2"></i>무효 사유별 통계
            </h5>
            <?php
            $reason_stats = [];
            foreach ($invalid_data_details as $invalid) {
                foreach ($invalid['reasons'] as $reason) {
                    if (!isset($reason_stats[$reason])) {
                        $reason_stats[$reason] = 0;
                    }
                    $reason_stats[$reason]++;
                }
            }
            ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php foreach ($reason_stats as $reason => $count): ?>
                <div class="flex items-center justify-between p-2 bg-white rounded border">
                    <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($reason); ?></span>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                        <?php echo $count; ?>개
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (count($price_differences) > 0): ?>
    <div class="mt-6">
        <h4 class="text-md font-medium text-orange-900 mb-3">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            가격 차이가 있는 상품 (<?php echo count($price_differences); ?>개)
        </h4>
        <div class="bg-orange-50 rounded-lg p-4 max-h-40 overflow-y-auto">
            <?php foreach (array_slice($price_differences, 0, 10) as $diff): ?>
            <div class="mb-2 text-sm">
                <strong>SKU: <?php echo htmlspecialchars($diff['sku']); ?></strong><br>
                원가: 엑셀 <?php echo number_format($diff['excel_cost']); ?>원 ↔ DB <?php echo number_format($diff['db_cost']); ?>원<br>
                판매가: 엑셀 <?php echo number_format($diff['excel_selling']); ?>원 ↔ DB <?php echo number_format($diff['db_selling']); ?>원
            </div>
            <?php endforeach; ?>
            <?php if (count($price_differences) > 5): ?>
            <p class="text-xs text-gray-600">... 외 <?php echo count($price_differences) - 5; ?>개 더</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="mt-6 bg-gray-50 rounded-lg p-4">
        <h4 class="text-md font-medium text-gray-900 mb-2">다음 단계 안내</h4>
        <ul class="text-sm text-gray-700 space-y-1">
            <li><i class="fas fa-arrow-right mr-2 text-blue-500"></i>신규 상품을 상품 관리에서 등록</li>
            <li><i class="fas fa-arrow-right mr-2 text-green-500"></i>기존 상품의 가격 정보 업데이트</li>
            <li><i class="fas fa-arrow-right mr-2 text-purple-500"></i>점포별 가격 설정 (inventory 테이블 연동)</li>
            <li><i class="fas fa-arrow-right mr-2 text-orange-500"></i>매입 내역에 새로운 상품 추가</li>
        </ul>
    </div>
    
    <!-- 데이터 저장 영역 -->
    <div class="mt-6 bg-green-50 border border-green-200 rounded-lg p-6">
        <h4 class="text-md font-medium text-green-900 mb-4">
            <i class="fas fa-database mr-2"></i>실제 데이터베이스에 저장하기
        </h4>
        <p class="text-sm text-green-800 mb-4">
            위에 표시된 모든 데이터를 상품정보(products)와 점포별 가격정보(inventory)에 실제로 저장합니다.
        </p>
        
        <!-- 데이터 검증 미리보기 -->
        <?php if (!empty($excel_data)): ?>
        <div class="mb-4 bg-white border border-gray-300 rounded-lg p-4">
            <h5 class="font-medium text-gray-900 mb-3">
                <i class="fas fa-check-circle mr-2"></i>저장 전 데이터 검증
            </h5>
            <div class="space-y-2 text-sm">
                <?php 
                $preview_data = array_slice($excel_data, 0, 10);
                foreach ($preview_data as $index => $row): 
                    $sku = trim($row[0] ?? '');
                    $name_en = trim($row[1] ?? '');
                    $cost_price = floatval($row[2] ?? 0);
                    $selling_price = floatval($row[3] ?? 0);
                    
                    // 원가가 음수이면 0으로 설정
                    if ($cost_price < 0) $cost_price = 0;
                    
                    // 판매가가 음수이면 0으로 설정
                    if ($selling_price < 0) $selling_price = 0;
                    
                    $issues = [];
                    if (empty($sku)) $issues[] = "SKU 없음";
                    if (empty($name_en)) $issues[] = "상품명 없음";
                    // 원가, 판매가 검증 제거 - 0원도 허용
                    
                    $status_class = empty($issues) ? 'text-green-600' : 'text-red-600';
                    $status_icon = empty($issues) ? 'fa-check-circle' : 'fa-exclamation-triangle';
                    $status_text = empty($issues) ? '검증 통과' : '오류: ' . implode(', ', $issues);
                ?>
                <div class="flex items-center justify-between p-2 border rounded <?php echo empty($issues) ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'; ?>">
                    <div class="flex-1">
                        <span class="font-medium">행 <?php echo $index + 1; ?>:</span>
                        SKU: <?php echo $sku ?: '<span class="text-gray-400">없음</span>'; ?> |
                        상품명: <?php echo $name_en ?: '<span class="text-gray-400">없음</span>'; ?> |
                        원가: <?php echo $cost_price > 0 ? number_format($cost_price) . '원' : '<span class="text-gray-400">없음</span>'; ?> |
                        판매가: <?php echo $selling_price > 0 ? number_format($selling_price) . '원' : '<span class="text-gray-400">없음</span>'; ?>
                    </div>
                    <div class="<?php echo $status_class; ?>">
                        <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                        <?php echo $status_text; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <form id="saveDataForm" method="POST" class="space-y-4">
            <input type="hidden" name="save_data" value="1">
            <input type="hidden" id="excel_data_input" name="excel_data_json" value="">
            
            <!-- 점포 선택 -->
            <div>
                <label for="target_store_id" class="block text-sm font-medium text-gray-700 mb-2">
                    저장할 점포 선택
                </label>
                <?php
                // 헤더에서 이미 조회한 점포 정보 활용 ($current_store_id, $current_store_name)
                $available_stores = [];
                
                try {
                    $store_conn = get_db_connection();
                    
                    // 먼저 stores 테이블에 데이터가 있는지 확인
                    $count_stmt = $store_conn->prepare("SELECT COUNT(*) as total FROM stores");
                    $count_stmt->execute();
                    $count_result = $count_stmt->get_result();
                    $total_stores = $count_result->fetch_assoc()['total'];
                    $count_stmt->close();
                    error_log("Excel Test - Total stores in database: {$total_stores}");
                    
                    // 현재 사용자의 점포가 존재하지 않는다면 생성
                    if ($current_store_id && $current_store_name && $current_store_name !== '본점') {
                        $check_stmt = $store_conn->prepare("SELECT COUNT(*) as count FROM stores WHERE id = ?");
                        $check_stmt->bind_param("i", $current_store_id);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        $store_exists = $check_result->fetch_assoc()['count'];
                        $check_stmt->close();
                        
                        if ($store_exists == 0) {
                            $create_stmt = $store_conn->prepare("INSERT INTO stores (id, name) VALUES (?, ?)");
                            $create_stmt->bind_param("is", $current_store_id, $current_store_name);
                            $create_stmt->execute();
                            $create_stmt->close();
                            error_log("Excel Test - Created missing user store: {$current_store_name}");
                        }
                    }
                    
                    // 점포가 없다면 기본 점포들 생성 (super_admin만)
                    if ($total_stores == 0 && $_SESSION['role'] === 'super_admin') {
                        $stores_to_create = [
                            ['name' => '본점'],
                            ['name' => 'CLARK HILLS'],
                            ['name' => '강남점'],
                            ['name' => '홍대점']
                        ];
                        
                        foreach ($stores_to_create as $store) {
                            $create_stmt = $store_conn->prepare("INSERT INTO stores (name) VALUES (?)");
                            $create_stmt->bind_param("s", $store['name']);
                            $create_stmt->execute();
                            $create_stmt->close();
                        }
                        error_log("Excel Test - Created default stores: " . count($stores_to_create));
                    }
                    
                    // 점포 목록 가져오기 (권한에 따라)
                    if ($_SESSION['role'] === 'super_admin') {
                        // 최고관리자: 모든 점포
                        $store_stmt = $store_conn->prepare("SELECT id, name FROM stores ORDER BY name");
                        $store_stmt->execute();
                    } else {
                        // 일반 관리자: 자신의 점포만 (점포가 있는 경우)
                        if ($current_store_id) {
                            $store_stmt = $store_conn->prepare("SELECT id, name FROM stores WHERE id = ?");
                            $store_stmt->bind_param("i", $current_store_id);
                            $store_stmt->execute();
                        } else {
                            // 점포가 할당되지 않은 경우, 빈 결과
                            $store_stmt = $store_conn->prepare("SELECT id, name FROM stores WHERE 1=0");
                            $store_stmt->execute();
                        }
                    }
                    
                    $store_result = $store_stmt->get_result();
                    while ($store = $store_result->fetch_assoc()) {
                        $available_stores[] = $store;
                    }
                    $store_stmt->close();
                    
                    // 디버깅 정보 로깅
                    error_log("Excel Test - User: " . ($_SESSION['username'] ?? 'unknown') . ", Role: " . ($_SESSION['role'] ?? 'unknown') . ", Store ID: {$current_store_id}, Store Name: {$current_store_name}");
                    error_log("Excel Test - Available stores count: " . count($available_stores));
                    if (!empty($available_stores)) {
                        error_log("Excel Test - Available stores: " . json_encode(array_column($available_stores, 'name')));
                    } else {
                        error_log("Excel Test - No stores found! Check if stores table has data.");
                    }
                    
                    $store_conn->close();
                } catch (Exception $e) {
                    error_log("Excel Test - Store selection error: " . $e->getMessage());
                }
                ?>
                
                <!-- 현재 사용자 점포 정보 표시 -->
                <div class="mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center text-sm">
                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                        <span class="text-blue-800">
                            <strong>현재 로그인:</strong> 
                            <?php echo htmlspecialchars($_SESSION['full_name']); ?> 
                            (<?php echo $_SESSION['role'] === 'super_admin' ? '최고관리자' : ($_SESSION['role'] === 'admin' ? '관리자' : $_SESSION['role']); ?>)
                            <?php if ($current_store_name && $current_store_name !== '본점'): ?>
                            | <strong>소속 점포:</strong> <?php echo htmlspecialchars($current_store_name); ?>
                            <?php elseif ($_SESSION['role'] !== 'super_admin'): ?>
                            | <strong class="text-orange-600">소속 점포 없음</strong>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($_SESSION['role'] === 'super_admin'): ?>
                    <div class="mt-1 text-xs text-blue-600">
                        <i class="fas fa-crown mr-1"></i>
                        최고관리자 권한으로 모든 점포를 선택할 수 있습니다. (총 <?php echo count($available_stores); ?>개 점포)
                    </div>
                    <?php elseif (empty($available_stores)): ?>
                    <div class="mt-1 text-xs text-orange-600">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        소속 점포가 없어 데이터 저장이 불가능합니다. 관리자에게 문의하세요.
                    </div>
                    <?php endif; ?>
                </div>
                
                <select name="target_store_id" id="target_store_id" class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500" <?php echo empty($available_stores) ? 'disabled' : ''; ?>>
                    <?php if (empty($available_stores)): ?>
                        <?php if ($_SESSION['role'] === 'super_admin'): ?>
                            <option value="">활성 점포가 없습니다 - 점포 관리에서 점포를 먼저 생성하세요</option>
                        <?php else: ?>
                            <option value="">소속 점포가 없습니다 - 관리자에게 점포 할당을 요청하세요</option>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php foreach ($available_stores as $store): ?>
                            <?php 
                            $selected = ($current_store_id == $store['id']) ? 'selected' : '';
                            $store_info = htmlspecialchars($store['name']);
                            ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo $selected; ?>>
                                <?php echo $store_info; ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                
                <div class="mt-2 text-xs text-gray-600">
                    <i class="fas fa-map-marker-alt mr-1"></i>
                    선택된 점포의 inventory 테이블에 가격 정보가 저장됩니다.
                </div>
            </div>
            
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-600">
                    <i class="fas fa-info-circle mr-1"></i>
                    <strong>처리 방식:</strong> 기존 SKU는 업데이트, 신규 SKU는 생성
                </div>
                <button 
                    type="submit" 
                    onclick="return confirmSave()"
                    <?php echo empty($available_stores) ? 'disabled' : ''; ?>
                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white <?php echo empty($available_stores) ? 'bg-gray-400 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700'; ?> focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                >
                    <i class="fas fa-save mr-2"></i>
                    <?php echo empty($available_stores) ? '점포 없음 - 저장 불가' : '모든 데이터 저장하기'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 디버깅 정보 -->
<div class="mt-8 bg-gray-50 rounded-lg p-6">
    <h3 class="text-lg font-medium text-gray-900 mb-4">디버깅 정보</h3>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <h4 class="font-medium text-gray-700 mb-2">헤더 정보</h4>
            <div class="bg-white p-3 rounded border text-xs">
                <pre><?php echo json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
            </div>
        </div>
        
        <div>
            <h4 class="font-medium text-gray-700 mb-2">첫 번째 행 데이터</h4>
            <div class="bg-white p-3 rounded border text-xs">
                <pre><?php echo !empty($excel_data) ? json_encode($excel_data[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '데이터 없음'; ?></pre>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
// 엑셀 데이터를 JavaScript로 전달 - 전체 데이터 사용
<?php if (!empty($excel_data)): ?>
// 전체 엑셀 데이터를 다시 읽어서 JavaScript에 전달
let fullExcelData = [];
<?php if (isset($actualMaxRow) && isset($worksheet)): ?>
// 전체 데이터를 PHP에서 생성하여 JavaScript로 전달
<?php
$full_data_for_js = [];
for ($js_row = 2; $js_row <= $actualMaxRow; $js_row++) {
    try {
        $js_sku = trim($worksheet->getCell($columnLetters[1] . $js_row)->getValue() ?? '');
        $js_name = trim($worksheet->getCell($columnLetters[2] . $js_row)->getValue() ?? '');  
        $js_cost = (float)($worksheet->getCell($columnLetters[6] . $js_row)->getValue() ?? 0);
        $js_selling = (float)($worksheet->getCell($columnLetters[7] . $js_row)->getValue() ?? 0);
        
        // 원가가 음수이면 0으로 설정
        if ($js_cost < 0) $js_cost = 0;
        
        // 판매가가 음수이면 0으로 설정
        if ($js_selling < 0) $js_selling = 0;
        
        // 검증: SKU, 상품명만 확인 (원가, 판매가는 0도 허용)
        if (!empty($js_sku) && !empty($js_name)) {
            $full_data_for_js[] = [$js_sku, $js_name, $js_cost, $js_selling];
        }
    } catch (Exception $e) {
        // 셀 읽기 오류 무시
    }
}
?>
const excelData = <?php echo json_encode($full_data_for_js); ?>; // 전체 유효 데이터만
<?php else: ?>
const excelData = <?php echo json_encode($excel_data); ?>; // fallback: 표시된 데이터
<?php endif; ?>
console.log('Full Excel data loaded:', excelData.length, 'valid rows');

// 페이지 로드 시 데이터 설정
document.addEventListener('DOMContentLoaded', function() {
    const dataInput = document.getElementById('excel_data_input');
    if (dataInput && excelData) {
        dataInput.value = JSON.stringify(excelData);
        console.log('Data set for saving:', excelData.length, 'rows');
    }
});

function confirmSave() {
    const dataInput = document.getElementById('excel_data_input');
    const storeSelect = document.getElementById('target_store_id');
    
    if (!dataInput.value) {
        alert('저장할 엑셀 데이터가 없습니다. 먼저 엑셀 파일을 업로드해주세요.');
        return false;
    }
    
    const storeName = storeSelect.options[storeSelect.selectedIndex].text;
    const dataArray = JSON.parse(dataInput.value);
    const dataCount = dataArray.length;
    
    // 데이터 검증
    let validCount = 0;
    let invalidCount = 0;
    
    dataArray.forEach((row, index) => {
        const sku = (row[0] || '').toString().trim();
        const name = (row[1] || '').toString().trim();
        let cost = parseFloat(row[2] || 0);
        const selling = parseFloat(row[3] || 0);
        
        // 원가가 음수이면 0으로 설정
        if (cost < 0) cost = 0;
        
        // 판매가가 음수이면 0으로 설정
        if (selling < 0) selling = 0;
        
        // 검증: SKU, 상품명만 확인 (원가, 판매가는 0도 허용)
        if (sku && name) {
            validCount++;
        } else {
            invalidCount++;
        }
    });
    
    const confirmMessage = `📊 데이터 저장 확인\n\n` +
        `• 총 데이터: ${dataCount}개\n` +
        `• 검증 통과: ${validCount}개 (저장될 예정)\n` +
        `• 검증 실패: ${invalidCount}개 (건너뛸 예정)\n` +
        `• 대상 점포: ${storeName}\n` +
        `• 저장 위치: products 테이블 + inventory 테이블\n` +
        `• 처리 방식: 기존 SKU 업데이트 / 신규 SKU 생성\n\n` +
        `${invalidCount > 0 ? '⚠️ 일부 데이터에 오류가 있어도 유효한 데이터는 저장됩니다.\n\n' : ''}` +
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