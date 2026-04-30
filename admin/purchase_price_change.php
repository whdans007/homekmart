<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('purchase.price_change_analysis') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/margin_helper.php';

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$purchase_id = $_GET['purchase_id'] ?? '';

if (empty($purchase_id)) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('purchase.no_purchase_id_specified')
    ];
    header('Location: purchase_management.php');
    exit;
}

$conn = get_db_connection();

// 매입 정보 조회 (확정 상태 포함)
$purchase_sql = "SELECT p.*, s.name as supplier_name, COALESCE(p.is_confirmed, 0) as is_confirmed, p.confirmed_at, p.confirmed_by_user_id
                 FROM purchases p
                 JOIN suppliers s ON p.supplier_id = s.id
                 WHERE p.purchase_id = ?";
$purchase_stmt = $conn->prepare($purchase_sql);
$purchase_stmt->bind_param("s", $purchase_id);
$purchase_stmt->execute();
$purchase_result = $purchase_stmt->get_result();

if ($purchase_result->num_rows == 0) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('purchase.purchase_not_found')
    ];
    header('Location: purchase_management.php');
    exit;
}

$purchase_info = $purchase_result->fetch_assoc();

// 헤더에서 이미 설정된 점포 정보 사용 (header.php에서 처리됨)
// $current_store_id와 $current_store_name은 이미 header.php에서 설정됨

// 디버깅: 헤더에서 설정된 점포 정보 확인
error_log("가격변동 페이지 로드 - user_id: " . ($_SESSION['user_id'] ?? 'null') . ", store_id: " . ($current_store_id ?? 'null') . ", store_name: " . ($current_store_name ?? 'null') . ", role: " . ($_SESSION['role'] ?? 'null'));
$debug_msg = date('Y-m-d H:i:s') . " - 가격변동 페이지 로드 - user_id: " . ($_SESSION['user_id'] ?? 'null') . ", store_id: " . ($current_store_id ?? 'null') . ", store_name: " . ($current_store_name ?? 'null') . ", role: " . ($_SESSION['role'] ?? 'null') . "\n";
file_put_contents(__DIR__ . '/debug_log.txt', $debug_msg, FILE_APPEND | LOCK_EX);

// 매입 상품과 현재 점포 정보를 비교 조회
$items_sql = "SELECT
    pi.*,
    pr.name_ko as product_name_ko,
    pr.name_en as product_name_en,
    pr.sku,
    pr.pieces_per_box,
    c.name as category_name,
    COALESCE(inv.cost_price, 0) as current_cost_price,
    COALESCE(inv.selling_price, 0) as current_selling_price,
    inv.quantity as store_quantity,
    -- 매입 단가를 낱개 기준으로 환산
    CASE
        WHEN pi.purchase_type = 'box' AND pr.pieces_per_box > 0
        THEN pi.unit_price / pr.pieces_per_box
        ELSE pi.unit_price
    END as purchase_unit_price_per_piece
FROM purchase_items pi
JOIN products pr ON pi.product_id = pr.id
LEFT JOIN categories c ON pr.category_id = c.id
LEFT JOIN inventory inv ON pi.product_id = inv.product_id AND inv.store_id = ?
WHERE pi.purchase_id = ?
ORDER BY COALESCE(pi.sort_order, pi.item_id)";

