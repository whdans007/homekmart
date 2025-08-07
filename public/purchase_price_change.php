<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$page_title = "매입건별 가격변동 분석 - HOME K MART";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/margin_helper.php';

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => '매입관리에 접근할 권한이 없습니다.'
    ];
    header('Location: shop.php');
    exit;
}

$purchase_id = $_GET['purchase_id'] ?? '';

if (empty($purchase_id)) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => '매입 ID가 지정되지 않았습니다.'
    ];
    header('Location: purchase_management.php');
    exit;
}

$conn = get_db_connection();

// 매입 정보 조회
$purchase_sql = "SELECT p.*, s.name as supplier_name 
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
        'message' => '해당 매입건을 찾을 수 없습니다.'
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
    pr.cost_price as product_cost_price,
    pr.selling_price as product_selling_price,
    pr.pieces_per_box,
    c.name as category_name,
    COALESCE(inv.cost_price, pr.cost_price) as current_cost_price,
    COALESCE(inv.selling_price, pr.selling_price) as current_selling_price,
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
ORDER BY pr.name_ko";

$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("is", $current_store_id, $purchase_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">매입건별 가격변동 분석</h1>
            <p class="text-sm text-gray-600 mt-1">매입번호: <?php echo htmlspecialchars($purchase_id); ?></p>
            <p class="text-sm text-gray-600">거래처: <?php echo htmlspecialchars($purchase_info['supplier_name']); ?></p>
            <p class="text-sm text-gray-600">매입일: <?php echo htmlspecialchars($purchase_info['purchase_date']); ?></p>
            <p class="text-sm text-gray-600">점포: <?php echo htmlspecialchars($current_store_name); ?></p>
        </div>
        <div class="flex space-x-3">
            <button id="bulkMarginBtn" class="inline-flex items-center justify-center rounded-md border border-transparent bg-purple-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 print:hidden" disabled>
                <i class="fas fa-percentage mr-2"></i> 선택상품 마진율 적용 (<span id="selectedCount">0</span>개)
            </button>
            <button id="bulkApplyBtn" class="inline-flex items-center justify-center rounded-md border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 print:hidden">
                <i class="fas fa-arrow-up mr-2"></i> 인상상품 일괄적용
            </button>
            <button onclick="window.print()" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                <i class="fas fa-print mr-2"></i> 프린트
            </button>
            <a href="purchase_management.php" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                <i class="fas fa-arrow-left mr-2"></i> 매입관리로 돌아가기
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
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">상품정보</th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">기존원가</th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">매입원가<br><span class="text-xs normal-case">(낱개단위)</span></th>
                        <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">원가변동</th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">기존마진율</th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">기존판매가</th>
                        <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">예상판매가<br><span class="text-xs normal-case">(매입원가기준)</span></th>
                        <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 print:hidden">가격적용</th>
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
                            
                            // 원가 변동이 없으면 (1원 미만 차이) 건너뛰기
                            if (abs($price_change) < 1) {
                                continue;
                            }
                            
                            $has_price_changes = true;
                            
                            // 기존 마진율 계산
                            $current_margin_rate = 0;
                            if ($item['current_cost_price'] > 0) {
                                $current_margin_rate = (($item['current_selling_price'] - $item['current_cost_price']) / $item['current_cost_price']) * 100;
                            }
                            
                            // 새 원가 기준 예상 판매가 계산 (기존 마진율 적용)
                            $new_selling_price = 0;
                            if ($current_margin_rate > 0) {
                                $new_selling_price = $item['purchase_unit_price_per_piece'] * (1 + ($current_margin_rate / 100));
                            } else {
                                // 마진율이 없으면 카테고리 기본 마진율 적용
                                $margin_rate = get_margin_rate_by_product($item['product_id']);
                                $new_selling_price = $item['purchase_unit_price_per_piece'] * (1 + ($margin_rate / 100));
                            }
                        ?>
                            <tr class="hover:bg-gray-50 cursor-pointer item-row" data-product-id="<?php echo $item['product_id']; ?>">
                                <td class="px-3 py-4 text-center border border-gray-300 print:hidden">
                                    <input type="checkbox" class="item-checkbox h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded" data-product-id="<?php echo $item['product_id']; ?>">
                                </td>
                                <td class="px-3 py-4 border border-gray-300">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['product_name_ko']); ?></div>
                                    <?php if ($item['product_name_en']): ?>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($item['product_name_en']); ?></div>
                                    <?php endif; ?>
                                    <div class="text-xs text-gray-500 font-mono">SKU: <?php echo htmlspecialchars($item['sku']); ?></div>
                                </td>
                                <td class="px-3 py-4 text-right border border-gray-300">
                                    <span class="text-sm font-mono"><?php echo number_format($item['current_cost_price']); ?>원</span>
                                </td>
                                <td class="px-3 py-4 text-right border border-gray-300">
                                    <span class="text-sm font-mono font-semibold text-blue-600"><?php echo number_format($item['purchase_unit_price_per_piece']); ?>원</span>
                                    <?php if ($item['purchase_type'] === 'box' && $item['pieces_per_box']): ?>
                                        <div class="text-xs text-gray-500">
                                            박스: <?php echo number_format($item['unit_price']); ?>원
                                            (<?php echo $item['pieces_per_box']; ?>개입)
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4 text-center border border-gray-300">
                                    <?php if (abs($price_change) < 1): ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                            동일
                                        </span>
                                    <?php elseif ($price_change > 0): ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                            <i class="fas fa-arrow-up mr-1"></i>
                                            인상 <?php echo number_format(abs($price_change)); ?>원
                                        </span>
                                        <div class="text-xs text-red-600 mt-1">
                                            (+<?php echo number_format($price_change_percent, 1); ?>%)
                                        </div>
                                    <?php else: ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            <i class="fas fa-arrow-down mr-1"></i>
                                            인하 <?php echo number_format(abs($price_change)); ?>원
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
                                               data-cost-price="<?php echo $item['purchase_unit_price_per_piece']; ?>"
                                               data-original-margin="<?php echo number_format($current_margin_rate, 1); ?>">
                                        <span class="text-sm text-gray-500">%</span>
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-right border border-gray-300">
                                    <span class="text-sm font-mono"><?php echo number_format($item['current_selling_price']); ?>원</span>
                                </td>
                                <td class="px-3 py-4 text-right border border-gray-300">
                                    <span class="expected-price text-sm font-mono font-semibold text-green-600" 
                                          data-product-id="<?php echo $item['product_id']; ?>"
                                          data-original-price="<?php echo $new_selling_price; ?>">
                                        <?php echo number_format($new_selling_price); ?>원
                                    </span>
                                    <div class="price-difference text-xs text-gray-500" 
                                         data-product-id="<?php echo $item['product_id']; ?>"
                                         data-current-price="<?php echo $item['current_selling_price']; ?>">
                                        차이: <?php echo number_format($new_selling_price - $item['current_selling_price']); ?>원
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-center border border-gray-300 print:hidden">
                                    <div class="space-y-2">
                                        <button type="button" 
                                                class="apply-new-prices-btn w-full inline-flex items-center justify-center px-2 py-1 border border-transparent text-xs font-medium rounded text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                                                data-product-id="<?php echo $item['product_id']; ?>"
                                                data-cost-price="<?php echo $item['purchase_unit_price_per_piece']; ?>"
                                                data-selling-price="<?php echo $new_selling_price; ?>"
                                                data-price-change="<?php echo $price_change > 0 ? 'increase' : 'decrease'; ?>"
                                                data-current-selling-price="<?php echo $item['current_selling_price']; ?>">
                                            <i class="fas fa-check mr-1"></i>
                                            가격적용
                                        </button>
                                        <button type="button" 
                                                class="manual-edit-btn w-full inline-flex items-center justify-center px-2 py-1 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                                                data-product-id="<?php echo $item['product_id']; ?>">
                                            <i class="fas fa-edit mr-1"></i>
                                            수동설정
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    
                    <?php if (!$has_price_changes): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-300">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-equals text-4xl text-gray-400"></i>
                                    <p class="mt-4">원가 변동이 있는 상품이 없습니다.</p>
                                    <p class="text-xs text-gray-400">모든 매입 상품의 원가가 현재 원가와 동일합니다.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- 선택상품 일괄 마진율 적용 패널 -->
    <div id="bulkActionPanel" class="mt-6 bg-white border-2 border-purple-200 rounded-lg shadow-lg p-6 hidden print:hidden">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center">
                <i class="fas fa-percentage text-purple-600 text-lg mr-3"></i>
                <div>
                    <h3 class="text-lg font-medium text-gray-900">선택상품 마진율 일괄 적용</h3>
                    <p class="text-sm text-gray-600">선택된 <span id="panelSelectedCount" class="font-semibold text-purple-600">0</span>개 상품에 동일한 마진율을 적용합니다</p>
                </div>
            </div>
            <button id="closeBulkPanel" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        
        <div class="flex items-center space-x-4">
            <div class="flex-1">
                <label for="bulkMarginInput" class="block text-sm font-medium text-gray-700 mb-2">
                    마진율 (%)
                </label>
                <div class="relative">
                    <input type="number" 
                           id="bulkMarginInput" 
                           class="w-full px-4 py-3 text-lg border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500" 
                           placeholder="예: 30" 
                           step="0.1" 
                           min="0">
                    <span class="absolute right-3 top-3 text-lg text-gray-500">%</span>
                </div>
                <p class="text-xs text-gray-500 mt-1">예: 30% 마진율을 원하면 30을 입력하세요</p>
            </div>
            
            <div class="flex flex-col space-y-2">
                <button id="applyBulkMargin" 
                        class="px-6 py-3 bg-purple-600 text-white font-medium rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                        disabled>
                    <i class="fas fa-check mr-2"></i>
                    마진율 적용
                </button>
                <button id="clearSelection" 
                        class="px-6 py-2 bg-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500">
                    <i class="fas fa-times mr-1"></i>
                    선택 해제
                </button>
            </div>
        </div>
        
        <!-- 선택된 상품 미리보기 -->
        <div class="mt-4 pt-4 border-t border-gray-200">
            <h4 class="text-sm font-medium text-gray-700 mb-2">선택된 상품</h4>
            <div id="selectedItemsPreview" class="max-h-32 overflow-y-auto">
                <p class="text-sm text-gray-500">상품을 선택하면 여기에 표시됩니다.</p>
            </div>
        </div>
    </div>
