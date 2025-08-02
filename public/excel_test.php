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
                <span class="text-gray-600">Cost Price</span>
            </div>
            <div class="bg-red-50 p-2 rounded">
                <strong>컬럼8:</strong> 판매가<br>
                <span class="text-gray-600">Selling Price</span>
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
    <h3 class="text-lg font-medium text-gray-900 mb-4">상품 연결 분석 요약</h3>
    
    <?php
    // 연결 분석 통계 계산
    $total_rows = count($excel_data);
    $existing_products = 0;
    $new_products = 0;
    $price_differences = [];
    
    foreach ($excel_data as $row) {
        $sku = isset($row[0]) ? trim($row[0]) : '';
        $excel_cost = isset($row[2]) ? (float)trim($row[2]) : 0;
        $excel_selling = isset($row[3]) ? (float)trim($row[3]) : 0;
        
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
    ?>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
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
    </div>
    
    <?php if (count($price_differences) > 0): ?>
    <div class="mt-6">
        <h4 class="text-md font-medium text-orange-900 mb-3">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            가격 차이가 있는 상품 (<?php echo count($price_differences); ?>개)
        </h4>
        <div class="bg-orange-50 rounded-lg p-4 max-h-40 overflow-y-auto">
            <?php foreach (array_slice($price_differences, 0, 5) as $diff): ?>
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

<?php require_once __DIR__ . '/partials/footer.php'; ?>