<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('wholesale.add_product') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$errors = [];
$success_message = '';
$stores = [];

// DB 연결
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $errors[] = t('add_wholesale_product.database_connection_error') . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력값 검증 (새로운 필드들 추가)
    $product_id = (int)($_POST['product_id'] ?? 0);
    $wholesale_name_ko = trim($_POST['wholesale_name_ko'] ?? '');
    $wholesale_name_en = trim($_POST['wholesale_name_en'] ?? '');
    $wholesale_skus_input = trim($_POST['wholesale_skus'] ?? '');
    $wholesale_description = trim($_POST['wholesale_description'] ?? '');
    $memo = trim($_POST['memo'] ?? '');
    $wholesale_price = trim($_POST['wholesale_price'] ?? '');
    $cost_price = trim($_POST['cost_price'] ?? '');
    $cost_price_piece = trim($_POST['cost_price_piece'] ?? '0');
    $wholesale_price_piece = trim($_POST['wholesale_price_piece'] ?? '0');
    $margin_rate = trim($_POST['margin_rate'] ?? '15');
    $sale_unit = (($_POST['sale_unit'] ?? 'box') === 'piece') ? 'piece' : 'box';
    $min_quantity = 1; // 최소 주문량 미사용 (DB 기본값 유지)
    $store_id = $current_store_id; // 점포는 접속자 소속 점포로 고정

    // 숫자 정규화
    if (!is_numeric($cost_price_piece) || $cost_price_piece < 0) $cost_price_piece = 0;
    if (!is_numeric($wholesale_price_piece) || $wholesale_price_piece < 0) $wholesale_price_piece = 0;
    if (!is_numeric($margin_rate)) $margin_rate = 15;
    
    if (empty($product_id)) {
        $errors[] = t('add_wholesale_product.product_required');
    }
    
    // 도매 상품명 검증 (한국어 또는 영어 중 최소 하나는 필수)
    if (empty($wholesale_name_ko) && empty($wholesale_name_en)) {
        $errors[] = t('add_wholesale_product.product_name_required');
    }
    
    // SKU 검증 및 JSON 변환
    $wholesale_skus_json = null;
    if (!empty($wholesale_skus_input)) {
        $skus_array = array_map('trim', explode(',', $wholesale_skus_input));
        $skus_array = array_filter($skus_array); // 빈 값 제거
        if (!empty($skus_array)) {
            $wholesale_skus_json = json_encode($skus_array);
        }
    }
    
    if (empty($wholesale_price) || !is_numeric($wholesale_price) || $wholesale_price <= 0) {
        $errors[] = t('add_wholesale_product.price_required');
    }
    
    // 원가는 반드시 입력해야 하며 0보다 커야 함
    if ($cost_price === '' || !is_numeric($cost_price) || $cost_price <= 0) {
        $errors[] = t('add_wholesale_product.cost_price_required');
    }


    if (empty($errors)) {
        try {
            // 존재하는 컬럼 동적 감지 (스키마 버전별 호환)
            $existing = [];
            try {
                foreach ($pdo->query("SHOW COLUMNS FROM wholesale_products")->fetchAll(PDO::FETCH_COLUMN) as $col) {
                    $existing[$col] = true;
                }
            } catch (PDOException $e) {
                // 감지 실패 시 기본 컬럼만 사용
            }

            // 필수 컬럼
            $cols = ['product_id', 'store_id', 'wholesale_price', 'min_quantity'];
            $vals = [$product_id, $store_id, $wholesale_price, $min_quantity];

            // 존재할 경우에만 포함하는 선택 컬럼
            $optional = [
                'wholesale_name_ko'     => ($wholesale_name_ko ?: null),
                'wholesale_name_en'     => ($wholesale_name_en ?: null),
                'wholesale_skus'        => $wholesale_skus_json,
                'wholesale_description' => ($wholesale_description ?: null),
                'memo'                  => ($memo ?: null),
                'cost_price'            => $cost_price,
                'cost_price_piece'      => $cost_price_piece,
                'margin_rate'           => $margin_rate,
                'wholesale_price_piece' => $wholesale_price_piece,
                'sale_unit'             => $sale_unit,
            ];
            foreach ($optional as $col => $val) {
                if (!empty($existing[$col])) {
                    $cols[] = $col;
                    $vals[] = $val;
                }
            }

            // ON DUPLICATE KEY UPDATE 대상 (유니크 키 컬럼 제외)
            $update_parts = [];
            foreach ($cols as $c) {
                if ($c === 'product_id' || $c === 'store_id') continue;
                $update_parts[] = "$c = VALUES($c)";
            }

            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO wholesale_products (" . implode(', ', $cols) . ", is_active, created_at)
                    VALUES ($placeholders, 1, NOW())
                    ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts) . ", is_active = 1, updated_at = NOW()";

            $stmt = $pdo->prepare($sql);
            $insert_success = $stmt->execute($vals);

            if ($insert_success) {
                header('Location: wholesale_product_management.php');
                exit;
            } else {
                $errors[] = t('add_wholesale_product.save_error');
            }
        } catch (PDOException $e) {
            $errors[] = t('add_wholesale_product.database_error') . $e->getMessage();
        }
    }

}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// 스키마 호환성 확인 (도매명/SKU 컬럼 존재 여부) - 폼 렌더링 전에 미리 계산
$has_new_columns_ui = false;
if ($pdo) {
    try {
        $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
        $has_new_columns_ui = $check_columns->rowCount() > 0;
    } catch (PDOException $e) {
        $has_new_columns_ui = false;
    }
}

