<?php
$page_title = "상품정보 가져오기 - HOME K MART";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 환경설정 권한 확인
require_permission('settings');

require_once __DIR__ . '/../config/db_config.php';

$success_message = '';
$error_message = '';
$import_results = [];

// 엑셀 업로드 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_products'])) {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $uploaded_file = $_FILES['excel_file'];
        $target_store_id = intval($_POST['target_store_id'] ?? 0);
        
        if (empty($target_store_id)) {
            $error_message = "저장할 점포를 선택해주세요.";
        } else {
            try {
                require_once __DIR__ . '/../vendor/autoload.php';
                
                $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['xlsx', 'xls', 'csv'];
                
                if (!in_array($file_extension, $allowed_extensions)) {
                    throw new Exception("지원하지 않는 파일 형식입니다. Excel 파일(.xlsx, .xls) 또는 CSV 파일(.csv)을 업로드해주세요.");
                }
                
                // 파일 리더 생성
                if ($file_extension === 'xlsx') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                } elseif ($file_extension === 'csv') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                } else {
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($uploaded_file['tmp_name']);
                }
                
                $spreadsheet = $reader->load($uploaded_file['tmp_name']);
                $worksheet = $spreadsheet->getActiveSheet();
                
                // 헤더 읽기
                $headers = [];
                $columnLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
                for ($i = 0; $i < 10; $i++) {
                    $cellValue = $worksheet->getCell($columnLetters[$i] . '1')->getValue();
                    if (!empty(trim($cellValue))) {
                        $headers[] = trim($cellValue);
                    } else {
                        break;
                    }
                }
                
                if (count($headers) < 3) {
                    throw new Exception("최소 3개의 헤더(SKU, 상품명, 원가)가 필요합니다.");
                }
                
                // 데이터 읽기
                $maxRow = $worksheet->getHighestDataRow();
                $excel_data = [];
                
                for ($row = 2; $row <= $maxRow; $row++) {
                    $rowData = [];
                    for ($col = 0; $col < count($headers); $col++) {
                        $cellValue = $worksheet->getCell($columnLetters[$col] . $row)->getValue();
                        $rowData[] = $cellValue ?? '';
                    }
                    
                    // 빈 행 건너뛰기
                    if (!empty(trim($rowData[0]))) {
                        $excel_data[] = $rowData;
                    }
                }
                
                if (empty($excel_data)) {
                    throw new Exception("업로드된 파일에 데이터가 없습니다.");
                }
                
                // 데이터베이스 저장 처리
                $conn = get_db_connection();
                $conn->autocommit(false); // 트랜잭션 시작
                
                $success_count = 0;
                $error_count = 0;
                $processed_items = [];
                
                // 기본 카테고리 확인
                $default_category_stmt = $conn->prepare("SELECT id FROM categories LIMIT 1");
                $default_category_stmt->execute();
                $default_category_result = $default_category_stmt->get_result();
                $default_category_id = $default_category_result->num_rows > 0 ? $default_category_result->fetch_assoc()['id'] : 1;
                $default_category_stmt->close();
                
                foreach ($excel_data as $index => $row) {
                    try {
                        $sku = trim($row[0] ?? '');
                        $name_en = trim($row[1] ?? '');
                        $cost_price = floatval($row[2] ?? 0);
                        $selling_price = floatval($row[3] ?? $cost_price * 1.3); // 기본 30% 마진
                        
                        // 데이터 검증
                        $validation_errors = [];
                        if (empty($sku)) $validation_errors[] = "SKU 없음";
                        if (empty($name_en)) $validation_errors[] = "상품명 없음";
                        if ($cost_price < 0) $validation_errors[] = "원가가 음수입니다";
                        if ($selling_price <= 0) $validation_errors[] = "판매가 없음 또는 0원";
                        
                        if (!empty($validation_errors)) {
                            $error_count++;
                            $processed_items[] = "행 " . ($index + 1) . ": 데이터 오류 (" . implode(", ", $validation_errors) . ")";
                            continue;
                        }
                        
                        // 기존 상품 확인
                        $check_stmt = $conn->prepare("SELECT id FROM products WHERE sku = ?");
                        $check_stmt->bind_param("s", $sku);
                        $check_stmt->execute();
                        $existing_product = $check_stmt->get_result()->fetch_assoc();
                        $check_stmt->close();
                        
                        if ($existing_product) {
                            // 기존 상품 업데이트
                            $product_id = $existing_product['id'];
                            $update_stmt = $conn->prepare("UPDATE products SET name_en = ? WHERE id = ?");
                            $update_stmt->bind_param("si", $name_en, $product_id);
                            $update_stmt->execute();
                            $update_stmt->close();
                            
                            $processed_items[] = "행 " . ($index + 1) . ": SKU {$sku} 기존 상품 업데이트";
                        } else {
                            // 새 상품 생성
                            $insert_stmt = $conn->prepare("INSERT INTO products (sku, name_en, name_kr, category_id) VALUES (?, ?, ?, ?)");
                            $name_kr = $name_en; // 한글명이 없으면 영문명 사용
                            $insert_stmt->bind_param("sssi", $sku, $name_en, $name_kr, $default_category_id);
                            $insert_stmt->execute();
                            $product_id = $conn->insert_id;
                            $insert_stmt->close();
                            
                            $processed_items[] = "행 " . ($index + 1) . ": SKU {$sku} 신규 상품 생성 (ID: {$product_id})";
                        }
                        
                        // 점포별 가격정보 저장/업데이트
                        $inventory_check_stmt = $conn->prepare("SELECT id FROM inventory WHERE product_id = ? AND store_id = ?");
                        $inventory_check_stmt->bind_param("ii", $product_id, $target_store_id);
                        $inventory_check_stmt->execute();
                        $existing_inventory = $inventory_check_stmt->get_result()->fetch_assoc();
                        $inventory_check_stmt->close();
                        
                        if ($existing_inventory) {
                            // 기존 가격정보 업데이트
                            $inventory_update_stmt = $conn->prepare("UPDATE inventory SET cost_price = ?, selling_price = ? WHERE id = ?");
                            $inventory_update_stmt->bind_param("ddi", $cost_price, $selling_price, $existing_inventory['id']);
                            $inventory_update_stmt->execute();
                            $inventory_update_stmt->close();
                        } else {
                            // 새 가격정보 생성
                            $inventory_insert_stmt = $conn->prepare("INSERT INTO inventory (product_id, store_id, cost_price, selling_price, quantity) VALUES (?, ?, ?, ?, 0)");
                            $inventory_insert_stmt->bind_param("iidd", $product_id, $target_store_id, $cost_price, $selling_price);
                            $inventory_insert_stmt->execute();
                            $inventory_insert_stmt->close();
                        }
                        
                        $processed_items[] = "   → 가격정보 저장 (원가: " . number_format($cost_price) . "원, 판매가: " . number_format($selling_price) . "원)";
                        $success_count++;
                        
                    } catch (Exception $e) {
                        $error_count++;
                        $processed_items[] = "행 " . ($index + 1) . ": 처리 오류 - " . $e->getMessage();
                    }
                }
                
                if ($error_count === 0) {
                    $conn->commit();
                    $success_message = "✅ 모든 데이터 저장 완료: {$success_count}개 처리됨";
                } else {
                    $conn->commit(); // 부분 성공도 커밋
                    if ($success_count > 0) {
                        $success_message = "⚠️ 부분 성공: {$success_count}개 저장됨, {$error_count}개 실패";
                    } else {
                        $error_message = "❌ 모든 데이터 처리 실패: {$error_count}개 오류";
                    }
                }
                
                $import_results = $processed_items;
                $conn->close();
                
            } catch (Exception $e) {
                $error_message = "파일 처리 중 오류가 발생했습니다: " . $e->getMessage();
            }
        }
    } else {
        $error_message = "파일 업로드에 실패했습니다.";
    }
}

