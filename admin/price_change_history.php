<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('price_change.history') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 매입관리 권한 확인 (가격변경 이력도 매입관리 권한으로 제한)
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('price_change.no_permission')
    ];
    header('Location: shop.php');
    exit;
}

$pdo = null;
$price_changes = [];
$error_message = '';

// 날짜 변수 (기본값: 오늘)
$selected_date = $_GET['date'] ?? date('Y-m-d');
// 날짜 유효성 검사
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 가격변경 이력 테이블이 존재하는지 확인
    $table_check = $pdo->prepare("SHOW TABLES LIKE 'price_change_history'");
    $table_check->execute();
    
    if (!$table_check->fetch()) {
        $error_message = t('price_change.table_not_exists');
    } else {
        // 날짜 조건 구성
        $where_conditions = [];
        $params = [];
        
        // 선택된 날짜의 변경 이력만 조회
        $where_conditions[] = "DATE(pch.changed_at) = ?";
        $params[] = $selected_date;

        // 작성자 필터링 (super_admin이 아닌 경우 본인이 작성한 내용만 조회)
        if ($_SESSION['role'] !== 'super_admin') {
            if (!empty($_SESSION['user_id'])) {
                $where_conditions[] = "pch.changed_by_user_id = ?";
                $params[] = $_SESSION['user_id'];
            } else {
                // 사용자 ID가 없으면 데이터 조회 불가
                $where_conditions[] = "1 = 0";
            }
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // 가격변경 이력 조회
        $sql = "
            SELECT 
                pch.*,
                p.name_ko as product_name_ko,
                p.name_en as product_name_en,
                p.sku,
                u.username as changed_by,
                s.name as store_name
            FROM price_change_history pch
            LEFT JOIN products p ON pch.product_id = p.id
            LEFT JOIN users u ON pch.changed_by_user_id = u.id
            LEFT JOIN stores s ON pch.store_id = s.id
            $where_clause
            ORDER BY pch.changed_at DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $price_changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 전체 레코드 수 계산
        $total_records = count($price_changes);
    }

} catch (PDOException $e) {
    $error_message = t('price_change.load_error') . ": " . $e->getMessage();
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- Flash messages -->
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="mb-6">
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

    <!-- 날짜 네비게이션 -->
    <div class="mb-6 bg-white p-4 rounded-lg shadow">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <?php 
                $prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
                $next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));
                $today = date('Y-m-d');
                ?>
                
                <a href="?date=<?php echo $prev_date; ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    <i class="fas fa-chevron-left mr-2"></i>
                    <?php echo t('price_change.previous_day'); ?>
                </a>
                
                <div class="flex items-center space-x-2">
                    <input type="date" id="datePicker" value="<?php echo $selected_date; ?>" 
                           class="rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                           onchange="selectedIds = []; window.location.href='?date=' + this.value">
                    <span class="text-sm text-gray-600">
                        <?php 
                        $day_keys = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                        $day_of_week = t('price_change.' . $day_keys[date('w', strtotime($selected_date))]);
                        $day_suffix = t('price_change.day_suffix');
                        echo $day_suffix ? "({$day_of_week}{$day_suffix})" : "({$day_of_week})";
                        ?>
                    </span>
                </div>
                
                <?php if ($selected_date < $today): ?>
                <a href="?date=<?php echo $next_date; ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    <?php echo t('price_change.next_day'); ?>
                    <i class="fas fa-chevron-right ml-2"></i>
                </a>
                <?php else: ?>
                <button disabled class="inline-flex items-center px-4 py-2 border border-gray-200 text-sm font-medium rounded-md text-gray-400 bg-gray-100 cursor-not-allowed">
                    <?php echo t('price_change.next_day'); ?>
                    <i class="fas fa-chevron-right ml-2"></i>
                </button>
                <?php endif; ?>
                
                <?php if ($selected_date != $today): ?>
                <a href="?date=<?php echo $today; ?>" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    <i class="fas fa-calendar-day mr-2"></i>
                    <?php echo t('price_change.today'); ?>
                </a>
                <?php endif; ?>
            </div>

            <!-- 가격변경 버튼 -->
            <div>
                <button onclick="openPriceChangeModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    <i class="fas fa-edit mr-2"></i>
                    가격변경
                </button>
            </div>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="bg-red-50 border border-red-200 rounded-md p-4">
            <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php elseif (empty($price_changes)): ?>
        <div class="text-center py-12">
            <i class="fas fa-chart-line text-5xl text-gray-400"></i>
            <h2 class="mt-4 text-lg font-medium text-gray-900"><?php echo t('price_change.no_history'); ?></h2>
            <p class="mt-1 text-sm text-gray-500"><?php echo t('price_change.no_history_desc'); ?></p>
        </div>
    <?php else: ?>
        <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
            <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
                <h3 class="text-lg leading-6 font-semibold text-gray-900">
                    <?php echo t('price_change.history'); ?>
                    <?php if (!$error_message && isset($total_records)): ?>
                        <span class="text-sm font-normal text-gray-500 ml-2">(총 <?php echo number_format($total_records); ?>건)</span>
                    <?php endif; ?>
                </h3>
                <div class="flex items-center space-x-2">
                    <p class="text-sm text-gray-500" id="selectedCount" style="display:none;">
                        <?php echo t('price_change.selected_items'); ?>: <span class="font-semibold">0</span><?php echo t('common.items'); ?>
                    </p>
                    <button id="deleteSelectedBtn" onclick="confirmDeleteSelected()" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:cursor-not-allowed">
                        <i class="fas fa-trash mr-2"></i>
                        선택 삭제
                    </button>
                    <button id="printPriceCardsBtn" onclick="openPriceCardModal()" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:cursor-not-allowed">
                        <i class="fas fa-tags mr-2"></i>
                        <?php echo t('price_change.print_price_cards'); ?>
                    </button>
                    <button id="printSelectedBtn" onclick="openSelectedPrintModal()" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:cursor-not-allowed">
                        <i class="fas fa-print mr-2"></i>
                        <?php echo t('price_change.print_selected'); ?>
                    </button>
                    <button onclick="openPrintModal()" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                        <i class="fas fa-print mr-2"></i>
                        <?php echo t('common.print_preview'); ?>
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider" style="width: 40px;">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">SKU</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('price_change.barcode'); ?></th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('price_change.product_name'); ?></th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('price_change.old_cost_price'); ?></th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('price_change.new_cost_price'); ?></th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('price_change.old_selling_price'); ?></th>
                            <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('price_change.new_selling_price'); ?></th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('price_change.changed_by'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <?php foreach ($price_changes as $change): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="toggleRowSelection(this, event)" data-id="<?php echo $change['id']; ?>">
                                <td class="px-6 py-4 text-center" onclick="event.stopPropagation();">
                                    <input type="checkbox" class="row-checkbox rounded border-gray-300 text-primary-600 focus:ring-primary-500" data-id="<?php echo $change['id']; ?>">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($change['sku'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php if (!empty($change['sku'])): ?>
                                        <svg class="barcode inline-block" data-sku="<?php echo htmlspecialchars($change['sku']); ?>"></svg>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if (!empty($change['product_name_en'])): ?>
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($change['product_name_en']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($change['product_name_ko'] ?? 'N/A'); ?></div>
                                    <?php else: ?>
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($change['product_name_ko'] ?? 'N/A'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    <?php if ($change['old_cost_price']): ?>
                                        <span class="text-gray-900"><?php echo number_format($change['old_cost_price'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    <?php if ($change['new_cost_price']): ?>
                                        <span class="text-green-600 font-semibold"><?php echo number_format($change['new_cost_price'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    <?php if ($change['old_selling_price']): ?>
                                        <span class="text-gray-900"><?php echo number_format($change['old_selling_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                    <?php if ($change['new_selling_price']): ?>
                                        <span class="text-blue-600 font-semibold"><?php echo number_format($change['new_selling_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($change['changed_by'] ?? 'N/A'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo date('Y-m-d H:i', strtotime($change['changed_at'])); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- Print Preview Modal -->
<div id="printModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900"><?php echo t('common.print_preview'); ?></h3>
                <button onclick="closePrintModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <!-- 날짜 선택 및 네비게이션 -->
            <div class="flex items-center justify-center gap-4 mb-4 p-3 bg-gray-50 rounded-lg">
                <button onclick="changePrintDate(-1)" class="px-3 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
                    <i class="fas fa-chevron-left"></i> <?php echo t('common.previous_day'); ?>
                </button>
                <input type="date" id="modalDatePicker" class="px-3 py-2 border rounded" onchange="loadPrintData(this.value)">
                <button onclick="changePrintDate(1)" class="px-3 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
                    <?php echo t('common.next_day'); ?> <i class="fas fa-chevron-right"></i>
                </button>
                <button onclick="setPrintToday()" class="px-3 py-2 bg-gray-500 text-white rounded hover:bg-gray-600">
                    <?php echo t('common.today'); ?>
                </button>
                <button onclick="printModalContent()" class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600">
                    <i class="fas fa-print"></i> <?php echo t('price_change.print'); ?>
                </button>
            </div>
            
            <!-- 인쇄 내용 -->
            <div id="modalPrintContent" class="border rounded-lg p-4 bg-white" style="max-height: 500px; overflow-y: auto;">
                <div class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-2xl text-gray-400"></i>
                    <p class="mt-2 text-gray-600"><?php echo t('price_change.loading_data'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Price Card Print Modal -->
<div id="priceCardModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900"><?php echo t('price_change.print_price_cards'); ?></h3>
                <button onclick="closePriceCardModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="flex items-center justify-center gap-4 mb-4 p-3 bg-gray-50 rounded-lg">
                <button onclick="printPriceCards()" class="px-6 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-semibold">
                    <i class="fas fa-print mr-2"></i><?php echo t('price_change.print_price_cards'); ?>
                </button>
            </div>
            
            <!-- 프라이스카드 미리보기 -->
            <div id="priceCardContent" class="border rounded-lg p-4 bg-gray-50 text-center" style="max-height: 600px; overflow-y: auto;">
                <!-- 프라이스카드들이 여기에 생성됩니다 -->
            </div>
            
            <style>
                /* 미리보기용 CSS - 실제 크기 표시 */
                #priceCardContent table {
                    width: 70mm;
                    height: 28mm;
                    border: 1px solid #000;
                    margin: 5mm auto;
                    background: white;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                    border-collapse: collapse;
                }
                
                #priceCardContent .name-cell {
                    height: 11mm;
                    text-align: center;
                    padding: 0.3mm;
                    vertical-align: middle;
                }
                
                #priceCardContent .product-name-en-line1 {
                    font-size: 11pt;
                    font-weight: bold;
                    line-height: 1.0;
                    margin-bottom: 0.2mm;
                }
                
                #priceCardContent .product-name-en-line2 {
                    font-size: 11pt;
                    font-weight: bold;
                    line-height: 1.0;
                    margin-bottom: 0.2mm;
                }
                
                #priceCardContent .product-name-ko {
                    font-size: 10pt;
                    font-weight: normal;
                    line-height: 1.0;
                }
                
                #priceCardContent .divider-row {
                    height: 1mm;
                }
                
                #priceCardContent .divider-line {
                    height: 1mm;
                    border-bottom: 1px solid #000;
                    padding: 0;
                }
                
                #priceCardContent .content-row {
                    height: 16mm;
                }
                
                #priceCardContent .barcode-cell {
                    width: 42mm;
                    text-align: center;
                    padding: 1mm;
                    vertical-align: middle;
                }
                
                #priceCardContent .price-cell {
                    width: 28mm;
                    text-align: center;
                    border-left: 1px solid #000;
                    font-size: 14pt;
                    font-weight: bold;
                    padding: 1mm;
                    vertical-align: middle;
                }
                
                #priceCardContent .price-card-barcode {
                    width: 38mm !important;
                    height: 14mm !important;
                    display: block;
                    margin: 0 auto;
                }
                
                #priceCardContent td {
                    border: none;
                    padding: 0.5mm;
                }
                
            </style>
        </div>
    </div>
</div>

<!-- Price Change Modal -->
<div id="priceChangeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-2xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900">가격변경</h3>
                <button onclick="closePriceChangeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- 바코드 입력 영역 -->
            <div class="mb-6">
                <label for="barcodeInput" class="block text-sm font-medium text-gray-700 mb-2">
                    바코드 스캔
                </label>
                <input type="text" id="barcodeInput"
                       class="w-full px-4 py-3 text-lg border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                       placeholder="바코드를 스캔하거나 입력하세요"
                       autocomplete="off">
                <p class="mt-1 text-sm text-gray-500">바코드 스캔 후 Enter를 누르세요</p>
            </div>

            <!-- 로딩 표시 -->
            <div id="loadingIndicator" class="hidden text-center py-4">
                <i class="fas fa-spinner fa-spin text-2xl text-indigo-600"></i>
                <p class="mt-2 text-sm text-gray-600">상품 정보를 불러오는 중...</p>
            </div>

            <!-- 상품 정보 표시 영역 -->
            <div id="productInfoArea" class="hidden">
                <div class="bg-gray-50 rounded-lg p-4 mb-4">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3">상품 정보</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">상품명 (영문)</label>
                            <p id="productNameEn" class="text-sm font-medium text-gray-900">-</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">상품명 (한글)</label>
                            <p id="productNameKo" class="text-sm font-medium text-gray-900">-</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">원가</label>
                            <p id="currentCostPrice" class="text-sm font-medium text-gray-900">-</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">현재 판매가</label>
                            <p id="currentSellingPrice" class="text-sm font-medium text-gray-900">-</p>
                        </div>
                    </div>
                </div>

                <!-- 신규 판매가 입력 -->
                <div class="mb-4">
                    <label for="newSellingPrice" class="block text-sm font-medium text-gray-700 mb-2">
                        신규 판매가 <span class="text-red-500">*</span>
                    </label>
                    <input type="number" id="newSellingPrice"
                           class="w-full px-4 py-3 text-lg border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                           placeholder="신규 판매가를 입력하세요"
                           min="0"
                           step="1">
                </div>

                <!-- 저장 버튼 -->
                <div class="flex justify-end space-x-3">
                    <button onclick="closePriceChangeModal()"
                            class="px-4 py-2 bg-gray-300 text-gray-800 text-base font-medium rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-300">
                        취소
                    </button>
                    <button id="savePriceChangeBtn" onclick="savePriceChange()"
                            class="px-4 py-2 bg-indigo-600 text-white text-base font-medium rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        저장
                    </button>
                </div>
            </div>

            <!-- 에러 메시지 -->
            <div id="priceChangeError" class="hidden mt-4 p-3 bg-red-50 border border-red-200 rounded-md">
                <p class="text-sm text-red-800"></p>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                <i class="fas fa-trash text-red-600 text-xl"></i>
            </div>
            <h3 class="text-lg leading-6 font-medium text-gray-900 mt-4">선택된 항목 삭제</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">
                    선택된 <span id="deleteCount" class="font-semibold">0</span>개 항목을 삭제하시겠습니까?<br>
                    <span class="text-red-600 font-medium">이 작업은 되돌릴 수 없습니다.</span>
                </p>
            </div>
            <div class="items-center px-4 py-3">
                <button id="confirmDeleteBtn" onclick="executeDelete()"
                        class="px-4 py-2 bg-red-600 text-white text-base font-medium rounded-md w-24 mr-3 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                    삭제
                </button>
                <button onclick="closeDeleteModal()"
                        class="px-4 py-2 bg-gray-300 text-gray-800 text-base font-medium rounded-md w-24 hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-300">
                    취소
                </button>
            </div>
        </div>
    </div>
</div>

<?php 
// JavaScript에서 사용할 번역 키들
$js_keys = [
    'price_change.select_items_to_print',
    'price_change.loading_selected_items',
    'price_change.confirm_delete_selected',
    'price_change.delete_success',
    'price_change.delete_error',
    'price_change.selected_items',
    'common.items'
];
echo get_js_translation_script($js_keys); 
?>

<!-- JsBarcode 라이브러리 -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<script>
let modalCurrentDate = '<?php echo date('Y-m-d'); ?>';
let selectedIds = [];

// 행 선택 토글
function toggleRowSelection(row, event) {
    // 체크박스 클릭 시에는 이벤트 전파 중지
    if (event.target.type === 'checkbox') {
        return;
    }
    
    const checkbox = row.querySelector('.row-checkbox');
    const id = row.getAttribute('data-id');
    
    checkbox.checked = !checkbox.checked;
    
    if (checkbox.checked) {
        row.classList.add('bg-blue-50');
        if (!selectedIds.includes(id)) {
            selectedIds.push(id);
        }
    } else {
        row.classList.remove('bg-blue-50');
        selectedIds = selectedIds.filter(item => item !== id);
    }
    
    updateSelectedCount();
}

// 바코드 생성 함수
function generateBarcodes() {
    const barcodeElements = document.querySelectorAll('.barcode');
    barcodeElements.forEach(element => {
        const sku = element.getAttribute('data-sku');
        if (sku && sku !== 'N/A') {
            try {
                JsBarcode(element, sku, {
                    format: "CODE128",
                    width: 1,
                    height: 20,
                    displayValue: false,
                    margin: 0
                });
            } catch (e) {
                console.error('<?php echo t('price_change.barcode_generation_failed'); ?>:', sku, e);
                element.innerHTML = '<span class="text-red-400 text-xs">' + t('common.error') + '</span>';
            }
        }
    });
}

// 전체 선택/해제
document.addEventListener('DOMContentLoaded', function() {
    // 초기 버튼 상태 설정
    const printSelectedBtn = document.getElementById('printSelectedBtn');
    const printPriceCardsBtn = document.getElementById('printPriceCardsBtn');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');

    if (printSelectedBtn) printSelectedBtn.disabled = true;
    if (printPriceCardsBtn) printPriceCardsBtn.disabled = true;
    if (deleteSelectedBtn) deleteSelectedBtn.disabled = true;
    
    // 바코드 생성
    generateBarcodes();
    
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            selectedIds = [];
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                const row = checkbox.closest('tr');
                const id = checkbox.getAttribute('data-id');
                
                if (this.checked) {
                    row.classList.add('bg-blue-50');
                    selectedIds.push(id);
                } else {
                    row.classList.remove('bg-blue-50');
                }
            });
            
            updateSelectedCount();
        });
    }
    
    // 개별 체크박스 이벤트
    document.querySelectorAll('.row-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function(e) {
            e.stopPropagation();
            const row = this.closest('tr');
            const id = this.getAttribute('data-id');
            
            if (this.checked) {
                row.classList.add('bg-blue-50');
                if (!selectedIds.includes(id)) {
                    selectedIds.push(id);
                }
            } else {
                row.classList.remove('bg-blue-50');
                selectedIds = selectedIds.filter(item => item !== id);
            }
            
            updateSelectedCount();
            
            // 전체 선택 체크박스 상태 업데이트
            const allCheckboxes = document.querySelectorAll('.row-checkbox');
            const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
            const selectAll = document.getElementById('selectAll');
            
            if (selectAll) {
                selectAll.checked = allCheckboxes.length === checkedBoxes.length && allCheckboxes.length > 0;
            }
        });
    });
});