?>

<div class="w-full px-2 sm:px-3 md:px-4 pt-0 pb-4">
    <div class="w-full">
        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-4 py-3 border-b border-gray-200">
                <h1 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-plus-circle mr-2 text-primary-500"></i>
                    <?php echo t('wholesale.add_product'); ?>
                </h1>
            </div>

            <div class="px-4 py-3">
                <?php if (!empty($errors)): ?>
                    <div class="mb-3 p-3 bg-red-50 border border-red-200 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800"><?php echo htmlspecialchars(t('add_wholesale_product.solve_errors')); ?></h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <!-- 기준 상품 선택 -->
                    <div>
                        <label for="product_search" class="block text-sm font-medium text-gray-700 mb-1">
                            <?php echo t('add_wholesale_product.product_selection'); ?> <span class="text-red-500"><?php echo t('add_wholesale_product.required_field'); ?></span>
                        </label>
                        <div class="relative">
                            <input type="text" id="product_search" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="<?php echo htmlspecialchars(t('add_wholesale_product.search_placeholder')); ?>"
                                   autocomplete="off">
                            <div id="product_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                                <!-- 검색 결과가 여기에 표시됩니다 -->
                            </div>
                        </div>
                        
                        <!-- 선택된 상품: 좌 상품정보 / 우 구매이력 -->
                        <div id="selected_product" class="mt-3 hidden">
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                <!-- 좌: 상품 정보 (도매명 한글/영문 수정 가능 + 하단 나머지 정보) -->
                                <div class="lg:col-span-1 p-3 bg-gray-50 border border-gray-200 rounded-md">
                                    <div class="flex items-start justify-between mb-2">
                                        <h4 class="text-sm font-semibold text-gray-700"><?php echo htmlspecialchars(t('add_wholesale_product.product_selection')); ?></h4>
                                        <button type="button" id="clear_selection" class="text-red-500 hover:text-red-700">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>

                                    <?php if ($has_new_columns_ui): ?>
                                    <!-- 도매 상품명 (한글) -->
                                    <label for="wholesale_name_ko" class="block text-xs font-medium text-gray-600">
                                        <?php echo t('add_wholesale_product.wholesale_name_ko'); ?> <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" name="wholesale_name_ko" id="wholesale_name_ko"
                                           value="<?php echo htmlspecialchars($_POST['wholesale_name_ko'] ?? ''); ?>"
                                           class="mt-1 mb-2 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                           placeholder="<?php echo htmlspecialchars(t('add_wholesale_product.wholesale_name_ko_placeholder')); ?>">
                                    <!-- 도매 상품명 (영문) -->
                                    <label for="wholesale_name_en" class="block text-xs font-medium text-gray-600">
                                        <?php echo t('add_wholesale_product.wholesale_name_en'); ?>
                                    </label>
                                    <input type="text" name="wholesale_name_en" id="wholesale_name_en"
                                           value="<?php echo htmlspecialchars($_POST['wholesale_name_en'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                           placeholder="<?php echo htmlspecialchars(t('add_wholesale_product.wholesale_name_en_placeholder')); ?>">
                                    <input type="hidden" name="wholesale_skus" id="wholesale_skus" value="<?php echo htmlspecialchars($_POST['wholesale_skus'] ?? ''); ?>">
                                    <?php endif; ?>

                                    <!-- 나머지 정보 + 박스당 개수(수정 가능) -->
                                    <div class="mt-3 pt-3 border-t border-gray-200">
                                        <div class="flex items-center gap-2 mb-2">
                                            <label for="pieces_per_box_display" class="text-xs font-medium text-gray-700 whitespace-nowrap"><?php echo t('add_wholesale_product.pieces_per_box_label'); ?></label>
                                            <input type="number" id="pieces_per_box_display" min="1" step="1" value="1"
                                                   class="w-20 px-2 py-1 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                            <span class="text-xs text-gray-500"><?php echo t('purchase.piece_unit'); ?></span>
                                        </div>
                                        <div class="text-xs text-gray-600 break-words" id="selected_product_info"></div>
                                    </div>
                                </div>

                                <!-- 우: 구매이력 -->
                                <div class="lg:col-span-2 p-3 bg-gray-50 border border-gray-200 rounded-md">
                                    <div class="flex items-center justify-between mb-2">
                                        <h4 class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('add_wholesale_product.recent_purchase_history')); ?></h4>
                                        <div id="purchase_history_loading" class="text-xs text-gray-500 hidden">
                                            <i class="fas fa-spinner fa-spin mr-1"></i>
                                            <?php echo htmlspecialchars(t('add_wholesale_product.loading')); ?>
                                        </div>
                                    </div>
                                    <div id="purchase_history_content">
                                        <!-- 구매이력 테이블이 여기에 동적으로 추가됩니다 -->
                                    </div>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="product_id" id="product_id" value="<?php echo $_POST['product_id'] ?? ''; ?>">
                    </div>

                    <?php if (!$has_new_columns_ui): ?>
                    <!-- 스키마 업데이트 안내 -->
                    <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800"><?php echo htmlspecialchars(t('add_wholesale_product.schema_upgrade_needed')); ?></h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p><?php echo htmlspecialchars(t('add_wholesale_product.schema_upgrade_description')); ?></p>
                                    <p class="mt-1">
                                        <a href="schema_update_helper.php" class="font-medium underline">
                                            <?php echo htmlspecialchars(t('add_wholesale_product.schema_update_link')); ?>
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 현재 점포 (접속자 소속 점포로 고정) -->
                    <div class="flex items-center gap-2 text-sm">
                        <span class="font-medium text-gray-700"><?php echo t('add_wholesale_product.store'); ?>:</span>
                        <span class="px-2 py-1 bg-gray-50 border border-gray-300 rounded text-gray-900 font-medium">
                            <?php echo htmlspecialchars($current_store_name); ?>
                        </span>
                        <input type="hidden" name="store_id" value="<?php echo htmlspecialchars($current_store_id); ?>">
                    </div>

                    <?php $sel_unit = ($_POST['sale_unit'] ?? 'box') === 'piece' ? 'piece' : 'box'; ?>
                    <!-- 가격 정보 한 줄: 판매단위 / 원가(낱개) / 원가(박스) / 마진율 / 도매가(낱개) / 도매가(박스) -->
                    <div style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:0.5rem;align-items:end;">
                        <!-- 판매 단위 (BOX/PCS 토글, Design Ref: logistics/inbound_add.php) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.sale_unit_label'); ?> <span class="text-red-500">*</span>
                            </label>
                            <div id="saleUnitToggle" class="mt-1 flex rounded-md border border-gray-300 overflow-hidden shadow-sm">
                                <button type="button" data-unit="box" onclick="setSaleUnit('box')"
                                        class="sale-unit-btn flex-1 px-2 py-2 font-extrabold whitespace-nowrap transition-colors <?php echo $sel_unit === 'box' ? 'bg-amber-500 text-white' : 'bg-white text-gray-400'; ?>">
                                    <i class="fas fa-box mr-1"></i>BOX
                                </button>
                                <button type="button" data-unit="piece" onclick="setSaleUnit('piece')"
                                        class="sale-unit-btn flex-1 px-2 py-2 font-extrabold whitespace-nowrap transition-colors <?php echo $sel_unit === 'piece' ? 'bg-blue-500 text-white' : 'bg-white text-gray-400'; ?>">
                                    <i class="fas fa-cube mr-1"></i>PCS
                                </button>
                            </div>
                            <input type="hidden" name="sale_unit" id="sale_unit" value="<?php echo $sel_unit; ?>">
                        </div>

                        <!-- 원가(낱개) -->
                        <div>
                            <label for="cost_price_piece" class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.cost_price_piece'); ?>
                            </label>
                            <input type="number" name="cost_price_piece" id="cost_price_piece" step="0.01" min="0"
                                   value="<?php echo htmlspecialchars($_POST['cost_price_piece'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="0">
                        </div>

                        <!-- 원가(박스) -->
                        <div>
                            <label for="cost_price" class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.cost_price_box'); ?> <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="cost_price" id="cost_price" step="0.01" min="0.01" required
                                   value="<?php echo htmlspecialchars($_POST['cost_price'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="0">
                        </div>

                        <!-- 마진율 -->
                        <div>
                            <label for="margin_rate_input" class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.margin_rate_field'); ?> <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="margin_rate" id="margin_rate_input" step="0.1" min="0" max="1000" required
                                   value="<?php echo htmlspecialchars($_POST['margin_rate'] ?? '15'); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="15">
                        </div>

                        <!-- 도매가(낱개) -->
                        <div>
                            <label for="wholesale_price_piece" class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.wholesale_price_piece'); ?>
                            </label>
                            <input type="number" name="wholesale_price_piece" id="wholesale_price_piece" step="0.01" min="0"
                                   value="<?php echo htmlspecialchars($_POST['wholesale_price_piece'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="0">
                        </div>

                        <!-- 도매가(박스) -->
                        <div>
                            <label for="wholesale_price" class="block text-sm font-medium text-gray-700">
                                <?php echo t('add_wholesale_product.wholesale_price_box'); ?> <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="wholesale_price" id="wholesale_price" step="0.01" min="0" required
                                   value="<?php echo htmlspecialchars($_POST['wholesale_price'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="0">
                        </div>
                    </div>

                    <!-- 메모 (특이사항) -->
                    <div>
                        <label for="memo" class="block text-sm font-medium text-gray-700 mb-1">
                            <?php echo t('add_wholesale_product.memo_label'); ?>
                        </label>
                        <textarea name="memo" id="memo" rows="3"
                                  class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                  placeholder="<?php echo htmlspecialchars(t('add_wholesale_product.memo_placeholder')); ?>"><?php echo htmlspecialchars($_POST['memo'] ?? ''); ?></textarea>
                    </div>

                    <div class="flex justify-end space-x-3 pt-2">
                        <a href="wholesale_product_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            <?php echo t('common.cancel'); ?>
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-save mr-2"></i>
                            <?php echo t('common.save'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 번역 텍스트
    const translations = {
        selectPurchasePrice: '<?php echo htmlspecialchars(t('add_wholesale_product.js_select_purchase_price')); ?>',
        invalidMargin: '<?php echo htmlspecialchars(t('add_wholesale_product.js_invalid_margin')); ?>',
        calculatedMargin: '<?php echo htmlspecialchars(t('add_wholesale_product.js_calculated_margin')); ?>',
        expectedWholesalePrice: '<?php echo htmlspecialchars(t('add_wholesale_product.js_expected_wholesale_price')); ?>',
        priceAutoCalculated: '<?php echo htmlspecialchars(t('add_wholesale_product.js_price_auto_calculated')); ?>',
        directInput: '<?php echo htmlspecialchars(t('add_wholesale_product.js_direct_input')); ?>',
        marginApplied: '<?php echo htmlspecialchars(t('add_wholesale_product.js_margin_applied')); ?>',
        useButton: '<?php echo htmlspecialchars(t('add_wholesale_product.js_use_button')); ?>',
        appliedButton: '<?php echo htmlspecialchars(t('add_wholesale_product.js_applied_button')); ?>',
        useButtonTable: '<?php echo htmlspecialchars(t('add_wholesale_product.use_button_table')); ?>',
        purchaseDate: '<?php echo htmlspecialchars(t('add_wholesale_product.purchase_date')); ?>',
        supplier: '<?php echo htmlspecialchars(t('add_wholesale_product.supplier')); ?>',
        unitPriceBox: '<?php echo htmlspecialchars(t('add_wholesale_product.unit_price_box')); ?>',
        unitPricePerPiece: '<?php echo htmlspecialchars(t('add_wholesale_product.unit_price_per_piece')); ?>',
        quantity: '<?php echo htmlspecialchars(t('add_wholesale_product.quantity')); ?>',
        purchaseType: '<?php echo htmlspecialchars(t('add_wholesale_product.purchase_type')); ?>'
    };
    
    const productSearch = document.getElementById('product_search');
    const searchResults = document.getElementById('product_search_results');
    const selectedProduct = document.getElementById('selected_product');
    const productId = document.getElementById('product_id');
    const clearSelection = document.getElementById('clear_selection');
    const wholesalePriceInput = document.getElementById('wholesale_price');
    const costPriceInput = document.getElementById('cost_price');
    const costPricePieceInput = document.getElementById('cost_price_piece');
    const wholesalePricePieceInput = document.getElementById('wholesale_price_piece');
    const piecesPerBoxDisplay = document.getElementById('pieces_per_box_display');

    let searchTimeout;
    let currentCostPrice = 0;
    let currentPiecesPerBox = 1;

    function round2(n) { return Math.round(n * 100) / 100; }

    // 판매 단위 토글 (BOX=주황 / PCS=파랑, Design Ref: logistics/inbound_add.php)
    window.setSaleUnit = function(unit) {
        document.getElementById('sale_unit').value = unit;
        document.querySelectorAll('#saleUnitToggle .sale-unit-btn').forEach(function(btn) {
            var u = btn.getAttribute('data-unit');
            btn.classList.remove('bg-amber-500', 'bg-blue-500', 'text-white', 'bg-white', 'text-gray-400');
            if (u === unit) {
                btn.classList.add(u === 'box' ? 'bg-amber-500' : 'bg-blue-500', 'text-white');
            } else {
                btn.classList.add('bg-white', 'text-gray-400');
            }
        });
    };
    
    // 상품 검색
    productSearch.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            searchResults.classList.add('hidden');
            return;
        }
        
        searchTimeout = setTimeout(function() {
            searchProducts(query);
        }, 300);
    });
    
    // 검색 결과 외부 클릭시 닫기
    document.addEventListener('click', function(e) {
        if (!productSearch.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.classList.add('hidden');
        }
    });
    
    // 선택 취소
    clearSelection.addEventListener('click', function() {
        productId.value = '';
        selectedProduct.classList.add('hidden');
        productSearch.value = '';
        resetPriceCalculation();
    });
    
    const marginRateInputAdd = document.getElementById('margin_rate_input');

    // 원가(낱개) 기준 계산: 박스원가 = 낱개 × 개수, 도매가는 마진율 적용
    function recalcFromPiece() {
        const ppb = parseFloat(piecesPerBoxDisplay.value) || 1;
        const costPiece = parseFloat(costPricePieceInput.value) || 0;
        const margin = parseFloat(marginRateInputAdd.value) || 0;

        if (costPiece > 0) {
            currentCostPrice = costPiece;
            costPriceInput.value = round2(costPiece * ppb);                 // 원가(박스)
            const wPiece = Math.ceil(costPiece * (1 + margin / 100));       // 도매가(낱개): 소수점 올림
            wholesalePricePieceInput.value = wPiece;
            wholesalePriceInput.value = Math.round(wPiece * ppb);           // 도매가(박스)
        }
        updateMarginDisplayAdd();
    }

    // 원가(박스) 직접 입력 시: 낱개원가 역산 후 도매가 계산
    function recalcFromBox() {
        const ppb = parseFloat(piecesPerBoxDisplay.value) || 1;
        const costBox = parseFloat(costPriceInput.value) || 0;
        const margin = parseFloat(marginRateInputAdd.value) || 0;

        if (costBox > 0) {
            currentCostPrice = costBox;
            costPricePieceInput.value = round2(costBox / ppb);             // 원가(낱개)
            const wBox = Math.round(costBox * (1 + margin / 100));         // 도매가(박스)
            wholesalePriceInput.value = wBox;
            wholesalePricePieceInput.value = Math.ceil(wBox / ppb);        // 도매가(낱개): 소수점 올림
        }
        updateMarginDisplayAdd();
    }

    // 마진율/박스당 개수 변경 시: 낱개원가가 있으면 낱개 기준, 아니면 박스 기준
    function recalcAll() {
        if (parseFloat(costPricePieceInput.value) > 0) {
            recalcFromPiece();
        } else {
            recalcFromBox();
        }
    }

    // 도매가(박스) 직접 입력 시 마진율 역산 + 낱개가 재계산
    function recalcFromWholesaleBox() {
        const ppb = parseFloat(piecesPerBoxDisplay.value) || 1;
        const costBox = parseFloat(costPriceInput.value) || 0;
        const wBox = parseFloat(wholesalePriceInput.value) || 0;

        if (costBox > 0 && wBox > 0) {
            marginRateInputAdd.value = ((wBox / costBox - 1) * 100).toFixed(1);
        }
        if (wBox > 0) {
            wholesalePricePieceInput.value = Math.ceil(wBox / ppb);        // 도매가(낱개): 소수점 올림
        }
        updateMarginDisplayAdd();
    }

    // 마진 정보 안내 문구 제거됨 (no-op)
    function updateMarginDisplayAdd() {}

    // 이벤트 리스너 등록
    // 원가(낱개) 입력 → 낱개 기준 계산
    costPricePieceInput.addEventListener('input', function() {
        if (parseFloat(this.value) > 0) {
            recalcFromPiece();
        } else {
            costPriceInput.value = '';
            wholesalePriceInput.value = '';
            wholesalePricePieceInput.value = '';
            updateMarginDisplayAdd();
        }
    });

    // 원가(박스) 직접 입력 → 박스 기준 계산
    costPriceInput.addEventListener('input', function() {
        if (parseFloat(this.value) > 0) {
            recalcFromBox();
        } else {
            costPricePieceInput.value = '';
            wholesalePriceInput.value = '';
            wholesalePricePieceInput.value = '';
            updateMarginDisplayAdd();
        }
    });

    marginRateInputAdd.addEventListener('input', recalcAll);

    // 박스당 개수 수정 시 낱개/박스 가격 재계산
    if (piecesPerBoxDisplay) {
        piecesPerBoxDisplay.addEventListener('input', function() {
            currentPiecesPerBox = parseFloat(this.value) || 1;
            recalcAll();
        });
    }

    // 도매가(박스) 직접 입력 → 마진율/낱개가 재계산 (0.5초 디바운스)
    wholesalePriceInput.addEventListener('input', function() {
        clearTimeout(wholesalePriceInput.timeoutId);
        wholesalePriceInput.timeoutId = setTimeout(recalcFromWholesaleBox, 500);
    });

    // 페이지 로드 시 초기 계산
    updateMarginDisplayAdd();

    function searchProducts(query) {
        fetch('ajax_search_products_simple.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
        })
        .then(response => {
            // console.log('Response status:', response.status);
            return response.json();
        })
        .then(data => {
            // console.log('Response data:', data);
            if (data.success && data.products) {
                displaySearchResults(data.products);
            } else {
                const message = data.message || '검색 결과가 없습니다.';
                searchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">' + message + '</div>';
                searchResults.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            searchResults.innerHTML = '<div class="p-3 text-sm text-red-500">검색 중 오류가 발생했습니다: ' + error.message + '</div>';
            searchResults.classList.remove('hidden');
        });
    }
    
    function displaySearchResults(products) {
        let html = '';
        products.forEach(function(product) {
            html += `
                <div class="p-3 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0 product-item"
                     data-id="${product.id}"
                     data-sku="${product.sku}"
                     data-name-ko="${product.name_ko || ''}"
                     data-name-en="${product.name_en || ''}"
                     data-pieces-per-box="${Number(product.pieces_per_box || 1)}"
                     data-cost-price="${Number(product.cost_price || 0)}">
                    <div class="font-medium text-gray-900">${product.name_en || product.name_ko || 'N/A'}</div>
                    <div class="text-sm text-gray-600">${product.name_ko && product.name_en ? product.name_ko : ''}</div>
                    <div class="text-xs text-gray-500 mt-1">
                        SKU: ${product.sku} | 박스당: ${Number(product.pieces_per_box || 1)}개 | 원가(낱개): ${Number(product.cost_price || 0).toLocaleString()} | 판매가: ${Number(product.selling_price || 0).toLocaleString()}
                    </div>
                </div>
            `;
        });
        
        searchResults.innerHTML = html;
        searchResults.classList.remove('hidden');
        
        // 상품 선택 이벤트
        document.querySelectorAll('.product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                selectProduct(this);
            });
        });
    }
    
    function selectProduct(item) {
        const id = item.dataset.id;
        const sku = item.dataset.sku;
        const nameKo = item.dataset.nameKo;
        const nameEn = item.dataset.nameEn;
        
        productId.value = id;

        // 박스당 개수 / 원가(낱개) 반영 — 검색 결과의 원가는 낱개 단가임
        const piecesPerBox = parseInt(item.dataset.piecesPerBox) || 1;
        const costPiece = parseFloat(item.dataset.costPrice) || 0;
        const sellingPrice = item.querySelector('.text-xs').textContent.match(/판매가: ([0-9,]+)/)?.[1] || '0';
        currentPiecesPerBox = piecesPerBox;
        piecesPerBoxDisplay.value = piecesPerBox;

        // 하단 나머지 정보 (박스당 개수는 별도 입력칸에서 수정)
        document.getElementById('selected_product_info').textContent = `SKU: ${sku} | 원가(낱개): ${costPiece.toLocaleString()} | 판매가: ${sellingPrice}`;

        // 도매명/SKU를 선택 상품 기준으로 자동 입력 (사용자가 한글/영문 수정 가능)
        const nameKoInput = document.getElementById('wholesale_name_ko');
        const nameEnInput = document.getElementById('wholesale_name_en');
        const skusInput = document.getElementById('wholesale_skus');
        if (nameKoInput && !nameKoInput.value && nameKo) nameKoInput.value = nameKo;
        if (nameEnInput && !nameEnInput.value && nameEn) nameEnInput.value = nameEn;
        if (skusInput && !skusInput.value && sku) skusInput.value = sku;

        // 원가(낱개)가 비어있으면 상품 낱개원가로 채우고, 낱개 기준으로 가격 일괄 계산
        if (!costPricePieceInput.value && costPiece > 0) {
            costPricePieceInput.value = costPiece;
        }
        recalcFromPiece();

        selectedProduct.classList.remove('hidden');
        searchResults.classList.add('hidden');
        productSearch.value = nameEn || nameKo || sku;
        
        // 구매이력 조회
        loadPurchaseHistory(id);
    }
    
    // 구매이력 조회 함수
    function loadPurchaseHistory(productId) {
        const loadingElement = document.getElementById('purchase_history_loading');
        const contentElement = document.getElementById('purchase_history_content');
        
        loadingElement.classList.remove('hidden');
        
        // 점포 ID 가져오기 (super_admin인 경우 선택된 점포, 아닌 경우 현재 점포)
        const storeIdElement = document.getElementById('store_id');
        const storeId = storeIdElement ? storeIdElement.value : '';
        
        const url = `ajax_get_purchase_history.php?product_id=${productId}${storeId ? '&store_id=' + storeId : ''}`;
        
        fetch(url)
        .then(response => response.json())
        .then(data => {
            loadingElement.classList.add('hidden');
            
            if (data.success && data.data && data.data.length > 0) {
                displayPurchaseHistory(data.data);
            } else {
                contentElement.innerHTML = `
                    <div class="text-center py-4 text-gray-500">
                        <i class="fas fa-info-circle mr-2"></i>
                        구매이력이 없습니다.
                    </div>
                `;
            }
        })
        .catch(error => {
            loadingElement.classList.add('hidden');
            contentElement.innerHTML = `
                <div class="text-center py-4 text-red-500">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    구매이력 조회 중 오류가 발생했습니다.
                </div>
            `;
            console.error('구매이력 조회 오류:', error);
        });
    }
    
    // 구매이력 표시 함수
    function displayPurchaseHistory(historyData) {
        const contentElement = document.getElementById('purchase_history_content');
        
        let html = `
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">${translations.purchaseDate}</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">${translations.supplier}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">${translations.unitPriceBox}</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">${translations.unitPricePerPiece}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">${translations.quantity}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">${translations.purchaseType}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">${translations.useButtonTable}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
        `;
        
        historyData.forEach((item, index) => {
            html += `
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 whitespace-nowrap text-gray-900">${item.purchase_date_formatted}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-gray-900">${item.supplier_name}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-right text-gray-900 font-medium">${Number(item.unit_price).toLocaleString()}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-right text-gray-500 text-xs">${Number(item.unit_cost_per_piece).toLocaleString()}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-center text-gray-900">${item.quantity}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-center text-gray-900">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${item.purchase_type === 'box' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'}">
                            ${item.purchase_type === 'box' ? '박스' : '낱개'}
                        </span>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap text-center">
                        <button type="button" 
                                class="inline-flex items-center px-2 py-1 border border-transparent text-xs font-medium rounded text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 use-price-btn"
                                data-cost-price="${item.unit_price}"
                                data-supplier="${item.supplier_name}"
                                data-date="${item.purchase_date_formatted}">
                            ${translations.useButtonTable}
                        </button>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        contentElement.innerHTML = html;
        
        // 사용 버튼 이벤트 바인딩
        bindUsePriceButtons();
    }
    
    // 가격 계산 초기화
    function resetPriceCalculation() {
        currentCostPrice = 0;
        currentPiecesPerBox = 1;
        piecesPerBoxDisplay.value = 1;
        costPriceInput.value = '';
        costPricePieceInput.value = '';
        wholesalePriceInput.value = '';
        wholesalePricePieceInput.value = '';
        updateMarginDisplayAdd();
    }

    // 매입가 선택 함수 (구매이력 "사용" 버튼 → 매입가(박스)이므로 원가(박스)로 반영)
    function selectCostPrice(costPrice, supplier, date) {
        costPriceInput.value = costPrice;
        recalcFromBox();
    }
    
    // 사용 버튼 이벤트 바인딩 함수
    function bindUsePriceButtons() {
        document.querySelectorAll('.use-price-btn').forEach(button => {
            button.addEventListener('click', function() {
                const costPrice = this.dataset.costPrice;
                const supplier = this.dataset.supplier;
                const date = this.dataset.date;
                
                selectCostPrice(costPrice, supplier, date);
                
                // 선택된 행 하이라이트
                document.querySelectorAll('.use-price-btn').forEach(btn => {
                    btn.closest('tr').classList.remove('bg-primary-50', 'border-primary-200');
                });
                this.closest('tr').classList.add('bg-primary-50', 'border-primary-200');
                
                // 버튼 상태 변경
                document.querySelectorAll('.use-price-btn').forEach(btn => {
                    btn.textContent = translations.useButton;
                    btn.classList.remove('bg-green-600', 'hover:bg-green-700');
                    btn.classList.add('bg-primary-600', 'hover:bg-primary-700');
                });
                this.textContent = translations.appliedButton;
                this.classList.remove('bg-primary-600', 'hover:bg-primary-700');
                this.classList.add('bg-green-600', 'hover:bg-green-700');
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>