<?php
// 상품명 업데이트 처리 - HTML 출력 전에 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_product_name') {
    session_start();
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/lang_helper.php';

    header('Content-Type: application/json');

    $product_id = (int)$_POST['product_id'];
    $history_id = (int)$_POST['history_id'];
    $new_name = trim($_POST['new_name']);
    $field_type = $_POST['field_type'] ?? 'ko'; // 'ko' 또는 'en'

    // 입력 검증
    if (empty($new_name)) {
        $error_msg = $field_type === 'en'
            ? t('price_change.product_name_en_empty_error')
            : t('price_change.product_name_empty_error');
        echo json_encode(['success' => false, 'error' => $error_msg]);
        exit;
    }

    if (strlen($new_name) > 255) {
        $error_msg = $field_type === 'en'
            ? t('price_change.product_name_en_too_long_error')
            : t('price_change.product_name_too_long_error');
        echo json_encode(['success' => false, 'error' => $error_msg]);
        exit;
    }

    try {
        $conn = get_db_connection();
        $conn->begin_transaction();

        // 상품명 업데이트 (한글 또는 영문)
        $field_name = $field_type === 'en' ? 'name_en' : 'name_ko';
        $update_stmt = $conn->prepare("UPDATE products SET {$field_name} = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_name, $product_id);

        if ($update_stmt->execute()) {
            $conn->commit();
            $update_stmt->close();
            $conn->close();
            $success_msg = $field_type === 'en'
                ? t('price_change.product_name_en_update_success')
                : t('price_change.product_name_update_success');
            echo json_encode(['success' => true, 'message' => $success_msg]);
        } else {
            $error_msg = $field_type === 'en'
                ? t('price_change.product_name_en_update_failed')
                : t('price_change.product_name_update_failed');
            throw new Exception($error_msg);
        }

    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
            $conn->close();
        }
        if (isset($update_stmt)) $update_stmt->close();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('price_change.history') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 조회·출력은 점포 소속 사용자 누구나 가능 (로그인 여부는 header.php에서 확인됨)
// 가격변경 생성/삭제 등 관리 기능은 매입관리 권한 보유자에게만 노출
$can_manage_price_change = has_permission('purchase_management');

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

        // 점포 필터링 (super_admin이 아닌 경우 본인 소속 점포의 이력 전체 조회)
        if ($_SESSION['role'] !== 'super_admin') {
            if (!empty($current_store_id)) {
                $where_conditions[] = "pch.store_id = ?";
                $params[] = $current_store_id;
            } else {
                // 소속 점포가 없으면 데이터 조회 불가
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

            <!-- 가격변경 / 행상상품 등록 버튼 (관리 권한 보유자만) -->
            <?php if ($can_manage_price_change): ?>
            <div class="flex items-center space-x-2">
                <button onclick="openBulkPriceModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    <i class="fas fa-tags mr-2"></i>
                    <?php echo t('price_change.bulk_price_change_button'); ?>
                </button>
                <button onclick="openPriceChangeModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    <i class="fas fa-truck mr-2"></i>
                    <?php echo t('price_change.price_change_button'); ?>
                </button>
            </div>
            <?php endif; ?>
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
                    <?php if ($can_manage_price_change): ?>
                    <button id="deleteSelectedBtn" onclick="confirmDeleteSelected()" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:cursor-not-allowed">
                        <i class="fas fa-trash mr-2"></i>
                        <?php echo t('price_change.delete_selected'); ?>
                    </button>
                    <?php endif; ?>
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
                            <th class="px-3 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider" style="width: 40px;">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            </th>
                            <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider" style="width: 100px;">SKU</th>
                            <th class="px-3 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider" style="width: 100px;"><?php echo t('price_change.barcode'); ?></th>
                            <th class="px-4 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider" style="min-width: 280px;"><?php echo t('price_change.product_name'); ?></th>
                            <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider" style="width: 90px;"><?php echo t('price_change.old_cost_price'); ?></th>
                            <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider" style="width: 90px;"><?php echo t('price_change.new_cost_price'); ?></th>
                            <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider" style="width: 90px;"><?php echo t('price_change.old_selling_price'); ?></th>
                            <th class="px-3 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider" style="width: 90px;"><?php echo t('price_change.new_selling_price'); ?></th>
                            <th class="px-3 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider" style="width: 110px;"><?php echo t('price_change.changed_by'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <?php foreach ($price_changes as $change): ?>
                            <?php $change_time = date('Y-m-d H:i', strtotime($change['changed_at'])); ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="toggleRowSelection(this, event)" data-id="<?php echo $change['id']; ?>" data-changed-time="<?php echo $change_time; ?>">
                                <td class="px-3 py-4 text-center" onclick="event.stopPropagation();">
                                    <input type="checkbox" class="row-checkbox rounded border-gray-300 text-primary-600 focus:ring-primary-500" data-id="<?php echo $change['id']; ?>">
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($change['sku'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap text-center">
                                    <?php if (!empty($change['sku'])): ?>
                                        <svg class="barcode inline-block" data-sku="<?php echo htmlspecialchars($change['sku']); ?>"></svg>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <?php if (!empty($change['product_name_en'])): ?>
                                        <div class="flex items-center mb-1">
                                            <span class="text-xs font-bold text-green-600 mr-2 min-w-0 w-8">ENG</span>
                                            <div class="product-name-en-editable flex-1 text-sm font-medium text-gray-900 hover:bg-gray-100 hover:cursor-pointer rounded px-2 py-1 transition-colors"
                                                 data-product-id="<?php echo $change['product_id']; ?>"
                                                 data-history-id="<?php echo $change['id']; ?>"
                                                 data-original-name="<?php echo htmlspecialchars($change['product_name_en']); ?>"
                                                 title="<?php echo t('price_change.edit_product_name_en'); ?>"><?php echo htmlspecialchars($change['product_name_en']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex items-center">
                                        <span class="text-xs font-bold text-blue-600 mr-2 min-w-0 w-8">KOR</span>
                                        <div class="product-name-editable flex-1 text-<?php echo !empty($change['product_name_en']) ? 'xs' : 'sm'; ?> <?php echo !empty($change['product_name_en']) ? 'text-gray-600' : 'font-medium text-gray-900'; ?> hover:bg-gray-100 hover:cursor-pointer rounded px-2 py-1 transition-colors"
                                             data-product-id="<?php echo $change['product_id']; ?>"
                                             data-history-id="<?php echo $change['id']; ?>"
                                             data-original-name="<?php echo htmlspecialchars($change['product_name_ko'] ?? 'N/A'); ?>"
                                             title="<?php echo t('price_change.edit_product_name'); ?>"><?php echo htmlspecialchars($change['product_name_ko'] ?? 'N/A'); ?></div>
                                    </div>
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap text-sm text-right">
                                    <?php if ($change['old_cost_price']): ?>
                                        <span class="text-gray-900"><?php echo number_format($change['old_cost_price'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap text-sm text-right">
                                    <?php if ($change['new_cost_price']): ?>
                                        <span class="text-green-600 font-semibold"><?php echo number_format($change['new_cost_price'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap text-sm text-right">
                                    <?php if ($change['old_selling_price']): ?>
                                        <span class="text-gray-900"><?php echo number_format($change['old_selling_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap text-sm text-right">
                                    <?php if ($change['new_selling_price']): ?>
                                        <span class="text-blue-600 font-semibold"><?php echo number_format($change['new_selling_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($change['changed_by'] ?? 'N/A'); ?></div>
                                    <div class="time-group-selector text-xs text-blue-500 hover:text-blue-700 hover:underline cursor-pointer select-none" data-time="<?php echo $change_time; ?>" onclick="selectByTime(event, '<?php echo $change_time; ?>')" title="클릭하면 같은 시간대 항목 모두 선택"><?php echo $change_time; ?></div>
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
                <h3 class="text-lg font-bold text-gray-900"><?php echo t('price_change.modal_title'); ?></h3>
                <button onclick="closePriceChangeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- 바코드 입력 영역 -->
            <div class="mb-6">
                <label for="barcodeInput" class="block text-sm font-medium text-gray-700 mb-2">
                    <?php echo t('price_change.barcode_scan'); ?>
                </label>
                <input type="text" id="barcodeInput"
                       class="w-full px-4 py-3 text-lg border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                       placeholder="<?php echo t('price_change.barcode_input_placeholder'); ?>"
                       autocomplete="off">
                <p class="mt-1 text-sm text-gray-500"><?php echo t('price_change.barcode_input_help'); ?></p>
            </div>

            <!-- 로딩 표시 -->
            <div id="loadingIndicator" class="hidden text-center py-4">
                <i class="fas fa-spinner fa-spin text-2xl text-indigo-600"></i>
                <p class="mt-2 text-sm text-gray-600"><?php echo t('price_change.loading_indicator'); ?></p>
            </div>

            <!-- 상품 정보 표시 영역 -->
            <div id="productInfoArea" class="hidden">
                <div class="bg-gray-50 rounded-lg p-4 mb-4">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3"><?php echo t('price_change.product_info'); ?></h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1"><?php echo t('price_change.product_name_en'); ?></label>
                            <p id="productNameEn" class="text-sm font-medium text-gray-900">-</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1"><?php echo t('price_change.product_name_ko'); ?></label>
                            <p id="productNameKo" class="text-sm font-medium text-gray-900">-</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1"><?php echo t('price_change.current_cost_price'); ?></label>
                            <p id="currentCostPrice" class="text-sm font-medium text-gray-900">-</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1"><?php echo t('price_change.current_selling_price'); ?></label>
                            <p id="currentSellingPrice" class="text-sm font-medium text-gray-900">-</p>
                        </div>
                    </div>
                </div>

                <!-- 신규 원가 입력 -->
                <div class="mb-4">
                    <label for="newCostPrice" class="block text-sm font-medium text-gray-700 mb-2">
                        <?php echo t('price_change.new_cost_price_label'); ?>
                        <span class="text-xs text-gray-400 font-normal ml-1">(<?php echo t('common.optional'); ?>)</span>
                    </label>
                    <input type="number" id="newCostPrice"
                           class="w-full px-4 py-3 text-lg border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                           placeholder="<?php echo t('price_change.new_cost_price_placeholder'); ?>"
                           min="0"
                           step="0.01">
                </div>

                <!-- 마진율 입력 -->
                <div class="mb-4">
                    <label for="marginRate" class="block text-sm font-medium text-gray-700 mb-2">
                        <?php echo t('price_change.margin_rate_label'); ?>
                        <span class="text-xs text-gray-400 font-normal ml-1">(<?php echo t('common.optional'); ?>)</span>
                    </label>
                    <input type="number" id="marginRate"
                           class="w-full px-4 py-3 text-lg border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                           placeholder="<?php echo t('price_change.margin_rate_placeholder'); ?>"
                           min="0"
                           step="0.1">
                    <p class="mt-1 text-sm text-gray-500"><?php echo t('price_change.margin_rate_help'); ?></p>
                </div>

                <!-- 신규 판매가 입력 -->
                <div class="mb-4">
                    <label for="newSellingPrice" class="block text-sm font-medium text-gray-700 mb-2">
                        <?php echo t('price_change.new_selling_price_label'); ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="number" id="newSellingPrice"
                           class="w-full px-4 py-3 text-lg border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                           placeholder="<?php echo t('price_change.new_selling_price_placeholder'); ?>"
                           min="0"
                           step="1">
                </div>

                <!-- 저장 버튼 -->
                <div class="flex justify-end space-x-3">
                    <button onclick="closePriceChangeModal()"
                            class="px-4 py-2 bg-gray-300 text-gray-800 text-base font-medium rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-300">
                        <?php echo t('price_change.cancel_btn'); ?>
                    </button>
                    <button id="savePriceChangeBtn" onclick="savePriceChange()"
                            class="px-4 py-2 bg-indigo-600 text-white text-base font-medium rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <?php echo t('price_change.save_btn'); ?>
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

<!-- Bulk Price Change Modal -->
<div id="bulkPriceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-10 mx-auto p-5 border w-11/12 max-w-5xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-gray-900"><?php echo t('price_change.bulk_modal_title'); ?></h3>
                <button onclick="closeBulkPriceModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- 검색 -->
            <div class="mb-4">
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input type="text" id="bulkSearchInput"
                               placeholder="<?php echo t('price_adjustment.search_placeholder'); ?>"
                               class="w-full rounded-md border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                               autocomplete="off">
                        <ul id="bulkSearchPreview" class="hidden absolute z-50 left-0 right-0 top-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-64 overflow-y-auto text-sm"></ul>
                    </div>
                    <button id="bulkSearchBtn" class="inline-flex items-center px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-md hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <i class="fas fa-search mr-2"></i><?php echo t('price_adjustment.search_button'); ?>
                    </button>
                </div>
            </div>

            <!-- 툴바 -->
            <div id="bulkTableToolbar" class="hidden px-4 py-3 border border-gray-200 rounded-md bg-gray-50 mb-2 flex items-center justify-between">
                <span id="bulkResultCount" class="text-sm text-gray-600"></span>
                <button id="bulkSaveAllBtn" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-save mr-2"></i><span id="bulkSaveBtnLabel"><?php echo t('price_adjustment.save_all_button'); ?></span>
                </button>
            </div>

            <!-- 안내 메시지 -->
            <div id="bulkHintArea" class="py-12 text-center text-gray-400">
                <i class="fas fa-search text-3xl mb-3"></i>
                <p class="text-sm"><?php echo t('price_adjustment.search_hint'); ?></p>
            </div>
            <div id="bulkNoResultsArea" class="hidden py-12 text-center text-gray-400">
                <i class="fas fa-box-open text-3xl mb-3"></i>
                <p class="text-sm"><?php echo t('price_adjustment.no_results'); ?></p>
            </div>

            <!-- 결과 테이블 -->
            <div id="bulkResultsArea" class="hidden overflow-x-auto border border-gray-200 rounded-md" style="max-height: 420px; overflow-y: auto;">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200 sticky top-0">
                        <tr>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-28"><?php echo t('price_adjustment.col_sku'); ?></th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"><?php echo t('price_adjustment.col_product_name'); ?></th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-28"><?php echo t('price_adjustment.col_current_cost'); ?></th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-32"><?php echo t('price_adjustment.col_new_cost'); ?></th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-24"><?php echo t('price_adjustment.col_margin'); ?></th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-28"><?php echo t('price_adjustment.col_current_selling'); ?></th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-32"><?php echo t('price_adjustment.col_new_selling'); ?></th>
                            <th class="px-3 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider w-10"></th>
                        </tr>
                    </thead>
                    <tbody id="bulkResultsTbody" class="divide-y divide-gray-100"></tbody>
                </table>
            </div>

            <!-- 저장 결과 요약 -->
            <div id="bulkSaveSummary" class="hidden mt-4 p-4 rounded-md border"></div>
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
            <h3 class="text-lg leading-6 font-medium text-gray-900 mt-4"><?php echo t('price_change.delete_confirm_title'); ?></h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">
                    <?php echo t('price_change.delete_confirm_msg', ['count' => '<span id="deleteCount" class="font-semibold">0</span>']); ?><br>
                    <span class="text-red-600 font-medium"><?php echo t('price_change.delete_confirm_warning'); ?></span>
                </p>
            </div>
            <div class="items-center px-4 py-3">
                <button id="confirmDeleteBtn" onclick="executeDelete()"
                        class="px-4 py-2 bg-red-600 text-white text-base font-medium rounded-md w-24 mr-3 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                    <?php echo t('price_change.delete_btn'); ?>
                </button>
                <button onclick="closeDeleteModal()"
                        class="px-4 py-2 bg-gray-300 text-gray-800 text-base font-medium rounded-md w-24 hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-300">
                    <?php echo t('price_change.cancel_btn'); ?>
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
    'price_change.select_items_prompt',
    'price_change.error_msg_product_not_found',
    'price_change.error_msg_enter_price',
    'price_change.error_msg_enter_cost_price',
    'price_change.error_msg_select_product',
    'price_change.success_msg',
    'price_change.error_msg_failed',
    'price_change.loading_indicator',
    'price_change.product_name_update_success',
    'price_change.product_name_en_update_success',
    'price_change.product_name_update_failed',
    'price_change.server_communication_error',
    'common.items',
    'common.error',
    'common.save',
    'common.cancel'
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

// 같은 시간대 행 전체 선택
function selectByTime(event, time) {
    event.stopPropagation();

    const rows = document.querySelectorAll(`tr[data-changed-time="${time}"]`);
    const rowIds = Array.from(rows).map(r => r.getAttribute('data-id'));

    // 해당 시간대가 이미 모두 선택되어 있으면 해제, 아니면 선택
    const allSelected = rowIds.every(id => selectedIds.includes(id));

    rows.forEach(row => {
        const checkbox = row.querySelector('.row-checkbox');
        const id = row.getAttribute('data-id');

        if (allSelected) {
            checkbox.checked = false;
            row.classList.remove('bg-blue-50');
            selectedIds = selectedIds.filter(s => s !== id);
        } else {
            checkbox.checked = true;
            row.classList.add('bg-blue-50');
            if (!selectedIds.includes(id)) selectedIds.push(id);
        }
    });

    updateSelectedCount();

    // 전체 선택 체크박스 상태 동기화
    const allCheckboxes = document.querySelectorAll('.row-checkbox');
    const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.checked = allCheckboxes.length === checkedBoxes.length && allCheckboxes.length > 0;
        selectAll.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < allCheckboxes.length;
    }
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
        alert(t('price_change.select_items_prompt'));
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
        alert(t('price_change.select_items_prompt'));
        return;
    }

    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

    // 버튼 비활성화 및 로딩 표시
    confirmDeleteBtn.disabled = true;
    confirmDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + t('price_change.delete_btn') + '...';

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
            alert(t('price_change.delete_error') + ': ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Delete error:', error);
        alert(t('price_change.delete_error'));
    })
    .finally(() => {
        // 버튼 상태 복구
        confirmDeleteBtn.disabled = false;
        confirmDeleteBtn.innerHTML = t('price_change.delete_btn');
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
        alert(t('price_change.select_items_to_print'));
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
                    <h2 class="text-lg font-bold">Input Server & Print Price Label</h2>
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
        alert(t('price_change.select_items_to_print'));
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
    container.innerHTML = '<div class="text-center py-8 text-gray-500">' + t('price_change.loading_data') + '</div>';

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
                container.innerHTML = '<div class="text-center py-8 text-red-500">' + t('price_change.loading_failed') + ': ' + (data.message || 'Unknown error') + '</div>';
                return;
            }

        if (data.products.length === 0) {
            container.innerHTML = '<div class="text-center py-8 text-gray-500">' + t('price_change.no_history_desc') + '</div>';
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
            container.innerHTML = '<div class="text-center py-8 text-red-500">' + t('common.error') + ': ' + e.message + '</div>';
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        container.innerHTML = '<div class="text-center py-8 text-red-500">' + t('common.error') + ': ' + error.message + '</div>';
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
    document.getElementById('newCostPrice').value = '';
    document.getElementById('marginRate').value = '';
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

                // 신규 원가/판매가 입력 필드 초기화
                const newCostPriceInput = document.getElementById('newCostPrice');
                newCostPriceInput.value = '';
                newCostPriceInput.dataset.currentRaw = product.cost_price_raw;

                const newPriceInput = document.getElementById('newSellingPrice');
                newPriceInput.value = '';

                // 마진율 입력 필드 초기화
                document.getElementById('marginRate').value = '';

                document.getElementById('productInfoArea').classList.remove('hidden');

                setTimeout(() => {
                    newPriceInput.focus();
                }, 100);
            } else {
                showPriceChangeError(data.message || t('price_change.error_msg_product_not_found'));
                document.getElementById('barcodeInput').select();
            }
        })
        .catch(error => {
            document.getElementById('loadingIndicator').classList.add('hidden');
            console.error('Error:', error);
            showPriceChangeError(t('price_change.loading_failed'));
        });
}

// 가격 변경 저장
function savePriceChange() {
    if (!currentProductId) {
        showPriceChangeError(t('price_change.error_msg_select_product'));
        return;
    }

    const newSellingPrice = document.getElementById('newSellingPrice').value;
    const newCostPriceInput = document.getElementById('newCostPrice');
    const newCostPrice = newCostPriceInput.value;

    if (!newSellingPrice || newSellingPrice <= 0) {
        showPriceChangeError(t('price_change.error_msg_enter_price'));
        return;
    }

    if (newCostPrice !== '' && (isNaN(parseFloat(newCostPrice)) || parseFloat(newCostPrice) < 0)) {
        showPriceChangeError(t('price_change.error_msg_enter_cost_price'));
        return;
    }

    hidePriceChangeError();

    const saveBtn = document.getElementById('savePriceChangeBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + t('common.save') + '...';

    const requestBody = {
        product_id: currentProductId,
        new_selling_price: parseFloat(newSellingPrice)
    };
    if (newCostPrice !== '') {
        requestBody.new_cost_price = parseFloat(newCostPrice);
    }

    fetch('ajax_save_price_change.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(requestBody)
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 성공 메시지
                alert(t('price_change.success_msg'));

                // 모달 닫기
                closePriceChangeModal();

                // 페이지 새로고침하여 리스트 갱신
                window.location.reload();
            } else {
                showPriceChangeError(data.message || t('price_change.error_msg_failed'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showPriceChangeError(t('price_change.error_msg_failed'));
        })
        .finally(() => {
            saveBtn.disabled = false;
            saveBtn.innerHTML = t('common.save');
        });
}

// 원가 + 마진율 -> 신판매가 자동 계산 (소숫점 첫째자리 무조건 올림)
function recalcSellingPriceFromMargin() {
    const cost = parseFloat(document.getElementById('newCostPrice').value);
    const margin = parseFloat(document.getElementById('marginRate').value);

    // 원가와 마진율이 모두 유효할 때만 계산
    if (isNaN(cost) || cost <= 0 || isNaN(margin)) {
        return;
    }

    // 마진율 = (판매가 - 원가) / 원가 * 100  =>  판매가 = 원가 * (1 + 마진율/100)
    const sellingPrice = Math.ceil(cost * (1 + margin / 100));
    document.getElementById('newSellingPrice').value = sellingPrice;
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

    // 신규 원가 / 마진율 입력 시 신판매가 자동 계산
    const newCostPriceInput = document.getElementById('newCostPrice');
    const marginRateInput = document.getElementById('marginRate');

    if (newCostPriceInput) {
        newCostPriceInput.addEventListener('input', recalcSellingPriceFromMargin);
    }
    if (marginRateInput) {
        marginRateInput.addEventListener('input', recalcSellingPriceFromMargin);
        // 마진율 입력 후 Enter 시 저장
        marginRateInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                savePriceChange();
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

// 상품명 인라인 편집 기능 (한글, 영문)
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('product-name-editable') || e.target.classList.contains('product-name-en-editable')) {
        const nameDiv = e.target;
        const currentName = nameDiv.textContent.trim();
        const productId = nameDiv.dataset.productId;
        const historyId = nameDiv.dataset.historyId;
        const isEnglish = nameDiv.classList.contains('product-name-en-editable');

        // 이미 편집 모드인 경우 무시
        if (nameDiv.querySelector('input')) {
            return;
        }

        // 입력 필드 생성
        const input = document.createElement('input');
        input.type = 'text';
        input.value = currentName;
        input.className = 'w-full px-2 py-1 border border-indigo-500 rounded text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500';

        // 원래 내용 숨기기
        nameDiv.innerHTML = '';
        nameDiv.appendChild(input);
        nameDiv.classList.add('editing');

        // 입력 필드에 포커스
        input.focus();
        input.select();

        // 저장 함수
        function saveName() {
            const newName = input.value.trim();
            if (newName === '' || newName === currentName) {
                // 변경사항 없음 또는 빈 값
                cancelEdit();
                return;
            }

            // AJAX로 서버에 전송
            const formData = new FormData();
            formData.append('action', 'update_product_name');
            formData.append('product_id', productId);
            formData.append('history_id', historyId);
            formData.append('new_name', newName);
            formData.append('field_type', isEnglish ? 'en' : 'ko');

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 성공 시 화면 업데이트
                    nameDiv.textContent = newName;
                    nameDiv.dataset.originalName = newName;
                    nameDiv.classList.remove('editing');

                    // 성공 피드백
                    const successMessage = isEnglish ? t('price_change.product_name_en_update_success') : t('price_change.product_name_update_success');
                    showFeedback(successMessage, 'success');
                } else {
                    // 실패 시 원래 값으로 복원
                    cancelEdit();
                    showFeedback(data.error || t('price_change.product_name_update_failed'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                cancelEdit();
                showFeedback(t('price_change.server_communication_error'), 'error');
            });
        }

        // 취소 함수
        function cancelEdit() {
            nameDiv.textContent = currentName;
            nameDiv.classList.remove('editing');
        }

        // 키보드 이벤트
        input.addEventListener('keydown', function(e) {
            e.stopPropagation();
            if (e.key === 'Enter') {
                e.preventDefault();
                saveName();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancelEdit();
            }
        });

        // 포커스 잃을 때 저장
        input.addEventListener('blur', function() {
            setTimeout(saveName, 100); // 약간의 지연을 주어 다른 클릭 이벤트 처리
        });
    }
});

// 피드백 메시지 표시 함수
function showFeedback(message, type = 'success') {
    const feedbackDiv = document.createElement('div');
    feedbackDiv.className = `fixed top-20 right-4 z-50 p-4 rounded-lg shadow-lg transition-all transform ${
        type === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800'
    }`;
    feedbackDiv.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${type === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400'} mr-3"></i>
            <span class="font-medium">${message}</span>
        </div>
    `;

    document.body.appendChild(feedbackDiv);

    // 3초 후 자동 제거
    setTimeout(() => {
        feedbackDiv.style.opacity = '0';
        setTimeout(() => {
            feedbackDiv.remove();
        }, 300);
    }, 3000);
}

// ===== 가격변경 (일괄) 모달 =====
function bulkEscHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function bulkFormatNumber(n) {
    return parseFloat(n || 0).toLocaleString('ko-KR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function bulkCalcMargin(costPrice, sellingPrice) {
    const cost = parseFloat(costPrice) || 0;
    const sell = parseFloat(sellingPrice) || 0;
    if (cost <= 0) return '-';
    const margin = ((sell - cost) / cost * 100);
    const cls = margin >= 0 ? 'text-green-600' : 'text-red-600';
    return `<span class="${cls}">${margin.toFixed(1)}%</span>`;
}

// 행의 유효 원가 (신원가 입력값 우선, 없으면 기존 원가)
function bulkEffectiveCost(row) {
    const v = row.querySelector('.bulk-new-cost').value;
    return v !== '' ? (parseFloat(v) || 0) : (parseFloat(row.dataset.origCost) || 0);
}

// 원가/판매가로 마진율(%) 문자열 계산 (입력 필드용)
function bulkMarginValue(cost, sell) {
    cost = parseFloat(cost) || 0;
    sell = parseFloat(sell) || 0;
    if (cost <= 0) return '';
    return ((sell - cost) / cost * 100).toFixed(1);
}

// 원가 + 마진율 -> 신판매가 자동 계산 (소숫점 첫째자리 무조건 올림)
function bulkRecalcSellingFromMargin(row) {
    const cost = bulkEffectiveCost(row);
    const margin = parseFloat(row.querySelector('.bulk-margin').value);
    if (cost <= 0 || isNaN(margin)) return;
    const selling = Math.ceil(cost * (1 + margin / 100));
    row.querySelector('.bulk-new-selling').value = selling;
}

// 현재 원가/판매가로 마진율 입력 필드 갱신
function bulkRecalcMargin(row) {
    const cost = bulkEffectiveCost(row);
    const sellV = row.querySelector('.bulk-new-selling').value;
    const sell = sellV !== '' ? parseFloat(sellV) : (parseFloat(row.dataset.origSelling) || 0);
    row.querySelector('.bulk-margin').value = bulkMarginValue(cost, sell);
}

function openBulkPriceModal() {
    document.getElementById('bulkPriceModal').classList.remove('hidden');
    document.getElementById('bulkSearchInput').value = '';
    setTimeout(() => document.getElementById('bulkSearchInput').focus(), 100);
}

function closeBulkPriceModal() {
    document.getElementById('bulkPriceModal').classList.add('hidden');
}

function bulkCheckChanged(row) {
    const origCost    = parseFloat(row.dataset.origCost)    || 0;
    const origSelling = parseFloat(row.dataset.origSelling) || 0;
    const costVal    = row.querySelector('.bulk-new-cost').value;
    const sellingVal = row.querySelector('.bulk-new-selling').value;
    const newCost    = costVal    !== '' ? parseFloat(costVal)    : origCost;
    const newSelling = sellingVal !== '' ? parseFloat(sellingVal) : origSelling;
    const changed = (newCost !== origCost) || (newSelling !== origSelling);
    row.dataset.changed = changed ? 'true' : 'false';
    row.classList.toggle('bg-yellow-50', changed);
}


function bulkBuildRow(p) {
    const tr = document.createElement('tr');
    tr.className = 'bulk-product-row hover:bg-gray-50 transition-colors';
    tr.dataset.productId   = p.id;
    tr.dataset.origCost    = p.cost_price || 0;
    tr.dataset.origSelling = p.selling_price || 0;
    tr.dataset.changed     = 'false';

    tr.innerHTML = `
        <td class="px-3 py-3 text-left text-sm font-mono text-gray-700">${bulkEscHtml(p.sku || '')}</td>
        <td class="px-3 py-3">
            <div class="text-sm text-gray-900 font-medium">${bulkEscHtml(p.name_ko || p.name_en || '')}</div>
            ${p.name_en && p.name_ko ? `<div class="text-xs text-gray-400 mt-0.5">${bulkEscHtml(p.name_en)}</div>` : ''}
        </td>
        <td class="px-3 py-3 text-right text-sm text-gray-600">${bulkFormatNumber(p.cost_price)}</td>
        <td class="px-3 py-3 text-right">
            <input type="number" class="bulk-new-cost w-full text-right border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-teal-400"
                placeholder="${bulkFormatNumber(p.cost_price)}" min="0" step="0.01" value="">
        </td>
        <td class="px-3 py-3 text-right">
            <div class="relative">
                <input type="number" class="bulk-margin w-full text-right border border-gray-300 rounded pl-2 pr-5 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-teal-400"
                    placeholder="%" step="0.1" value="${bulkMarginValue(p.cost_price, p.selling_price)}">
                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">%</span>
            </div>
        </td>
        <td class="px-3 py-3 text-right text-sm text-gray-600">${bulkFormatNumber(p.selling_price)}</td>
        <td class="px-3 py-3 text-right">
            <input type="number" class="bulk-new-selling w-full text-right border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-teal-400"
                placeholder="${bulkFormatNumber(p.selling_price)}" min="0" step="0.01" value="">
        </td>
        <td class="px-3 py-3 text-center">
            <button class="bulk-remove-row-btn text-gray-300 hover:text-red-500 transition-colors" title="<?php echo t('price_change.delete_selected'); ?>">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

    tr.querySelector('.bulk-new-cost').addEventListener('input', () => {
        // 마진율이 입력되어 있으면 신원가 기준으로 신판매가 재계산, 아니면 마진율 갱신
        if (tr.querySelector('.bulk-margin').value !== '') {
            bulkRecalcSellingFromMargin(tr);
        } else {
            bulkRecalcMargin(tr);
        }
        bulkCheckChanged(tr);
    });
    tr.querySelector('.bulk-new-selling').addEventListener('input', () => {
        // 판매가 직접 입력 시 마진율 역산
        bulkRecalcMargin(tr);
        bulkCheckChanged(tr);
    });
    tr.querySelector('.bulk-margin').addEventListener('input', () => {
        // 마진율 입력 시 신판매가 자동 계산 (올림)
        bulkRecalcSellingFromMargin(tr);
        bulkCheckChanged(tr);
    });
    tr.querySelector('.bulk-remove-row-btn').addEventListener('click', () => {
        tr.remove();
        bulkUpdateRowCount();
    });

    return tr;
}

function bulkAddProductToList(p) {
    const existing = document.querySelector(`.bulk-product-row[data-product-id="${p.id}"]`);
    if (existing) {
        existing.classList.add('ring-2', 'ring-inset', 'ring-teal-400');
        existing.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(() => existing.classList.remove('ring-2', 'ring-inset', 'ring-teal-400'), 1500);
        return;
    }
    document.getElementById('bulkHintArea').classList.add('hidden');
    document.getElementById('bulkNoResultsArea').classList.add('hidden');
    document.getElementById('bulkResultsArea').classList.remove('hidden');
    document.getElementById('bulkTableToolbar').classList.remove('hidden');
    document.getElementById('bulkResultsTbody').appendChild(bulkBuildRow(p));
    bulkUpdateRowCount();
}

function bulkUpdateRowCount() {
    const count = document.querySelectorAll('.bulk-product-row').length;
    document.getElementById('bulkResultCount').textContent = `<?php echo t('price_change.selected_items'); ?>: ${count}<?php echo t('common.items'); ?>`;
    if (count === 0) {
        document.getElementById('bulkResultsArea').classList.add('hidden');
        document.getElementById('bulkTableToolbar').classList.add('hidden');
        document.getElementById('bulkHintArea').classList.remove('hidden');
    }
}

async function bulkDoSearch() {
    const term = bulkSearchInput.value.trim();
    if (!term) return;

    const searchBtn = document.getElementById('bulkSearchBtn');
    const searchLabel = '<?php echo t("price_adjustment.search_button"); ?>';
    searchBtn.disabled = true;
    searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + searchLabel;

    try {
        const resp = await fetch(`ajax_search_products.php?term=${encodeURIComponent(term)}&limit=10`);
        const data = await resp.json();
        const products = Array.isArray(data) ? data : (data.products || []);

        if (products.length === 0) {
            document.getElementById('bulkNoResultsArea').classList.remove('hidden');
            setTimeout(() => document.getElementById('bulkNoResultsArea').classList.add('hidden'), 2000);
        } else {
            products.forEach(p => bulkAddProductToList(p));
        }

        bulkSearchInput.value = '';
        bulkClosePreview();
    } catch (e) {
        alert('<?php echo t("price_change.server_communication_error"); ?>');
    } finally {
        searchBtn.disabled = false;
        searchBtn.innerHTML = '<i class="fas fa-search mr-2"></i>' + searchLabel;
    }
}

async function bulkSaveAll() {
    const rows = [...document.querySelectorAll('.bulk-product-row[data-changed="true"]')];

    if (rows.length === 0) {
        bulkShowSummary('warning', '<?php echo t("price_adjustment.no_changes"); ?>');
        return;
    }

    const saveBtn = document.getElementById('bulkSaveAllBtn');
    const saveLabel = document.getElementById('bulkSaveBtnLabel');
    const originalLabel = saveLabel.textContent;
    saveBtn.disabled = true;
    saveLabel.textContent = '<?php echo t("price_adjustment.saving"); ?>';

    let successCount = 0;
    const errors = [];

    for (const row of rows) {
        const productId  = row.dataset.productId;
        const costVal    = row.querySelector('.bulk-new-cost').value;
        const sellingVal = row.querySelector('.bulk-new-selling').value;
        const newCost    = costVal    !== '' ? parseFloat(costVal)    : parseFloat(row.dataset.origCost)    || 0;
        const newSelling = sellingVal !== '' ? parseFloat(sellingVal) : parseFloat(row.dataset.origSelling) || 0;

        try {
            const resp = await fetch('ajax_save_price_change.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    product_id:        parseInt(productId),
                    new_cost_price:    newCost,
                    new_selling_price: newSelling,
                    skip_event: true
                })
            });
            const result = await resp.json();

            if (result.success) {
                successCount++;
                row.dataset.changed = 'false';
                row.classList.remove('bg-yellow-50');
                setTimeout(() => { row.remove(); bulkUpdateRowCount(); }, 800);
            } else {
                row.classList.add('bg-red-50');
                errors.push(result.message || '<?php echo t("price_change.error_msg_failed"); ?>');
            }
        } catch (e) {
            row.classList.add('bg-red-50');
            errors.push('<?php echo t("price_change.server_communication_error"); ?>');
        }
    }

    saveBtn.disabled = false;
    saveLabel.textContent = originalLabel;

    if (errors.length === 0) {
        const msg = '<?php echo t("price_adjustment.save_success_count"); ?>'.replace('{n}', successCount);
        bulkShowSummary('success', msg);
        // 가격변경 이력 갱신을 위해 새로고침
        setTimeout(() => window.location.reload(), 1000);
    } else {
        const msg = `${successCount} / ${rows.length} - ${errors.join(', ')}`;
        bulkShowSummary('error', msg);
    }
}

function bulkShowSummary(type, msg) {
    const el = document.getElementById('bulkSaveSummary');
    el.classList.remove('hidden', 'bg-green-50', 'border-green-200', 'text-green-800',
                                  'bg-red-50', 'border-red-200', 'text-red-800',
                                  'bg-yellow-50', 'border-yellow-200', 'text-yellow-800');
    if (type === 'success') {
        el.classList.add('bg-green-50', 'border-green-200', 'text-green-800');
        el.innerHTML = `<i class="fas fa-check-circle mr-2"></i>${bulkEscHtml(msg)}`;
    } else if (type === 'error') {
        el.classList.add('bg-red-50', 'border-red-200', 'text-red-800');
        el.innerHTML = `<i class="fas fa-exclamation-circle mr-2"></i>${bulkEscHtml(msg)}`;
    } else {
        el.classList.add('bg-yellow-50', 'border-yellow-200', 'text-yellow-800');
        el.innerHTML = `<i class="fas fa-info-circle mr-2"></i>${bulkEscHtml(msg)}`;
    }
}

// 검색 미리보기
let bulkPreviewTimer = null;
let bulkPreviewActive = -1;
let bulkPreviewProducts = [];

const bulkSearchInput = document.getElementById('bulkSearchInput');
const bulkPreviewList = document.getElementById('bulkSearchPreview');

function bulkClosePreview() {
    bulkPreviewList.classList.add('hidden');
    bulkPreviewList.innerHTML = '';
    bulkPreviewActive = -1;
    bulkPreviewProducts = [];
}

function bulkBuildPreview(products) {
    bulkPreviewProducts = products;
    bulkPreviewList.innerHTML = '';
    if (products.length === 0) { bulkClosePreview(); return; }

    products.slice(0, 8).forEach((p, idx) => {
        const li = document.createElement('li');
        li.className = 'flex items-center gap-2 px-4 py-2 cursor-pointer hover:bg-teal-50 transition-colors';
        li.dataset.idx = idx;
        li.innerHTML = `
            <span class="text-xs text-gray-400 font-mono w-20 shrink-0">
                <i class="fas fa-barcode mr-1"></i>${bulkEscHtml(p.sku || '')}
            </span>
            <span class="truncate">
                <span class="text-gray-800">${bulkEscHtml(p.name_ko || p.name_en || '')}</span>
                ${p.name_en && p.name_ko ? `<span class="text-gray-400 text-xs ml-1">${bulkEscHtml(p.name_en)}</span>` : ''}
            </span>
        `;
        li.addEventListener('mousedown', e => {
            e.preventDefault();
            bulkAddProductToList(p);
            bulkSearchInput.value = '';
            bulkClosePreview();
        });
        bulkPreviewList.appendChild(li);
    });
    bulkPreviewList.classList.remove('hidden');
    bulkPreviewActive = -1;
}

function bulkHighlightPreview(idx) {
    const items = bulkPreviewList.querySelectorAll('li');
    items.forEach(li => li.classList.remove('bg-teal-50'));
    if (idx >= 0 && idx < items.length) {
        items[idx].classList.add('bg-teal-50');
        bulkPreviewActive = idx;
    }
}

bulkSearchInput.addEventListener('input', () => {
    clearTimeout(bulkPreviewTimer);
    const term = bulkSearchInput.value.trim();
    if (term.length < 1) { bulkClosePreview(); return; }
    bulkPreviewTimer = setTimeout(async () => {
        try {
            const resp = await fetch(`ajax_search_products.php?term=${encodeURIComponent(term)}&limit=8`);
            const data = await resp.json();
            const products = Array.isArray(data) ? data : (data.products || []);
            bulkBuildPreview(products);
        } catch (_) { bulkClosePreview(); }
    }, 200);
});

bulkSearchInput.addEventListener('keydown', e => {
    const items = bulkPreviewList.querySelectorAll('li');
    if (!bulkPreviewList.classList.contains('hidden') && items.length > 0) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            bulkHighlightPreview(Math.min(bulkPreviewActive + 1, items.length - 1));
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            bulkHighlightPreview(Math.max(bulkPreviewActive - 1, 0));
            return;
        }
        if (e.key === 'Enter' && bulkPreviewActive >= 0) {
            e.preventDefault();
            bulkAddProductToList(bulkPreviewProducts[bulkPreviewActive]);
            bulkSearchInput.value = '';
            bulkClosePreview();
            return;
        }
        if (e.key === 'Escape') { bulkClosePreview(); return; }
    }
    if (e.key === 'Enter') bulkDoSearch();
});

bulkSearchInput.addEventListener('blur', () => setTimeout(bulkClosePreview, 150));

document.getElementById('bulkSearchBtn').addEventListener('click', bulkDoSearch);
document.getElementById('bulkSaveAllBtn').addEventListener('click', bulkSaveAll);

document.getElementById('bulkPriceModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeBulkPriceModal();
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>