// 선택된 항목 수 업데이트
function updateSelectedCount() {
    const countElement = document.getElementById('selectedCount');
    const printSelectedBtn = document.getElementById('printSelectedBtn');
    const printPriceCardsBtn = document.getElementById('printPriceCardsBtn');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');

    if (selectedIds.length > 0) {
        if (countElement) {
            countElement.style.display = 'block';
            const spanElement = countElement.querySelector('span');
            if (spanElement) spanElement.textContent = selectedIds.length;
        }
        if (printSelectedBtn) printSelectedBtn.disabled = false;
        if (printPriceCardsBtn) printPriceCardsBtn.disabled = false;
        if (deleteSelectedBtn) deleteSelectedBtn.disabled = false;
    } else {
        if (countElement) countElement.style.display = 'none';
        if (printSelectedBtn) printSelectedBtn.disabled = true;
        if (printPriceCardsBtn) printPriceCardsBtn.disabled = true;
        if (deleteSelectedBtn) deleteSelectedBtn.disabled = true;
    }
}

// 삭제 확인 모달 열기
function confirmDeleteSelected() {
    if (selectedIds.length === 0) {
        alert('삭제할 항목을 선택해주세요.');
        return;
    }

    document.getElementById('deleteCount').textContent = selectedIds.length;
    document.getElementById('deleteModal').classList.remove('hidden');
}

