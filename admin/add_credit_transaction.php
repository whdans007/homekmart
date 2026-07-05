<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '외상거래 입력 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 외상거래(도매판매) 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$errors = [];
$edit_mode = false;
$edit_tx_id = 0;
$edit_data = null;
$prefill_customer = null;

// 수정 모드 확인
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_tx_id = (int)$_GET['edit'];
    $edit_mode = true;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 수정 모드 데이터 로드
    if ($edit_mode && $edit_tx_id > 0) {
        $edit_sql = "
            SELECT ct.*, cc.name AS customer_name, cc.phone AS customer_phone, cc.address AS customer_address
            FROM credit_transactions ct
            LEFT JOIN credit_customers cc ON ct.customer_id = cc.id
            WHERE ct.id = ?
        ";
        if ($_SESSION['role'] !== 'super_admin') {
            $edit_sql .= " AND ct.store_id = " . (int)$current_store_id;
        }
        $edit_stmt = $pdo->prepare($edit_sql);
        $edit_stmt->execute([$edit_tx_id]);
        $edit_data = $edit_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$edit_data) {
            $errors[] = '수정할 외상거래를 찾을 수 없습니다.';
            $edit_mode = false;
        } else {
            $items_sql = "
                SELECT
                    cti.product_id,
                    cti.quantity,
                    cti.unit_price,
                    cti.total_price,
                    cti.sale_unit,
                    cti.remarks,
                    cti.custom_product_name,
                    cti.custom_cost_price,
                    COALESCE(p.sku, '수기') AS sku,
                    COALESCE(wp.wholesale_name_ko, p.name_ko, cti.custom_product_name) AS name_ko,
                    COALESCE(wp.wholesale_name_en, p.name_en, cti.custom_product_name) AS name_en,
                    wp.wholesale_price,
                    COALESCE(wp.wholesale_price_piece, 0) AS wholesale_price_piece,
                    COALESCE(p.pieces_per_box, wp.min_quantity, 1) AS min_quantity,
                    COALESCE(cti.custom_cost_price, 0) AS cost_price,
                    COALESCE(inv.selling_price, 0) AS selling_price
                FROM credit_transaction_items cti
                LEFT JOIN products p ON cti.product_id = p.id
                LEFT JOIN wholesale_products wp ON wp.product_id = p.id AND wp.store_id = ?
                LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.store_id = ?
                WHERE cti.transaction_id = ?
                ORDER BY cti.sort_order ASC, cti.id ASC
            ";
            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([$edit_data['store_id'], $edit_data['store_id'], $edit_tx_id]);
            $edit_data['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // 신규 등록 시 거래처 선지정 (?customer_id= 로 진입한 경우)
    if (!$edit_mode && isset($_GET['customer_id']) && is_numeric($_GET['customer_id'])) {
        $pf_sql = "SELECT id, name, phone, address FROM credit_customers WHERE id = ? AND is_active = 1";
        if ($_SESSION['role'] !== 'super_admin') {
            $pf_sql .= " AND store_id = " . (int)$current_store_id;
        }
        $pf_stmt = $pdo->prepare($pf_sql);
        $pf_stmt->execute([(int)$_GET['customer_id']]);
        $prefill_customer = $pf_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (PDOException $e) {
    $errors[] = '데이터베이스 연결 오류: ' . $e->getMessage();
}

// 저장 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $store_id = $current_store_id;
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
    $is_edit = isset($_POST['edit_tx_id']) && is_numeric($_POST['edit_tx_id']);
    $edit_tx_id_post = $is_edit ? (int)$_POST['edit_tx_id'] : 0;

    if (empty($customer_id)) {
        $errors[] = '거래처를 선택해주세요.';
    }
    if (empty($cart_items)) {
        $errors[] = '상품을 1개 이상 추가해주세요.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $total_amount = array_sum(array_column($cart_items, 'total_price'));

            if ($is_edit && $edit_tx_id_post > 0) {
                // 권한 확인
                $check_sql = "SELECT id FROM credit_transactions WHERE id = ?";
                if ($_SESSION['role'] !== 'super_admin') {
                    $check_sql .= " AND store_id = " . (int)$current_store_id;
                }
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([$edit_tx_id_post]);
                if (!$check_stmt->fetch()) {
                    throw new Exception('수정 권한이 없습니다.');
                }

                $update = $pdo->prepare("
                    UPDATE credit_transactions
                    SET customer_id = ?, store_id = ?, transaction_date = ?, total_amount = ?, final_amount = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $update->execute([$customer_id, $store_id, $transaction_date, $total_amount, $total_amount, $notes, $edit_tx_id_post]);

                $pdo->prepare("DELETE FROM credit_transaction_items WHERE transaction_id = ?")->execute([$edit_tx_id_post]);
                $tx_id = $edit_tx_id_post;
                $success_msg = '외상거래가 수정되었습니다.';
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO credit_transactions (customer_id, store_id, user_id, transaction_date, total_amount, final_amount, status, notes, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'confirmed', ?, NOW())
                ");
                $insert->execute([$customer_id, $store_id, $_SESSION['user_id'], $transaction_date, $total_amount, $total_amount, $notes]);
                $tx_id = $pdo->lastInsertId();
                $success_msg = '외상거래가 등록되었습니다.';
            }

            // 품목 저장
            foreach ($cart_items as $sort_index => $item) {
                $is_manual = empty($item['product_id']);
                $product_id = $is_manual ? null : (int)$item['product_id'];
                $custom_name = $is_manual ? trim($item['name_ko'] ?? $item['name_en'] ?? '') : null;
                $custom_cost = $is_manual ? (isset($item['cost_price']) && $item['cost_price'] > 0 ? (float)$item['cost_price'] : null) : null;

                $item_stmt = $pdo->prepare("
                    INSERT INTO credit_transaction_items (transaction_id, product_id, custom_product_name, custom_cost_price, quantity, unit_price, total_price, sale_unit, remarks, sort_order, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $item_stmt->execute([
                    $tx_id,
                    $product_id,
                    $custom_name,
                    $custom_cost,
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total_price'],
                    $item['sale_unit'] ?? 'box',
                    $item['remarks'] ?? '',
                    (int)$sort_index
                ]);
            }

            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => $success_msg];
            header("Location: credit_transaction_preview.php?id=$tx_id");
            exit;

        } catch (Exception $e) {
            $pdo->rollback();
            $errors[] = '처리 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="w-full px-3 py-3">

    <?php if (isset($flash)): ?>
        <div class="mb-3 px-4 py-2 rounded-md flex items-center gap-2 text-sm
            <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-700'; ?>">
            <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>"></i>
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="mb-3 px-4 py-2 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            <?php echo implode(' / ', array_map('htmlspecialchars', $errors)); ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="credit-tx-form">

        <!-- 타이틀 + 액션 버튼 -->
        <div class="flex items-center justify-between mb-3">
            <h1 class="text-base font-semibold text-gray-800">
                <i class="fas fa-file-invoice-dollar mr-1 text-yellow-600"></i>
                <?php echo $edit_mode ? '외상거래 수정' : '외상거래 입력'; ?>
            </h1>
            <div class="flex gap-2">
                <button type="submit" id="complete_btn"
                        class="px-4 py-2 text-sm font-medium bg-green-500 text-white rounded-md hover:bg-green-600 disabled:bg-gray-300 disabled:cursor-not-allowed whitespace-nowrap"
                        disabled>
                    <i class="fas fa-save mr-1"></i>
                    <?php echo $edit_mode ? '수정 완료' : '저장'; ?>
                </button>
                <a href="credit_transactions.php"
                   class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 whitespace-nowrap">
                    <i class="fas fa-times mr-1"></i>취소
                </a>
            </div>
        </div>

        <!-- Row 1: 거래처 / 점포 / 거래일자 / 마진율 -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-2">
            <!-- 거래처 -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">외상거래처 <span class="text-red-500">*</span></label>
                <div class="flex gap-1">
                    <div class="relative flex-1">
                        <input type="text" id="customer_search"
                               class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="거래처명 검색..." autocomplete="off">
                        <div id="customer_search_results"
                             class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden"></div>
                    </div>
                    <button type="button" id="customer_search_btn" class="px-2 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600">
                        <i class="fas fa-search text-sm"></i>
                    </button>
                </div>
                <input type="hidden" name="customer_id" id="customer_id" value="">
            </div>

            <!-- 점포 (고정) -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">점포</label>
                <div class="px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-md text-gray-700 font-medium">
                    <?php echo htmlspecialchars($current_store_name); ?>
                </div>
                <input type="hidden" name="store_id" id="store_id" value="<?php echo $current_store_id; ?>">
            </div>

            <!-- 거래일자 -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">거래일자</label>
                <input type="date" name="transaction_date" id="transaction_date" value="<?php echo date('Y-m-d'); ?>"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500">
            </div>

            <!-- 마진율 -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">마진율</label>
                <div class="flex items-center gap-1">
                    <input type="number" id="margin_rate"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           value="15" min="0" max="100" step="0.1">
                    <span class="text-sm text-gray-500 whitespace-nowrap">%</span>
                </div>
            </div>
        </div>

        <!-- 선택된 거래처 표시 -->
        <div id="selected_customer" class="hidden mb-2 px-3 py-2 bg-blue-50 border border-blue-200 rounded-md flex items-center justify-between">
            <div class="flex items-center gap-3 min-w-0">
                <i class="fas fa-building text-blue-400 flex-shrink-0"></i>
                <span class="text-sm font-medium text-blue-900 truncate" id="selected_customer_name"></span>
                <span class="text-xs text-blue-600 truncate" id="selected_customer_info"></span>
            </div>
            <button type="button" id="clear_customer_selection" class="text-blue-400 hover:text-blue-600 ml-2 flex-shrink-0">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>

        <!-- Row 2: 상품 검색 + 수기 입력 -->
        <div class="flex gap-2 mb-2">
            <div class="relative flex-1">
                <input type="text" id="product_search"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                       placeholder="상품명/SKU/바코드 검색..." autocomplete="off">
                <div id="product_search_results"
                     class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-96 overflow-y-auto hidden"></div>
            </div>
            <button type="button" id="product_search_btn" class="px-3 py-2 text-sm bg-blue-500 text-white rounded-md hover:bg-blue-600 whitespace-nowrap">
                <i class="fas fa-search mr-1"></i>검색
            </button>
            <button type="button" id="toggle_manual_entry" class="px-3 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-md hover:bg-indigo-100 whitespace-nowrap">
                <i class="fas fa-pencil-alt mr-1"></i>수기 입력
            </button>
        </div>

        <!-- 수기 입력 폼 -->
        <div id="manual_entry_form" class="hidden mb-3 p-3 bg-gray-50 border border-indigo-200 rounded-md">
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">상품명 <span class="text-red-500">*</span></label>
                    <input type="text" id="manual_product_name" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="상품명 입력">
                </div>
                <div class="w-28">
                    <label class="block text-xs font-medium text-gray-600 mb-1">원가</label>
                    <input type="number" id="manual_cost_price" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="0" min="0" step="0.01">
                </div>
                <div class="w-28">
                    <label class="block text-xs font-medium text-gray-600 mb-1">수량</label>
                    <input type="number" id="manual_quantity" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" value="1" min="1">
                </div>
                <div class="w-28">
                    <label class="block text-xs font-medium text-gray-600 mb-1">판매가(합계) <span class="text-red-500">*</span></label>
                    <input type="number" id="manual_wholesale_price" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="0" min="0" step="0.01">
                </div>
                <button type="button" id="add_manual_item_btn" class="px-4 py-2 text-sm font-medium bg-indigo-600 text-white rounded-md hover:bg-indigo-700 whitespace-nowrap">
                    <i class="fas fa-plus mr-1"></i>추가
                </button>
                <button type="button" id="cancel_manual_entry" class="px-4 py-2 text-sm text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 whitespace-nowrap">취소</button>
            </div>
        </div>

        <!-- 장바구니 -->
        <div id="cart_empty" class="text-center text-gray-400 py-16">
            <i class="fas fa-shopping-cart text-3xl mb-2"></i>
            <p class="text-sm">상품을 검색하거나 수기로 추가하세요.</p>
        </div>

        <div id="cart_items" class="hidden">
            <div class="cart-table-wrapper overflow-x-auto border rounded-md">
                <table class="w-full cart-table text-sm">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-2 py-2 text-left text-xs font-semibold text-gray-600">SKU</th>
                            <th class="px-2 py-2 text-left text-xs font-semibold text-gray-600">상품명</th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600">박스포장</th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600">원가</th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600">판매가(소매)</th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600">판매단위</th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600">판매가</th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600">수량</th>
                            <th class="px-2 py-2 text-right text-xs font-semibold text-gray-600">합계</th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600">삭제</th>
                        </tr>
                    </thead>
                    <tbody id="cart_list"></tbody>
                </table>
            </div>
            <div class="mt-3 flex justify-between items-center">
                <span class="text-sm text-gray-500"><span id="cart_count">0</span>개 품목</span>
                <div class="text-base font-semibold text-gray-900">
                    합계: <span id="cart_total" class="text-primary-600">0</span>
                </div>
            </div>
        </div>

        <!-- 비고 -->
        <div class="mt-3">
            <label class="block text-xs font-medium text-gray-600 mb-1">비고</label>
            <textarea name="notes" id="notes" rows="2" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="메모/비고"><?php echo htmlspecialchars($edit_data['notes'] ?? ''); ?></textarea>
        </div>

        <input type="hidden" name="cart_items" id="cart_items_input" value="">
        <?php if ($edit_mode): ?>
            <input type="hidden" name="edit_tx_id" value="<?php echo $edit_tx_id; ?>">
        <?php endif; ?>
    </form>
</div>

<!-- 거래처 목록 모달 -->
<div id="customer-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[70vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900"><i class="fas fa-users mr-2 text-blue-500"></i>외상거래처 목록</h3>
                <button type="button" id="close-customer-modal" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="p-3 border-b border-gray-200">
                    <input type="text" id="modal-customer-search" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="거래처명/전화/주소 필터...">
                </div>
                <div id="customer-list" class="flex-1 overflow-y-auto p-3 space-y-2 max-h-80"></div>
            </div>
        </div>
    </div>
</div>

<!-- 신규 거래처 등록 모달 -->
<div id="new-customer-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-[60]">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900"><i class="fas fa-plus-circle mr-2 text-green-500"></i>신규 외상거래처 등록</h3>
                <button type="button" id="close-new-customer-modal" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="p-4">
                <div id="new-customer-form-errors" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm text-red-700" id="new-customer-error-text"></p>
                </div>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">업체명 <span class="text-red-500">*</span></label>
                        <input type="text" id="new_customer_name" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="업체명을 입력하세요">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">전화번호</label>
                        <input type="tel" id="new_customer_phone" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="010-0000-0000">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">주소</label>
                        <textarea id="new_customer_address" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="주소"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">메모</label>
                        <textarea id="new_customer_memo" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="메모"></textarea>
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" id="cancel-new-customer" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50">취소</button>
                    <button type="button" id="save-new-customer" class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700"><i class="fas fa-save mr-1"></i>저장</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 상품 목록 모달 -->
<div id="product-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-h-[70vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900"><i class="fas fa-box mr-2 text-green-500"></i>상품 목록</h3>
                <button type="button" id="close-product-modal" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="p-3 border-b border-gray-200">
                    <input type="text" id="modal-product-search" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="상품명/SKU 필터...">
                </div>
                <div id="product-list" class="flex-1 overflow-y-auto p-3 space-y-2 max-h-80"></div>
            </div>
        </div>
    </div>
</div>

<!-- 상품 타입 선택 모달 -->
<div id="product-type-selection-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900"><i class="fas fa-box-open mr-2 text-blue-500"></i>판매가 선택</h3>
                <button type="button" id="close-type-selection-modal" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-4">어떤 가격으로 추가할까요?</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div id="wholesale-option" class="border-2 border-blue-500 rounded-lg p-4 cursor-pointer hover:bg-blue-50 transition">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-900">등록 도매가</h4>
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded">추천</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div>상품명: <span id="modal-wholesale-name" class="font-medium"></span></div>
                            <div>도매가: <span id="modal-wholesale-price" class="font-medium text-blue-600"></span>원</div>
                            <div class="text-xs text-gray-500 mt-2">등록된 도매가로 판매</div>
                        </div>
                    </div>
                    <div id="inventory-option" class="border-2 border-gray-300 rounded-lg p-4 cursor-pointer hover:bg-gray-50 transition">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-900">재고 상품가</h4>
                            <span class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded">일반</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div>상품명: <span id="modal-inventory-name" class="font-medium"></span></div>
                            <div>원가: <span id="modal-inventory-cost" class="font-medium"></span>원</div>
                            <div>판매가: <span id="modal-inventory-price" class="font-medium text-green-600"></span>원</div>
                            <div class="text-xs text-gray-500 mt-2">마진율 적용가로 판매</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const editMode = <?php echo json_encode($edit_mode); ?>;
const editData = <?php echo json_encode($edit_data); ?>;
const prefillCustomer = <?php echo json_encode($prefill_customer); ?>;

const translations = {
    no_phone: '전화번호 없음',
    wholesale_badge: '도매',
    minimum_prefix: '박스: ',
    pieces: '개',
    loading: '불러오는 중...',
    no_customers: '거래처가 없습니다.',
    customer_load_error: '거래처를 불러오지 못했습니다.',
    no_products: '상품이 없습니다.',
    product_load_error: '상품을 불러오지 못했습니다.',
    error_customers: '거래처 오류',
    error_products: '상품 오류',
    product_name_required: '상품명을 입력해주세요.',
    wholesale_price_required: '판매가를 입력해주세요.',
    quantity_min: '수량은 1 이상이어야 합니다.',
    manual_added: '수기 상품이 추가되었습니다.',
    product_added: '상품이 추가되었습니다.',
    qty_increased: '수량이 증가되었습니다.',
    customer_registered: '거래처가 등록되었습니다.',
    product_not_found_barcode: '상품을 찾을 수 없습니다',
    barcode_error: '바코드 검색 오류',
    store_required: '점포 정보가 필요합니다.',
    margin_range: '마진율은 0~100 사이여야 합니다.',
    unregistered_margin: '미등록 상품 (마진율 {rate}% 적용)',
    server_error: '서버 오류가 발생했습니다.'
};

document.addEventListener('DOMContentLoaded', function() {
    let cart = [];
    const customerSearch = document.getElementById('customer_search');
    const customerSearchResults = document.getElementById('customer_search_results');
    const selectedCustomer = document.getElementById('selected_customer');
    const customerId = document.getElementById('customer_id');
    const clearCustomerSelection = document.getElementById('clear_customer_selection');

    const productSearch = document.getElementById('product_search');
    const productSearchResults = document.getElementById('product_search_results');

    const cartEmpty = document.getElementById('cart_empty');
    const cartItems = document.getElementById('cart_items');
    const cartList = document.getElementById('cart_list');
    const cartTotal = document.getElementById('cart_total');
    const cartItemsInput = document.getElementById('cart_items_input');
    const completeBtn = document.getElementById('complete_btn');

    let searchTimeout;

    const customerModal = document.getElementById('customer-modal');
    const productModal = document.getElementById('product-modal');
    const closeCustomerModal = document.getElementById('close-customer-modal');
    const closeProductModal = document.getElementById('close-product-modal');
    const customerSearchBtn = document.getElementById('customer_search_btn');
    const productSearchBtn = document.getElementById('product_search_btn');
    const customerList = document.getElementById('customer-list');
    const productList = document.getElementById('product-list');
    const modalCustomerSearch = document.getElementById('modal-customer-search');
    const modalProductSearch = document.getElementById('modal-product-search');

    customerSearch.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        if (query.length < 2) { customerSearchResults.classList.add('hidden'); return; }
        searchTimeout = setTimeout(function() { searchCustomers(query); }, 300);
    });

    customerSearchBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const query = customerSearch.value.trim();
        if (query.length === 0) { showCustomerModal(); } else { searchCustomers(query); }
    });

    productSearch.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        if (query.length < 2) { productSearchResults.classList.add('hidden'); return; }
        searchTimeout = setTimeout(function() { searchProducts(query); }, 300);
    });

    productSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimeout);
            productSearchResults.classList.add('hidden');
            const query = this.value.trim();
            if (query.length === 0) { showProductModal(); return; }
            searchProductByBarcode(query);
        }
    });

    customerSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const query = this.value.trim();
            if (query.length >= 2) { searchCustomers(query); }
            else if (query.length === 0) { showCustomerModal(); }
        }
    });

    productSearchBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const query = productSearch.value.trim();
        if (query.length === 0) { showProductModal(); } else { searchProducts(query); }
    });

    clearCustomerSelection.addEventListener('click', function() {
        customerId.value = '';
        selectedCustomer.classList.add('hidden');
        customerSearch.value = '';
        updateButton();
    });

    closeCustomerModal.addEventListener('click', function() { customerModal.classList.add('hidden'); });
    closeProductModal.addEventListener('click', function() { productModal.classList.add('hidden'); });
    customerModal.addEventListener('click', function(e) { if (e.target === customerModal) customerModal.classList.add('hidden'); });
    productModal.addEventListener('click', function(e) { if (e.target === productModal) productModal.classList.add('hidden'); });

    function focusCartQuantity(index) {
        setTimeout(function() {
            const rows = document.querySelectorAll('#cart_list tr');
            if (rows[index]) {
                const qtyInput = rows[index].querySelector('.quantity-controls input[type="number"]');
                if (qtyInput) { qtyInput.focus(); qtyInput.select(); }
            }
        }, 50);
    }

    cartList.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.matches('.quantity-controls input[type="number"]')) {
            e.preventDefault();
            productSearch.value = '';
            productSearch.focus();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            customerModal.classList.add('hidden');
            productModal.classList.add('hidden');
            document.getElementById('manual_entry_form').classList.add('hidden');
            document.getElementById('new-customer-modal').classList.add('hidden');
        }
    });

    document.getElementById('toggle_manual_entry').addEventListener('click', function() {
        const form = document.getElementById('manual_entry_form');
        form.classList.toggle('hidden');
        if (!form.classList.contains('hidden')) document.getElementById('manual_product_name').focus();
    });
    document.getElementById('cancel_manual_entry').addEventListener('click', function() {
        document.getElementById('manual_entry_form').classList.add('hidden');
        clearManualForm();
    });
    document.getElementById('add_manual_item_btn').addEventListener('click', function() { addManualItemToCart(); });

    ['manual_product_name', 'manual_cost_price', 'manual_wholesale_price', 'manual_quantity'].forEach(function(id) {
        document.getElementById(id).addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); addManualItemToCart(); }
        });
    });

    let manualWholesaleEdited = false;
    function recalcManualWholesalePrice() {
        if (manualWholesaleEdited) return;
        const cost = parseFloat(document.getElementById('manual_cost_price').value) || 0;
        const qty = parseFloat(document.getElementById('manual_quantity').value) || 0;
        const marginEl = document.getElementById('margin_rate');
        const margin = marginEl ? (parseFloat(marginEl.value) || 0) : 0;
        const wpEl = document.getElementById('manual_wholesale_price');
        if (cost > 0) {
            const unit = cost * (1 + margin / 100);
            wpEl.value = Math.round(unit * (qty > 0 ? qty : 1));
        }
    }
    document.getElementById('manual_cost_price').addEventListener('input', recalcManualWholesalePrice);
    document.getElementById('manual_quantity').addEventListener('input', recalcManualWholesalePrice);
    document.getElementById('margin_rate').addEventListener('input', recalcManualWholesalePrice);

    (function rememberMarginRate() {
        const MARGIN_KEY = 'credit_margin_rate';
        const marginInput = document.getElementById('margin_rate');
        if (!marginInput) return;
        const saved = localStorage.getItem(MARGIN_KEY);
        if (saved !== null && saved !== '' && !isNaN(parseFloat(saved))) {
            marginInput.value = saved;
            recalcManualWholesalePrice();
        }
        marginInput.addEventListener('change', function() {
            const v = parseFloat(this.value);
            if (!isNaN(v) && v >= 0 && v <= 100) localStorage.setItem(MARGIN_KEY, this.value);
        });
    })();

    document.getElementById('manual_wholesale_price').addEventListener('input', function() {
        manualWholesaleEdited = this.value.trim() !== '';
    });

    function clearManualForm() {
        document.getElementById('manual_product_name').value = '';
        document.getElementById('manual_cost_price').value = '';
        document.getElementById('manual_wholesale_price').value = '';
        document.getElementById('manual_quantity').value = '1';
        manualWholesaleEdited = false;
    }

    function addManualItemToCart() {
        const name = document.getElementById('manual_product_name').value.trim();
        const costPrice = parseFloat(document.getElementById('manual_cost_price').value) || 0;
        const totalAmount = Math.round(parseFloat(document.getElementById('manual_wholesale_price').value) || 0);
        const quantity = parseInt(document.getElementById('manual_quantity').value) || 1;
        const unitPrice = quantity > 0 ? Math.round(totalAmount / quantity) : totalAmount;

        if (!name) { showNotification(translations.product_name_required, 'error'); document.getElementById('manual_product_name').focus(); return; }
        if (totalAmount <= 0) { showNotification(translations.wholesale_price_required, 'error'); document.getElementById('manual_wholesale_price').focus(); return; }
        if (quantity <= 0) { showNotification(translations.quantity_min, 'error'); return; }

        cart.push({
            product_id: null, is_manual: true, sku: '수기', name_ko: name, name_en: name,
            unit_price: unitPrice, quantity: quantity, total_price: totalAmount, min_quantity: 1,
            wholesale_price: unitPrice, wholesale_price_piece: 0, cost_price: costPrice, selling_price: 0, sale_unit: 'box'
        });
        updateCart();
        clearManualForm();
        document.getElementById('manual_entry_form').classList.add('hidden');
        showNotification(translations.manual_added, 'success');
    }

    modalCustomerSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        customerList.querySelectorAll('.modal-customer-item').forEach(function(item) {
            const name = item.dataset.name.toLowerCase();
            const phone = (item.dataset.phone || '').toLowerCase();
            const address = (item.dataset.address || '').toLowerCase();
            item.style.display = (name.includes(query) || phone.includes(query) || address.includes(query)) ? '' : 'none';
        });
    });

    modalProductSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        productList.querySelectorAll('.modal-product-item').forEach(function(item) {
            const name = (item.dataset.nameEn || '').toLowerCase() + ' ' + (item.dataset.nameKo || '').toLowerCase();
            const sku = item.dataset.sku.toLowerCase();
            item.style.display = (name.includes(query) || sku.includes(query)) ? '' : 'none';
        });
    });

    document.addEventListener('click', function(e) {
        if (!customerSearch.contains(e.target) && !customerSearchResults.contains(e.target)) customerSearchResults.classList.add('hidden');
        if (!productSearch.contains(e.target) && !productSearchResults.contains(e.target)) productSearchResults.classList.add('hidden');
    });

    function searchCustomers(query) {
        fetch('ajax_search_credit_customers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.customers && data.customers.length > 0) {
                displayCustomerResults(data.customers, query);
            } else {
                customerSearchResults.innerHTML = `
                    <div class="p-3 text-sm text-gray-500">검색 결과가 없습니다.</div>
                    <div class="p-2 border-t border-gray-100">
                        <button type="button" class="open-new-customer-modal w-full flex items-center justify-center gap-2 px-3 py-2 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md hover:bg-green-100" data-name="${escapeAttr(query)}">
                            <i class="fas fa-plus-circle"></i> "<span class="font-medium">${escapeHtml(query)}</span>" 신규 거래처로 등록
                        </button>
                    </div>`;
                customerSearchResults.classList.remove('hidden');
                bindNewCustomerBtns();
            }
        })
        .catch(error => {
            customerSearchResults.innerHTML = '<div class="p-3 text-sm text-red-500">검색 중 오류: ' + error.message + '</div>';
            customerSearchResults.classList.remove('hidden');
        });
    }

    function searchProducts(query) {
        fetch('ajax_search_wholesale_products.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.products) { displayProductResults(data.products); }
            else { productSearchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">검색 결과가 없습니다.</div>'; productSearchResults.classList.remove('hidden'); }
        })
        .catch(error => { console.error('Error:', error); });
    }

    function displayCustomerResults(customers, query) {
        let html = '';
        customers.forEach(function(customer) {
            html += `
                <div class="p-3 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0 customer-item"
                     data-id="${customer.id}" data-name="${escapeAttr(customer.name)}" data-phone="${escapeAttr(customer.phone || '')}" data-address="${escapeAttr(customer.address || '')}">
                    <div class="font-medium text-gray-900">${escapeHtml(customer.name)}</div>
                    <div class="text-sm text-gray-600">${escapeHtml(customer.phone || '')} ${escapeHtml(customer.address || '')}</div>
                </div>`;
        });
        html += `
            <div class="p-2 border-t border-gray-100">
                <button type="button" class="open-new-customer-modal w-full flex items-center justify-center gap-2 px-3 py-2 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md hover:bg-green-100" data-name="${escapeAttr(query || '')}">
                    <i class="fas fa-plus-circle"></i> 신규 거래처 등록
                </button>
            </div>`;
        customerSearchResults.innerHTML = html;
        customerSearchResults.classList.remove('hidden');
        document.querySelectorAll('.customer-item').forEach(function(item) {
            item.addEventListener('click', function() { selectCustomer(this); });
        });
        bindNewCustomerBtns();
    }

    function displayProductResults(products) {
        let html = '';
        const fmtPrice = function(v) { return (v && Number(v) > 0) ? Number(v).toLocaleString() : '-'; };
        products.forEach(function(product) {
            const isRegistered = product.status === 'registered';
            let displaySkus = '';
            if (product.wholesale_skus) {
                try { const a = JSON.parse(product.wholesale_skus); displaySkus = Array.isArray(a) ? a.join(', ') : product.sku; }
                catch (e) { displaySkus = product.sku; }
            } else { displaySkus = product.sku; }

            if (isRegistered) {
                html += `
                    <div class="p-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0 product-item"
                         data-id="${product.id}" data-sku="${displaySkus}" data-name-ko="${product.display_name_ko || ''}" data-name-en="${product.display_name_en || ''}"
                         data-wholesale-price="${product.wholesale_price}" data-wholesale-price-piece="${product.wholesale_price_piece || 0}" data-min-quantity="${product.min_quantity}"
                         data-cost-price="${product.cost_price || 0}" data-cost-box="${product.wp_cost_box || 0}" data-cost-piece="${product.wp_cost_piece || 0}"
                         data-selling-price="${product.selling_price || 0}" data-registered="true">
                        <div class="font-medium text-gray-900">${product.display_name_en || product.display_name_ko || 'N/A'}
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-2">도매상품</span></div>
                        <div class="text-sm text-gray-600">${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? product.display_name_ko : ''}</div>
                        <div class="text-xs text-gray-500 mt-1">SKU: ${displaySkus} | 박스: ${product.min_quantity || 1}개</div>
                        <div class="text-xs text-gray-600 mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                            <span>원가(낱개): ${fmtPrice(product.wp_cost_piece)}</span>
                            <span>원가(박스): ${fmtPrice(product.wp_cost_box)}</span>
                            <span class="text-blue-700 font-medium">도매가(낱개): ${fmtPrice(product.wholesale_price_piece)}</span>
                            <span class="text-blue-700 font-medium">도매가(박스): ${fmtPrice(product.wholesale_price)}</span>
                        </div>
                    </div>`;
            } else {
                const marginRateInput = document.getElementById('margin_rate');
                const currentMarginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;
                const costPrice = parseFloat(product.cost_price) || 0;
                const suggestedPrice = Math.round(costPrice * (1 + currentMarginRate / 100) * 100) / 100;
                html += `
                    <div class="p-3 hover:bg-yellow-50 cursor-pointer border-b border-gray-100 last:border-b-0 product-item bg-yellow-50 border-l-4 border-l-yellow-400"
                         data-id="${product.id}" data-sku="${product.sku}" data-name-ko="${product.display_name_ko || ''}" data-name-en="${product.display_name_en || ''}"
                         data-cost-price="${costPrice}" data-selling-price="${product.selling_price || 0}" data-suggested-price="${suggestedPrice}"
                         data-min-quantity="${product.product_pieces_per_box || 1}" data-registered="false">
                        <div class="font-medium text-gray-900">${product.display_name_en || product.display_name_ko || 'N/A'}
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 ml-2">미등록</span></div>
                        <div class="text-sm text-gray-600">${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? product.display_name_ko : ''}</div>
                        <div class="text-xs text-gray-500 mt-1">SKU: ${product.sku} | 원가: ${costPrice.toLocaleString()}원 | 제안가(${currentMarginRate}%): ${suggestedPrice.toLocaleString()}원 | 박스: ${product.product_pieces_per_box || 1}개</div>
                    </div>`;
            }
        });
        productSearchResults.innerHTML = html;
        productSearchResults.classList.remove('hidden');
        document.querySelectorAll('.product-item').forEach(function(item) {
            item.addEventListener('click', function() { addToCartFromSearch(this); });
        });
    }

    function addToCartFromSearch(item) {
        const isRegistered = item.dataset.registered === 'true';
        const productData = {
            product_id: item.dataset.id, sku: item.dataset.sku, name_ko: item.dataset.nameKo, name_en: item.dataset.nameEn,
            min_quantity: parseInt(item.dataset.minQuantity) || 1, is_registered: isRegistered
        };
        if (isRegistered) {
            productData.wholesale_price = parseFloat(item.dataset.wholesalePrice);
            productData.wholesale_price_piece = parseFloat(item.dataset.wholesalePricePiece) || 0;
            productData.cost_box = parseFloat(item.dataset.costBox) || 0;
            productData.cost_piece = parseFloat(item.dataset.costPiece) || 0;
            productData.cost_price = parseFloat(item.dataset.costPrice) || 0;       // 인벤토리 원가 (폴백)
            productData.selling_price = parseFloat(item.dataset.sellingPrice) || 0; // 소매 판매가
            productData.margin_rate = 0;
        } else {
            const marginRateInput = document.getElementById('margin_rate');
            const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;
            const costPrice = parseFloat(item.dataset.costPrice) || 0;
            const sellingPrice = parseFloat(item.dataset.sellingPrice) || 0;
            if (costPrice <= 0 && sellingPrice > 0) { productData.wholesale_price = Math.round(sellingPrice * 0.9); }
            else { productData.wholesale_price = parseFloat(item.dataset.suggestedPrice); }
            productData.wholesale_price_piece = 0;
            productData.cost_price = costPrice;
            productData.selling_price = sellingPrice;
            productData.margin_rate = marginRate;
        }
        addProductToCartDirect(productData);
        productSearchResults.classList.add('hidden');
        productSearch.value = '';
        productSearch.focus();
    }

    function selectCustomer(item) {
        customerId.value = item.dataset.id;
        document.getElementById('selected_customer_name').textContent = item.dataset.name;
        document.getElementById('selected_customer_info').textContent = `${item.dataset.phone} ${item.dataset.address}`;
        selectedCustomer.classList.remove('hidden');
        customerSearchResults.classList.add('hidden');
        customerSearch.value = item.dataset.name;
        updateButton();
    }

    function searchProductByBarcode(query) {
        const storeIdElement = document.getElementById('store_id');
        let storeId = storeIdElement ? storeIdElement.value : <?php echo json_encode($current_store_id); ?>;
        if (!storeId) { showNotification(translations.store_required, 'error'); return; }
        const marginRateInput = document.getElementById('margin_rate');
        const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;

        fetch(`ajax_get_wholesale_product_by_barcode.php?barcode=${encodeURIComponent(query)}&store_id=${storeId}&margin_rate=${marginRate}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const product = data.data;
                    let cartIdx = addProductToCartDirect(product);
                    productSearch.value = '';
                    if (cartIdx >= 0) { focusCartQuantity(cartIdx); } else { productSearch.focus(); }
                } else {
                    showNotification(translations.product_not_found_barcode + ': ' + escapeHtml(query), 'error');
                    productSearch.value = '';
                    productSearch.focus();
                }
            })
            .catch(error => { console.error('Error:', error); showNotification(translations.barcode_error, 'error'); productSearch.value = ''; productSearch.focus(); });
    }

    function addProductToCartDirect(product) {
        const costPrice = parseFloat(product.cost_price) || 0;
        const sellingPrice = parseFloat(product.selling_price) || 0;
        if (product.is_registered && (costPrice > 0 || sellingPrice > 0)) {
            showProductTypeSelectionModal(product);
            return -1;
        }
        return addToCart(product, product.wholesale_price);
    }

    function addToCart(product, price) {
        const existingIndex = cart.findIndex(item => item.product_id == product.product_id);
        let targetIndex;
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1;
            cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
            showNotification(translations.qty_increased, 'success');
            targetIndex = existingIndex;
        } else {
            cart.push({
                product_id: product.product_id, sku: product.sku, name_ko: product.name_ko, name_en: product.name_en,
                unit_price: price, quantity: 1, total_price: price * 1, min_quantity: product.min_quantity,
                wholesale_price: price, wholesale_price_piece: product.wholesale_price_piece || 0,
                cost_price: product.cost_box || product.cost_price || 0, cost_price_piece: product.cost_piece || 0,
                selling_price: product.selling_price || 0, sale_unit: 'box'
            });
            if (!product.is_registered) { showNotification(translations.unregistered_margin.replace('{rate}', product.margin_rate), 'info'); }
            else { showNotification(translations.product_added, 'success'); }
            targetIndex = cart.length - 1;
        }
        updateCart();
        return targetIndex;
    }

    function showProductTypeSelectionModal(product) {
        const modal = document.getElementById('product-type-selection-modal');
        const marginRateInput = document.getElementById('margin_rate');
        const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;
        const costPrice = parseFloat(product.cost_price) || 0;
        const sellingPrice = parseFloat(product.selling_price) || 0;
        let inventoryPrice;
        if (costPrice <= 0) { inventoryPrice = Math.ceil(sellingPrice * 0.93); }
        else { inventoryPrice = Math.ceil(costPrice * (1 + marginRate / 100)); }

        document.getElementById('modal-wholesale-name').textContent = product.name_ko;
        document.getElementById('modal-wholesale-price').textContent = parseFloat(product.wholesale_price).toLocaleString();
        document.getElementById('modal-inventory-name').textContent = product.name_ko;
        document.getElementById('modal-inventory-cost').textContent = costPrice > 0 ? costPrice.toLocaleString() : '판매가 기준';
        document.getElementById('modal-inventory-price').textContent = inventoryPrice.toLocaleString();
        modal.classList.remove('hidden');

        const wholesaleOption = document.getElementById('wholesale-option');
        const inventoryOption = document.getElementById('inventory-option');
        const closeBtn = document.getElementById('close-type-selection-modal');
        const wholesaleHandler = () => { modal.classList.add('hidden'); addToCart(product, parseFloat(product.wholesale_price)); };
        const inventoryHandler = () => { modal.classList.add('hidden'); addToCart({...product, wholesale_price: inventoryPrice, is_registered: false, margin_rate: marginRate}, inventoryPrice); };
        const closeHandler = () => { modal.classList.add('hidden'); };
        wholesaleOption.replaceWith(wholesaleOption.cloneNode(true));
        inventoryOption.replaceWith(inventoryOption.cloneNode(true));
        closeBtn.replaceWith(closeBtn.cloneNode(true));
        document.getElementById('wholesale-option').addEventListener('click', wholesaleHandler);
        document.getElementById('inventory-option').addEventListener('click', inventoryHandler);
        document.getElementById('close-type-selection-modal').addEventListener('click', closeHandler);
    }

    function updateCart() {
        if (cart.length === 0) {
            cartEmpty.classList.remove('hidden');
            cartItems.classList.add('hidden');
        } else {
            cartEmpty.classList.add('hidden');
            cartItems.classList.remove('hidden');
            let html = '';
            cart.forEach(function(item, index) {
                // 수기 입력 제외 상품: 판매가(단가)는 소수점 이하 무조건 올림
                if (!item.is_manual) {
                    item.unit_price = Math.ceil(Number(item.unit_price) || 0);
                    item.total_price = item.quantity * item.unit_price;
                }
                html += `
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-2 py-3 text-xs font-mono text-gray-700 font-medium sku-column">${item.sku}</td>
                        <td class="px-2 py-3 product-name">
                            <div class="text-sm font-medium text-gray-900" title="${item.name_en || '-'}">${item.name_en || '-'}</div>
                            ${item.name_ko && item.name_ko !== item.name_en ? `<div class="text-sm text-gray-600 mt-1" title="${item.name_ko}">${item.name_ko}</div>` : ''}
                        </td>
                        <td class="px-2 py-3 text-center"><div class="text-sm font-medium text-gray-700">${item.min_quantity}${translations.pieces}</div></td>
                        <td class="px-2 py-3 text-center"><div class="text-sm text-gray-700">${(item.sale_unit === 'piece' ? (item.cost_price_piece ? Number(item.cost_price_piece).toLocaleString() : '-') : (item.cost_price ? Number(item.cost_price).toLocaleString() : '-'))}</div></td>
                        <td class="px-2 py-3 text-center"><div class="text-sm text-gray-700">${item.selling_price ? Number(item.selling_price).toLocaleString() : '-'}</div></td>
                        <td class="px-2 py-3 text-center">
                            <div class="inline-flex rounded border border-gray-300 overflow-hidden text-xs">
                                <button type="button" onclick="setCartUnit(${index},'box')" class="px-2 py-1 font-bold ${item.sale_unit === 'piece' ? 'bg-white text-gray-400' : 'bg-amber-500 text-white'}">BOX</button>
                                <button type="button" onclick="setCartUnit(${index},'piece')" class="px-2 py-1 font-bold ${item.sale_unit === 'piece' ? 'bg-blue-500 text-white' : 'bg-white text-gray-400'}">PCS</button>
                            </div>
                        </td>
                        <td class="px-2 py-3 text-center">
                            <input type="number" class="w-full px-2 py-1 text-sm border border-gray-300 rounded text-center focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                   value="${item.unit_price}" min="0" step="0.01" onchange="updateUnitPrice(${index}, this.value)" style="min-width: 80px;">
                        </td>
                        <td class="px-2 py-3 text-center">
                            <div class="flex items-center justify-center space-x-1 quantity-controls">
                                <button type="button" onclick="updateQuantity(${index}, -1)" class="w-6 h-6 text-xs bg-gray-200 hover:bg-gray-300 rounded flex items-center justify-center"><i class="fas fa-minus"></i></button>
                                <input type="number" value="${item.quantity}" class="w-16 text-sm text-center border border-gray-300 rounded px-1 py-1" min="1" onchange="setQuantity(${index}, this.value)">
                                <button type="button" onclick="updateQuantity(${index}, 1)" class="w-6 h-6 text-xs bg-gray-200 hover:bg-gray-300 rounded flex items-center justify-center"><i class="fas fa-plus"></i></button>
                            </div>
                        </td>
                        <td class="px-2 py-3 text-right"><div class="text-sm font-semibold text-primary-600">${Number(item.total_price).toLocaleString()}</div></td>
                        <td class="px-2 py-3 text-center">
                            <button type="button" onclick="removeFromCart(${index})" class="text-red-400 hover:text-red-600 w-6 h-6 rounded hover:bg-red-50 flex items-center justify-center"><i class="fas fa-times text-xs"></i></button>
                        </td>
                    </tr>`;
            });
            cartList.innerHTML = html;
            const total = cart.reduce((sum, item) => sum + item.total_price, 0);
            cartTotal.textContent = Number(total).toLocaleString();
            const cartCountEl = document.getElementById('cart_count');
            if (cartCountEl) cartCountEl.textContent = cart.length;
        }
        cartItemsInput.value = JSON.stringify(cart);
        updateButton();
    }

    window.removeFromCart = function(index) { cart.splice(index, 1); updateCart(); };
    window.updateQuantity = function(index, change) {
        cart[index].quantity += change;
        if (cart[index].quantity <= 0) { cart.splice(index, 1); }
        else { cart[index].total_price = cart[index].quantity * cart[index].unit_price; }
        updateCart();
    };
    window.setQuantity = function(index, newQuantity) {
        const quantity = parseInt(newQuantity) || 0;
        if (quantity <= 0) { showNotification(translations.quantity_min, 'error'); updateCart(); return; }
        cart[index].quantity = quantity;
        cart[index].total_price = cart[index].quantity * cart[index].unit_price;
        updateCart();
    };
    window.updateUnitPrice = function(index, newPrice) {
        const price = parseFloat(newPrice) || 0;
        cart[index].unit_price = price;
        cart[index].total_price = cart[index].quantity * price;
        updateCart();
    };
    window.setCartUnit = function(index, unit) {
        const it = cart[index];
        it.sale_unit = (unit === 'piece') ? 'piece' : 'box';
        const boxPrice = parseFloat(it.wholesale_price) || 0;
        const piecePrice = parseFloat(it.wholesale_price_piece) || 0;
        if (it.sale_unit === 'piece') {
            if (piecePrice > 0) { it.unit_price = piecePrice; }
            else { showNotification('낱개 판매가가 없습니다. 판매가를 직접 입력하세요.', 'info'); }
        } else {
            if (boxPrice > 0) { it.unit_price = boxPrice; }
        }
        it.total_price = it.quantity * it.unit_price;
        updateCart();
    };

    function updateButton() {
        completeBtn.disabled = !(customerId.value !== '' && cart.length > 0);
    }

    function showCustomerModal() { customerModal.classList.remove('hidden'); modalCustomerSearch.value = ''; loadAllCustomers(); }
    function showProductModal() { productModal.classList.remove('hidden'); modalProductSearch.value = ''; loadAllProducts(); }

    function loadAllCustomers() {
        customerList.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin mr-2"></i>' + translations.loading + '</div>';
        fetch('ajax_search_credit_customers.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'q=&limit=100&show_all=1'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.customers) { displayModalCustomerList(data.customers); }
            else { customerList.innerHTML = '<div class="text-center py-4 text-gray-500">' + translations.no_customers + '</div>'; }
        })
        .catch(() => { customerList.innerHTML = '<div class="text-center py-4 text-red-500">' + translations.customer_load_error + '</div>'; });
    }

    function loadAllProducts() {
        productList.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin mr-2"></i>' + translations.loading + '</div>';
        fetch('ajax_search_wholesale_products.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'q=&limit=100&show_all=1'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.products) { displayModalProductList(data.products); }
            else { productList.innerHTML = '<div class="text-center py-4 text-gray-500">' + translations.no_products + '</div>'; }
        })
        .catch(() => { productList.innerHTML = '<div class="text-center py-4 text-red-500">' + translations.product_load_error + '</div>'; });
    }

    function displayModalCustomerList(customers) {
        let html = `
            <div class="mb-2">
                <button type="button" class="open-new-customer-modal w-full flex items-center justify-center gap-2 px-3 py-2 text-sm font-medium text-green-700 bg-green-50 border-2 border-green-300 rounded-md hover:bg-green-100" data-name="">
                    <i class="fas fa-plus-circle text-base"></i> 신규 거래처 등록
                </button>
            </div>`;
        customers.forEach(function(customer) {
            html += `
                <div class="modal-customer-item p-3 border border-gray-200 rounded-md hover:bg-blue-50 cursor-pointer transition-colors duration-200"
                     data-id="${customer.id}" data-name="${escapeAttr(customer.name)}" data-phone="${escapeAttr(customer.phone || '')}" data-address="${escapeAttr(customer.address || '')}">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-sm truncate">${escapeHtml(customer.name)}</div>
                            <div class="text-xs text-gray-600 mt-1 truncate"><i class="fas fa-phone mr-1"></i>${escapeHtml(customer.phone || translations.no_phone)}</div>
                            ${customer.address ? `<div class="text-xs text-gray-500 truncate mt-1"><i class="fas fa-map-marker-alt mr-1"></i>${escapeHtml(customer.address)}</div>` : ''}
                        </div>
                        <div class="text-blue-500 ml-2"><i class="fas fa-chevron-right text-sm"></i></div>
                    </div>
                </div>`;
        });
        customerList.innerHTML = html;
        customerList.querySelectorAll('.modal-customer-item').forEach(function(item) {
            item.addEventListener('click', function() { selectCustomerFromModal(this); });
        });
        bindNewCustomerBtns();
    }

    function displayModalProductList(products) {
        let html = '';
        const fmtPrice = function(v) { return (v && Number(v) > 0) ? Number(v).toLocaleString() : '-'; };
        products.forEach(function(product) {
            let displaySkus = '';
            if (product.wholesale_skus) {
                try { const a = JSON.parse(product.wholesale_skus); displaySkus = Array.isArray(a) ? a.join(', ') : product.sku; }
                catch (e) { displaySkus = product.sku; }
            } else { displaySkus = product.sku; }
            html += `
                <div class="modal-product-item p-3 border border-gray-200 rounded-md hover:bg-green-50 cursor-pointer transition-colors duration-200"
                     data-id="${product.id}" data-sku="${displaySkus}" data-name-ko="${product.display_name_ko || ''}" data-name-en="${product.display_name_en || ''}"
                     data-wholesale-price="${product.wholesale_price}" data-wholesale-price-piece="${product.wholesale_price_piece || 0}" data-min-quantity="${product.min_quantity}"
                     data-cost-price="${product.cost_price || 0}" data-cost-box="${product.wp_cost_box || 0}" data-cost-piece="${product.wp_cost_piece || 0}" data-selling-price="${product.selling_price || 0}">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-sm truncate">${product.display_name_en || product.display_name_ko || 'N/A'}</div>
                            ${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? `<div class="text-xs text-gray-600 mt-1 truncate">${product.display_name_ko}</div>` : ''}
                            <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-2">
                                <span><i class="fas fa-barcode mr-1"></i>${displaySkus}</span>
                                <span><i class="fas fa-box mr-1"></i>${translations.minimum_prefix}${product.min_quantity || 1}</span>
                            </div>
                            <div class="text-xs text-gray-600 mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                                <span>원가(박스): ${fmtPrice(product.wp_cost_box)}</span>
                                <span class="text-blue-700 font-medium">도매가(박스): ${fmtPrice(product.wholesale_price)}</span>
                            </div>
                        </div>
                        <div class="text-green-500 ml-2"><i class="fas fa-plus-circle text-lg"></i></div>
                    </div>
                </div>`;
        });
        productList.innerHTML = html;
        productList.querySelectorAll('.modal-product-item').forEach(function(item) {
            item.addEventListener('click', function() { addToCartFromModal(this); });
        });
    }

    function selectCustomerFromModal(item) {
        customerId.value = item.dataset.id;
        document.getElementById('selected_customer_name').textContent = item.dataset.name;
        document.getElementById('selected_customer_info').textContent = `${item.dataset.phone} ${item.dataset.address}`;
        selectedCustomer.classList.remove('hidden');
        customerSearch.value = item.dataset.name;
        updateButton();
        customerModal.classList.add('hidden');
    }

    function addToCartFromModal(item) {
        const productId = item.dataset.id;
        const existingIndex = cart.findIndex(it => it.product_id == productId);
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1;
            cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
        } else {
            const wholesalePrice = parseFloat(item.dataset.wholesalePrice);
            cart.push({
                product_id: productId, sku: item.dataset.sku, name_ko: item.dataset.nameKo, name_en: item.dataset.nameEn,
                unit_price: wholesalePrice, quantity: 1, total_price: wholesalePrice * 1, min_quantity: parseInt(item.dataset.minQuantity) || 1,
                wholesale_price: wholesalePrice, wholesale_price_piece: parseFloat(item.dataset.wholesalePricePiece) || 0,
                cost_price: parseFloat(item.dataset.costBox) || parseFloat(item.dataset.costPrice) || 0, cost_price_piece: parseFloat(item.dataset.costPiece) || 0,
                selling_price: parseFloat(item.dataset.sellingPrice) || 0, sale_unit: 'box'
            });
        }
        updateCart();
        productModal.classList.add('hidden');
    }

    function initializeEditMode() {
        if (!editMode || !editData) return;
        if (editData.customer_id) {
            customerId.value = editData.customer_id;
            customerSearch.value = editData.customer_name || '';
            if (selectedCustomer && editData.customer_name) {
                document.getElementById('selected_customer_name').textContent = editData.customer_name;
                document.getElementById('selected_customer_info').textContent = [editData.customer_phone, editData.customer_address].filter(Boolean).join(' ');
                selectedCustomer.classList.remove('hidden');
            }
        }
        const dateInput = document.getElementById('transaction_date');
        if (dateInput && editData.transaction_date) dateInput.value = editData.transaction_date;
        if (editData.items && editData.items.length > 0) {
            cart = [];
            editData.items.forEach(function(item) {
                const isManual = !item.product_id;
                cart.push({
                    product_id: item.product_id || null, is_manual: isManual, sku: item.sku || '수기',
                    name_ko: item.name_ko, name_en: item.name_en,
                    unit_price: parseFloat(item.unit_price), quantity: parseInt(item.quantity), total_price: parseFloat(item.total_price),
                    min_quantity: isManual ? 1 : (parseInt(item.min_quantity) || 1),
                    wholesale_price: parseFloat(item.wholesale_price) || parseFloat(item.unit_price) || 0,
                    wholesale_price_piece: parseFloat(item.wholesale_price_piece) || 0,
                    cost_price: parseFloat(item.cost_price) || 0, selling_price: parseFloat(item.selling_price) || 0,
                    sale_unit: (item.sale_unit === 'piece') ? 'piece' : 'box'
                });
            });
            updateCart();
        }
    }
    if (editMode) initializeEditMode();

    // 신규 등록 시 거래처 선지정 (외상거래 목록의 "판매등록" 버튼으로 진입)
    if (!editMode && prefillCustomer && prefillCustomer.id) {
        customerId.value = prefillCustomer.id;
        customerSearch.value = prefillCustomer.name || '';
        document.getElementById('selected_customer_name').textContent = prefillCustomer.name || '';
        document.getElementById('selected_customer_info').textContent =
            [prefillCustomer.phone, prefillCustomer.address].filter(Boolean).join(' ');
        selectedCustomer.classList.remove('hidden');
        updateButton();
    }

    // ─── 신규 거래처 등록 모달 ───
    const newCustomerModal = document.getElementById('new-customer-modal');
    function openNewCustomerModal(prefillName) {
        document.getElementById('new_customer_name').value = prefillName || '';
        document.getElementById('new_customer_phone').value = '';
        document.getElementById('new_customer_address').value = '';
        document.getElementById('new_customer_memo').value = '';
        document.getElementById('new-customer-form-errors').classList.add('hidden');
        customerModal.classList.add('hidden');
        customerSearchResults.classList.add('hidden');
        newCustomerModal.classList.remove('hidden');
        document.getElementById('new_customer_name').focus();
    }
    function closeNewCustomerModal() { newCustomerModal.classList.add('hidden'); }
    document.getElementById('close-new-customer-modal').addEventListener('click', closeNewCustomerModal);
    document.getElementById('cancel-new-customer').addEventListener('click', closeNewCustomerModal);
    newCustomerModal.addEventListener('click', function(e) { if (e.target === newCustomerModal) closeNewCustomerModal(); });

    document.getElementById('save-new-customer').addEventListener('click', function() {
        const name = document.getElementById('new_customer_name').value.trim();
        const phone = document.getElementById('new_customer_phone').value.trim();
        const address = document.getElementById('new_customer_address').value.trim();
        const memo = document.getElementById('new_customer_memo').value.trim();
        const errBox = document.getElementById('new-customer-form-errors');
        const errText = document.getElementById('new-customer-error-text');
        if (!name) { errText.textContent = '업체명을 입력해주세요.'; errBox.classList.remove('hidden'); return; }
        errBox.classList.add('hidden');
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>저장 중...';
        fetch('ajax_add_credit_customer.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `name=${encodeURIComponent(name)}&phone=${encodeURIComponent(phone)}&address=${encodeURIComponent(address)}&memo=${encodeURIComponent(memo)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeNewCustomerModal();
                const c = data.customer;
                customerId.value = c.id;
                document.getElementById('selected_customer_name').textContent = c.name;
                document.getElementById('selected_customer_info').textContent = `${c.phone || ''} ${c.address || ''}`.trim();
                selectedCustomer.classList.remove('hidden');
                customerSearch.value = c.name;
                updateButton();
                showNotification(translations.customer_registered, 'success');
            } else {
                errText.textContent = data.message || '저장 중 오류가 발생했습니다.';
                errBox.classList.remove('hidden');
            }
        })
        .catch(() => { errText.textContent = translations.server_error; errBox.classList.remove('hidden'); })
        .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save mr-1"></i>저장'; });
    });

    function bindNewCustomerBtns() {
        document.querySelectorAll('.open-new-customer-modal').forEach(function(btn) {
            btn.addEventListener('click', function(e) { e.stopPropagation(); openNewCustomerModal(this.dataset.name || ''); });
        });
    }

    function escapeHtml(str) { return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function escapeAttr(str) { return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    window.showNotification = function(message, type = 'info') {
        const existing = document.querySelector('.notification-toast');
        if (existing) existing.remove();
        const n = document.createElement('div');
        n.className = `notification-toast fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300 ease-in-out ${type === 'success' ? 'bg-green-500 text-white' : type === 'error' ? 'bg-red-500 text-white' : 'bg-blue-500 text-white'}`;
        n.innerHTML = `<div class="flex items-center"><i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i><span>${message}</span></div>`;
        document.body.appendChild(n);
        setTimeout(() => { n.style.transform = 'translateX(100%)'; setTimeout(() => { if (n.parentNode) n.remove(); }, 300); }, 3000);
    };
});
</script>

<style>
.modal-customer-item, .modal-product-item { transition: all 0.15s ease-in-out; }
.modal-customer-item:hover, .modal-product-item:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.cart-table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.cart-table { min-width: 940px; }
@media (max-width: 640px) {
    .cart-table { font-size: 0.7rem; }
    .cart-table th, .cart-table td { padding: 0.25rem; }
    .cart-table .product-name { min-width: 110px; }
    .cart-table .sku-column { min-width: 55px; font-size: 0.625rem; }
    .cart-table .quantity-controls button { width:1.25rem; height:1.25rem; font-size:0.625rem; }
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