$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("is", $current_store_id, $purchase_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
?>

<!-- 타이틀을 컨테이너 밖으로 이동 -->
<div class="bg-gray-50 py-4 mb-1">
    <div class="text-center">
        <h1 class="text-3xl font-bold text-gray-900 mb-3"><?php echo t('purchase.price_change_analysis'); ?></h1>
        <!-- 매입 정보 테이블 -->
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <table class="w-full border-collapse border border-gray-300">
                <tbody>
                    <tr>
                        <td class="border border-gray-300 bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 text-center"><?php echo t('purchase.purchase_number'); ?></td>
                        <td class="border border-gray-300 px-4 py-2 text-sm text-gray-900 text-center"><?php echo htmlspecialchars($purchase_id); ?></td>
                        <td class="border border-gray-300 bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 text-center"><?php echo t('supplier.supplier'); ?></td>
                        <td class="border border-gray-300 px-4 py-2 text-sm text-gray-900 text-center"><?php echo htmlspecialchars($purchase_info['supplier_name']); ?></td>
                        <td class="border border-gray-300 bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 text-center"><?php echo t('purchase.purchase_date'); ?></td>
                        <td class="border border-gray-300 px-4 py-2 text-sm text-gray-900 text-center"><?php echo htmlspecialchars($purchase_info['purchase_date']); ?></td>
                        <td class="border border-gray-300 bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 text-center"><?php echo t('store.store'); ?></td>
                        <td class="border border-gray-300 px-4 py-2 text-sm text-gray-900 text-center"><?php echo htmlspecialchars($current_store_name); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-2">
    <div class="flex justify-between items-center mb-2">
        <!-- 왼쪽: 매입확정 상태 및 버튼 -->
        <div class="flex items-center space-x-3">
            <?php if (isset($purchase_info['is_confirmed']) && $purchase_info['is_confirmed']): ?>
                <div class="flex items-center space-x-2 bg-green-50 border border-green-200 rounded-md px-4 py-2">
                    <i class="fas fa-check-circle text-green-600"></i>
                    <div>
                        <span class="text-sm font-semibold text-green-800"><?php echo t('purchase.confirmed'); ?></span>
                        <?php if ($purchase_info['confirmed_at']): ?>
                            <span class="text-xs text-green-600 block"><?php echo t('purchase.confirmed_at'); ?>: <?php echo date('Y-m-d H:i', strtotime($purchase_info['confirmed_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($_SESSION['role'] === 'super_admin'): ?>
                    <button type="button" id="cancel-confirm-btn" data-purchase-id="<?php echo htmlspecialchars($purchase_id); ?>" class="inline-flex items-center justify-center rounded-md border border-orange-300 bg-orange-50 px-4 py-2 text-sm font-medium text-orange-700 shadow-sm hover:bg-orange-100 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2">
                        <i class="fas fa-undo mr-2"></i> <?php echo t('purchase.cancel_confirmation'); ?>
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <button type="button" id="confirm-purchase-btn" data-purchase-id="<?php echo htmlspecialchars($purchase_id); ?>" class="inline-flex items-center justify-center rounded-md border border-green-300 bg-green-50 px-4 py-2 text-sm font-medium text-green-700 shadow-sm hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    <i class="fas fa-check-circle mr-2"></i> <?php echo t('purchase.confirm_purchase'); ?>
                </button>
            <?php endif; ?>
            <a href="edit_purchase.php?id=<?php echo htmlspecialchars($purchase_id); ?>" class="inline-flex items-center justify-center rounded-md border border-blue-300 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 shadow-sm hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <i class="fas fa-edit mr-2"></i> <?php echo t('purchase.edit_purchase'); ?>
            </a>
        </div>

        <!-- 오른쪽: 기존 버튼들 -->
        <div class="flex space-x-3">
            <!-- 가격 변동 선택 버튼들 -->
            <div class="flex space-x-2">
                <button id="selectIncreaseBtn" class="inline-flex items-center justify-center rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    <i class="fas fa-arrow-up mr-1"></i> <?php echo t('purchase.select_increase'); ?> (<span id="increaseCount">0</span>)
                </button>
                
                <button id="selectDecreaseBtn" class="inline-flex items-center justify-center rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm font-medium text-green-700 hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    <i class="fas fa-arrow-down mr-1"></i> <?php echo t('purchase.select_decrease'); ?> (<span id="decreaseCount">0</span>)
                </button>
                
                <button id="clearSelectionBtn" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    <i class="fas fa-times mr-1"></i> <?php echo t('purchase.clear_selection'); ?>
                </button>
            </div>
            
            <!-- 마진율 입력과 저장 버튼 -->
            <div class="flex space-x-2">
                <input type="number" id="bulkMarginInput" class="w-20 px-2 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" placeholder="30" step="0.1" min="0">
                <span class="flex items-center text-sm text-gray-500">%</span>
                
                <!-- 저장 버튼 -->
                <button id="saveBtn" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 print:hidden disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    <i class="fas fa-save mr-2"></i> <?php echo t('common.save'); ?> (<span id="selectedCount">0</span><?php echo t('common.items'); ?>)
                </button>
            </div>
            <a href="purchase_management.php" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                <i class="fas fa-arrow-left mr-2"></i> <?php echo t('purchase.back_to_purchase_management'); ?>
            </a>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="mb-6 print:hidden">
            <?php 
            $flash = $_SESSION['flash'];
            $alert_class = $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800';
            $icon_class = $flash['type'] === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400';
            ?>
            <div class="<?php echo $alert_class; ?> border rounded-md p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas <?php echo $icon_class; ?>"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm"><?php echo htmlspecialchars($flash['message']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="bg-white shadow-lg rounded-lg overflow-hidden border border-gray-300">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 border-collapse border border-gray-300">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 print:hidden">
                            <input type="checkbox" id="selectAll" class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                        </th>
                        <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">순번</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('product.product_info'); ?></th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('purchase.existing_cost'); ?></th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('purchase.purchase_cost'); ?><br><span class="text-xs normal-case">(<?php echo t('purchase.per_unit'); ?>)</span></th>
                        <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('purchase.cost_change'); ?></th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('purchase.margin_rate'); ?></th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('purchase.existing_selling_price'); ?></th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('purchase.expected_selling_price'); ?><br><span class="text-xs normal-case">(<?php echo t('purchase.based_on_purchase_cost'); ?>)</span></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php
                    $has_price_changes = false;
                    if ($items_result && $items_result->num_rows > 0):
                        while($item = $items_result->fetch_assoc()):
                            // 가격 변동 계산
                            $price_change = $item['purchase_unit_price_per_piece'] - $item['current_cost_price'];
                            $price_change_percent = $item['current_cost_price'] > 0 ? ($price_change / $item['current_cost_price']) * 100 : 0;

                            // 가격 변동 타입 결정
                            if (abs($price_change) < 1) {
                                $price_change_type = 'same';
                            } elseif ($price_change > 0) {
                                $price_change_type = 'increase';
                            } else {
                                $price_change_type = 'decrease';
                            }

                            // 원가 변동이 있는지 확인 (데이터 없음 메시지 표시용)
                            if (abs($price_change) >= 1) {
                                $has_price_changes = true;
                            }

                            // 기존 마진율 계산
                            $current_margin_rate = 0;
                            if ($item['current_cost_price'] > 0) {
                                $current_margin_rate = (($item['current_selling_price'] - $item['current_cost_price']) / $item['current_cost_price']) * 100;
                            }

                            // 새 원가 기준 예상 판매가 계산 (기존 마진율 적용, 소수점 이하 무조건 올림)
                            $new_selling_price = 0;
                            if ($current_margin_rate > 0) {
                                $new_selling_price = ceil($item['purchase_unit_price_per_piece'] * (1 + ($current_margin_rate / 100)));
                            } else {
                                // 마진율이 없으면 카테고리 기본 마진율 적용
                                $margin_rate = get_margin_rate_by_product($item['product_id']);
                                $new_selling_price = ceil($item['purchase_unit_price_per_piece'] * (1 + ($margin_rate / 100)));
                            }
                        ?>
                            <tr class="hover:bg-gray-50 cursor-pointer item-row" data-product-id="<?php echo $item['product_id']; ?>" data-item-id="<?php echo $item['item_id']; ?>" data-price-change-type="<?php echo $price_change_type; ?>">
                                <td class="px-3 py-4 text-center border border-gray-300 print:hidden">
                                    <input type="checkbox" class="item-checkbox h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded" data-product-id="<?php echo $item['product_id']; ?>" data-item-id="<?php echo $item['item_id']; ?>">
                                </td>
                                <td class="px-3 py-4 text-center border border-gray-300 font-medium text-gray-700">
                                    <?php echo $item['sort_order'] ?? $item['item_id']; ?>
                                </td>
                                <td class="px-3 py-4 border border-gray-300">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['product_name_ko']); ?></div>
                                    <?php if ($item['product_name_en']): ?>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($item['product_name_en']); ?></div>
                                    <?php endif; ?>
                                    <div class="text-xs text-gray-500 font-mono">SKU: <?php echo htmlspecialchars($item['sku']); ?></div>
                                </td>
                                <td class="px-3 py-4 text-right border border-gray-300">
                                    <span class="text-sm font-mono"><?php echo number_format($item['current_cost_price'], 2); ?></span>
                                </td>
                                <td class="px-3 py-4 text-right border border-gray-300">
                                    <span class="text-sm font-mono font-semibold text-blue-600"><?php echo number_format($item['purchase_unit_price_per_piece'], 2); ?></span>
                                    <?php if ($item['purchase_type'] === 'box' && $item['pieces_per_box']): ?>
                                        <div class="text-xs text-gray-500">
                                            <?php echo t('product.box'); ?>: <?php echo number_format($item['unit_price'], 2); ?>
                                            (<?php echo $item['pieces_per_box']; ?><?php echo t('product.pieces_per_box_unit'); ?>)
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4 text-center border border-gray-300">
                                    <?php if (abs($price_change) < 1): ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                            <?php echo t('purchase.same'); ?>
                                        </span>
                                    <?php elseif ($price_change > 0): ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                            <i class="fas fa-arrow-up mr-1"></i>
                                            <?php echo t('purchase.increase'); ?> <?php echo number_format(abs($price_change)); ?>
                                        </span>
                                        <div class="text-xs text-red-600 mt-1">
                                            (+<?php echo number_format($price_change_percent, 1); ?>%)
                                        </div>
                                    <?php else: ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            <i class="fas fa-arrow-down mr-1"></i>
                                            <?php echo t('purchase.decrease'); ?> <?php echo number_format(abs($price_change)); ?>
                                        </span>
                                        <div class="text-xs text-green-600 mt-1">
                                            (<?php echo number_format($price_change_percent, 1); ?>%)
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4 text-right border border-gray-300">
                                    <div class="flex items-center justify-end space-x-1">
                                        <input type="number" 
                                               class="margin-input w-16 text-right text-sm font-mono border border-gray-300 rounded px-1 py-0.5 focus:outline-none focus:ring-1 focus:ring-yellow-500 focus:border-yellow-500" 
                                               value="<?php echo number_format($current_margin_rate, 1); ?>" 
                                               step="0.1" 
                                               min="0"
                                               data-product-id="<?php echo $item['product_id']; ?>"
                                               data-item-id="<?php echo $item['item_id']; ?>"
                                               data-cost-price="<?php echo $item['purchase_unit_price_per_piece']; ?>"
                                               data-original-margin="<?php echo number_format($current_margin_rate, 1); ?>">
                                        <span class="text-sm text-gray-500">%</span>
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-right border border-gray-300">
                                    <span class="text-sm font-mono"><?php echo number_format($item['current_selling_price']); ?></span>
                                </td>
                                <td class="px-3 py-4 text-right border border-gray-300">
                                    <input type="number" 
                                           class="expected-price w-24 text-right text-sm font-mono font-semibold text-green-600 border border-gray-300 rounded px-1 py-0.5 focus:outline-none focus:ring-1 focus:ring-green-500 focus:border-green-500" 
                                           data-product-id="<?php echo $item['product_id']; ?>"
                                           data-item-id="<?php echo $item['item_id']; ?>"
                                           data-original-price="<?php echo $new_selling_price; ?>"
                                           value="<?php echo $new_selling_price; ?>"
                                           min="0"
                                           step="1">
                                    <div class="price-difference text-xs text-gray-500" 
                                         data-product-id="<?php echo $item['product_id']; ?>"
                                         data-item-id="<?php echo $item['item_id']; ?>"
                                         data-current-price="<?php echo $item['current_selling_price']; ?>">
                                        <?php echo t('purchase.difference'); ?>: <?php echo number_format($new_selling_price - $item['current_selling_price']); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    
                    <?php if ($items_result->num_rows == 0): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-300">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-box-open text-4xl text-gray-400"></i>
                                    <p class="mt-4"><?php echo t('purchase.no_purchase_items'); ?></p>
                                    <p class="text-xs text-gray-400"><?php echo t('purchase.no_registered_items'); ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
</div>



<script>
document.addEventListener('DOMContentLoaded', function() {
    // 현재 점포 정보 (product_management.php와 동일한 방식)
    const currentStoreId = <?php echo json_encode($current_store_id); ?>;
    const currentStoreName = <?php echo json_encode($current_store_name); ?>;
    
    // 디버깅: JavaScript에서 점포 정보 확인
    console.log('JavaScript - currentStoreId:', currentStoreId, 'currentStoreName:', currentStoreName);
    
    
    // 선택 관련 변수
    let selectedItems = new Set();
    
    // 전체 선택/해제 기능
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.item-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
            updateItemSelection(checkbox.dataset.itemId, this.checked);
        });
        updateSelectedUI();
    });
    
    // 개별 체크박스 이벤트
    document.querySelectorAll('.item-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateItemSelection(this.dataset.itemId, this.checked);
            updateSelectedUI();
            updateSelectAllState();
        });
    });
    
    // 행 클릭으로 선택/해제 (체크박스와 버튼 영역 제외)
    document.querySelectorAll('.item-row').forEach(row => {
        row.addEventListener('click', function(e) {
            // 체크박스, 버튼, 입력 필드 클릭은 제외
            if (e.target.type === 'checkbox' || 
                e.target.tagName === 'BUTTON' || 
                e.target.tagName === 'INPUT' || 
                e.target.closest('button') ||
                e.target.closest('.apply-new-prices-btn') ||
                e.target.closest('.manual-edit-btn')) {
                return;
            }
            
            const checkbox = this.querySelector('.item-checkbox');
            checkbox.checked = !checkbox.checked;
            updateItemSelection(checkbox.dataset.itemId, checkbox.checked);
            updateSelectedUI();
            updateSelectAllState();
        });
    });
    
    // 선택 상태 업데이트
    function updateItemSelection(itemId, isSelected) {
        if (isSelected) {
            selectedItems.add(itemId);
        } else {
            selectedItems.delete(itemId);
        }
        
        // 행의 시각적 표시 업데이트
        const row = document.querySelector(`.item-row[data-item-id="${itemId}"]`);
        if (row) {
            if (isSelected) {
                row.classList.add('bg-blue-50', 'border-l-4', 'border-blue-500');
            } else {
                row.classList.remove('bg-blue-50', 'border-l-4', 'border-blue-500');
            }
        }
    }
    
    // 전체 선택 체크박스 상태 업데이트
    function updateSelectAllState() {
        const checkboxes = document.querySelectorAll('.item-checkbox');
        const selectAllCheckbox = document.getElementById('selectAll');
        
        const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        
        if (checkedCount === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedCount === checkboxes.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }
    
    // 선택된 항목 UI 업데이트
    function updateSelectedUI() {
        const selectedCount = selectedItems.size;
        const selectedCountSpan = document.getElementById('selectedCount');
        
        // 헤더 버튼 업데이트
        selectedCountSpan.textContent = selectedCount;
        
        updateSaveButtonState();
        
        // 선택된 항목이 있고 마진율이 입력되어 있으면 자동 적용
        const bulkMarginInput = document.getElementById('bulkMarginInput');
        const marginValue = parseFloat(bulkMarginInput.value);
        if (selectedCount > 0 && !isNaN(marginValue) && marginValue >= 0) {
            applyBulkMarginRateRealtime(marginValue);
        }
    }
    
    // 저장 버튼 상태 업데이트
    function updateSaveButtonState() {
        const selectedCount = selectedItems.size;
        const saveBtn = document.getElementById('saveBtn');
        const bulkMarginInput = document.getElementById('bulkMarginInput');
        const marginValue = parseFloat(bulkMarginInput.value);
        
        // 선택된 개수 업데이트
        document.getElementById('selectedCount').textContent = selectedCount;
        
        if (selectedCount > 0 && !isNaN(marginValue) && marginValue >= 0) {
            // 저장 버튼 활성화
            saveBtn.disabled = false;
        } else {
            // 저장 버튼 비활성화
            saveBtn.disabled = true;
        }
    }
    
    
    
    // 저장 버튼 이벤트 (데이터베이스 저장)
    const saveBtnElement = document.getElementById('saveBtn');
    if (saveBtnElement) {
        saveBtnElement.addEventListener('click', function() {
            if (selectedItems.size === 0) {
                alert('<?php echo addslashes(t('purchase.js_select_products_first')); ?>');
                return;
            }
            
            const marginRate = parseFloat(document.getElementById('bulkMarginInput').value);
            
            if (isNaN(marginRate) || marginRate < 0) {
                alert('<?php echo addslashes(t('purchase.js_enter_valid_margin')); ?>');
                document.getElementById('bulkMarginInput').focus();
                return;
            }
            
            // 확인 대화상자
            if (confirm(`<?php echo addslashes(t('purchase.js_confirm_apply_margin')); ?>`.replace('{count}', selectedItems.size).replace('{rate}', marginRate) + '\n\n<?php echo addslashes(t('purchase.js_irreversible_action')); ?>')) {
                applyBulkMarginRateDatabase(marginRate);
            }
        });
    }
    
    
    // 마진율 입력 필드 변경 시 자동 적용 및 localStorage 저장
    document.getElementById('bulkMarginInput').addEventListener('input', function() {
        // localStorage에 마진율 값 저장
        localStorage.setItem('lastMarginRate', this.value);
        updateSaveButtonState();
        
        // 선택된 항목이 있으면 자동으로 마진율 적용
        const marginValue = parseFloat(this.value);
        if (selectedItems.size > 0 && !isNaN(marginValue) && marginValue >= 0) {
            applyBulkMarginRateRealtime(marginValue);
        }
    });
    
    
    // 예상판매가 업데이트 함수
    function updateExpectedPrice(itemId, marginRate) {
        const marginInput = document.querySelector(`.margin-input[data-item-id="${itemId}"]`);
        if (!marginInput) return;
        
        const costPrice = parseFloat(marginInput.dataset.costPrice);
        
        // 새로운 판매가 계산 (소수점 이하 무조건 올림)
        const newSellingPrice = Math.ceil(costPrice * (1 + (marginRate / 100)));
        
        // 예상판매가 업데이트
        const expectedPriceInput = document.querySelector(`.expected-price[data-item-id="${itemId}"]`);
        
        if (expectedPriceInput) {
            expectedPriceInput.value = newSellingPrice;
            updatePriceDifference(itemId);
            
            // 마진율이 변경되었는지 표시
            const originalMargin = parseFloat(marginInput.dataset.originalMargin);
            if (Math.abs(marginRate - originalMargin) > 0.1) {
                marginInput.classList.add('border-yellow-500', 'bg-yellow-50');
            } else {
                marginInput.classList.remove('border-yellow-500', 'bg-yellow-50');
            }
        }
    }
    
    // 가격 차이 업데이트 함수
    function updatePriceDifference(itemId) {
        const expectedPriceInput = document.querySelector(`.expected-price[data-item-id="${itemId}"]`);
        const priceDifferenceDiv = document.querySelector(`.price-difference[data-item-id="${itemId}"]`);
        
        if (expectedPriceInput && priceDifferenceDiv) {
            const expectedPrice = parseFloat(expectedPriceInput.value) || 0;
            const currentPrice = parseFloat(priceDifferenceDiv.dataset.currentPrice);
            const priceDifference = expectedPrice - currentPrice;
            
            priceDifferenceDiv.textContent = '<?php echo addslashes(t('purchase.difference')); ?>: ' + priceDifference.toLocaleString();
        }
    }
    
    // 판매가 기준으로 마진율 역계산 함수
    function updateMarginFromSellingPrice(itemId) {
        const expectedPriceInput = document.querySelector(`.expected-price[data-item-id="${itemId}"]`);
        const marginInput = document.querySelector(`.margin-input[data-item-id="${itemId}"]`);
        
        if (expectedPriceInput && marginInput) {
            const sellingPrice = parseFloat(expectedPriceInput.value) || 0;
            const costPrice = parseFloat(marginInput.dataset.costPrice) || 0;
            
            if (costPrice > 0 && sellingPrice > 0) {
                // 마진율 계산: (1 - 원가/판매가) * 100
                const marginRate = (1 - costPrice / sellingPrice) * 100;
                
                // 마진율 필드 업데이트 (소수점 첫째 자리까지)
                marginInput.value = marginRate.toFixed(1);
                
                // 마진율이 변경되었는지 표시
                const originalMargin = parseFloat(marginInput.dataset.originalMargin);
                if (Math.abs(marginRate - originalMargin) > 0.1) {
                    marginInput.classList.add('border-yellow-500', 'bg-yellow-50');
                } else {
                    marginInput.classList.remove('border-yellow-500', 'bg-yellow-50');
                }
            } else if (sellingPrice === 0) {
                // 판매가가 0이면 마진율도 0으로 설정
                marginInput.value = '0.0';
                marginInput.classList.remove('border-yellow-500', 'bg-yellow-50');
            }
        }
    }
    
    // 선택상품 일괄 마진율 실시간 적용 함수 (UI만 업데이트, DB 저장 없음)
    function applyBulkMarginRateRealtime(marginRate) {
        let updatedCount = 0;
        
        // 선택된 각 상품에 대해 마진율 업데이트
        selectedItems.forEach(itemId => {
            const marginInput = document.querySelector(`.margin-input[data-item-id="${itemId}"]`);
            
            if (marginInput) {
                // 마진율 입력 필드 업데이트
                marginInput.value = marginRate.toFixed(1);
                
                // 예상판매가 실시간 계산 및 표시
                updateExpectedPrice(itemId, marginRate);
                
                updatedCount++;
            }
        });
        
        // 사용자 피드백 - 자동 적용이므로 알림 없이 조용히 업데이트
        if (updatedCount > 0) {
            // 저장 버튼 상태만 업데이트
            updateSaveButtonState();
        }
    }
    
    // 선택상품 일괄 마진율 데이터베이스 적용 함수
    function applyBulkMarginRateDatabase(marginRate) {
        console.log('DB 저장 함수 시작, marginRate:', marginRate);
        
        const saveBtn = document.getElementById('saveBtn');
        console.log('버튼 요소:', saveBtn);
        
        // 버튼 비활성화 및 로딩 상태
        saveBtn.disabled = true;
        const originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><?php echo addslashes(t('purchase.js_saving_to_database')); ?>...';
        
        // 선택된 아이템 ID 배열 생성
        const itemIds = Array.from(selectedItems);
        console.log('전송할 아이템 ID들:', itemIds);
        
        // AJAX 요청 데이터 준비
        const formData = new FormData();
        formData.append('purchase_id', '<?php echo htmlspecialchars($purchase_id); ?>');
        formData.append('margin_rate', marginRate);
        formData.append('store_id', currentStoreId || '');
        
        console.log('FormData 준비:');
        console.log('- purchase_id:', '<?php echo htmlspecialchars($purchase_id); ?>');
        console.log('- margin_rate:', marginRate);
        console.log('- store_id:', currentStoreId || '');
        
        // 각 아이템의 정보를 수집해서 전송 (배열 순서 의존성 제거)
        itemIds.forEach(itemId => {
            // 해당 아이템의 product_id 가져오기
            const marginInput = document.querySelector(`.margin-input[data-item-id="${itemId}"]`);
            const expectedPriceInput = document.querySelector(`.expected-price[data-item-id="${itemId}"]`);
            
            if (marginInput && expectedPriceInput) {
                const productId = marginInput.dataset.productId;
                const newSellingPrice = expectedPriceInput.value;
                
                // item_id별 직접 매핑 방식으로 데이터 전송
                formData.append('item_ids[]', itemId);
                formData.append(`product_id_${itemId}`, productId);
                formData.append(`selling_price_${itemId}`, newSellingPrice);
                console.log(`아이템 ${itemId} (상품 ${productId})의 예상 판매가: ${newSellingPrice}`);
            }
        });
        
        console.log('AJAX 요청 전송 시작');
        
        // 서버로 AJAX 요청 전송 (직접 SQL 실행 버전)
        fetch('ajax_bulk_apply_margin_direct.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('응답 상태:', response.status, response.statusText);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            console.log('서버 응답 텍스트:', text);
            try {
                const data = JSON.parse(text);
                console.log('파싱된 JSON 데이터:', data);
                return data;
            } catch (e) {
                console.error('JSON 파싱 오류:', e);
                console.error('서버 응답:', text);
                throw new Error('<?php echo addslashes(t('purchase.js_invalid_server_response')); ?>');
            }
        })
        .then(data => {
            console.log('처리할 데이터:', data);
            if (data.success) {
                console.log('성공 응답 처리');
                
                // 업데이트된 상품 정보 확인
                if (data.updated_items) {
                    console.log('업데이트된 상품 정보:', data.updated_items);
                    data.updated_items.forEach(item => {
                        console.log(`상품 ${item.product_id} (${item.product_name}):
                            원가: ${item.old_cost_price} → ${item.new_cost_price},
                            판매가: ${item.old_selling_price} → ${item.new_selling_price},
                            마진율: ${item.old_margin_rate}% → ${item.new_margin_rate}%,
                            이력저장: ${item.history_saved ? '성공' : '실패'}`);
                    });
                }
                
                // 성공 시
                showSuccessMessage(`<?php echo addslashes(t('purchase.js_success')); ?>: ${data.message}`);
                
                // 페이지 새로고침하여 업데이트된 정보 표시
                setTimeout(() => {
                    console.log('페이지 새로고침 시작');
                    window.location.reload();
                }, 1500);
            } else {
                console.log('실패 응답 처리:', data.message);
                // 실패 시
                alert('<?php echo addslashes(t('purchase.js_error')); ?>: ' + data.message);
            }
        })
        .catch(error => {
            console.error('AJAX 요청 오류:', error);
            alert('<?php echo addslashes(t('purchase.js_communication_error')); ?>: ' + error.message);
        })
        .finally(() => {
            // 버튼 원상복구
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
        });
    }
    
    // 선택 해제 함수
    function clearAllSelections() {
        // 모든 체크박스 해제
        document.querySelectorAll('.item-checkbox').forEach(checkbox => {
            checkbox.checked = false;
            updateItemSelection(checkbox.dataset.itemId, false);
        });
        
        // 전체 선택 체크박스도 해제
        document.getElementById('selectAll').checked = false;
        document.getElementById('selectAll').indeterminate = false;
        
        // 선택 상태 초기화
        selectedItems.clear();
        updateSelectedUI();
    }
    
    // 성공 메시지 표시 함수
    function showSuccessMessage(message) {
        // 기존 메시지 제거
        const existingMessage = document.querySelector('.bulk-success-message');
        if (existingMessage) {
            existingMessage.remove();
        }
        
        // 새 메시지 생성
        const messageDiv = document.createElement('div');
        messageDiv.className = 'bulk-success-message mb-4 p-4 bg-green-50 border border-green-200 rounded-md';
        messageDiv.innerHTML = `
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <div class="text-sm text-green-700">
                    ${message}
                </div>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-green-400 hover:text-green-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        // 테이블 위에 삽입
        const tableContainer = document.querySelector('.bg-white.shadow-lg');
        tableContainer.parentNode.insertBefore(messageDiv, tableContainer);
        
        // 5초 후 자동 삭제
        setTimeout(() => {
            if (messageDiv && messageDiv.parentNode) {
                messageDiv.remove();
            }
        }, 5000);
    }
    
    // localStorage에서 최근 마진율 값 불러오기 및 설정
    const savedMarginRate = localStorage.getItem('lastMarginRate');
    if (savedMarginRate && !isNaN(parseFloat(savedMarginRate))) {
        document.getElementById('bulkMarginInput').value = savedMarginRate;
        console.log('저장된 마진율 불러옴:', savedMarginRate);
    }
    
    // 가격 변동 상품 수 계산 함수
    function countPriceChangeItems() {
        let increaseCount = 0;
        let decreaseCount = 0;
        let sameCount = 0;
        
        document.querySelectorAll('.item-row').forEach(row => {
            const type = row.dataset.priceChangeType;
            if (type === 'increase') increaseCount++;
            else if (type === 'decrease') decreaseCount++;
            else if (type === 'same') sameCount++;
        });
        
        // 버튼에 개수 표시
        const increaseCountSpan = document.getElementById('increaseCount');
        const decreaseCountSpan = document.getElementById('decreaseCount');
        
        if (increaseCountSpan) increaseCountSpan.textContent = increaseCount;
        if (decreaseCountSpan) decreaseCountSpan.textContent = decreaseCount;
        
        return { increaseCount, decreaseCount, sameCount };
    }
    
    // 가격 변동 타입별 선택 함수
    function selectByPriceChangeType(type) {
        // 먼저 모든 선택 해제
        clearAllSelections();
        
        // 해당 타입의 상품만 선택
        document.querySelectorAll(`.item-row[data-price-change-type="${type}"]`).forEach(row => {
            const checkbox = row.querySelector('.item-checkbox');
            if (checkbox) {
                checkbox.checked = true;
                updateItemSelection(checkbox.dataset.itemId, true);
            }
        });
        
        updateSelectedUI();
        updateSelectAllState();
    }
    
    // 인상선택 버튼 이벤트
    const selectIncreaseBtnElement = document.getElementById('selectIncreaseBtn');
    if (selectIncreaseBtnElement) {
        selectIncreaseBtnElement.addEventListener('click', function() {
            selectByPriceChangeType('increase');
        });
    }
    
    // 인하선택 버튼 이벤트
    const selectDecreaseBtnElement = document.getElementById('selectDecreaseBtn');
    if (selectDecreaseBtnElement) {
        selectDecreaseBtnElement.addEventListener('click', function() {
            selectByPriceChangeType('decrease');
        });
    }
    
    // 선택해제 버튼 이벤트
    const clearSelectionBtnElement = document.getElementById('clearSelectionBtn');
    if (clearSelectionBtnElement) {
        clearSelectionBtnElement.addEventListener('click', function() {
            clearAllSelections();
        });
    }
    
    // 페이지 로드 시 가격 변동 상품 수 계산
    countPriceChangeItems();
    
    // 초기 UI 상태 설정
    updateSelectedUI();

    // 매입 확정/취소 기능
    const confirmPurchaseBtn = document.getElementById('confirm-purchase-btn');
    const cancelConfirmBtn = document.getElementById('cancel-confirm-btn');

    if (confirmPurchaseBtn) {
        confirmPurchaseBtn.addEventListener('click', function(e) {
            e.preventDefault();

            if (confirm('<?php echo addslashes(t("purchase.js_confirm_purchase_message")); ?>')) {
                const purchaseId = this.dataset.purchaseId;
                handlePurchaseConfirmation(purchaseId, 'confirm');
            }
        });
    }

    if (cancelConfirmBtn) {
        cancelConfirmBtn.addEventListener('click', function(e) {
            e.preventDefault();

            if (confirm('<?php echo addslashes(t("purchase.js_cancel_confirmation_message")); ?>')) {
                const purchaseId = this.dataset.purchaseId;
                handlePurchaseConfirmation(purchaseId, 'cancel');
            }
        });
    }

    // 매입 확정/취소 처리 함수
    function handlePurchaseConfirmation(purchaseId, action) {
        const button = action === 'confirm' ? confirmPurchaseBtn : cancelConfirmBtn;
        const originalText = button.innerHTML;

        // 버튼 비활성화 및 로딩 상태 표시
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + (action === 'confirm' ? '<?php echo addslashes(t("purchase.confirming")); ?>...' : '<?php echo addslashes(t("purchase.canceling")); ?>...');

        // AJAX 요청
        fetch('ajax_confirm_purchase.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'purchase_id=' + encodeURIComponent(purchaseId) + '&action=' + encodeURIComponent(action)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 성공 시 페이지 새로고침
                alert(data.message);
                window.location.reload();
            } else {
                // 실패 시 오류 메시지 표시
                alert('<?php echo addslashes(t("common.error")); ?>: ' + data.message);

                // 버튼 복원
                button.disabled = false;
                button.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('<?php echo addslashes(t("purchase.js_network_error")); ?>:', error);
            alert('<?php echo addslashes(t("purchase.js_network_error_occurred")); ?>');

            // 버튼 복원
            button.disabled = false;
            button.innerHTML = originalText;
        });
    }

    // 마진율 입력 필드 이벤트 (실시간 예상판매가 업데이트)
    document.querySelectorAll('.margin-input').forEach(input => {
        input.addEventListener('input', function() {
            const itemId = this.dataset.itemId;
            const marginRate = parseFloat(this.value) || 0;
            
            // 예상판매가 업데이트 함수 호출
            updateExpectedPrice(itemId, marginRate);
        });
    });
    
    // 예상판매가 input 필드 이벤트 (수기 입력 시 차이 계산 및 마진율 역계산 업데이트)
    document.querySelectorAll('.expected-price').forEach(input => {
        input.addEventListener('input', function() {
            const itemId = this.dataset.itemId;
            updatePriceDifference(itemId);
            updateMarginFromSellingPrice(itemId);
        });
    });
    
    // 자동 가격 적용 버튼 이벤트
    document.querySelectorAll('.apply-new-prices-btn').forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.dataset.productId;
            const costPrice = this.dataset.costPrice;
            const sellingPrice = this.dataset.sellingPrice;
            const marginRate = this.dataset.marginRate;
            
            if (confirm('계산된 가격으로 적용하시겠습니까?')) {
                updatePrice(productId, costPrice, sellingPrice, this, marginRate);
            }
        });
    });
    
    
    
    
    
});
</script>

<style>
@media print {
    /* 전체 페이지 설정 */
    * {
        -webkit-print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    
    body { 
        font-size: 9px !important; 
        line-height: 1.2 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
    }
    
    .container { 
        max-width: none !important; 
        margin: 0 !important; 
        padding: 4px !important; 
    }
    
    /* 헤더 영역 */
    .mb-8 {
        margin-bottom: 9px !important;
    }
    
    h1 {
        font-size: 13px !important;
        margin-bottom: 4px !important;
    }
    
    /* 테이블 스타일 */
    table { 
        font-size: 8px !important;
        border-collapse: collapse !important;
        width: 100% !important;
        margin: 0 !important;
    }
    
    th, td {
        padding: 2px 4px !important;
        border: 1px solid #000 !important;
        text-align: left !important;
    }
    
    th {
        background-color: #f5f5f5 !important;
        font-weight: bold !important;
        font-size: 8px !important;
    }
    
    /* 숫자 컬럼 우측 정렬 */
    td:nth-child(3), td:nth-child(4), td:nth-child(6), td:nth-child(7), td:nth-child(8) {
        text-align: right !important;
    }
    
    /* 원가변동 컬럼 중앙 정렬 */
    td:nth-child(5) {
        text-align: center !important;
    }
    
    /* 입력 필드 스타일 */
    input {
        border: none !important;
        background: transparent !important;
        font-size: 8px !important;
        padding: 0 !important;
        margin: 0 !important;
        text-align: right !important;
    }
    
    /* 배지 스타일 */
    .bg-red-100, .bg-green-100, .bg-gray-100 {
        background-color: #f0f0f0 !important;
        color: #000 !important;
        padding: 1px 3px !important;
        font-size: 7px !important;
    }
    
    /* 상품 정보 글씨 크기 조정 */
    .text-sm {
        font-size: 8px !important;
    }
    
    .text-xs {
        font-size: 7px !important;
    }
    
    /* 아이콘 제거 */
    .fas {
        display: none !important;
    }
    
    /* 숨김 요소 */
    .print\\:hidden, 
    button, 
    .hover\\:bg-gray-50,
    #bulkActionPanel,
    .border-l-4 {
        display: none !important;
    }
    
    /* 그림자 제거 */
    .shadow, .shadow-lg, .shadow-md {
        box-shadow: none !important;
    }
    
    /* 여백 조정 */
    .p-6, .p-4, .p-3 {
        padding: 2px !important;
    }
    
    /* 페이지 나누기 방지 */
    tr {
        page-break-inside: avoid !important;
    }
    
    /* 테이블 헤더 반복 */
    thead {
        display: table-header-group !important;
    }
}

</style>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>