// 삭제 확인 모달 닫기
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

// 실제 삭제 실행
function executeDelete() {
    if (selectedIds.length === 0) {
        alert('삭제할 항목이 없습니다.');
        return;
    }

    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

    // 버튼 비활성화 및 로딩 표시
    confirmDeleteBtn.disabled = true;
    confirmDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>삭제 중...';

    fetch('ajax_delete_price_history.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            ids: selectedIds
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 성공 시 선택된 행들을 DOM에서 제거
            selectedIds.forEach(id => {
                const row = document.querySelector(`tr[data-id="${id}"]`);
                if (row) {
                    row.remove();
                }
            });

            // 선택 초기화
            selectedIds = [];
            updateSelectedCount();

            // 전체 선택 체크박스 해제
            const selectAll = document.getElementById('selectAll');
            if (selectAll) selectAll.checked = false;

            // 모달 닫기
            closeDeleteModal();

            // 성공 메시지 표시 제거

            // 페이지 새로고침 (총 개수 업데이트를 위해)
            window.location.reload();
        } else {
            alert('삭제 중 오류가 발생했습니다: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Delete error:', error);
        alert('삭제 요청 중 네트워크 오류가 발생했습니다.');
    })
    .finally(() => {
        // 버튼 상태 복구
        confirmDeleteBtn.disabled = false;
        confirmDeleteBtn.innerHTML = '삭제';
    });
}