</div>

<!-- 선택상품 마진율 설정 모달 -->
<div id="bulkMarginModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden print:hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">선택상품 마진율 일괄 적용</h3>
            <div class="mb-4">
                <p class="text-sm text-gray-600">선택된 <span id="modalSelectedCount">0</span>개 상품에 동일한 마진율을 적용합니다.</p>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">마진율 (%)</label>
                    <input type="number" id="modalMarginRate" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500" placeholder="마진율 입력 (예: 30)" step="0.1" min="0">
                    <p class="text-xs text-gray-500 mt-1">예: 30% 마진율을 원하면 30을 입력하세요</p>
                </div>
            </div>
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" id="cancelBulkMarginModal" class="px-4 py-2 bg-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500">
                    취소
                </button>
                <button type="button" id="confirmBulkMarginModal" class="px-4 py-2 bg-purple-600 text-white text-sm font-medium rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500">
                    적용
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 수동 가격 설정 모달 -->
<div id="manualPriceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden print:hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">수동 가격 설정</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">새 원가</label>
                    <input type="number" id="modalCostPrice" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500" placeholder="원가 입력">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">새 판매가</label>
                    <input type="number" id="modalSellingPrice" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500" placeholder="판매가 입력">
                </div>
            </div>
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" id="cancelModal" class="px-4 py-2 bg-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500">
                    취소
                </button>
                <button type="button" id="confirmModal" class="px-4 py-2 bg-primary-600 text-white text-sm font-medium rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    적용
                </button>
            </div>
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
    
    let currentProductId = null;
    
    // 선택 관련 변수
    let selectedItems = new Set();
    
    // 전체 선택/해제 기능
    document.getElementById('selectAll').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.item-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
            updateItemSelection(checkbox.dataset.productId, this.checked);
        });
        updateSelectedUI();
    });
    
    // 개별 체크박스 이벤트
    document.querySelectorAll('.item-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateItemSelection(this.dataset.productId, this.checked);
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
            updateItemSelection(checkbox.dataset.productId, checkbox.checked);
            updateSelectedUI();
            updateSelectAllState();
        });
    });
    
    // 선택 상태 업데이트
    function updateItemSelection(productId, isSelected) {
        if (isSelected) {
            selectedItems.add(productId);
        } else {
            selectedItems.delete(productId);
        }
        
        // 행의 시각적 표시 업데이트
        const row = document.querySelector(`.item-row[data-product-id="${productId}"]`);
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
        const bulkMarginBtn = document.getElementById('bulkMarginBtn');
        const selectedCountSpan = document.getElementById('selectedCount');
        const bulkActionPanel = document.getElementById('bulkActionPanel');
        const panelSelectedCount = document.getElementById('panelSelectedCount');
        const applyBulkMarginBtn = document.getElementById('applyBulkMargin');
        
        // 헤더 버튼 업데이트
        selectedCountSpan.textContent = selectedCount;
        
        if (selectedCount > 0) {
            bulkMarginBtn.disabled = false;
            bulkMarginBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            
            // 하단 패널 표시
            bulkActionPanel.classList.remove('hidden');
            panelSelectedCount.textContent = selectedCount;
            
            updateSelectedItemsPreview();
        } else {
            bulkMarginBtn.disabled = true;
            bulkMarginBtn.classList.add('opacity-50', 'cursor-not-allowed');
            
            // 하단 패널 숨기기
            bulkActionPanel.classList.add('hidden');
        }
        
        // 마진율 입력값에 따라 적용 버튼 활성화
        updateApplyButtonState();
    }
    
    // 선택된 상품 미리보기 업데이트
    function updateSelectedItemsPreview() {
        const preview = document.getElementById('selectedItemsPreview');
        
        if (selectedItems.size === 0) {
            preview.innerHTML = '<p class="text-sm text-gray-500">상품을 선택하면 여기에 표시됩니다.</p>';
            return;
        }
        
        let html = '<div class="space-y-1">';
        selectedItems.forEach(productId => {
            const row = document.querySelector(`.item-row[data-product-id="${productId}"]`);
            if (row) {
                const productName = row.querySelector('.text-sm.font-medium').textContent;
                const sku = row.querySelector('.text-xs.text-gray-500.font-mono').textContent;
                html += `<div class="flex justify-between items-center text-sm py-1">
                    <span class="text-gray-900">${productName}</span>
                    <span class="text-gray-500 font-mono">${sku}</span>
                </div>`;
            }
        });
        html += '</div>';
        
        preview.innerHTML = html;
    }
    
    // 적용 버튼 상태 업데이트
    function updateApplyButtonState() {
        const bulkMarginInput = document.getElementById('bulkMarginInput');
        const applyBulkMarginBtn = document.getElementById('applyBulkMargin');
        const marginValue = parseFloat(bulkMarginInput.value);
        
        if (selectedItems.size > 0 && !isNaN(marginValue) && marginValue >= 0) {
            applyBulkMarginBtn.disabled = false;
        } else {
            applyBulkMarginBtn.disabled = true;
        }
    }
    
    // 선택상품 마진율 버튼 이벤트
    document.getElementById('bulkMarginBtn').addEventListener('click', function() {
        if (selectedItems.size === 0) {
            alert('상품을 먼저 선택해주세요.');
            return;
        }
        
        document.getElementById('modalSelectedCount').textContent = selectedItems.size;
        document.getElementById('modalMarginRate').value = '';
        document.getElementById('bulkMarginModal').classList.remove('hidden');
    });
    
    // 마진율 모달 취소
    document.getElementById('cancelBulkMarginModal').addEventListener('click', function() {
        document.getElementById('bulkMarginModal').classList.add('hidden');
    });
    
    // 마진율 모달 확인
    document.getElementById('confirmBulkMarginModal').addEventListener('click', function() {
        const marginRate = parseFloat(document.getElementById('modalMarginRate').value);
        
        if (isNaN(marginRate) || marginRate < 0) {
            alert('올바른 마진율을 입력해주세요.');
            return;
        }
        
        if (confirm(`선택된 ${selectedItems.size}개 상품에 ${marginRate}% 마진율을 적용하시겠습니까?`)) {
            applyBulkMarginRate(marginRate);
        }
    });
    
    // 하단 패널 이벤트 핸들러들
    
    // 마진율 입력 필드 변경 시 적용 버튼 상태 업데이트
    document.getElementById('bulkMarginInput').addEventListener('input', function() {
        updateApplyButtonState();
    });
    
    // 하단 패널 마진율 적용 버튼
    document.getElementById('applyBulkMargin').addEventListener('click', function() {
        const marginRate = parseFloat(document.getElementById('bulkMarginInput').value);
        
        if (isNaN(marginRate) || marginRate < 0) {
            alert('올바른 마진율을 입력해주세요.');
            return;
        }
        
        if (selectedItems.size === 0) {
            alert('상품을 먼저 선택해주세요.');
            return;
        }
        
        if (confirm(`선택된 ${selectedItems.size}개 상품에 ${marginRate}% 마진율을 적용하시겠습니까?`)) {
            applyBulkMarginRateFromPanel(marginRate);
        }
    });
    
    // 선택 해제 버튼
    document.getElementById('clearSelection').addEventListener('click', function() {
        // 모든 체크박스 해제
        document.querySelectorAll('.item-checkbox').forEach(checkbox => {
            checkbox.checked = false;
            updateItemSelection(checkbox.dataset.productId, false);
        });
        
        // 전체 선택 체크박스도 해제
        document.getElementById('selectAll').checked = false;
        document.getElementById('selectAll').indeterminate = false;
        
        // 선택 상태 초기화
        selectedItems.clear();
        updateSelectedUI();
    });
    
    // 하단 패널 닫기 버튼
    document.getElementById('closeBulkPanel').addEventListener('click', function() {
        document.getElementById('bulkActionPanel').classList.add('hidden');
    });
    
    // 하단 패널에서 마진율 적용 함수
    function applyBulkMarginRateFromPanel(marginRate) {
        selectedItems.forEach(productId => {
            const marginInput = document.querySelector(`.margin-input[data-product-id="${productId}"]`);
            if (marginInput) {
                marginInput.value = marginRate.toFixed(1);
                
                // 직접 예상판매가 업데이트 로직 호출
                updateExpectedPrice(productId, marginRate);
            }
        });
        
        alert(`${selectedItems.size}개 상품의 마진율이 ${marginRate}%로 설정되었습니다.`);
        
        // 입력 필드 초기화
        document.getElementById('bulkMarginInput').value = '';
        updateApplyButtonState();
    }
    
    // 예상판매가 업데이트 함수
    function updateExpectedPrice(productId, marginRate) {
        const marginInput = document.querySelector(`.margin-input[data-product-id="${productId}"]`);
        if (!marginInput) return;
        
        const costPrice = parseFloat(marginInput.dataset.costPrice);
        
        // 새로운 판매가 계산
        const newSellingPrice = Math.round(costPrice * (1 + (marginRate / 100)));
        
        // 예상판매가 업데이트
        const expectedPriceSpan = document.querySelector(`.expected-price[data-product-id="${productId}"]`);
        const priceDifferenceDiv = document.querySelector(`.price-difference[data-product-id="${productId}"]`);
        const applyButton = document.querySelector(`.apply-new-prices-btn[data-product-id="${productId}"]`);
        
        if (expectedPriceSpan && priceDifferenceDiv && applyButton) {
            const currentPrice = parseFloat(priceDifferenceDiv.dataset.currentPrice);
            const priceDifference = newSellingPrice - currentPrice;
            
            expectedPriceSpan.textContent = newSellingPrice.toLocaleString() + '원';
            priceDifferenceDiv.textContent = '차이: ' + priceDifference.toLocaleString() + '원';
            
            // 버튼 데이터 업데이트
            applyButton.dataset.sellingPrice = newSellingPrice;
            applyButton.dataset.marginRate = marginRate;
            
            // 마진율이 변경되었는지 표시
            const originalMargin = parseFloat(marginInput.dataset.originalMargin);
            if (Math.abs(marginRate - originalMargin) > 0.1) {
                marginInput.classList.add('border-yellow-500', 'bg-yellow-50');
                applyButton.classList.remove('bg-primary-600', 'hover:bg-primary-700');
                applyButton.classList.add('bg-yellow-600', 'hover:bg-yellow-700');
                applyButton.innerHTML = '<i class="fas fa-percentage mr-1"></i>마진율적용';
            } else {
                marginInput.classList.remove('border-yellow-500', 'bg-yellow-50');
                applyButton.classList.remove('bg-yellow-600', 'hover:bg-yellow-700');
                applyButton.classList.add('bg-primary-600', 'hover:bg-primary-700');
                applyButton.innerHTML = '<i class="fas fa-check mr-1"></i>가격적용';
            }
        }
    }
    
    // 일괄 마진율 적용 함수 (모달용 - 기존 유지)
    function applyBulkMarginRate(marginRate) {
        selectedItems.forEach(productId => {
            const marginInput = document.querySelector(`.margin-input[data-product-id="${productId}"]`);
            if (marginInput) {
                marginInput.value = marginRate.toFixed(1);
                
                // 직접 예상판매가 업데이트 로직 호출
                updateExpectedPrice(productId, marginRate);
            }
        });
        
        document.getElementById('bulkMarginModal').classList.add('hidden');
        alert(`${selectedItems.size}개 상품의 마진율이 ${marginRate}%로 설정되었습니다.`);
    }
    
    // 초기 UI 상태 설정
    updateSelectedUI();
    
    // 기존 일괄 적용 버튼 이벤트 (기존 기능 유지)
    document.getElementById('bulkApplyBtn').addEventListener('click', function() {
        // 인상된 상품 개수 확인
        const increasedItems = document.querySelectorAll('.apply-new-prices-btn[data-price-change="increase"]');
        const increasedCount = increasedItems.length;
        
        if (increasedCount === 0) {
            alert('인상된 상품이 없습니다.');
            return;
        }
        
        if (confirm(`${increasedCount}개의 인상된 상품의 가격을 일괄 적용하시겠습니까?`)) {
            bulkApplyPriceIncrease();
        }
    });
    
    // 마진율 입력 필드 이벤트 (실시간 예상판매가 업데이트)
    document.querySelectorAll('.margin-input').forEach(input => {
        input.addEventListener('input', function() {
            const productId = this.dataset.productId;
            const costPrice = parseFloat(this.dataset.costPrice);
            const marginRate = parseFloat(this.value) || 0;
            
            // 새로운 판매가 계산
            const newSellingPrice = Math.round(costPrice * (1 + (marginRate / 100)));
            
            // 예상판매가 업데이트
            const expectedPriceSpan = document.querySelector(`.expected-price[data-product-id="${productId}"]`);
            const priceDifferenceDiv = document.querySelector(`.price-difference[data-product-id="${productId}"]`);
            const applyButton = document.querySelector(`.apply-new-prices-btn[data-product-id="${productId}"]`);
            
            if (expectedPriceSpan && priceDifferenceDiv && applyButton) {
                const currentPrice = parseFloat(priceDifferenceDiv.dataset.currentPrice);
                const priceDifference = newSellingPrice - currentPrice;
                
                expectedPriceSpan.textContent = newSellingPrice.toLocaleString() + '원';
                priceDifferenceDiv.textContent = '차이: ' + priceDifference.toLocaleString() + '원';
                
                // 버튼 데이터 업데이트
                applyButton.dataset.sellingPrice = newSellingPrice;
                applyButton.dataset.marginRate = marginRate;
                
                // 마진율이 변경되었는지 표시
                const originalMargin = parseFloat(this.dataset.originalMargin);
                if (Math.abs(marginRate - originalMargin) > 0.1) {
                    this.classList.add('border-yellow-500', 'bg-yellow-50');
                    applyButton.classList.remove('bg-primary-600', 'hover:bg-primary-700');
                    applyButton.classList.add('bg-yellow-600', 'hover:bg-yellow-700');
                    applyButton.innerHTML = '<i class="fas fa-percentage mr-1"></i>마진율적용';
                } else {
                    this.classList.remove('border-yellow-500', 'bg-yellow-50');
                    applyButton.classList.remove('bg-yellow-600', 'hover:bg-yellow-700');
                    applyButton.classList.add('bg-primary-600', 'hover:bg-primary-700');
                    applyButton.innerHTML = '<i class="fas fa-check mr-1"></i>가격적용';
                }
            }
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
    
    // 수동 설정 버튼 이벤트
    document.querySelectorAll('.manual-edit-btn').forEach(button => {
        button.addEventListener('click', function() {
            currentProductId = this.dataset.productId;
            document.getElementById('modalCostPrice').value = '';
            document.getElementById('modalSellingPrice').value = '';
            document.getElementById('manualPriceModal').classList.remove('hidden');
        });
    });
    
    
    // 모달 취소 버튼
    document.getElementById('cancelModal').addEventListener('click', function() {
        document.getElementById('manualPriceModal').classList.add('hidden');
        currentProductId = null;
    });
    
    // 모달 확인 버튼
    document.getElementById('confirmModal').addEventListener('click', function() {
        const costPrice = document.getElementById('modalCostPrice').value;
        const sellingPrice = document.getElementById('modalSellingPrice').value;
        
        if (!costPrice && !sellingPrice) {
            alert('변경할 가격을 입력해주세요.');
            return;
        }
        
        if (costPrice && (isNaN(costPrice) || costPrice < 0)) {
            alert('올바른 원가를 입력해주세요.');
            return;
        }
        
        if (sellingPrice && (isNaN(sellingPrice) || sellingPrice <= 0)) {
            alert('올바른 판매가를 입력해주세요.');
            return;
        }
        
        updatePrice(currentProductId, costPrice, sellingPrice, this);
        document.getElementById('manualPriceModal').classList.add('hidden');
    });
    
    
    // 일괄 적용 함수
    function bulkApplyPriceIncrease() {
        const bulkBtn = document.getElementById('bulkApplyBtn');
        
        // 버튼 비활성화
        bulkBtn.disabled = true;
        const originalText = bulkBtn.innerHTML;
        bulkBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>일괄 적용 중...';
        
        // AJAX 요청
        const formData = new FormData();
        formData.append('purchase_id', '<?php echo htmlspecialchars($purchase_id); ?>');
        // product_management.php와 동일한 방식으로 currentStoreId 변수 사용
        if (currentStoreId) {
            formData.append('store_id', currentStoreId);
        }
        
        fetch('ajax_bulk_apply_price_increase.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('서버 응답:', text);
                throw new Error('서버에서 올바르지 않은 응답을 받았습니다.');
            }
        })
        .then(data => {
            if (data.success) {
                alert(data.message);
                // 페이지 새로고침하여 업데이트된 정보 표시
                window.location.reload();
            } else {
                alert('오류: ' + data.message);
            }
        })
        .catch(error => {
            console.error('일괄 적용 오류:', error);
            console.error('오류 메시지:', error.message);
            alert('통신 오류가 발생했습니다: ' + error.message);
        })
        .finally(() => {
            // 버튼 복원
            bulkBtn.disabled = false;
            bulkBtn.innerHTML = originalText;
        });
    }
    
    // 가격 업데이트 함수
    function updatePrice(productId, costPrice, sellingPrice, buttonElement, marginRate) {
        // 버튼 비활성화
        buttonElement.disabled = true;
        const originalText = buttonElement.innerHTML;
        buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>처리중...';
        
        // AJAX 요청
        const formData = new FormData();
        formData.append('product_id', productId);
        formData.append('purchase_id', '<?php echo htmlspecialchars($purchase_id); ?>');
        if (costPrice) formData.append('cost_price', costPrice);
        if (sellingPrice) formData.append('selling_price', sellingPrice);
        if (marginRate) formData.append('margin_rate', marginRate);
        // product_management.php와 동일한 방식으로 currentStoreId 변수 사용
        if (currentStoreId) {
            formData.append('store_id', currentStoreId);
        }
        
        fetch('ajax_update_selling_price.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                // 페이지 새로고침하여 업데이트된 정보 표시
                window.location.reload();
            } else {
                alert('오류: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('통신 오류가 발생했습니다.');
        })
        .finally(() => {
            // 버튼 복원
            buttonElement.disabled = false;
            buttonElement.innerHTML = originalText;
        });
    }
    
    
    // 모달 외부 클릭시 닫기
    document.getElementById('manualPriceModal').addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
            currentProductId = null;
        }
    });
    
    // 마진율 모달 외부 클릭시 닫기
    document.getElementById('bulkMarginModal').addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
        }
    });
    
    
    // 페이지 로드시 일괄 적용 버튼 상태 업데이트
    function updateBulkApplyButton() {
        const increasedItems = document.querySelectorAll('.apply-new-prices-btn[data-price-change="increase"]');
        const bulkBtn = document.getElementById('bulkApplyBtn');
        
        if (increasedItems.length > 0) {
            bulkBtn.innerHTML = `<i class="fas fa-arrow-up mr-2"></i>인상상품 일괄적용 (${increasedItems.length}개)`;
            bulkBtn.disabled = false;
        } else {
            bulkBtn.innerHTML = '<i class="fas fa-arrow-up mr-2"></i>인상상품 없음';
            bulkBtn.disabled = true;
            bulkBtn.classList.add('opacity-50', 'cursor-not-allowed');
        }
    }
    
    // 초기 버튼 상태 설정
    updateBulkApplyButton();
});
</script>

<style>
@media print {
    body { font-size: 12px; }
    .container { max-width: none; margin: 0; padding: 10px; }
    table { font-size: 11px; }
    .print\\:hidden { display: none !important; }
}
</style>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>