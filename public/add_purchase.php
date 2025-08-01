<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'><strong class='font-bold'>접근 불가:</strong><span class='block sm:inline'> 이 페이지에 접근할 권한이 없습니다.</span></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();

// 현재 로그인된 사용자의 점포 정보 조회
$store_name = '미지정';
$user_store_id = null;
if (!empty($_SESSION['user_id'])) {
    $user_stmt = $conn->prepare("SELECT s.name as store_name, s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_row = $user_result->fetch_assoc()) {
        $store_name = $user_row['store_name'] ?? '미지정';
        $user_store_id = $user_row['store_id'];
    }
    $user_stmt->close();
}

$suppliers_result = $conn->query("SELECT id, name FROM suppliers ORDER BY name");
$message = '';

// 기존 매입을 수정하는 경우 데이터 로드
$edit_purchase_id = $_GET['edit_purchase_id'] ?? null;
$existing_purchase = null;
$existing_items = [];
$is_edit_mode = false;

if ($edit_purchase_id) {
    $is_edit_mode = true;
    
    // 기존 매입 정보 조회
    $purchase_stmt = $conn->prepare("SELECT p.*, s.name as supplier_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id WHERE p.purchase_id = ?");
    $purchase_stmt->bind_param("i", $edit_purchase_id);
    $purchase_stmt->execute();
    $purchase_result = $purchase_stmt->get_result();
    $existing_purchase = $purchase_result->fetch_assoc();
    $purchase_stmt->close();
    
    if ($existing_purchase) {
        // 기존 매입 상품들 조회
        $items_stmt = $conn->prepare("
            SELECT pi.*, pr.name_ko as product_name, pr.name_en as product_name_en, pr.sku, pr.barcode, pr.pieces_per_box
            FROM purchase_items pi 
            JOIN products pr ON pi.product_id = pr.id 
            WHERE pi.purchase_id = ?
        ");
        $items_stmt->bind_param("i", $edit_purchase_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        
        while ($item = $items_result->fetch_assoc()) {
            $existing_items[] = $item;
        }
        $items_stmt->close();
    }
}

$page_title = $is_edit_mode ? "매입 내역 수정 - 상품 추가" : "신규 매입 등록";
require_once __DIR__ . '/partials/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = $_POST['supplier_id'];
    $purchase_date = $_POST['purchase_date'];
    $items = $_POST['items'] ?? [];
    $total_items = 0;
    $total_amount = 0;
    $edit_purchase_id = $_POST['edit_purchase_id'] ?? null;

    if (empty($supplier_id) || empty($purchase_date) || empty($items)) {
        $message = "거래처, 매입 날짜, 그리고 최소 하나 이상의 품목을 입력해야 합니다.";
    } else {
        $conn->begin_transaction();
        try {
            // 새로운 상품들만 처리 (기존 상품 제외)
            $new_items = [];
            foreach ($items as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['unit_price']) && empty($item['existing_item_id'])) {
                    $new_items[] = $item;
                    $total_items += (int)$item['quantity'];
                    $total_amount += (int)$item['quantity'] * (float)$item['unit_price'];
                }
            }

            if ($edit_purchase_id && !empty($new_items)) {
                // 기존 매입에 상품 추가
                $purchase_id = $edit_purchase_id;
            } else if (!$edit_purchase_id) {
                // 새로운 매입 생성
                foreach ($items as $item) {
                    if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                        $total_items += (int)$item['quantity'];
                        $total_amount += (int)$item['quantity'] * (float)$item['unit_price'];
                    }
                }
                
                $stmt = $conn->prepare("INSERT INTO purchases (supplier_id, purchase_date, total_amount, total_items) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isdi", $supplier_id, $purchase_date, $total_amount, $total_items);
                $stmt->execute();
                $purchase_id = $stmt->insert_id;
                $stmt->close();
                $new_items = $items;
            } else {
                throw new Exception("추가할 새로운 상품이 없습니다.");
            }

            // 매입 상세 데이터 저장 및 재고 업데이트
            $stmt_item = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, purchase_type, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
            
            foreach ($new_items as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                    // 데이터 검증 및 정제
                    $purchase_type = trim($item['purchase_type'] ?? 'box');
                    if (!in_array($purchase_type, ['box', 'piece'])) {
                        $purchase_type = 'box'; // 기본값으로 설정
                    }
                    
                    // 1. 매입 상세 데이터 저장
                    $stmt_item->bind_param("iisis", $purchase_id, $item['product_id'], $purchase_type, $item['quantity'], $item['unit_price']);
                    if (!$stmt_item->execute()) {
                        throw new Exception("매입 상세 저장 실패: " . $stmt_item->error . " - purchase_type: '$purchase_type'");
                    }
                    
                    // 2. 실제 입고 수량 계산 (박스/낱개 구분)
                    $actual_quantity = (int)$item['quantity'];
                    if ($purchase_type === 'box') {
                        // 박스 매입인 경우 pieces_per_box를 곱해서 낱개 수량 계산
                        $pieces_stmt = $conn->prepare("SELECT pieces_per_box FROM products WHERE id = ?");
                        $pieces_stmt->bind_param("i", $item['product_id']);
                        $pieces_stmt->execute();
                        $pieces_result = $pieces_stmt->get_result();
                        if ($pieces_row = $pieces_result->fetch_assoc()) {
                            $pieces_per_box = $pieces_row['pieces_per_box'] ?? 1;
                            $actual_quantity = (int)$item['quantity'] * $pieces_per_box;
                        }
                        $pieces_stmt->close();
                    }
                    
                    // 3. inventory 테이블 업데이트 (사용자 점포에만 적용)
                    if ($user_store_id) {
                        // 기존 재고 레코드 확인
                        $inv_check_stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND store_id = ?");
                        $inv_check_stmt->bind_param("ii", $item['product_id'], $user_store_id);
                        $inv_check_stmt->execute();
                        $inv_result = $inv_check_stmt->get_result();
                        
                        if ($inv_row = $inv_result->fetch_assoc()) {
                            // 기존 재고 업데이트
                            $new_quantity = $inv_row['quantity'] + $actual_quantity;
                            $inv_update_stmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
                            $inv_update_stmt->bind_param("ii", $new_quantity, $inv_row['id']);
                            $inv_update_stmt->execute();
                            $inv_update_stmt->close();
                            $inventory_id = $inv_row['id'];
                        } else {
                            // 새로운 재고 레코드 생성
                            $inv_insert_stmt = $conn->prepare("INSERT INTO inventory (product_id, store_id, quantity) VALUES (?, ?, ?)");
                            $inv_insert_stmt->bind_param("iii", $item['product_id'], $user_store_id, $actual_quantity);
                            $inv_insert_stmt->execute();
                            $inventory_id = $inv_insert_stmt->insert_id;
                            $inv_insert_stmt->close();
                        }
                        $inv_check_stmt->close();
                        
                        // 4. inventory_transactions 로그 기록
                        $transaction_stmt = $conn->prepare("INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks) VALUES (?, ?, '입고', ?, ?)");
                        $remarks = "매입 등록 (Purchase ID: {$purchase_id})";
                        
                        
                        $transaction_stmt->bind_param("iiis", $inventory_id, $_SESSION['user_id'], $actual_quantity, $remarks);
                        if (!$transaction_stmt->execute()) {
                            throw new Exception("재고 거래 로그 저장 실패: " . $transaction_stmt->error);
                        }
                        $transaction_stmt->close();
                    }
                }
            }
            $stmt_item->close();

            // 기존 매입에 상품을 추가한 경우 총계 업데이트
            if ($edit_purchase_id) {
                $update_stmt = $conn->prepare("
                    UPDATE purchases SET 
                        total_amount = (SELECT SUM(quantity * unit_price) FROM purchase_items WHERE purchase_id = ?),
                        total_items = (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = ?)
                    WHERE purchase_id = ?
                ");
                $update_stmt->bind_param("iii", $purchase_id, $purchase_id, $purchase_id);
                $update_stmt->execute();
                $update_stmt->close();
            }

            $conn->commit();
            
            if ($edit_purchase_id) {
                $_SESSION['flash'] = ['type' => 'success', 'message' => '매입 내역에 새로운 상품이 성공적으로 추가되었습니다.'];
                header("Location: edit_purchase.php?id={$purchase_id}");
            } else {
                $_SESSION['flash'] = ['type' => 'success', 'message' => '매입 내역이 성공적으로 등록되고 재고에 반영되었습니다.'];
                header('Location: purchase_management.php');
            }
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "매입 등록에 실패했습니다: " . $e->getMessage();
        }
    }
}
?>