function openPrintModal() {
    document.getElementById('printModal').classList.remove('hidden');
    modalCurrentDate = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('modalDatePicker').value = modalCurrentDate;
    loadPrintData(modalCurrentDate);
}

function openSelectedPrintModal() {
    if (selectedIds.length === 0) {
        alert('항목을 선택해주세요.');
        return;
    }
    
    document.getElementById('printModal').classList.remove('hidden');
    modalCurrentDate = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('modalDatePicker').value = modalCurrentDate;
    loadSelectedPrintData(modalCurrentDate);
}

function closePrintModal() {
    document.getElementById('printModal').classList.add('hidden');
}

function loadPrintData(date) {
    modalCurrentDate = date;
    document.getElementById('modalDatePicker').value = date;
    
    const container = document.getElementById('modalPrintContent');
    container.innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-gray-400"></i><p class="mt-2 text-gray-600"><?php echo t('price_change.loading_data'); ?></p></div>';
    
    fetch(`ajax_print_data.php?date=${date}`)
        .then(response => response.text())
        .then(html => {
            // 인쇄용 헤더 추가
            const printHeader = `
                <div class="text-center mb-6 pb-4 border-b-2 border-gray-800">
                    <h2 class="text-lg font-bold">Price Change History</h2>
                    <p class="text-sm text-gray-600">Date: ${date} | Print Time: ${new Date().toLocaleString('en-US')}</p>
                </div>
            `;
            container.innerHTML = printHeader + html;
            // 인쇄 모달에서 바코드 생성
            generateBarcodes();
        })
        .catch(error => {
            container.innerHTML = '<div class="text-center py-8 text-red-600"><?php echo t('price_change.loading_failed'); ?>: ' + error.message + '</div>';
        });
}