// 점포 목록 가져오기
$available_stores = [];
try {
    $store_conn = get_db_connection();
    if ($_SESSION['role'] === 'super_admin') {
        $store_stmt = $store_conn->prepare("SELECT id, name FROM stores ORDER BY name");
        $store_stmt->execute();
    } else {
        if ($current_store_id) {
            $store_stmt = $store_conn->prepare("SELECT id, name FROM stores WHERE id = ?");
            $store_stmt->bind_param("i", $current_store_id);
            $store_stmt->execute();
        } else {
            $store_stmt = $store_conn->prepare("SELECT id, name FROM stores WHERE 1=0");
            $store_stmt->execute();
        }
    }
    
    $store_result = $store_stmt->get_result();
    while ($store = $store_result->fetch_assoc()) {
        $available_stores[] = $store;
    }
    $store_stmt->close();
    $store_conn->close();
} catch (Exception $e) {
    error_log("Store selection error: " . $e->getMessage());
}
?>

<!-- Page header -->
<div class="mb-8">
    <a href="settings.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i>
        환경설정으로 돌아가기
    </a>
    <h1 class="text-3xl font-bold text-gray-900">상품정보 가져오기</h1>
    <p class="mt-2 text-gray-600">Excel 파일에서 상품정보를 가져와 데이터베이스에 저장합니다.</p>