<!-- Page header -->
<div class="mb-8 sm:flex sm:items-center sm:justify-between">
    <div>
        <h1 class="text-3xl font-bold text-gray-900"><?php echo $is_edit_mode ? '매입 내역 수정 - 상품 추가' : '신규 매입 등록'; ?></h1>
        <p class="mt-2 text-sm text-gray-700">
            <?php if ($is_edit_mode): ?>
                기존 매입 내역에 새로운 상품을 추가하세요. 기존 상품들은 이미 목록에 표시되어 있습니다.
            <?php else: ?>
                매입할 상품을 검색하여 목록에 추가하세요.
            <?php endif; ?>
        </p>
        <?php if ($is_edit_mode && $existing_purchase): ?>
            <div class="mt-3 flex items-center space-x-4 text-sm text-gray-600">
                <span><strong>거래처:</strong> <?php echo htmlspecialchars($existing_purchase['supplier_name']); ?></span>
                <span><strong>매입날짜:</strong> <?php echo htmlspecialchars($existing_purchase['purchase_date']); ?></span>
            </div>
        <?php endif; ?>
    </div>
    <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
        <?php if ($is_edit_mode): ?>
            <a href="edit_purchase.php?id=<?php echo $edit_purchase_id; ?>" class="btn">
                <i class="fas fa-arrow-left mr-2"></i>
                상세보기로 돌아가기
            </a>
        <?php else: ?>
            <a href="purchase_management.php" class="btn">
                <i class="fas fa-arrow-left mr-2"></i>
                매입 관리로 돌아가기
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="mb-6 rounded-md bg-red-50 p-4 border border-red-200">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-circle text-red-400"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm text-red-800"><?php echo $message; ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="bg-white shadow rounded-lg">
    <form id="purchase-form" method="POST" class="p-6">
        <?php if ($is_edit_mode): ?>
            <input type="hidden" name="edit_purchase_id" value="<?php echo $edit_purchase_id; ?>">
        <?php endif; ?>

        <!-- 0. 점포 정보 표시 -->
        <div class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-store text-blue-500"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-blue-800">매입 점포</p>
                    <p class="text-base font-semibold text-blue-900"><?php echo htmlspecialchars($store_name); ?></p>
                </div>
            </div>
        </div>

        <!-- 1 & 2: 거래처 및 날짜 선택 -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 border-b border-gray-200 pb-6">
            <div>
                <label for="supplier_search" class="block text-sm font-medium text-gray-700">
                    <span class="text-red-500">*</span> 거래처 (필수)
                </label>
                <?php if ($is_edit_mode): ?>
                    <!-- 수정 모드에서는 기존 거래처 정보만 표시 -->
                    <div class="mt-1 p-3 bg-gray-100 border border-gray-300 rounded-md">
                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($existing_purchase['supplier_name']); ?></div>
                    </div>
                    <input type="hidden" id="supplier_id" name="supplier_id" value="<?php echo $existing_purchase['supplier_id']; ?>">
                <?php else: ?>
                    <!-- 신규 등록 모드에서는 검색 기능 제공 -->
                    <div class="relative mt-1">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="supplier_search" 
                               class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                               placeholder="거래처명을 입력하여 검색하세요...">
                        <input type="hidden" id="supplier_id" name="supplier_id" required>
                    </div>
                    <div id="supplier_search_results" class="absolute z-30 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden border border-gray-200"></div>
                    <div id="selected_supplier" class="mt-2 hidden">
                        <div class="flex items-center justify-between p-3 bg-green-50 border border-green-200 rounded-md">
                            <div>
                                <div class="text-sm font-medium text-green-900" id="selected_supplier_name"></div>
                                <div class="text-xs text-green-700" id="selected_supplier_info"></div>
                            </div>
                            <button type="button" id="clear_supplier" class="text-green-600 hover:text-green-800">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                <p class="mt-1 text-xs text-gray-500">거래처를 선택해야 상품 입력이 가능합니다.</p>
            </div>
            <div>
                <label for="purchase_date" class="block text-sm font-medium text-gray-700">매입 날짜</label>
                <input type="date" id="purchase_date" name="purchase_date" 
                       value="<?php echo $is_edit_mode && $existing_purchase ? $existing_purchase['purchase_date'] : date('Y-m-d'); ?>" 
                       required 
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm <?php echo $is_edit_mode ? 'bg-gray-100' : ''; ?>"
                       <?php echo $is_edit_mode ? 'disabled' : ''; ?>>
                <?php if ($is_edit_mode): ?>
                    <input type="hidden" name="purchase_date" value="<?php echo $existing_purchase['purchase_date']; ?>">
                <?php endif; ?>
            </div>
        </div>

        <!-- 3. 상품 검색 -->
        <div class="mb-6 relative">
            <label for="product_search" class="block text-sm font-medium text-gray-700">상품명 또는 바코드 검색</label>
            <div class="relative mt-1">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400"></i>
                </div>
                <input type="text" id="product_search" disabled class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm disabled:bg-gray-100 disabled:text-gray-500" placeholder="거래처를 먼저 선택하세요...">
            </div>
            <div id="search_results" class="absolute z-20 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden"></div>
        </div>

        <!-- 4-9. 상품 목록 -->
        <div class="mb-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">매입 상품 목록</h3>
            <div class="overflow-x-auto">
                <table id="item-table" class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">바코드</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">상품명</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">구매유형</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">수량</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">박스당 수량</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">단가</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">낱개단가</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">합계</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">삭제</th>
                        </tr>
                    </thead>
                    <tbody id="item-list" class="bg-white divide-y divide-gray-200">
                        <tr id="empty-row">
                            <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-box-open text-4xl text-gray-300 mb-4"></i>
                                <p class="text-sm">상품을 검색하여 매입 목록에 추가하세요.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 총 합계 -->
        <div class="border-t border-gray-200 pt-6">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-sm text-gray-500">매입 총 합계</p>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-bold text-gray-900">₩<span id="total-amount">0</span></p>
                </div>
            </div>
        </div>

        <!-- 액션 버튼 -->
        <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200 mt-6">
            <a href="purchase_management.php" class="btn">
                취소
            </a>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save mr-2"></i>
                매입 등록
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const supplierSelect = document.getElementById('supplier_id');
    const searchInput = document.getElementById('product_search');
    const searchResults = document.getElementById('search_results');
    const itemList = document.getElementById('item-list');
    let itemIndex = 0;
    let searchTimeout;

    // 기존 매입 상품들을 자동으로 로드 (수정 모드인 경우)
    <?php if ($is_edit_mode && !empty($existing_items)): ?>
    const existingItems = <?php echo json_encode($existing_items); ?>;
    
    // 기존 상품들을 목록에 추가
    existingItems.forEach(function(item) {
        const productData = {
            id: item.product_id,
            name_ko: item.product_name,
            name_en: item.product_name_en || '',
            sku: item.sku,
            barcode: item.barcode || '',
            pieces_per_box: item.pieces_per_box || 1,
            cost_price: item.unit_price
        };
        
        addProductToList(productData, item.quantity, item.unit_price, item.purchase_type, item.item_id);
    });
    
    // 수정 모드에서는 검색 기능을 바로 활성화
    searchInput.disabled = false;
    searchInput.placeholder = '상품명 또는 바코드를 입력하세요...';
    searchInput.classList.remove('disabled:bg-gray-100', 'disabled:text-gray-500');
    <?php endif; ?>

    // 거래처 검색 관련 요소들
    const supplierSearch = document.getElementById('supplier_search');
    const supplierSearchResults = document.getElementById('supplier_search_results');
    const supplierIdInput = document.getElementById('supplier_id');
    const selectedSupplierDiv = document.getElementById('selected_supplier');
    const selectedSupplierName = document.getElementById('selected_supplier_name');
    const selectedSupplierInfo = document.getElementById('selected_supplier_info');
    const clearSupplierBtn = document.getElementById('clear_supplier');
    let supplierSearchTimeout;
    let currentSupplierResults = [];
    let selectedSupplierIndex = -1;

    // 거래처 검색 기능 (신규 등록 모드에서만)
    if (supplierSearch) {
        // 키보드 이벤트 처리
        supplierSearch.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (selectedSupplierIndex < currentSupplierResults.length - 1) {
                    selectedSupplierIndex++;
                    updateSupplierSelection();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (selectedSupplierIndex > 0) {
                    selectedSupplierIndex--;
                    updateSupplierSelection();
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selectedSupplierIndex >= 0 && selectedSupplierIndex < currentSupplierResults.length) {
                    if (selectedSupplierIndex === currentSupplierResults.length - 1 && currentSupplierResults[selectedSupplierIndex].isNewOption) {
                        // 신규 등록 옵션 선택
                        showAddSupplierModal(this.value.trim());
                    } else {
                        // 기존 거래처 선택
                        selectSupplier(currentSupplierResults[selectedSupplierIndex]);
                    }
                }
            } else if (e.key === 'Escape') {
                supplierSearchResults.classList.add('hidden');
                selectedSupplierIndex = -1;
                currentSupplierResults = [];
            }
        });

        supplierSearch.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            clearTimeout(supplierSearchTimeout);
            selectedSupplierIndex = -1; // 검색 시 선택 초기화

            if (searchTerm.length < 2) {
                supplierSearchResults.innerHTML = '';
                supplierSearchResults.classList.add('hidden');
                currentSupplierResults = [];
                return;
            }

            supplierSearchTimeout = setTimeout(() => {
                fetch(`ajax_search_suppliers.php?term=${encodeURIComponent(searchTerm)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error(data.error);
                            return;
                        }
                        
                        // 검색 결과 저장
                        currentSupplierResults = [...data];
                        
                        supplierSearchResults.innerHTML = '';
                        if (data.length > 0) {
                            data.forEach((supplier, index) => {
                                const div = document.createElement('div');
                                div.className = `supplier-result cursor-pointer bg-white hover:bg-indigo-50 p-3 border-b border-gray-100 last:border-b-0`;
                                div.innerHTML = `
                                    <div class="font-medium text-gray-900">${supplier.name}</div>
                                    <div class="text-sm text-gray-500">${supplier.phone ? '전화: ' + supplier.phone : ''} ${supplier.memo ? '| ' + supplier.memo : ''}</div>
                                `;
                                div.addEventListener('click', () => selectSupplier(supplier));
                                supplierSearchResults.appendChild(div);
                            });
                            
                            // 새 거래처 등록 옵션 추가
                            const addNewDiv = document.createElement('div');
                            addNewDiv.className = 'supplier-result cursor-pointer bg-green-50 hover:bg-green-100 p-3 border-t-2 border-green-200';
                            addNewDiv.innerHTML = `
                                <div class="flex items-center text-green-700">
                                    <i class="fas fa-plus mr-2"></i>
                                    <span class="font-medium">"${searchTerm}" 거래처 신규 등록</span>
                                </div>
                                <div class="text-sm text-green-600">↵ 엔터 또는 클릭하여 새 거래처를 등록하세요</div>
                            `;
                            addNewDiv.addEventListener('click', () => showAddSupplierModal(searchTerm));
                            supplierSearchResults.appendChild(addNewDiv);
                            
                            // 신규 등록 옵션을 결과에 추가
                            currentSupplierResults.push({ isNewOption: true, searchTerm: searchTerm });
                            
                            supplierSearchResults.classList.remove('hidden');
                        } else {
                            // 검색 결과가 없을 때 신규 등록 옵션만 표시
                            supplierSearchResults.innerHTML = `
                                <div class="supplier-result cursor-pointer bg-green-50 hover:bg-green-100 p-3">
                                    <div class="flex items-center text-green-700">
                                        <i class="fas fa-plus mr-2"></i>
                                        <span class="font-medium">"${searchTerm}" 거래처 신규 등록</span>
                                    </div>
                                    <div class="text-sm text-green-600">검색 결과가 없습니다. ↵ 엔터 또는 클릭하여 새 거래처를 등록하세요</div>
                                </div>
                            `;
                            supplierSearchResults.querySelector('div').addEventListener('click', () => showAddSupplierModal(searchTerm));
                            
                            // 신규 등록 옵션만 결과에 추가
                            currentSupplierResults = [{ isNewOption: true, searchTerm: searchTerm }];
                            
                            supplierSearchResults.classList.remove('hidden');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        supplierSearchResults.innerHTML = '<div class="p-3 text-red-500">검색 중 오류가 발생했습니다.</div>';
                        supplierSearchResults.classList.remove('hidden');
                        currentSupplierResults = [];
                    });
            }, 300);
        });

        // 선택 상태 업데이트 함수
        function updateSupplierSelection() {
            const resultDivs = supplierSearchResults.querySelectorAll('.supplier-result');
            resultDivs.forEach((div, index) => {
                if (index === selectedSupplierIndex) {
                    // 선택된 상태 스타일 적용
                    div.classList.remove('bg-white', 'bg-green-50', 'hover:bg-indigo-50', 'hover:bg-green-50', 'hover:bg-green-100');
                    div.classList.add('bg-indigo-200', 'border-l-4', 'border-indigo-500');
                    
                    // 선택된 항목이 검색 결과 컨테이너 내에서만 보이도록 스크롤
                    const container = supplierSearchResults;
                    const divTop = div.offsetTop;
                    const divBottom = divTop + div.offsetHeight;
                    const containerTop = container.scrollTop;
                    const containerBottom = containerTop + container.clientHeight;
                    
                    if (divTop < containerTop) {
                        // 위로 스크롤
                        container.scrollTop = divTop;
                    } else if (divBottom > containerBottom) {
                        // 아래로 스크롤
                        container.scrollTop = divBottom - container.clientHeight;
                    }
                } else {
                    // 선택되지 않은 상태로 복원
                    div.classList.remove('bg-indigo-200', 'border-l-4', 'border-indigo-500');
                    
                    // 원래 배경색과 hover 클래스 복원
                    if (index === currentSupplierResults.length - 1 && currentSupplierResults[index].isNewOption) {
                        div.classList.add('bg-green-50', 'hover:bg-green-100');
                        div.classList.remove('bg-white', 'hover:bg-indigo-50');
                    } else {
                        div.classList.add('bg-white', 'hover:bg-indigo-50');
                        div.classList.remove('bg-green-50', 'hover:bg-green-100');
                    }
                }
            });
        }

        // 거래처 선택 함수
        function selectSupplier(supplier) {
            supplierIdInput.value = supplier.id;
            selectedSupplierName.textContent = supplier.name;
            selectedSupplierInfo.textContent = (supplier.phone ? '전화: ' + supplier.phone : '') + (supplier.memo ? ' | ' + supplier.memo : '');
            
            supplierSearch.style.display = 'none';
            selectedSupplierDiv.classList.remove('hidden');
            supplierSearchResults.classList.add('hidden');
            
            // 상품 검색 활성화
            searchInput.disabled = false;
            searchInput.placeholder = '상품명 또는 바코드를 입력하세요...';
            searchInput.classList.remove('disabled:bg-gray-100', 'disabled:text-gray-500');
            searchInput.focus();
        }

        // 거래처 선택 해제
        if (clearSupplierBtn) {
            clearSupplierBtn.addEventListener('click', function() {
                supplierIdInput.value = '';
                supplierSearch.value = '';
                supplierSearch.style.display = 'block';
                selectedSupplierDiv.classList.add('hidden');
                
                // 상품 검색 비활성화
                searchInput.disabled = true;
                searchInput.placeholder = '거래처를 먼저 선택하세요...';
                searchInput.classList.add('disabled:bg-gray-100', 'disabled:text-gray-500');
                searchInput.value = '';
                searchResults.classList.add('hidden');
                
                supplierSearch.focus();
            });
        }

        // 검색 결과 외부 클릭 시 숨기기
        document.addEventListener('click', function(e) {
            if (!supplierSearch.contains(e.target) && !supplierSearchResults.contains(e.target)) {
                supplierSearchResults.classList.add('hidden');
                selectedSupplierIndex = -1;
                currentSupplierResults = [];
            }
        });
    }

    // 기존 거래처 선택 처리 (수정 모드가 아닌 경우 - 하위 호환성)
    if (supplierSelect) {
        supplierSelect.addEventListener('change', function() {
            if (this.value) {
                searchInput.disabled = false;
                searchInput.placeholder = '상품명 또는 바코드를 입력하세요...';
                searchInput.classList.remove('disabled:bg-gray-100', 'disabled:text-gray-500');
                searchInput.focus();
            } else {
                searchInput.disabled = true;
                searchInput.placeholder = '거래처를 먼저 선택하세요...';
                searchInput.classList.add('disabled:bg-gray-100', 'disabled:text-gray-500');
                searchInput.value = '';
                searchResults.classList.add('hidden');
            }
        });
    }

    // 전역 변수로 현재 검색 결과 저장
    let currentSearchResults = [];

    // 엔터키 및 ESC 키 처리를 위한 keydown 이벤트 추가
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault(); // 폼 제출 방지
            
            // 검색 결과가 있으면 첫 번째 상품을 자동으로 추가
            if (currentSearchResults.length > 0) {
                addProductToList(currentSearchResults[0]);
                this.value = '';
                searchResults.classList.add('hidden');
                currentSearchResults = [];
            }
        } else if (e.key === 'Escape') {
            searchResults.classList.add('hidden');
            currentSearchResults = [];
        }
    });

    // 상품 검색 (실시간)
    searchInput.addEventListener('keyup', function(e) {
        // 엔터키는 keydown에서 처리하므로 제외
        if (e.key === 'Enter') {
            return;
        }

        // 거래처가 선택되지 않았으면 검색 차단
        const supplierId = supplierSelect ? supplierSelect.value : document.getElementById('supplier_id').value;
        if (!supplierId) {
            alert('거래처를 먼저 선택해주세요.');
            this.blur();
            return;
        }

        const searchTerm = this.value.trim();
        clearTimeout(searchTimeout);

        if (searchTerm.length < 2) {
            searchResults.innerHTML = '';
            searchResults.classList.add('hidden');
            currentSearchResults = [];
            return;
        }

        searchTimeout = setTimeout(() => {
            fetch(`ajax_search_products.php?term=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        console.error(data.error);
                        currentSearchResults = [];
                        return;
                    }
                    
                    // 검색 결과를 전역 변수에 저장
                    currentSearchResults = data;
                    
                    // 바코드 정확히 일치 시 바로 추가
                    if (data.length === 1 && data[0].exact_match) {
                        addProductToList(data[0]);
                        searchInput.value = '';
                        searchResults.classList.add('hidden');
                        currentSearchResults = [];
                    } else {
                        searchResults.innerHTML = '';
                        if (data.length > 0) {
                            data.forEach((product, index) => {
                                const div = document.createElement('div');
                                div.className = `cursor-pointer hover:bg-indigo-50 p-3 ${index === 0 ? 'bg-blue-50 border-l-4 border-blue-500' : ''}`;
                                div.innerHTML = `
                                    <p class="font-semibold">${product.name_ko} <span class="text-gray-500 font-normal">(${product.name_en})</span></p>
                                    <p class="text-sm text-gray-500">SKU: ${product.sku} | 바코드: ${product.barcode || '없음'}</p>
                                    ${index === 0 ? '<p class="text-xs text-blue-600 mt-1"><i class="fas fa-keyboard mr-1"></i>엔터키로 선택</p>' : ''}
                                `;
                                div.addEventListener('click', () => {
                                    addProductToList(product);
                                    searchInput.value = '';
                                    searchResults.classList.add('hidden');
                                    currentSearchResults = [];
                                });
                                searchResults.appendChild(div);
                            });
                            searchResults.classList.remove('hidden');
                        } else {
                            searchResults.innerHTML = '<div class="p-3 text-gray-500">검색 결과가 없습니다.</div>';
                            searchResults.classList.remove('hidden');
                            currentSearchResults = [];
                        }
                    }
                });
        }, 300); // 300ms 디바운스
    });

    // 검색 결과 외부 클릭 시 숨기기
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.classList.add('hidden');
            currentSearchResults = [];
        }
    });

    // ESC 키로 검색 결과 닫기
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            searchResults.classList.add('hidden');
            currentSearchResults = [];
        }
    });

    // 4. 상품 목록에 추가
    function addProductToList(product, quantity = 1, unitPrice = null, purchaseType = 'box', existingItemId = null) {
        const newRow = document.createElement('tr');
        newRow.classList.add('item-row');
        newRow.dataset.piecesPerBox = product.pieces_per_box || 1;
        
        const finalUnitPrice = unitPrice !== null ? unitPrice : (product.cost_price || 0);
        const isExisting = existingItemId !== null;
        
        newRow.innerHTML = `
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-xs font-mono text-gray-600">${product.barcode || '없음'}</span>
                ${isExisting ? '<span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">기존</span>' : ''}
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <input type="hidden" name="items[${itemIndex}][product_id]" value="${product.id}">
                ${isExisting ? `<input type="hidden" name="items[${itemIndex}][existing_item_id]" value="${existingItemId}">` : ''}
                <div class="text-sm font-medium text-gray-900">${product.name_ko}</div>
                <div class="text-xs text-gray-500">SKU: ${product.sku}</div>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-center">
                <select name="items[${itemIndex}][purchase_type]" class="text-xs rounded-md px-2 py-1 purchase-type border-gray-300 focus:border-indigo-500 focus:ring-indigo-500" ${isExisting ? 'disabled' : ''}>
                    <option value="box" ${purchaseType === 'box' ? 'selected' : ''}>박스</option>
                    <option value="piece" ${purchaseType === 'piece' ? 'selected' : ''}>낱개</option>
                </select>
                ${isExisting ? `<input type="hidden" name="items[${itemIndex}][purchase_type]" value="${purchaseType}">` : ''}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-right">
                <input type="number" name="items[${itemIndex}][quantity]" class="w-16 px-2 py-1 border border-gray-300 rounded-md text-right text-sm quantity focus:border-indigo-500 focus:ring-indigo-500 ${isExisting ? 'bg-gray-100' : ''}" min="1" value="${quantity}" ${isExisting ? 'readonly' : ''}>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-right pieces-per-box text-sm text-gray-500">${product.pieces_per_box || 1}개</td>
            <td class="px-6 py-4 whitespace-nowrap text-right">
                <input type="number" name="items[${itemIndex}][unit_price]" class="w-20 px-2 py-1 border border-gray-300 rounded-md text-right text-sm unit-price focus:border-indigo-500 focus:ring-indigo-500 ${isExisting ? 'bg-gray-100' : ''}" step="1" min="0" value="${finalUnitPrice}" ${isExisting ? 'readonly' : ''}>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-right piece-price text-sm text-gray-500"></td>
            <td class="px-6 py-4 whitespace-nowrap text-right row-total font-semibold text-gray-900">₩0</td>
            <td class="px-6 py-4 whitespace-nowrap text-center">
                ${isExisting ? '<span class="text-gray-400 text-xs">수정 불가</span>' : '<button type="button" class="text-red-600 hover:text-red-800 remove-row"><i class="fas fa-trash-alt"></i></button>'}
            </td>
        `;
        
        // 빈 행 숨기기
        const emptyRow = document.getElementById('empty-row');
        if (emptyRow) {
            emptyRow.style.display = 'none';
        }
        
        itemList.appendChild(newRow);
        itemIndex++;
        
        updateRow(newRow);
        
        // 새로 추가된 행의 수량 필드에 자동 포커스 (기존 상품이 아닌 경우만)
        if (!isExisting) {
            setTimeout(() => {
                newRow.querySelector('.quantity').focus();
                newRow.querySelector('.quantity').select();
            }, 100);
        }
        
        // 이벤트 리스너는 새로운 상품에만 추가
        if (!isExisting) {
            // 6. 단가 입력 후 엔터 시 검색창으로 포커스 이동
            newRow.querySelector('.unit-price').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    // 값이 입력되었는지 확인
                    if (this.value && parseFloat(this.value) > 0) {
                        searchInput.focus();
                        searchInput.select(); // 검색창의 기존 텍스트 선택
                    }
                }
            });
            
            // 수량 입력 후 탭/엔터 시 단가로 포커스 이동
            newRow.querySelector('.quantity').addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === 'Tab') {
                    e.preventDefault();
                    newRow.querySelector('.unit-price').focus();
                    newRow.querySelector('.unit-price').select();
                }
            });
        }
    }

    // 5, 7, 8. 수량/단가 변경 시 합계 업데이트
    itemList.addEventListener('input', function(e) {
        if (e.target.classList.contains('quantity') || e.target.classList.contains('unit-price') || e.target.classList.contains('purchase-type')) {
            updateRow(e.target.closest('tr'));
        }
    });

    // 9. 삭제 버튼
    itemList.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-row')) {
            e.target.closest('tr').remove();
            updateTotalAmount();
            
            // 상품 행이 모두 제거되면 빈 행 다시 표시
            const productRows = itemList.querySelectorAll('.item-row');
            const emptyRow = document.getElementById('empty-row');
            if (productRows.length === 0 && emptyRow) {
                emptyRow.style.display = '';
            }
        }
    });

    function updateRow(row) {
        const purchaseType = row.querySelector('.purchase-type').value;
        const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.unit-price').value) || 0;
        const piecesPerBox = parseInt(row.dataset.piecesPerBox) || 1;

        const rowTotal = quantity * unitPrice;
        row.querySelector('.row-total').textContent = '₩' + rowTotal.toLocaleString();

        let piecePrice = 0;
        if (purchaseType === 'box' && piecesPerBox > 0) {
            piecePrice = unitPrice / piecesPerBox;
        } else {
            piecePrice = unitPrice;
        }
        row.querySelector('.piece-price').textContent = '₩' + Math.round(piecePrice).toLocaleString();
        
        updateTotalAmount();
    }

    function updateTotalAmount() {
        let total = 0;
        document.querySelectorAll('.row-total').forEach(function (el) {
            // ₩ 기호를 제거하고 숫자만 추출
            const amount = el.textContent.replace(/[₩,]/g, '');
            total += parseFloat(amount) || 0;
        });
        document.getElementById('total-amount').textContent = total.toLocaleString();
    }

    // 검색 결과 밖 클릭 시 숨기기
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.classList.add('hidden');
        }
    });

    // 거래처 신규 등록 모달 함수
    function showAddSupplierModal(defaultName = '') {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
        modal.innerHTML = `
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">새 거래처 등록</h3>
                        <button type="button" class="text-gray-400 hover:text-gray-600" onclick="closeModal()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <form id="add-supplier-form" class="space-y-4">
                        <div>
                            <label for="modal_supplier_name" class="block text-sm font-medium text-gray-700">거래처명 *</label>
                            <input type="text" id="modal_supplier_name" name="name" required 
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                   value="${defaultName}" placeholder="거래처명을 입력하세요">
                        </div>
                        <div>
                            <label for="modal_supplier_phone" class="block text-sm font-medium text-gray-700">전화번호</label>
                            <input type="text" id="modal_supplier_phone" name="phone" 
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                   placeholder="전화번호를 입력하세요">
                        </div>
                        <div>
                            <label for="modal_supplier_memo" class="block text-sm font-medium text-gray-700">메모</label>
                            <textarea id="modal_supplier_memo" name="memo" rows="3" 
                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                      placeholder="거래처 관련 메모를 입력하세요"></textarea>
                        </div>
                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" onclick="closeModal()" class="btn">
                                취소
                            </button>
                            <button type="submit" class="btn-success">
                                <i class="fas fa-plus mr-1"></i>
                                등록
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // 모달 닫기 함수
        window.closeModal = function() {
            document.body.removeChild(modal);
            delete window.closeModal;
        }
        
        // 외부 클릭 시 모달 닫기
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });
        
        // 폼 제출 처리
        document.getElementById('add-supplier-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                name: document.getElementById('modal_supplier_name').value.trim(),
                phone: document.getElementById('modal_supplier_phone').value.trim(),
                memo: document.getElementById('modal_supplier_memo').value.trim()
            };
            
            if (!formData.name) {
                alert('거래처명을 입력해주세요.');
                return;
            }
            
            // 로딩 상태 표시
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>등록 중...';
            submitBtn.disabled = true;
            
            fetch('ajax_add_supplier.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 성공 시 거래처 자동 선택
                    selectSupplier(data.supplier);
                    closeModal();
                    
                    // 성공 메시지 표시
                    const successDiv = document.createElement('div');
                    successDiv.className = 'mb-4 rounded-md bg-green-50 p-4 border border-green-200';
                    successDiv.innerHTML = `
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-check-circle text-green-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-green-800">${data.message}</p>
                            </div>
                        </div>
                    `;
                    
                    const form = document.getElementById('purchase-form');
                    form.parentNode.insertBefore(successDiv, form);
                    
                    // 3초 후 성공 메시지 제거
                    setTimeout(() => {
                        if (successDiv.parentNode) {
                            successDiv.parentNode.removeChild(successDiv);
                        }
                    }, 3000);
                } else {
                    alert('오류: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('거래처 등록 중 오류가 발생했습니다.');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
        
        // 첫 번째 입력 필드에 포커스
        setTimeout(() => {
            document.getElementById('modal_supplier_name').focus();
        }, 100);
    }
});
</script>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>