function loadSelectedPrintData(date) {
    modalCurrentDate = date;
    document.getElementById('modalDatePicker').value = date;
    
    const container = document.getElementById('modalPrintContent');
    container.innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-gray-400"></i><p class="mt-2 text-gray-600">' + t('price_change.loading_selected_items') + '</p></div>';
    
    fetch('ajax_print_data.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            date: date,
            selected_ids: selectedIds
        })
    })
        .then(response => response.text())
        .then(html => {
            // 테이블 헤더를 영문으로 변경
            let translatedHtml = html
                .replace(/No\./g, 'No')
                .replace(/SKU \/ 바코드/g, 'SKU / Barcode')
                .replace(/상품명/g, 'Product Name')
                .replace(/기존 원가/g, 'Previous Cost')
                .replace(/변경 원가/g, 'New Cost')
                .replace(/기존 판매가/g, 'Previous Price')
                .replace(/변경 판매가/g, 'New Price');
            
            // 인쇄용 헤더 추가 (선택된 항목 표시)
            const printHeader = `
                <div class="text-center mb-6 pb-4 border-b-2 border-gray-800">
                    <h2 class="text-lg font-bold">Price Change History (Selected Items)</h2>
                    <p class="text-sm text-gray-600">Date: ${date} | Selected Items: ${selectedIds.length} | Print Time: ${new Date().toLocaleString('en-US')}</p>
                </div>
            `;
            container.innerHTML = printHeader + translatedHtml;
            // 선택된 항목 인쇄 모달에서 바코드 생성
            generateBarcodes();
        })
        .catch(error => {
            container.innerHTML = '<div class="text-center py-8 text-red-600">' + t('price_change.delete_error') + ': ' + error.message + '</div>';
        });
}

function changePrintDate(days) {
    const date = new Date(modalCurrentDate);
    date.setDate(date.getDate() + days);
    loadPrintData(date.toISOString().split('T')[0]);
}

function setPrintToday() {
    loadPrintData(new Date().toISOString().split('T')[0]);
}

