<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

require_once __DIR__ . '/../lib/lang_helper.php';

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'><strong class='font-bold'>" . t('purchase.access_denied_title') . ":</strong><span class='block sm:inline'> " . t('purchase.access_denied') . "</span></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();

// 현재 로그인된 사용자의 점포 정보 조회
$store_name = t('purchase.unassigned');
$user_store_id = null;
if (!empty($_SESSION['user_id'])) {
    $user_stmt = $conn->prepare("SELECT s.name as store_name, s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_row = $user_result->fetch_assoc()) {
        $store_name = $user_row['store_name'] ?? t('purchase.unassigned');
        $user_store_id = $user_row['store_id'];

        // super_admin이고 store_id가 없는 경우 첫 번째 점포 자동 설정
        if ($_SESSION['role'] === 'super_admin' && empty($user_store_id)) {
            // 첫 번째 점포 조회
            $first_store_result = $conn->query("SELECT id, name FROM stores ORDER BY id LIMIT 1");
            if ($first_store_result && $first_store_row = $first_store_result->fetch_assoc()) {
                $user_store_id = $first_store_row['id'];
                $store_name = $first_store_row['name'];
            }
        }
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
            SELECT pi.*, pr.name_ko as product_name, pr.name_en as product_name_en, pr.sku, pr.pieces_per_box
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

$page_title = $is_edit_mode ? t('purchase.edit_add_items') : t('purchase.new_purchase');
require_once __DIR__ . '/partials/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = $_POST['supplier_id'];
    $purchase_date = $_POST['purchase_date'];
    $items = $_POST['items'] ?? [];
    $total_items = 0;
    $total_amount = 0;
    $edit_purchase_id = $_POST['edit_purchase_id'] ?? null;

    if (empty($supplier_id) || empty($purchase_date) || empty($items)) {
        $message = t('purchase.required_fields');
    } else if (empty($user_store_id)) {
        // 헤더에서 설정된 current_store_id 사용 시도
        if (!empty($current_store_id)) {
            $user_store_id = $current_store_id;
        } else {
            $message = '점포 정보가 없습니다. 관리자에게 문의하세요.';
        }
    }

    if (empty($message)) {
        // 연결 상태 확인 및 필요시 재연결
        $connection_valid = false;
        if (isset($conn) && ($conn instanceof mysqli)) {
            try {
                $connection_valid = $conn->ping();
            } catch (Error $e) {
                // ping() 실패 시 연결이 닫힌 상태
                $connection_valid = false;
            }
        }
        
        if (!$connection_valid) {
            $conn = get_db_connection();
            if (!$conn) {
                $message = t('purchase.database_connection_failed');
                goto skip_processing;
            }
        }
        
        $conn->begin_transaction();
        try {
            // 새로운 상품들만 처리 (기존 상품 제외)
            $new_items = [];
            foreach ($items as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity']) && isset($item['unit_price']) && $item['unit_price'] !== '' && empty($item['existing_item_id'])) {
                    $new_items[] = $item;
                    $total_items += 1; // 품목 개수 증가 (수량이 아닌 품목 수)
                    $total_amount += (int)$item['quantity'] * (float)$item['unit_price'];
                }
            }

            if ($edit_purchase_id && !empty($new_items)) {
                // 기존 매입에 상품 추가
                $purchase_id = $edit_purchase_id;
            } else if (!$edit_purchase_id) {
                // 새로운 매입 생성 - store_id 포함

                // store_id 최종 검증
                if (empty($user_store_id)) {
                    throw new Exception("점포 정보가 없습니다. store_id가 설정되지 않았습니다. (User: {$_SESSION['user_id']}, Role: {$_SESSION['role']})");
                }

                $stmt = $conn->prepare("INSERT INTO purchases (store_id, supplier_id, purchase_date, total_amount, total_items) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iisdi", $user_store_id, $supplier_id, $purchase_date, $total_amount, $total_items);
                $stmt->execute();
                $purchase_id = $stmt->insert_id;
                $stmt->close();
                $new_items = $items;
            } else {
                throw new Exception(t('purchase.no_new_items'));
            }

            // 매입 상세 데이터 저장 및 재고 업데이트
            $stmt_item = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, purchase_type, quantity, unit_price, vat_included, original_unit_price, vat_amount, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $sort_order = 1; // 순번 1부터 시작
            foreach ($new_items as $item) {
                if (!empty($item['product_id']) && !empty($item['quantity']) && isset($item['unit_price']) && $item['unit_price'] !== '') {
                    // 데이터 검증 및 정제
                    $purchase_type = trim($item['purchase_type'] ?? 'box');
                    if (!in_array($purchase_type, ['box', 'piece'])) {
                        $purchase_type = 'box'; // 기본값으로 설정
                    }
                    
                    // 상품의 VAT 적용 여부 확인
                    $product_vat_stmt = $conn->prepare("SELECT is_vat_applicable FROM products WHERE id = ?");
                    $product_vat_stmt->bind_param("i", $item['product_id']);
                    $product_vat_stmt->execute();
                    $product_vat_result = $product_vat_stmt->get_result();
                    $is_vat_applicable = 1; // 기본값: VAT 적용 상품
                    if ($product_vat_row = $product_vat_result->fetch_assoc()) {
                        $is_vat_applicable = (int)$product_vat_row['is_vat_applicable'];
                    }
                    
                    // VAT 처리 로직
                    $vat_included = isset($item['vat_included']) ? (int)$item['vat_included'] : 1; // 기본값: VAT 포함
                    $original_price = (float)$item['unit_price']; // 사용자 입력 원본 가격
                    $vat_rate = 0.12; // VAT 12%
                    
                    if ($vat_included == 1) {
                        // VAT 포함으로 선택한 경우
                        $final_unit_price = $original_price; // 입력값 그대로
                        if ($is_vat_applicable == 1) {
                            // VAT 적용 상품: VAT 금액 계산
                            $vat_amount = $original_price - ($original_price / 1.12);
                        } else {
                            // VAT 비적용 상품: VAT 금액 0
                            $vat_amount = 0;
                        }
                    } else {
                        // VAT 미포함으로 선택한 경우
                        if ($is_vat_applicable == 0) {
                            // VAT 비적용 상품: 원가 그대로 저장, VAT 구분은 "포함"으로 변경
                            $final_unit_price = $original_price;
                            $vat_amount = 0;
                            $vat_included = 1; // VAT 구분을 "포함"으로 변경
                        } else {
                            // VAT 적용 상품: 원가에 12% 추가하여 저장, VAT 구분은 "포함"으로 변경
                            $final_unit_price = $original_price * 1.12; // VAT 추가된 가격
                            $vat_amount = $original_price * $vat_rate;
                            $vat_included = 1; // VAT 구분을 "포함"으로 변경
                        }
                    }
                    
                    // 1. 매입 상세 데이터 저장 (VAT 관련 필드 포함 + sort_order)
                    $stmt_item->bind_param("iisiddddi", $purchase_id, $item['product_id'], $purchase_type, $item['quantity'], $final_unit_price, $vat_included, $original_price, $vat_amount, $sort_order);
                    if (!$stmt_item->execute()) {
                        throw new Exception(str_replace(['{error}', '{type}'], [$stmt_item->error, $purchase_type], t('purchase.item_save_failed')));
                    }
                    $sort_order++; // 다음 상품 순번 증가
                    
                    // 2. 실제 입고 수량 계산 (박스/낱개 구분)
                    $actual_quantity = (int)$item['quantity'];
                    if ($purchase_type === 'box') {
                        // 박스 매입인 경우 사용자가 입력한 pieces_per_box 값을 사용
                        $pieces_per_box = isset($item['pieces_per_box']) ? (int)$item['pieces_per_box'] : 1;
                        if ($pieces_per_box <= 0) {
                            $pieces_per_box = 1; // 안전장치
                        }
                        $actual_quantity = (int)$item['quantity'] * $pieces_per_box;
                    }
                    
                    // 3. inventory 테이블 업데이트 (사용자 점포에만 적용) - 비활성화
                    // 구매 시 inventory 자동 업데이트를 하지 않도록 주석 처리
                    /*
                    if ($user_store_id) {
                        // 기존 재고 레코드 확인
                        $inv_check_stmt = $conn->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND store_id = ?");
                        $inv_check_stmt->bind_param("ii", $item['product_id'], $user_store_id);
                        $inv_check_stmt->execute();
                        $inv_result = $inv_check_stmt->get_result();
                        
                        if ($inv_row = $inv_result->fetch_assoc()) {
                            // 기존 재고 업데이트 (VAT 포함 가격으로 cost_price도 함께 업데이트)
                            $new_quantity = $inv_row['quantity'] + $actual_quantity;
                            $inv_update_stmt = $conn->prepare("UPDATE inventory SET quantity = ?, cost_price = ? WHERE id = ?");
                            $inv_update_stmt->bind_param("idi", $new_quantity, $final_unit_price, $inv_row['id']);
                            $inv_update_stmt->execute();
                            $inv_update_stmt->close();
                            $inventory_id = $inv_row['id'];
                        } else {
                            // 새로운 재고 레코드 생성 (VAT 포함 가격으로 cost_price 설정)
                            $inv_insert_stmt = $conn->prepare("INSERT INTO inventory (product_id, store_id, quantity, cost_price) VALUES (?, ?, ?, ?)");
                            $inv_insert_stmt->bind_param("iiid", $item['product_id'], $user_store_id, $actual_quantity, $final_unit_price);
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
                            throw new Exception(str_replace('{error}', $transaction_stmt->error, t('purchase.transaction_log_failed')));
                        }
                        $transaction_stmt->close();
                    }
                    */
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
                $_SESSION['flash'] = ['type' => 'success', 'message' => t('purchase.items_added_successfully')];
                header("Location: edit_purchase.php?id={$purchase_id}");
            } else {
                $_SESSION['flash'] = ['type' => 'success', 'message' => t('purchase.registered_successfully')];
                header('Location: purchase_management.php');
            }
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = str_replace('{error}', $e->getMessage(), t('purchase.registration_failed'));
        }
    }
    
    skip_processing:
}
?>

<!-- Page header -->
<div class="flex items-center justify-between">
    <h1 class="text-lg font-bold text-gray-900"><?php echo $is_edit_mode ? t('purchase.edit_add_items') : t('purchase.new_purchase'); ?></h1>
    <div>
        <?php if ($is_edit_mode): ?>
            <a href="edit_purchase.php?id=<?php echo $edit_purchase_id; ?>" class="btn">
                <i class="fas fa-arrow-left mr-2"></i>
                <?php echo t('purchase.back_to_details'); ?>
            </a>
        <?php else: ?>
            <a href="purchase_management.php" class="btn">
                <i class="fas fa-arrow-left mr-2"></i>
                이전화면
            </a>
        <?php endif; ?>
    </div>
</div>
<?php if ($is_edit_mode && $existing_purchase): ?>
    <div class="flex justify-center items-center space-x-4 text-sm text-gray-600">
        <span><strong><?php echo t('purchase.supplier'); ?>:</strong> <?php echo htmlspecialchars($existing_purchase['supplier_name']); ?></span>
        <span><strong><?php echo t('purchase.purchase_date'); ?>:</strong> <?php echo htmlspecialchars($existing_purchase['purchase_date']); ?></span>
    </div>
<?php endif; ?>
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


        <!-- 1 & 2: 거래처 및 날짜 선택 -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6 border-b border-gray-200 pb-6">
            <div>
                <label for="supplier_search" class="block text-sm font-medium text-gray-700">
                    <span class="text-red-500">*</span> <?php echo t('purchase.supplier_required'); ?>
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
                               placeholder="<?php echo t('purchase.supplier_search_placeholder'); ?>">
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
                <p class="mt-1 text-xs text-gray-500"><?php echo t('purchase.select_supplier_first'); ?></p>
            </div>
            
            <!-- VAT 설정 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">VAT 적용</label>
                <div class="space-y-2">
                    <div class="flex items-center space-x-4">
                        <label class="flex items-center">
                            <input type="radio" name="default_vat_included" value="1" checked class="mr-2 text-indigo-600"> 
                            VAT 포함
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="default_vat_included" value="0" class="mr-2 text-indigo-600"> 
                            VAT 미포함
                        </label>
                    </div>
                    <div class="text-xs text-gray-500">
                        VAT 미포함 선택시 입력 단가에 자동으로 12% 추가하여 원가에 저장됩니다.
                    </div>
                    <!-- 가격 정보 실시간 표시 -->
                    <div id="vat-calculation-display" class="p-2 bg-gray-50 rounded text-sm hidden">
                        <div class="grid grid-cols-3 gap-4 text-xs">
                            <div>입력 단가: ₩<span id="global-input-price">0</span></div>
                            <div>저장될 원가: ₩<span id="global-final-price">0</span></div>
                            <div class="text-blue-600">VAT 금액: ₩<span id="global-vat-amount">0</span></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div>
                <label for="purchase_date" class="block text-sm font-medium text-gray-700"><?php echo t('purchase.purchase_date'); ?></label>
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

        <!-- 3. 통합 검색 (상품명 + 물류바코드) -->
        <div class="mb-6 relative">
            <label for="product_search" class="block text-sm font-medium text-gray-700 mb-1">
                <i class="fas fa-search mr-1 text-gray-600"></i>
                상품 검색 / 물류바코드 스캔
            </label>
            <div class="relative mt-1">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-barcode text-gray-400"></i>
                </div>
                <input type="text"
                       id="product_search"
                       disabled
                       class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm disabled:bg-gray-100 disabled:text-gray-500"
                       placeholder="<?php echo t('js.select_supplier_placeholder'); ?>">
            </div>
            <div id="search_results" class="absolute z-20 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden"></div>
            <p class="mt-2 text-xs text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>
                상품명으로 검색하거나 박스 물류바코드를 스캔하세요 (자동 인식)
            </p>
        </div>

        <!-- 4-9. 상품 목록 -->
        <div class="mb-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo t('purchase.item_list'); ?></h3>
            <div class="overflow-x-auto">
                <table id="item-table" class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.product_name'); ?></th>
                            <th class="hidden sm:table-cell px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.purchase_type'); ?></th>
                            <th class="hidden sm:table-cell px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.quantity'); ?></th>
                            <th class="hidden sm:table-cell px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.pieces_per_box'); ?></th>
                            <th class="hidden sm:table-cell px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.unit_price'); ?></th>
                            <th class="hidden sm:table-cell px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.piece_price'); ?></th>
                            <th class="hidden sm:table-cell px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.total'); ?></th>
                            <th class="hidden sm:table-cell px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo t('purchase.delete'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="item-list" class="bg-white divide-y divide-gray-200">
                        <tr id="empty-row">
                            <td colspan="2" class="px-6 py-12 text-center text-gray-500 sm:hidden">
                                <i class="fas fa-box-open text-4xl text-gray-300 mb-4"></i>
                                <p class="text-sm"><?php echo t('purchase.search_add_products'); ?></p>
                            </td>
                            <td colspan="9" class="hidden sm:table-cell px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-box-open text-4xl text-gray-300 mb-4"></i>
                                <p class="text-sm"><?php echo t('purchase.search_add_products'); ?></p>
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
                    <p class="text-sm text-gray-500"><?php echo t('purchase.total_amount'); ?></p>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-bold text-gray-900"><span id="total-amount">0</span></p>
                </div>
            </div>
        </div>

        <!-- 액션 버튼 -->
        <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200 mt-6">
            <a href="purchase_management.php" class="btn">
                <?php echo t('common.cancel'); ?>
            </a>
            <button type="submit" class="btn-primary">
                <i class="fas fa-save mr-2"></i>
                <?php echo t('purchase.register'); ?>
            </button>
        </div>
    </form>
</div>

<!-- 포장수량 변경 모달 -->
<div id="pieces-per-box-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900"><?php echo t('purchase.modal_pieces_per_box_title'); ?></h3>
        </div>
        <div class="px-6 py-4">
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700 font-semibold">
                            <?php echo t('purchase.modal_pieces_per_box_warning'); ?>
                        </p>
                    </div>
                </div>
            </div>
            <div>
                <label for="modal-pieces-per-box-input" class="block text-sm font-medium text-gray-700 mb-2">
                    <?php echo t('purchase.modal_pieces_per_box_label'); ?>
                </label>
                <input type="number" id="modal-pieces-per-box-input"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                       min="1" value="1">
                <p class="mt-2 text-sm text-gray-500">
                    <?php echo t('purchase.modal_pieces_per_box_help'); ?>
                </p>
            </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end space-x-3">
            <button type="button" id="cancel-pieces-per-box-btn"
                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <?php echo t('purchase.modal_cancel'); ?>
            </button>
            <button type="button" id="confirm-pieces-per-box-btn"
                    class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <?php echo t('purchase.modal_confirm'); ?>
            </button>
        </div>
    </div>
</div>

<script>
// JavaScript translations object
const translations = {
    new_supplier_registration: '<?php echo addslashes(t("purchase.new_supplier_registration")); ?>',
    supplier_name: '<?php echo addslashes(t("purchase.supplier_name")); ?>',
    phone_number: '<?php echo addslashes(t("purchase.phone_number")); ?>',
    cancel: '<?php echo addslashes(t("purchase.cancel")); ?>',
    product_search_placeholder: '<?php echo addslashes(t("purchase.product_search_placeholder")); ?>',
    select_supplier_message: '<?php echo addslashes(t("purchase.select_supplier_message")); ?>',
    product_already_added: '<?php echo addslashes(t("purchase.product_already_added")); ?>',
    new_product_registration: '<?php echo addslashes(t("purchase.new_product_registration")); ?>',
    product_name_ko: '<?php echo addslashes(t("purchase.product_name_ko")); ?>',
    product_name_en: '<?php echo addslashes(t("purchase.product_name_en")); ?>',
    sku: '<?php echo addslashes(t("purchase.sku")); ?>',
    category: '<?php echo addslashes(t("purchase.category")); ?>',
    brand: '<?php echo addslashes(t("purchase.brand")); ?>',
    box_quantity: '<?php echo addslashes(t("purchase.box_quantity")); ?>',
    box_unit: '<?php echo addslashes(t("purchase.box_unit")); ?>',
    piece_unit: '<?php echo addslashes(t("purchase.piece_unit")); ?>',
    confirm_remove: '<?php echo addslashes(t("purchase.js_confirm_remove")); ?>',
    supplier_required: '<?php echo addslashes(t("purchase.js_supplier_required")); ?>',
    product_search_error: '<?php echo addslashes(t("purchase.js_product_search_error")); ?>',
    network_error: '<?php echo addslashes(t("purchase.js_network_error")); ?>',
    product_registered: '<?php echo addslashes(t("purchase.js_product_registered")); ?>',
    product_register_error: '<?php echo addslashes(t("purchase.js_product_register_error")); ?>',
    phone: '<?php echo addslashes(t("purchase.js_phone")); ?>',
    no_barcode: '<?php echo addslashes(t("purchase.js_no_barcode")); ?>',
    register: '<?php echo addslashes(t("purchase.register")); ?>',
    memo: '<?php echo addslashes(t("supplier.memo")); ?>',
    required_field: '<?php echo addslashes(t("forms.required_field")); ?>'
};

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
    searchInput.placeholder = '상품명 검색 또는 물류바코드 스캔';
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
                                    <span class="font-medium">"${searchTerm}" ${translations.new_supplier_registration}</span>
                                </div>
                                <div class="text-sm text-green-600"><?php echo t('purchase.supplier_register_hint'); ?></div>
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
                                        <span class="font-medium">"${searchTerm}" ${translations.new_supplier_registration}</span>
                                    </div>
                                    <div class="text-sm text-green-600"><?php echo t('purchase.supplier_no_results_register'); ?></div>
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
                        supplierSearchResults.innerHTML = '<div class="p-3 text-red-500"><?php echo t("purchase.supplier_search_error"); ?></div>';
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
            selectedSupplierInfo.textContent = (supplier.phone ? t('js.phone_label') + ': ' + supplier.phone : '') + (supplier.memo ? ' | ' + supplier.memo : '');
            
            supplierSearch.style.display = 'none';
            selectedSupplierDiv.classList.remove('hidden');
            supplierSearchResults.classList.add('hidden');
            
            // 검색 필드 상태 업데이트
            updateSearchFieldState();
            searchInput.focus();
        }

        // 거래처 선택 해제
        if (clearSupplierBtn) {
            clearSupplierBtn.addEventListener('click', function() {
                supplierIdInput.value = '';
                supplierSearch.value = '';
                supplierSearch.style.display = 'block';
                selectedSupplierDiv.classList.add('hidden');
                
                // 검색 필드 상태 업데이트
                updateSearchFieldState();
                
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

    // 검색 필드 활성화 상태 확인 및 업데이트 함수
    function updateSearchFieldState() {
        const supplierIdInput = document.getElementById('supplier_id');
        const hasSupplier = supplierIdInput && supplierIdInput.value;
        
        // console.log('검색 필드 상태 업데이트:', hasSupplier ? '활성화' : '비활성화');
        
        if (hasSupplier) {
            searchInput.disabled = false;
            searchInput.placeholder = '상품명 검색 또는 물류바코드 스캔';
            searchInput.classList.remove('disabled:bg-gray-100', 'disabled:text-gray-500');
        } else {
            searchInput.disabled = true;
            searchInput.placeholder = t('js.select_supplier_placeholder');
            searchInput.classList.add('disabled:bg-gray-100', 'disabled:text-gray-500');
            searchInput.value = '';
            searchResults.classList.add('hidden');
        }
    }

    // 기존 거래처 선택 처리 (수정 모드가 아닌 경우 - 하위 호환성)
    if (supplierSelect) {
        supplierSelect.addEventListener('change', function() {
            updateSearchFieldState();
            if (this.value) {
                searchInput.focus();
            }
        });
    }

    // 페이지 로드 시 검색 필드 상태 초기화
    updateSearchFieldState();

    // 전역 변수로 현재 검색 결과 저장
    let currentSearchResults = [];
    let selectedProductIndex = 0; // 선택된 상품 인덱스 (0부터 시작)
    let keyboardNavigationActive = false; // 키보드 네비게이션 활성 상태
    let keyboardNavigationTimer = null; // 키보드 네비게이션 타이머
    let lastSearchTerm = ''; // 마지막 검색어 추적

    // 키보드 네비게이션 상태 관리 함수들
    function activateKeyboardNavigation() {
        keyboardNavigationActive = true;
        
        // 기존 타이머 제거
        if (keyboardNavigationTimer) {
            clearTimeout(keyboardNavigationTimer);
        }
        
        // 3초 후 자동 비활성화
        keyboardNavigationTimer = setTimeout(() => {
            keyboardNavigationActive = false;
        }, 3000);
    }

    function deactivateKeyboardNavigation() {
        keyboardNavigationActive = false;
        if (keyboardNavigationTimer) {
            clearTimeout(keyboardNavigationTimer);
            keyboardNavigationTimer = null;
        }
    }

    // 통합 키보드 네비게이션 이벤트 핸들러
    searchInput.addEventListener('keydown', function(e) {
        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                activateKeyboardNavigation(); // 키보드 네비게이션 활성화
                if (currentSearchResults.length > 0 && selectedProductIndex < currentSearchResults.length - 1) {
                    selectedProductIndex++;
                    updateProductSelection();
                }
                break;
                
            case 'ArrowUp':
                e.preventDefault();
                activateKeyboardNavigation(); // 키보드 네비게이션 활성화
                if (currentSearchResults.length > 0 && selectedProductIndex > 0) {
                    selectedProductIndex--;
                    updateProductSelection();
                }
                break;
                
            case 'Enter':
                e.preventDefault(); // 폼 제출 방지
                if (currentSearchResults.length > 0) {
                    // 선택된 인덱스의 상품 추가
                    addProductToList(currentSearchResults[selectedProductIndex]);
                    resetProductSearch();
                    deactivateKeyboardNavigation(); // 선택 후 비활성화
                }
                // 검색 결과가 없을 때는 아무것도 하지 않음 (기존 동작 유지)
                break;
                
            case 'Escape':
                resetProductSearch();
                deactivateKeyboardNavigation(); // Escape로 종료 시 비활성화
                break;
        }
    });

    // 상품 검색 (실시간)
    searchInput.addEventListener('keyup', function(e) {
        // 엔터키는 keydown에서 처리하므로 제외
        if (e.key === 'Enter') {
            return;
        }

        // 거래처가 선택되지 않았으면 검색 차단
        const supplierIdInput = document.getElementById('supplier_id');
        const supplierId = supplierIdInput ? supplierIdInput.value : '';
        
        // console.log('검색 시 거래처 ID 확인:', supplierId);
        
        if (!supplierId) {
            alert(t('js.select_supplier_first'));
            this.blur();
            return;
        }

        const searchTerm = this.value.trim();
        clearTimeout(searchTimeout);

        if (searchTerm.length < 2) {
            searchResults.innerHTML = '';
            searchResults.classList.add('hidden');
            currentSearchResults = [];
            lastSearchTerm = '';
            return;
        }

        // 검색어가 변경되지 않았고 키보드 네비게이션 중이면 새 검색 방지
        if (searchTerm === lastSearchTerm && keyboardNavigationActive) {
            return;
        }

        searchTimeout = setTimeout(() => {
            // 키보드 네비게이션 활성 중에는 새로운 검색 요청 차단
            if (keyboardNavigationActive && searchTerm === lastSearchTerm) {
                return;
            }

            // 바코드 패턴 감지 및 자동 전환
            if (isLikelyBarcode(searchTerm)) {
                console.log('🔍 바코드 패턴 감지, 물류바코드 검색으로 전환:', searchTerm);
                tryLogisticsBarcode(searchTerm);
                return; // 상품명 검색 중단
            }

            // 일반 상품명 검색
            console.log('상품명 검색 시작:', searchTerm);

            fetch(`ajax_search_products.php?term=${encodeURIComponent(searchTerm)}`)
                .then(response => {
                    // console.log('검색 응답 상태:', response.status);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    // console.log('검색 결과:', data);
                    
                    if (data.error) {
                        console.error('검색 오류:', data.error);
                        alert(t('js.search_error') + ': ' + data.error);
                        currentSearchResults = [];
                        return;
                    }
                    
                    // 검색 결과를 전역 변수에 저장
                    currentSearchResults = data;
                    lastSearchTerm = searchTerm; // 현재 검색어 저장
                    
                    // 바코드 정확히 일치 시 바로 추가
                    if (data.length === 1 && data[0].exact_match) {
                        addProductToList(data[0]);
                        searchInput.value = '';
                        searchResults.classList.add('hidden');
                        currentSearchResults = [];
                    } else {
                        // 검색 결과 초기화 및 선택 인덱스 리셋
                        selectedProductIndex = 0;
                        searchResults.innerHTML = '';
                        
                        if (data.length > 0) {
                            data.forEach((product, index) => {
                                const div = document.createElement('div');
                                div.className = 'product-result cursor-pointer p-3';
                                
                                // 첫 번째 항목은 기본 선택 상태
                                if (index === 0) {
                                    div.classList.add('bg-indigo-200', 'border-l-4', 'border-indigo-500');
                                } else {
                                    div.classList.add('hover:bg-indigo-50');
                                }
                                
                                div.innerHTML = `
                                    <p class="font-semibold">${product.name_ko} <span class="text-gray-500 font-normal">(${product.name_en})</span></p>
                                    <p class="text-sm text-gray-500">SKU: ${product.sku} | 바코드: ${product.barcode || '없음'}</p>
                                    ${index === 0 ? '<p class="text-xs text-blue-600 mt-1"><i class="fas fa-keyboard mr-1"></i>↑↓ 네비게이션, ↵ 선택</p>' : ''}
                                `;
                                
                                div.addEventListener('click', () => {
                                    selectedProductIndex = index;
                                    addProductToList(currentSearchResults[selectedProductIndex]);
                                    resetProductSearch();
                                });
                                
                                searchResults.appendChild(div);
                            });
                            searchResults.classList.remove('hidden');
                        } else {
                            // 검색 결과 없을 때 추가하기 버튼 표시
                            searchResults.innerHTML = `
                                <div class="p-3 bg-gradient-to-r from-green-50 to-blue-50 border-l-4 border-green-500">
                                    <div class="text-gray-600 mb-3 flex items-center">
                                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                                        <?php echo t('purchase.no_search_results'); ?>
                                    </div>
                                    <button type="button" id="add-new-product-btn" 
                                            class="w-full px-4 py-3 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white text-sm font-medium rounded-lg shadow-md hover:shadow-lg transition-all duration-200 flex items-center justify-center border-2 border-green-400 hover:border-green-500">
                                        <i class="fas fa-plus-circle mr-2 text-lg"></i>
                                        <span class="font-semibold">"${searchTerm}" <?php echo t('purchase.add_new_product'); ?></span>
                                    </button>
                                    <div class="text-xs text-gray-500 mt-2 text-center">
                                        <i class="fas fa-lightbulb mr-1"></i>
                                        <?php echo t('purchase.quick_product_registration'); ?>
                                    </div>
                                </div>
                            `;
                            
                            // 추가하기 버튼 클릭 이벤트
                            const addButton = searchResults.querySelector('#add-new-product-btn');
                            if (addButton) {
                                addButton.addEventListener('click', function() {
                                    showAddProductModal(searchTerm);
                                });
                            }
                            
                            searchResults.classList.remove('hidden');
                            currentSearchResults = [];
                        }
                    }
                })
                .catch(error => {
                    console.error('상품 검색 요청 실패:', error);
                    alert(t('js.network_error'));
                    currentSearchResults = [];
                    searchResults.innerHTML = `
                        <div class="p-3 bg-red-50 border-l-4 border-red-500">
                            <div class="text-red-600 text-sm">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
<?php echo t('purchase.product_search_network_error'); ?>
                            </div>
                        </div>
                    `;
                    searchResults.classList.remove('hidden');
                });
        }, 300); // 300ms 디바운스
    });

    // 바코드 패턴 감지 함수
    function isLikelyBarcode(input) {
        // 8자리 이상의 연속된 숫자이거나, 특정 패턴을 포함하면 바코드로 판단
        const pureNumbers = input.replace(/[^0-9]/g, '');

        // 1. 8자리 이상의 숫자만 있는 경우
        if (pureNumbers.length >= 8 && input === pureNumbers) {
            return true;
        }

        // 2. 13~14자리 연속 숫자 (EAN-13, ITF-14)
        if (pureNumbers.length >= 13 && pureNumbers.length <= 14) {
            return true;
        }

        // 3. BOX-, CASE-, CTN- 등 접두사가 있는 경우
        if (/^(BOX|CASE|CTN|PACK|BX|CS)-/i.test(input)) {
            return true;
        }

        // 4. GS1-128 패턴
        if (/\(01\)\d{14}/.test(input)) {
            return true;
        }

        return false;
    }

    // 물류바코드 처리 함수 (통합)
    function tryLogisticsBarcode(barcode) {
        console.log('물류바코드 분석 시작:', barcode);

        fetch(`ajax_parse_logistics_barcode.php?logistics_barcode=${encodeURIComponent(barcode)}`)
            .then(response => response.json())
            .then(data => {
                console.log('물류바코드 분석 응답:', data);

                if (data.success && data.data) {
                    const product = data.data;

                    // 디버그 정보 출력
                    if (data.extracted_barcodes) {
                        console.log('추출된 바코드들:', data.extracted_barcodes);
                        console.log('매칭된 SKU:', data.matched_sku);
                    }

                    // 이미 추가된 상품인지 확인하고, 있으면 수량 증가
                    const existingItems = itemList.querySelectorAll('tr:not(#empty-row)');
                    let existingRow = null;

                    existingItems.forEach(row => {
                        const productIdInput = row.querySelector('input[name^="items"][name$="[product_id]"]');
                        if (productIdInput && productIdInput.value == product.id) {
                            existingRow = row;
                        }
                    });

                    if (existingRow) {
                        // 기존 상품의 수량 1 증가
                        const quantityInput = existingRow.querySelector('input[name^="items"][name$="[quantity]"]');
                        if (quantityInput) {
                            const currentQty = parseInt(quantityInput.value) || 0;
                            quantityInput.value = currentQty + 1;

                            // 합계 재계산 트리거
                            quantityInput.dispatchEvent(new Event('input'));

                            // 시각적 피드백 (행 하이라이트)
                            existingRow.style.backgroundColor = '#d1fae5';
                            setTimeout(() => {
                                existingRow.style.backgroundColor = '';
                            }, 500);

                            showSuccessMessage(`${product.name_ko} 수량 증가 (${currentQty + 1}개)`);
                        }

                        searchInput.value = '';
                        searchResults.classList.add('hidden');
                        return;
                    }

                    // 상품 자동 추가
                    addProductToList(product);

                    // 입력 필드 초기화 및 성공 피드백
                    searchInput.value = '';
                    searchInput.style.backgroundColor = '#d1fae5'; // 녹색 배경
                    searchResults.classList.add('hidden');

                    setTimeout(() => {
                        searchInput.style.backgroundColor = '';
                    }, 500);

                    // 성공 메시지 표시 (매칭 정보 포함)
                    const matchInfo = data.matched_sku ? ` (SKU: ${data.matched_sku})` : '';
                    showSuccessMessage('물류바코드 인식 성공: ' + product.name_ko + matchInfo);

                } else {
                    // 물류바코드로 찾지 못했으면 일반 상품명 검색 실행
                    console.log('물류바코드 매칭 실패, 상품명 검색으로 전환');
                    performProductNameSearch(barcode);
                }
            })
            .catch(error => {
                console.error('물류바코드 분석 오류:', error);
                // 오류 발생 시에도 상품명 검색으로 폴백
                performProductNameSearch(barcode);
            });
    }

    // 상품명 검색 수행 함수
    function performProductNameSearch(searchTerm) {
        fetch(`ajax_search_products.php?term=${encodeURIComponent(searchTerm)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    console.error('검색 오류:', data.error);
                    alert(t('js.search_error') + ': ' + data.error);
                    currentSearchResults = [];
                    return;
                }

                // 검색 결과를 전역 변수에 저장
                currentSearchResults = data;
                lastSearchTerm = searchTerm;

                // 바코드 정확히 일치 시 바로 추가
                if (data.length === 1 && data[0].exact_match) {
                    addProductToList(data[0]);
                    searchInput.value = '';
                    searchResults.classList.add('hidden');
                    currentSearchResults = [];
                } else {
                    // 검색 결과 초기화 및 선택 인덱스 리셋
                    selectedProductIndex = 0;
                    searchResults.innerHTML = '';

                    if (data.length > 0) {
                        data.forEach((product, index) => {
                            const div = document.createElement('div');
                            div.className = 'product-result cursor-pointer p-3';

                            if (index === 0) {
                                div.classList.add('bg-indigo-200', 'border-l-4', 'border-indigo-500');
                            } else {
                                div.classList.add('hover:bg-indigo-50');
                            }

                            div.innerHTML = `
                                <p class="font-semibold">${product.name_ko} <span class="text-gray-500 font-normal">(${product.name_en})</span></p>
                                <p class="text-sm text-gray-500">SKU: ${product.sku} | 바코드: ${product.barcode || '없음'}</p>
                                ${index === 0 ? '<p class="text-xs text-blue-600 mt-1"><i class="fas fa-keyboard mr-1"></i>↑↓ 네비게이션, ↵ 선택</p>' : ''}
                            `;

                            div.addEventListener('click', () => {
                                selectedProductIndex = index;
                                addProductToList(currentSearchResults[selectedProductIndex]);
                                resetProductSearch();
                            });

                            searchResults.appendChild(div);
                        });
                        searchResults.classList.remove('hidden');
                    } else {
                        // 검색 결과 없을 때 추가하기 버튼 표시
                        searchResults.innerHTML = `
                            <div class="p-3 bg-gradient-to-r from-green-50 to-blue-50 border-l-4 border-green-500">
                                <div class="text-gray-600 mb-3 flex items-center">
                                    <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                                    <?php echo t('purchase.no_search_results'); ?>
                                </div>
                                <button type="button" id="add-new-product-btn"
                                        class="w-full px-4 py-3 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white text-sm font-medium rounded-lg shadow-md hover:shadow-lg transition-all duration-200 flex items-center justify-center border-2 border-green-400 hover:border-green-500">
                                    <i class="fas fa-plus-circle mr-2 text-lg"></i>
                                    <span class="font-semibold">"${searchTerm}" <?php echo t('purchase.add_new_product'); ?></span>
                                </button>
                                <div class="text-xs text-gray-500 mt-2 text-center">
                                    <i class="fas fa-lightbulb mr-1"></i>
                                    <?php echo t('purchase.quick_product_registration'); ?>
                                </div>
                            </div>
                        `;

                        const addButton = searchResults.querySelector('#add-new-product-btn');
                        if (addButton) {
                            addButton.addEventListener('click', function() {
                                showAddProductModal(searchTerm);
                            });
                        }

                        searchResults.classList.remove('hidden');
                        currentSearchResults = [];
                    }
                }
            })
            .catch(error => {
                console.error('상품 검색 오류:', error);
                alert(t('js.network_error'));
                currentSearchResults = [];
                searchResults.innerHTML = `
                    <div class="p-3 bg-red-50 border-l-4 border-red-500">
                        <div class="text-red-600 text-sm">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <?php echo t('purchase.product_search_network_error'); ?>
                        </div>
                    </div>
                `;
                searchResults.classList.remove('hidden');
            });
    }

    // 성공 메시지 표시 함수
    function showSuccessMessage(message) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50';
        messageDiv.innerHTML = `
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <span>${message}</span>
            </div>
        `;
        document.body.appendChild(messageDiv);

        setTimeout(() => {
            messageDiv.remove();
        }, 3000);
    }

    // 검색 결과 외부 클릭 시 숨기기 (키보드 네비게이션 중에는 무시)
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            // 키보드 네비게이션이 활성화된 상태에서는 외부 클릭 무시
            if (keyboardNavigationActive) {
                return; // 아무것도 하지 않음
            }
            resetProductSearch();
        }
    });

    // 시각적 피드백 업데이트 함수
    function updateProductSelection() {
        const resultDivs = searchResults.querySelectorAll('.product-result');
        resultDivs.forEach((div, index) => {
            // 기존 선택 스타일 제거
            div.classList.remove('bg-indigo-200', 'border-l-4', 'border-indigo-500', 'bg-blue-50', 'border-blue-500');
            
            if (index === selectedProductIndex) {
                // 선택된 항목 스타일 적용
                div.classList.add('bg-indigo-200', 'border-l-4', 'border-indigo-500');
                
                // 스크롤 자동 조정
                const container = searchResults;
                const divTop = div.offsetTop;
                const divBottom = divTop + div.offsetHeight;
                const containerTop = container.scrollTop;
                const containerBottom = containerTop + container.clientHeight;
                
                if (divTop < containerTop) {
                    container.scrollTop = divTop;
                } else if (divBottom > containerBottom) {
                    container.scrollTop = divBottom - container.clientHeight;
                }
            } else {
                // 기본 hover 스타일 복원
                div.classList.add('hover:bg-indigo-50');
            }
        });
    }

    // 검색 초기화 함수
    function resetProductSearch() {
        currentSearchResults = [];
        selectedProductIndex = 0;
        searchInput.value = '';
        searchResults.classList.add('hidden');
        searchResults.innerHTML = '';
        lastSearchTerm = ''; // 검색어 기록 초기화
        deactivateKeyboardNavigation(); // 키보드 네비게이션 비활성화
    }

    // 신규 상품 등록 모달 표시 함수
    function showAddProductModal(searchTerm) {
        // 모달이 이미 존재하면 제거
        const existingModal = document.getElementById('add-product-modal');
        if (existingModal) {
            existingModal.remove();
        }

        // 검색어가 숫자인지 확인 (바코드 가능성)
        const isNumeric = /^\d+$/.test(searchTerm);
        
        const modal = document.createElement('div');
        modal.id = 'add-product-modal';
        modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
        
        modal.innerHTML = `
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full mx-auto">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">
                                <i class="fas fa-plus-circle text-green-600 mr-2"></i>
                                <?php echo t('purchase.quick_product_registration_title'); ?>
                            </h3>
                            <button type="button" id="close-modal" class="text-gray-400 hover:text-gray-600 p-1">
                                <i class="fas fa-times text-lg"></i>
                            </button>
                        </div>
                        
                        <form id="add-product-form" class="space-y-4">
                            <!-- 상품명 (영어) -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    상품명 (영어) <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="product-name-en" name="name_en" required
                                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                       placeholder="Product name in English">
                            </div>
                            
                            <!-- 상품명 (한국어) -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    상품명 (한국어)
                                </label>
                                <input type="text" id="product-name-ko" name="name_ko"
                                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                       placeholder="상품명을 입력하세요">
                            </div>
                            
                            <div class="grid grid-cols-2 gap-3">
                                <!-- SKU (자동 생성) -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        SKU <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="product-sku" name="sku" required readonly
                                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md bg-gray-50 focus:outline-none"
                                           placeholder="자동 생성">
                                </div>
                                
                                <!-- 박스당 개수 -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        <?php echo t('purchase.pieces_per_box'); ?>
                                    </label>
                                    <input type="number" id="product-pieces-per-box" name="pieces_per_box"
                                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                           value="1" min="1">
                                </div>
                            </div>
                            
                            <!-- 설명 -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    설명
                                </label>
                                <textarea id="product-description" name="description" rows="2"
                                          class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 resize-none"
                                          placeholder="간단한 설명 (선택사항)"></textarea>
                            </div>
                            
                            <!-- 버튼 -->
                            <div class="flex justify-end space-x-3 pt-4 border-t">
                                <button type="button" id="cancel-add-product"
                                        class="px-4 py-2 text-sm text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors">
                                    <?php echo t('common.cancel'); ?>
                                </button>
                                <button type="submit" id="save-new-product"
                                        class="px-4 py-2 text-sm bg-green-600 hover:bg-green-700 text-white rounded-md transition-colors font-medium">
                                    <i class="fas fa-save mr-1"></i>
                                    <?php echo t('purchase.save_and_add'); ?>
                                </button>
                            </div>
                        </form>
                        
                        <!-- 로딩 오버레이 -->
                        <div id="modal-loading" class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center hidden rounded-lg">
                            <div class="text-center">
                                <i class="fas fa-spinner fa-spin text-2xl text-green-600 mb-2"></i>
                                <p class="text-sm text-gray-600"><?php echo t('purchase.registering'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // 검색어는 상품명에 자동으로 넣지 않음 (사용자가 직접 입력)
        
        // SKU 자동 생성 (검색어 기반)
        generateSKUFromSearchTerm(searchTerm);
        
        // 이벤트 리스너 설정
        setupModalEventListeners();
        
        // 첫 번째 입력 필드에 포커스 (영어명이 필수이므로)
        setTimeout(() => {
            document.getElementById('product-name-en').focus();
        }, 100);
    }
    
    // 카테고리 로드 함수
    function loadCategories() {
        const categorySelect = document.getElementById('product-category');
        const categoryLoading = document.getElementById('category-loading');
        const categoryWarning = document.getElementById('category-warning');
        const categoryWarningText = document.getElementById('category-warning-text');
        
        categoryLoading.classList.remove('hidden');
        categoryWarning.classList.add('hidden');
        
        fetch('ajax_search_categories.php')
            .then(response => response.json())
            .then(data => {
                categoryLoading.classList.add('hidden');
                
                if (data.success) {
                    categorySelect.innerHTML = '<option value="">카테고리를 선택하세요 (선택사항)</option>';
                    
                    // 카테고리가 있으면 추가
                    if (data.categories && data.categories.length > 0) {
                        data.categories.forEach(category => {
                            const option = document.createElement('option');
                            option.value = category.id;
                            option.textContent = category.name_ko;
                            categorySelect.appendChild(option);
                        });
                    }
                    
                    // 경고 메시지가 있으면 표시
                    if (data.warning) {
                        categoryWarningText.textContent = data.warning;
                        categoryWarning.classList.remove('hidden');
                    }
                    
                    // console.log(`카테고리 로드 완료: ${data.categories ? data.categories.length : 0}개 카테고리`);
                } else {
                    // 실패 시에도 계속 진행 가능하도록 함
                    categorySelect.innerHTML = '<option value="">카테고리 없음 (선택사항)</option>';
                    categoryWarningText.textContent = t('category.load_error') + '. ' + t('product.add') + ' ' + t('forms.optional');
                    categoryWarning.classList.remove('hidden');
                    console.error('카테고리 로드 실패:', data.message || '알 수 없는 오류');
                }
            })
            .catch(error => {
                categoryLoading.classList.add('hidden');
                // 에러 시에도 계속 진행 가능하도록 함
                categorySelect.innerHTML = '<option value="">카테고리 없음 (선택사항)</option>';
                categoryWarningText.textContent = t('category.load_error') + '. ' + t('product.add') + ' ' + t('forms.optional');
                categoryWarning.classList.remove('hidden');
                console.error('카테고리 로드 오류:', error);
            });
    }
    
    // SKU 자동 생성 함수 (검색어 기반)
    function generateSKUFromSearchTerm(searchTerm) {
        const skuInput = document.getElementById('product-sku');
        
        if (searchTerm && searchTerm.trim()) {
            // 검색어를 SKU로 사용
            skuInput.value = searchTerm.trim();
        } else {
            // 검색어가 없으면 자동 생성
            const timestamp = Date.now().toString().slice(-8);
            const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            skuInput.value = `SKU${timestamp}${random}`;
        }
    }
    
    // 바코드 입력 시 SKU 자동 업데이트
    function setupBarcodeToSKUSync() {
        const barcodeInput = document.getElementById('product-barcode');
        const skuInput = document.getElementById('product-sku');
        
        if (barcodeInput) {
            barcodeInput.addEventListener('input', function() {
                const barcodeValue = this.value.trim();
                const isNumeric = /^\d+$/.test(barcodeValue);
                
                if (isNumeric && barcodeValue) {
                    // 숫자 바코드면 SKU로 자동 설정
                    skuInput.value = barcodeValue;
                } else if (!barcodeValue) {
                    // 바코드가 비어있으면 자동 생성된 SKU로 복원
                    const timestamp = Date.now().toString().slice(-8);
                    const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                    skuInput.value = `NEW${timestamp}${random}`;
                }
            });
        }
    }
    
    // 모달 이벤트 리스너 설정
    function setupModalEventListeners() {
        const modal = document.getElementById('add-product-modal');
        const closeBtn = document.getElementById('close-modal');
        const cancelBtn = document.getElementById('cancel-add-product');
        const form = document.getElementById('add-product-form');
        
        // 모달 닫기
        function closeModal() {
            modal.remove();
        }
        
        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        
        // 모달 배경 클릭 시 닫기 (flexbox 컨테이너 클릭 시에만)
        modal.addEventListener('click', function(e) {
            if (e.target === modal || e.target.classList.contains('flex')) {
                closeModal();
            }
        });
        
        // ESC 키로 모달 닫기
        document.addEventListener('keydown', function escListener(e) {
            if (e.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', escListener);
            }
        });
        
        // 폼 제출
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            saveNewProduct();
        });
    }
    
    // 신규 상품 저장 함수
    function saveNewProduct() {
        const form = document.getElementById('add-product-form');
        const formData = new FormData(form);
        const loadingOverlay = document.getElementById('modal-loading');
        
        // 필수 필드 검증 (영어명과 SKU만 필수)
        const requiredFields = ['name_en', 'sku'];
        for (let field of requiredFields) {
            if (!formData.get(field)) {
                alert(field === 'name_en' ? t('js.product_name_en_required') : t('js.sku_required'));
                return;
            }
        }
        
        // 카테고리 필드가 제거되어 관련 검증 불필요
        
        loadingOverlay.classList.remove('hidden');
        
        fetch('ajax_add_product.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            loadingOverlay.classList.add('hidden');
            
            if (data.success) {
                // 모달 닫기
                document.getElementById('add-product-modal').remove();
                
                // 성공 메시지
                alert(t('js.product_registered'));
                
                // 매입 목록에 상품 추가 (isNew = true)
                addProductToList(data.product, 1, null, 'box', null, true);
                
                // 검색 입력 필드 초기화
                resetProductSearch();
            } else {
                // 오류 메시지 표시 (상세 정보 포함)
                let errorMsg = t('js.product_register_failed') + ': ' + data.message;
                
                // 개발 환경용 상세 정보
                if (data.sql_error) {
                    console.error('SQL 오류 상세:', data.sql_error);
                    console.error('디버그 정보:', data.debug);
                    console.error('오류 코드:', data.error_code);
                    errorMsg += '\n\n개발자 도구 콘솔을 확인해주세요.';
                }
                
                alert(errorMsg);
            }
        })
        .catch(error => {
            loadingOverlay.classList.add('hidden');
            console.error(t('js.product_register_error') + ':', error);
            alert(t('js.product_register_error'));
        });
    }

    // 4. 상품 목록에 추가
    function addProductToList(product, quantity = 1, unitPrice = null, purchaseType = 'box', existingItemId = null, isNew = false) {
        // 중복 상품 확인 (기존 상품이 아닌 경우만) - 수량 증가
        if (existingItemId === null) {
            const existingRows = itemList.querySelectorAll('.item-row');
            for (let row of existingRows) {
                const productIdInput = row.querySelector('input[name*="[product_id]"]');
                if (productIdInput && productIdInput.value == product.id) {
                    // 중복 상품 발견 시 수량 증가
                    const quantityInput = row.querySelector('input[name*="[quantity]"]');
                    if (quantityInput && !quantityInput.hasAttribute('readonly')) {
                        const currentQty = parseInt(quantityInput.value) || 0;
                        quantityInput.value = currentQty + 1;

                        // 합계 재계산 트리거
                        quantityInput.dispatchEvent(new Event('input'));

                        // 시각적 피드백 (행 하이라이트)
                        row.style.backgroundColor = '#d1fae5';
                        setTimeout(() => {
                            row.style.backgroundColor = '';
                        }, 500);

                        showSuccessMessage(`${product.name_ko} 수량 증가 (${currentQty + 1}개)`);
                        return; // 수량 증가 후 함수 종료
                    }
                    break;
                }
            }
        }
        
        const newRow = document.createElement('tr');
        newRow.classList.add('item-row');
        newRow.dataset.piecesPerBox = product.pieces_per_box || 1;
        newRow.dataset.productId = product.id; // 중복 검사를 위한 product_id 저장
        
        // 단가 설정: unitPrice가 있으면 사용, 없으면 박스단가(box_price) 사용, 그것도 없으면 0
        let finalUnitPrice;
        if (unitPrice !== null) {
            finalUnitPrice = unitPrice;
        } else if (product.box_price && product.box_price > 0) {
            finalUnitPrice = product.box_price; // 점포에 설정된 박스단가 사용
        } else {
            finalUnitPrice = 0; // 기본값을 0으로 설정
        }
        const isExisting = existingItemId !== null;
        
        newRow.innerHTML = `
            <td class="px-6 py-4 whitespace-nowrap">
                <span class="text-xs font-mono text-gray-600">${product.sku || '없음'}</span>
                ${isExisting ? '<span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">기존</span>' : ''}
                ${isNew ? '<div class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded mt-1 inline-block">(NEW)</div>' : ''}
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <input type="hidden" name="items[${itemIndex}][product_id]" value="${product.id}">
                ${isExisting ? `<input type="hidden" name="items[${itemIndex}][existing_item_id]" value="${existingItemId}">` : ''}
                <div class="text-sm font-medium text-gray-900">${product.name_ko}</div>
                <div class="text-xs text-gray-500">${product.name_en || ''}</div>
            </td>
            <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap text-center">
                <div class="flex items-center justify-center space-x-3">
                    <label class="inline-flex items-center">
                        <input type="radio" name="items[${itemIndex}][purchase_type]" value="box" 
                               class="purchase-type text-indigo-600 border-gray-300 focus:ring-indigo-500" 
                               ${purchaseType === 'box' ? 'checked' : ''} 
                               ${isExisting ? 'disabled' : ''}>
                        <span class="ml-1 text-xs"><?php echo t('purchase.box_unit'); ?></span>
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="items[${itemIndex}][purchase_type]" value="piece" 
                               class="purchase-type text-indigo-600 border-gray-300 focus:ring-indigo-500" 
                               ${purchaseType === 'piece' ? 'checked' : ''} 
                               ${isExisting ? 'disabled' : ''}>
                        <span class="ml-1 text-xs"><?php echo t('purchase.piece_unit'); ?></span>
                    </label>
                </div>
                ${isExisting ? `<input type="hidden" name="items[${itemIndex}][purchase_type]" value="${purchaseType}">` : ''}
            </td>
            <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap text-right">
                <input type="number" name="items[${itemIndex}][quantity]" class="w-16 px-2 py-1 border border-gray-300 rounded-md text-right text-sm quantity focus:border-indigo-500 focus:ring-indigo-500 ${isExisting ? 'bg-gray-100' : ''}" min="1" value="${quantity}" ${isExisting ? 'readonly' : ''}>
            </td>
            <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap text-right">
                <input type="number" name="items[${itemIndex}][pieces_per_box]" class="w-16 px-2 py-1 border border-gray-300 rounded-md text-right text-sm pieces-per-box focus:border-indigo-500 focus:ring-indigo-500 ${isExisting ? 'bg-gray-100' : ''}" min="1" value="${product.pieces_per_box || 1}" ${isExisting ? 'readonly' : ''}>
                <span class="text-xs text-gray-500 ml-1">개</span>
            </td>
            <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap text-right">
                <input type="number" name="items[${itemIndex}][unit_price]" class="w-20 px-2 py-1 border border-gray-300 rounded-md text-right text-sm unit-price focus:border-indigo-500 focus:ring-indigo-500 ${isExisting ? 'bg-gray-100' : ''}" step="0.01" min="0" value="${finalUnitPrice}" placeholder="0원 가능" ${isExisting ? 'readonly' : ''}>
                <!-- VAT 관련 hidden 필드 -->
                <input type="hidden" name="items[${itemIndex}][vat_included]" class="vat-included-field" value="1">
            </td>
            <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap text-right piece-price text-sm text-gray-500"></td>
            <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap text-right row-total font-semibold text-gray-900">0</td>
            <td class="hidden sm:table-cell px-6 py-4 whitespace-nowrap text-center">
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
                    // 값이 입력되었는지 확인 (0원 포함)
                    if (this.value !== '' && this.value !== null && !isNaN(parseFloat(this.value))) {
                        searchInput.focus();
                        searchInput.select(); // 검색창의 기존 텍스트 선택
                    }
                }
            });
            
            // 수량 입력 후 엔터 시 단가로 포커스 이동
            newRow.querySelector('.quantity').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    newRow.querySelector('.unit-price').focus();
                    newRow.querySelector('.unit-price').select();
                }
            });
            
            // 박스수량 입력 후 엔터 시 단가로 포커스 이동
            newRow.querySelector('.pieces-per-box').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    newRow.querySelector('.unit-price').focus();
                    newRow.querySelector('.unit-price').select();
                }
            });
        }
    }

    // 5, 7, 8. 수량/단가/박스당수량 변경 시 합계 업데이트
    itemList.addEventListener('input', function(e) {
        if (e.target.classList.contains('quantity') || e.target.classList.contains('unit-price') || e.target.classList.contains('pieces-per-box')) {
            const row = e.target.closest('tr');
            
            // 박스당 수량이 변경된 경우 dataset도 업데이트
            if (e.target.classList.contains('pieces-per-box')) {
                row.dataset.piecesPerBox = e.target.value || 1;
            }
            
            updateRow(row);
        }
    });
    
    // 매입유형 라디오 버튼 변경 시 합계 업데이트
    itemList.addEventListener('change', function(e) {
        if (e.target.classList.contains('purchase-type')) {
            const row = e.target.closest('tr');
            updateRow(row);
        }
    });
    
    // 박스당 수량 변경 시 상품정보 자동 업데이트 (박스 구매유형일 때만)
    itemList.addEventListener('change', function(e) {
        if (e.target.classList.contains('pieces-per-box')) {
            const row = e.target.closest('tr');
            const productId = row.dataset.productId;
            const newPiecesPerBox = parseInt(e.target.value) || 1;
            const purchaseTypeRadio = row.querySelector('.purchase-type:checked');
            const purchaseType = purchaseTypeRadio ? purchaseTypeRadio.value : 'box';
            
            // 기존 상품이 아니고, 구매유형이 박스인 경우에만 업데이트
            if (productId && !e.target.readOnly && purchaseType === 'box') {
                updateProductPiecesPerBox(productId, newPiecesPerBox, row);
            }
        }
        
        // 구매유형이 박스로 변경될 때도 박스당 수량 자동 업데이트 확인 (라디오 버튼용 수정)
        if (e.target.classList.contains('purchase-type') && e.target.checked && e.target.value === 'box') {
            const row = e.target.closest('tr');
            const productId = row.dataset.productId;
            const piecesPerBoxInput = row.querySelector('.pieces-per-box');
            const newPiecesPerBox = parseInt(piecesPerBoxInput.value) || 1;
            
            // 기존 상품이 아닌 경우에만 업데이트
            if (productId && !piecesPerBoxInput.readOnly) {
                updateProductPiecesPerBox(productId, newPiecesPerBox, row);
            }
        }
    });

    // 9. 삭제 버튼
    itemList.addEventListener('click', function(e) {
        // 삭제 버튼이나 그 안의 아이콘을 클릭했는지 확인
        const removeButton = e.target.closest('.remove-row');
        if (removeButton) {
            e.preventDefault();
            
            // 확인 대화상자 표시
            if (confirm('이 상품을 목록에서 삭제하시겠습니까?')) {
                removeButton.closest('tr').remove();
                updateTotalAmount();
                
                // 상품 행이 모두 제거되면 빈 행 다시 표시
                const productRows = itemList.querySelectorAll('.item-row');
                const emptyRow = document.getElementById('empty-row');
                if (productRows.length === 0 && emptyRow) {
                    emptyRow.style.display = '';
                }
            }
        }
    });

    function updateRow(row) {
        const purchaseTypeRadio = row.querySelector('.purchase-type:checked');
        const purchaseType = purchaseTypeRadio ? purchaseTypeRadio.value : 'box';
        const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.unit-price').value) || 0;
        const piecesPerBoxInput = row.querySelector('.pieces-per-box');
        const piecesPerBox = piecesPerBoxInput ? parseInt(piecesPerBoxInput.value) || 1 : parseInt(row.dataset.piecesPerBox) || 1;

        const rowTotal = quantity * unitPrice;
        row.querySelector('.row-total').textContent = rowTotal.toLocaleString();

        let piecePrice = 0;
        if (purchaseType === 'box' && piecesPerBox > 0) {
            piecePrice = unitPrice / piecesPerBox;
        } else {
            piecePrice = unitPrice;
        }
        row.querySelector('.piece-price').textContent = Math.round(piecePrice).toLocaleString();

        updateTotalAmount();
    }

    // updateRowTotal은 updateRow의 별칭 (호환성 유지)
    const updateRowTotal = updateRow;

    function updateTotalAmount() {
        let total = 0;
        document.querySelectorAll('.row-total').forEach(function (el) {
            // 콤마를 제거하고 숫자만 추출
            const amount = el.textContent.replace(/[,]/g, '');
            total += parseFloat(amount) || 0;
        });
        document.getElementById('total-amount').textContent = total.toLocaleString();
    }


    // 상품의 박스당 수량 업데이트 함수
    function updateProductPiecesPerBox(productId, newPiecesPerBox, row) {
        // 값이 양수가 아니면 업데이트하지 않음
        if (newPiecesPerBox <= 0) {
            return;
        }
        
        // AJAX 요청으로 상품정보 업데이트
        fetch('ajax_update_pieces_per_box.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                product_id: parseInt(productId),
                pieces_per_box: newPiecesPerBox
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 성공 메시지를 해당 행에 임시 표시
                showRowMessage(row, data.message, 'success');
                // console.log('상품 박스당 수량 업데이트 성공:', data);
            } else {
                // 오류 메시지 표시
                showRowMessage(row, data.error || t('js.pieces_per_box_update_failed'), 'error');
                console.error('상품 박스당 수량 업데이트 실패:', data);
            }
        })
        .catch(error => {
            console.error('박스당 수량 업데이트 요청 실패:', error);
            showRowMessage(row, t('js.network_error'), 'error');
        });
    }
    
    // 행별 메시지 표시 함수
    function showRowMessage(row, message, type) {
        // 기존 메시지 제거
        const existingMessage = row.querySelector('.row-message');
        if (existingMessage) {
            existingMessage.remove();
        }
        
        // 새 메시지 생성
        const messageDiv = document.createElement('div');
        messageDiv.className = `row-message absolute z-10 mt-1 p-2 rounded text-xs ${type === 'success' ? 'bg-green-100 text-green-800 border border-green-300' : 'bg-red-100 text-red-800 border border-red-300'}`;
        messageDiv.textContent = message;
        
        // 박스당 수량 필드의 부모에 상대 위치 설정 및 메시지 추가
        const piecesPerBoxCell = row.querySelector('.pieces-per-box').closest('td');
        piecesPerBoxCell.style.position = 'relative';
        piecesPerBoxCell.appendChild(messageDiv);
        
        // 3초 후 메시지 제거
        setTimeout(() => {
            if (messageDiv.parentNode) {
                messageDiv.remove();
            }
        }, 3000);
    }

    // 거래처 신규 등록 모달 함수
    function showAddSupplierModal(defaultName = '') {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
        modal.innerHTML = `
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">${translations.new_supplier_registration}</h3>
                        <button type="button" class="text-gray-400 hover:text-gray-600" onclick="closeModal()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <form id="add-supplier-form" class="space-y-4">
                        <div>
                            <label for="modal_supplier_name" class="block text-sm font-medium text-gray-700">${translations.supplier_name} *</label>
                            <input type="text" id="modal_supplier_name" name="name" required 
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                   value="${defaultName}" placeholder="${translations.supplier_name}">
                        </div>
                        <div>
                            <label for="modal_supplier_phone" class="block text-sm font-medium text-gray-700">${translations.phone_number}</label>
                            <input type="text" id="modal_supplier_phone" name="phone" 
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                   placeholder="${translations.phone_number}">
                        </div>
                        <div>
                            <label for="modal_supplier_memo" class="block text-sm font-medium text-gray-700">${translations.memo}</label>
                            <textarea id="modal_supplier_memo" name="memo" rows="3" 
                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                      placeholder="${translations.memo}"></textarea>
                        </div>
                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" onclick="closeModal()" class="btn">
                                <?php echo t('common.cancel'); ?>
                            </button>
                            <button type="submit" class="btn-success">
                                <i class="fas fa-plus mr-1"></i>
                                <?php echo t('common.add'); ?>
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
                alert(t('supplier.name') + ' ' + t('forms.required_field'));
                return;
            }
            
            // 로딩 상태 표시
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>' + translations.register + '...';
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
                    alert(t('common.error') + ': ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert(t('supplier.add') + ' ' + t('messages.operation_failed'));
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

    // VAT 계산 함수
    function updateVATCalculation() {
        const vatIncluded = document.querySelector('input[name="default_vat_included"]:checked').value === '1';
        const vatDisplay = document.getElementById('vat-calculation-display');
        
        // 모든 상품의 VAT 설정 업데이트
        document.querySelectorAll('.vat-included-field').forEach(field => {
            field.value = vatIncluded ? '1' : '0';
        });
        
        // 단가가 입력된 경우에만 계산 표시
        const unitPriceInputs = document.querySelectorAll('.unit-price');
        let hasPrice = false;
        let samplePrice = 0;
        
        unitPriceInputs.forEach(input => {
            const price = parseFloat(input.value) || 0;
            if (price > 0) {
                hasPrice = true;
                samplePrice = price;
                return;
            }
        });
        
        if (hasPrice) {
            const vatRate = 0.12; // 12%
            let finalPrice, vatAmount;
            
            if (vatIncluded) {
                // VAT 포함: 입력값 그대로 저장
                finalPrice = samplePrice;
                vatAmount = samplePrice - (samplePrice / 1.12);
            } else {
                // VAT 미포함: VAT 추가하여 저장
                finalPrice = samplePrice * 1.12;
                vatAmount = samplePrice * vatRate;
            }
            
            // UI 업데이트
            document.getElementById('global-input-price').textContent = samplePrice.toLocaleString();
            document.getElementById('global-final-price').textContent = finalPrice.toLocaleString();
            document.getElementById('global-vat-amount').textContent = vatAmount.toLocaleString();
            vatDisplay.classList.remove('hidden');
        } else {
            vatDisplay.classList.add('hidden');
        }
        
        // 모든 행의 총액 재계산
        document.querySelectorAll('.item-row').forEach(row => {
            updateRowTotal(row);
        });
    }
    
    // VAT 설정 변경 이벤트 리스너
    document.addEventListener('change', function(e) {
        if (e.target.name === 'default_vat_included') {
            updateVATCalculation();
        }
    });
    
    // 단가 입력시 VAT 계산 표시 업데이트
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('unit-price')) {
            updateVATCalculation();
        }
    });
    
    // 초기 VAT 설정
    updateVATCalculation();

    // 포장수량 변경 모달 관련 코드
    const piecesPerBoxModal = document.getElementById('pieces-per-box-modal');
    const modalPiecesPerBoxInput = document.getElementById('modal-pieces-per-box-input');
    const cancelPiecesPerBoxBtn = document.getElementById('cancel-pieces-per-box-btn');
    const confirmPiecesPerBoxBtn = document.getElementById('confirm-pieces-per-box-btn');
    let currentPiecesPerBoxInput = null; // 현재 수정 중인 input 요소

    // 포장수량 필드 클릭 이벤트 (이벤트 위임 사용)
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('pieces-per-box')) {
            // readonly인 경우는 모달을 띄우지 않음
            if (e.target.readOnly) {
                return;
            }

            e.preventDefault();
            currentPiecesPerBoxInput = e.target;
            modalPiecesPerBoxInput.value = e.target.value || 1;
            piecesPerBoxModal.classList.remove('hidden');
            piecesPerBoxModal.classList.add('flex');

            // 모달이 열리면 input에 포커스
            setTimeout(() => {
                modalPiecesPerBoxInput.focus();
                modalPiecesPerBoxInput.select();
            }, 100);
        }
    });

    // 포커스 이벤트도 처리 (탭 키로 이동했을 때)
    document.addEventListener('focus', function(e) {
        if (e.target.classList.contains('pieces-per-box') && !e.target.readOnly) {
            // click 이벤트에서 처리하므로 blur 처리
            e.target.blur();
            // click 이벤트 트리거
            e.target.click();
        }
    }, true);

    // 모달 취소 버튼
    cancelPiecesPerBoxBtn.addEventListener('click', function() {
        piecesPerBoxModal.classList.add('hidden');
        piecesPerBoxModal.classList.remove('flex');
        currentPiecesPerBoxInput = null;
    });

    // 모달 확인 버튼
    confirmPiecesPerBoxBtn.addEventListener('click', function() {
        if (currentPiecesPerBoxInput) {
            const newValue = parseInt(modalPiecesPerBoxInput.value) || 1;
            currentPiecesPerBoxInput.value = newValue;

            // input 이벤트 트리거 (합계 업데이트)
            const event = new Event('input', { bubbles: true });
            currentPiecesPerBoxInput.dispatchEvent(event);

            // change 이벤트 트리거 (상품정보 업데이트)
            const changeEvent = new Event('change', { bubbles: true });
            currentPiecesPerBoxInput.dispatchEvent(changeEvent);
        }

        piecesPerBoxModal.classList.add('hidden');
        piecesPerBoxModal.classList.remove('flex');
        currentPiecesPerBoxInput = null;
    });

    // 모달 배경 클릭 시 닫기
    piecesPerBoxModal.addEventListener('click', function(e) {
        if (e.target === piecesPerBoxModal) {
            piecesPerBoxModal.classList.add('hidden');
            piecesPerBoxModal.classList.remove('flex');
            currentPiecesPerBoxInput = null;
        }
    });

    // 모달에서 엔터 키 처리
    modalPiecesPerBoxInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmPiecesPerBoxBtn.click();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelPiecesPerBoxBtn.click();
        }
    });
});
</script>

<?php
if (isset($conn) && $conn instanceof mysqli) {
    try {
        // 연결이 여전히 활성 상태인지 확인
        if ($conn->ping()) {
            $conn->close();
        }
    } catch (Error $e) {
        // 이미 닫힌 연결이면 무시
        error_log("Connection already closed: " . $e->getMessage());
    }
}
require_once __DIR__ . '/partials/footer.php';
?>