</div>

<!-- 알림 메시지 -->
<?php if (!empty($success_message)): ?>
<div class="mb-6 rounded-md bg-green-50 p-4">
    <div class="flex">
        <div class="flex-shrink-0">
            <i class="fas fa-check-circle text-green-400"></i>
        </div>
        <div class="ml-3">
            <p class="text-sm font-medium text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
<div class="mb-6 rounded-md bg-red-50 p-4">
    <div class="flex">
        <div class="flex-shrink-0">
            <i class="fas fa-times-circle text-red-400"></i>
        </div>
        <div class="ml-3">
            <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 파일 업로드 폼 -->
<div class="bg-white shadow sm:rounded-lg">
    <div class="px-4 py-5 sm:p-6">
        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">
            <i class="fas fa-upload mr-2"></i>Excel 파일 업로드
        </h3>
        
        <form action="product_import.php" method="post" enctype="multipart/form-data" class="space-y-6">
            <!-- 파일 선택 -->
            <div>
                <label for="excel_file" class="block text-sm font-medium text-gray-700 mb-2">
                    상품정보 Excel 파일
                </label>
                <input type="file" 
                       id="excel_file" 
                       name="excel_file" 
                       accept=".xlsx,.xls,.csv" 
                       required
                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                <p class="mt-2 text-xs text-gray-500">지원 형식: .xlsx, .xls, .csv (최대 50MB)</p>
            </div>
            
            <!-- 점포 선택 -->
            <div>
                <label for="target_store_id" class="block text-sm font-medium text-gray-700 mb-2">
                    저장할 점포 선택
                </label>
                <select name="target_store_id" id="target_store_id" required class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                    <option value="">-- 점포 선택 --</option>
                    <?php foreach ($available_stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo ($current_store_id == $store['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($store['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- 업로드 버튼 -->
            <div class="flex justify-end">
                <button type="submit" 
                        name="import_products"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-download mr-2"></i>
                    상품정보 가져오기
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 처리 결과 -->
<?php if (!empty($import_results)): ?>
<div class="mt-8 bg-white shadow sm:rounded-lg">
    <div class="px-4 py-5 sm:p-6">
        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">
            <i class="fas fa-list mr-2"></i>처리 결과
        </h3>
        <div class="bg-gray-50 rounded-lg p-4 max-h-96 overflow-y-auto">
            <?php foreach ($import_results as $result): ?>
                <div class="text-sm text-gray-700 mb-1 font-mono"><?php echo htmlspecialchars($result); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 사용법 안내 -->
<div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
    <h3 class="text-lg font-medium text-blue-900 mb-4">
        <i class="fas fa-info-circle mr-2"></i>사용법 안내
    </h3>
    <div class="text-sm text-blue-800 space-y-2">
        <p><strong>Excel 파일 형식:</strong></p>
        <ul class="list-disc pl-5 space-y-1">
            <li>1행: 헤더 (SKU, 상품명, 원가, 판매가, ...)</li>
            <li>2행부터: 실제 데이터</li>
            <li>필수 컬럼: SKU(A열), 상품명(B열), 원가(C열)</li>
            <li>판매가(D열)가 없으면 원가 × 1.3 자동 계산</li>
        </ul>
        <p><strong>처리 방식:</strong></p>
        <ul class="list-disc pl-5 space-y-1">
            <li>기존 SKU: 상품정보 업데이트 + 점포별 가격 업데이트</li>
            <li>신규 SKU: 새 상품 생성 + 점포별 가격 생성</li>
            <li>모든 데이터를 한번에 처리 (개수 제한 없음)</li>
        </ul>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>