function printModalContent() {
    const printContent = document.getElementById('modalPrintContent').innerHTML;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Price Change History - ${modalCurrentDate}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #000; padding: 8px; text-align: left; font-size: 12px; }
                th { background-color: #f5f5f5; font-weight: bold; }
                .no-print { display: none; }
                .barcode svg { max-width: 80px; height: auto; }
                @media print {
                    body { margin: 0; }
                    table { font-size: 10px; }
                    th, td { padding: 4px; }
                    .barcode svg { max-width: 60px; }
                }
            </style>
        </head>
        <body>
            ${printContent}
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// 프라이스카드 모달 열기
function openPriceCardModal() {
    if (selectedIds.length === 0) {
        alert('항목을 선택해주세요.');
        return;
    }
    
    document.getElementById('priceCardModal').classList.remove('hidden');
    generatePriceCardContent();
}

// 프라이스카드 모달 닫기
function closePriceCardModal() {
    document.getElementById('priceCardModal').classList.add('hidden');
}

// 프라이스카드 내용 생성
function generatePriceCardContent() {
    const container = document.getElementById('priceCardContent');
    container.innerHTML = '<div class="text-center py-8 text-gray-500">데이터를 불러오는 중...</div>';
    
    // AJAX로 선택된 ID들의 데이터 가져오기
    fetch('ajax_price_card_final.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            ids: selectedIds
        })
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            
            if (!data.success) {
                container.innerHTML = '<div class="text-center py-8 text-red-500">데이터를 불러오는데 실패했습니다: ' + (data.message || 'Unknown error') + '</div>';
                return;
            }
        
        if (data.products.length === 0) {
            container.innerHTML = '<div class="text-center py-8 text-gray-500">선택한 항목의 데이터를 찾을 수 없습니다.</div>';
            return;
        }
        
        let cardsHtml = '';
        
        data.products.forEach(product => {
            const sku = product.sku;
            let productNameEn = product.product_name_en || '';
            let productNameKo = product.product_name_ko || '';
            const sellingPrice = product.selling_price;
            
            // 영문 상품명 2줄 처리
            let productNameEnLine1 = '';
            let productNameEnLine2 = '';
            
            if (productNameEn.length > 20) {
                // 단어 단위로 분할하되, 공백이 없으면 강제 분할
                const words = productNameEn.split(' ');
                let line1 = '';
                let line2 = '';
                
                for (let word of words) {
                    if ((line1 + ' ' + word).trim().length <= 20) {
                        line1 = (line1 + ' ' + word).trim();
                    } else {
                        line2 = (line2 + ' ' + word).trim();
                    }
                }
                
                // 첫 번째 줄이 너무 길면 강제 분할
                if (line1.length > 20) {
                    productNameEnLine1 = line1.substring(0, 20);
                    productNameEnLine2 = line1.substring(20) + ' ' + line2;
                } else {
                    productNameEnLine1 = line1;
                    productNameEnLine2 = line2;
                }
                
                // 두 번째 줄도 길면 자르기
                if (productNameEnLine2.length > 20) {
                    productNameEnLine2 = productNameEnLine2.substring(0, 17) + '...';
                }
            } else {
                productNameEnLine1 = productNameEn;
                productNameEnLine2 = '';
            }
            
            // 한글 길이 제한
            if (productNameKo.length > 20) {
                productNameKo = productNameKo.substring(0, 17) + '...';
            }
            
            cardsHtml += `
                <table>
                    <tr class="name-row">
                        <td colspan="2" class="name-cell">
                            <div class="product-name-en-line1">${productNameEnLine1}</div>
                            ${productNameEnLine2 ? `<div class="product-name-en-line2">${productNameEnLine2}</div>` : ''}
                            <div class="product-name-ko">${productNameKo}</div>
                        </td>
                    </tr>
                    <tr class="divider-row">
                        <td colspan="2" class="divider-line"></td>
                    </tr>
                    <tr class="content-row">
                        <td class="barcode-cell">
                            <svg class="price-card-barcode" data-sku="${sku}"></svg>
                        </td>
                        <td class="price-cell">
                            ${sellingPrice}
                        </td>
                    </tr>
                </table>
            `;
        });
        
            container.innerHTML = cardsHtml;
            
            // 바코드 생성
            setTimeout(() => {
                generatePriceCardBarcodes();
            }, 100);
        } catch (e) {
            console.error('JSON parse error:', e);
            container.innerHTML = '<div class="text-center py-8 text-red-500">응답 파싱 오류: ' + e.message + '</div>';
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        container.innerHTML = '<div class="text-center py-8 text-red-500">네트워크 오류: ' + error.message + '</div>';
    });
}

// 프라이스카드 바코드 생성 (Rongta TSC 최적화)
function generatePriceCardBarcodes() {
    const barcodeElements = document.querySelectorAll('.price-card-barcode');
    barcodeElements.forEach(element => {
        const sku = element.getAttribute('data-sku');
        if (sku && sku !== 'N/A') {
            try {
                // EAN-13 형식 확인 (13자리 숫자)
                if (/^\d{13}$/.test(sku)) {
                    // EAN-13 바코드 생성
                    JsBarcode(element, sku, {
                        format: "EAN13",
                        width: 1.0,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } else if (/^\d{12}$/.test(sku)) {
                    // UPC-A (12자리) - EAN-13으로 변환하여 생성
                    const ean13 = '0' + sku; // 앞에 0 추가
                    JsBarcode(element, ean13, {
                        format: "EAN13",
                        width: 1.0,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } else if (/^\d{8}$/.test(sku)) {
                    // EAN-8 바코드 생성
                    JsBarcode(element, sku, {
                        format: "EAN8",
                        width: 1.2,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } else {
                    // 기타 형식은 CODE128로 처리
                    JsBarcode(element, sku, {
                        format: "CODE128",
                        width: 1.2,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                }
            } catch (e) {
                console.error('<?php echo t('price_change.barcode_generation_failed'); ?>:', sku, e);
                // 실패 시 CODE128로 재시도
                try {
                    JsBarcode(element, sku, {
                        format: "CODE128",
                        width: 1.2,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } catch (e2) {
                    element.innerHTML = '<text style="font-size: 10px; font-weight: bold;"><?php echo t('price_change.barcode_error'); ?></text>';
                }
            }
        } else {
            element.innerHTML = '<text style="font-size: 10px; color: #666;">SKU 없음</text>';
        }
    });
}

// 바코드 생성 완료를 확인하는 함수
function checkBarcodesReady(container) {
    const barcodes = container.querySelectorAll('.price-card-barcode');
    if (barcodes.length === 0) return false;
    
    let allReady = true;
    barcodes.forEach(barcode => {
        const svgElement = barcode.querySelector('svg');
        if (!svgElement || svgElement.children.length === 0) {
            allReady = false;
        }
    });
    
    return allReady;
}

// 프라이스카드 인쇄
function printPriceCards() {
    const container = document.getElementById('priceCardContent');
    
    // 바코드 생성 완료를 기다리는 함수
    function waitForBarcodes(callback, maxAttempts = 15) {
        let attempts = 0;
        
        function check() {
            attempts++;
            
            if (checkBarcodesReady(container)) {
                callback();
            } else if (attempts < maxAttempts) {
                setTimeout(check, 200);
            } else {
                callback(); // 타임아웃되어도 인쇄 시도
            }
        }
        
        check();
    }
    
    waitForBarcodes(() => {
        const printContent = container.innerHTML;
        
        // 내용 확인
        if (!printContent || printContent.trim() === '') {
            alert('<?php echo t('price_change.print_content_empty'); ?>');
            return;
        }
        
        // SVG 요소가 있는지 확인
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = printContent;
        const svgElements = tempDiv.querySelectorAll('svg');
        
        if (svgElements.length === 0) {
            alert('<?php echo t('price_change.barcode_not_generated'); ?>');
            return;
        }
        
        const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Price Cards - Rongta TSC</title>
            <style>
                @media print {
                    @page {
                        size: 72mm 30mm;
                        margin: 0;
                    }
                    
                    body {
                        margin: 0;
                        padding: 0;
                        font-family: "Arial Black", Arial, sans-serif;
                        font-size: 12px;
                        line-height: 1.2;
                        -webkit-print-color-adjust: exact;
                        color-adjust: exact;
                        width: 72mm;
                        height: 30mm;
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        overflow: hidden;
                    }
                    
                    table {
                        margin-top: 0 !important;
                        height: 28mm !important;
                        padding: 1mm !important;
                    }
                }
                
                body {
                    font-family: "Arial Black", Arial, sans-serif;
                    margin: 0;
                    padding: 0;
                    width: 72mm;
                    height: 30mm;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    overflow: hidden;
                }
                
                table {
                    border-collapse: collapse;
                    width: 70mm;
                    height: 28mm;
                    border: none;
                    margin: 0 auto;
                    page-break-inside: avoid;
                    background: white;
                    padding: 1mm;
                }
                
                td {
                    border: none;
                    padding: 0.5mm;
                    vertical-align: middle;
                }
                
                .name-row {
                    height: 16mm;
                }
                
                .name-cell {
                    text-align: center;
                    padding: 0.3mm;
                }
                
                .product-name-en-line1 {
                    font-size: 11pt;
                    font-weight: bold;
                    line-height: 1.0;
                    margin-bottom: 0.2mm;
                }
                
                .product-name-en-line2 {
                    font-size: 11pt;
                    font-weight: bold;
                    line-height: 1.0;
                    margin-bottom: 0.2mm;
                }
                
                .product-name-ko {
                    font-size: 10pt;
                    font-weight: bold;
                    line-height: 1.0;
                }
                
                .divider-row {
                    height: 1mm;
                }
                
                .divider-line {
                    height: 1mm;
                    border-bottom: 1px solid #000;
                    padding: 0;
                }
                
                .content-row {
                    height: 11mm;
                }
                
                .barcode-cell {
                    width: 42mm;
                    text-align: center;
                    padding: 0.5mm;
                }
                
                .price-cell {
                    width: 28mm;
                    text-align: center;
                    font-size: 26pt;
                    font-weight: bold;
                    padding: 0.5mm;
                }
                
                .price-card-barcode {
                    width: 32mm !important;
                    height: 10mm !important;
                    display: block;
                    margin: 0 auto;
                }
                
                /* 바코드 텍스트 스타일 */
                .price-card-barcode text {
                    font-weight: bold !important;
                    font-family: "Arial Black", Arial, sans-serif !important;
                }
                
                /* TSC 프린터 최적화 */
                * {
                    -webkit-print-color-adjust: exact;
                    color-adjust: exact;
                }
            </style>
        </head>
        <body>
            ${printContent}
        </body>
        </html>
    `);
        printWindow.document.close();
        
        // 프린트 윈도우가 로드된 후 인쇄
        printWindow.onload = function() {
            // 프린트 윈도우에서도 바코드가 제대로 렌더링될 때까지 기다림
            setTimeout(() => {
                printWindow.print();
            }, 100);
        };
    });
}

// 모달 외부 클릭시 닫기
document.getElementById('printModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePrintModal();
    }
});

document.getElementById('priceCardModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePriceCardModal();
    }
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});

// 가격변경 모달 관련 변수
let currentProductId = null;

// 가격변경 모달 열기
function openPriceChangeModal() {
    document.getElementById('priceChangeModal').classList.remove('hidden');
    document.getElementById('barcodeInput').value = '';
    document.getElementById('productInfoArea').classList.add('hidden');
    document.getElementById('priceChangeError').classList.add('hidden');
    document.getElementById('loadingIndicator').classList.add('hidden');
    currentProductId = null;

    // 바코드 입력 필드에 포커스
    setTimeout(() => {
        document.getElementById('barcodeInput').focus();
    }, 100);
}

// 가격변경 모달 닫기
function closePriceChangeModal() {
    document.getElementById('priceChangeModal').classList.add('hidden');
}

// 에러 메시지 표시
function showPriceChangeError(message) {
    const errorDiv = document.getElementById('priceChangeError');
    errorDiv.querySelector('p').textContent = message;
    errorDiv.classList.remove('hidden');
}

// 에러 메시지 숨기기
function hidePriceChangeError() {
    document.getElementById('priceChangeError').classList.add('hidden');
}

// 바코드로 상품 검색
function searchProductByBarcode(barcode) {
    if (!barcode || barcode.trim() === '') {
        return;
    }

    hidePriceChangeError();
    document.getElementById('loadingIndicator').classList.remove('hidden');
    document.getElementById('productInfoArea').classList.add('hidden');

    fetch('ajax_get_product_by_barcode.php?barcode=' + encodeURIComponent(barcode))
        .then(response => response.json())
        .then(data => {
            document.getElementById('loadingIndicator').classList.add('hidden');

            if (data.success && data.product) {
                const product = data.product;
                currentProductId = product.product_id;

                // 상품 정보 표시
                document.getElementById('productNameEn').textContent = product.name_en || '-';
                document.getElementById('productNameKo').textContent = product.name_ko || '-';
                document.getElementById('currentCostPrice').textContent = product.cost_price;
                document.getElementById('currentSellingPrice').textContent = product.selling_price;

                // 신규 판매가 입력 필드 초기화 및 포커스
                const newPriceInput = document.getElementById('newSellingPrice');
                newPriceInput.value = '';

                document.getElementById('productInfoArea').classList.remove('hidden');

                setTimeout(() => {
                    newPriceInput.focus();
                }, 100);
            } else {
                showPriceChangeError(data.message || '상품을 찾을 수 없습니다.');
                document.getElementById('barcodeInput').select();
            }
        })
        .catch(error => {
            document.getElementById('loadingIndicator').classList.add('hidden');
            console.error('Error:', error);
            showPriceChangeError('상품 정보를 불러오는 중 오류가 발생했습니다.');
        });
}

// 가격 변경 저장
function savePriceChange() {
    if (!currentProductId) {
        showPriceChangeError('상품을 먼저 선택해주세요.');
        return;
    }

    const newSellingPrice = document.getElementById('newSellingPrice').value;

    if (!newSellingPrice || newSellingPrice <= 0) {
        showPriceChangeError('올바른 판매가를 입력해주세요.');
        return;
    }

    hidePriceChangeError();

    const saveBtn = document.getElementById('savePriceChangeBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>저장 중...';

    fetch('ajax_save_price_change.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            product_id: currentProductId,
            new_selling_price: parseFloat(newSellingPrice)
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 성공 메시지
                alert('가격이 성공적으로 변경되었습니다.');

                // 모달 닫기
                closePriceChangeModal();

                // 페이지 새로고침하여 리스트 갱신
                window.location.reload();
            } else {
                showPriceChangeError(data.message || '가격 변경에 실패했습니다.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showPriceChangeError('가격 변경 중 오류가 발생했습니다.');
        })
        .finally(() => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '저장';
        });
}

// 바코드 입력 이벤트 처리
document.addEventListener('DOMContentLoaded', function() {
    const barcodeInput = document.getElementById('barcodeInput');

    if (barcodeInput) {
        barcodeInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchProductByBarcode(this.value.trim());
            }
        });
    }

    // 신규 판매가 입력 시 Enter로 저장
    const newSellingPriceInput = document.getElementById('newSellingPrice');

    if (newSellingPriceInput) {
        newSellingPriceInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                savePriceChange();
            }
        });
    }

    // 모달 외부 클릭 시 닫기
    const priceChangeModal = document.getElementById('priceChangeModal');
    if (priceChangeModal) {
        priceChangeModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closePriceChangeModal();
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>