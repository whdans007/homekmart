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

        // 점포별 필터링 (super_admin이 아닌 경우)
        if ($_SESSION['role'] !== 'super_admin' && !empty($current_store_id)) {
            $where_conditions[] = "(pch.store_id = ? OR pch.store_id IS NULL)";
            $params[] = $current_store_id;
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // 가격변경 이력 조회 (페이징 없이 모든 레코드)
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

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo t('price_change.history'); ?></h1>
            <p class="text-sm text-gray-600 mt-1"><?php echo str_replace('{store}', htmlspecialchars($current_store_name), t('price_change.store_info')); ?></p>
            <?php if (!$error_message && isset($total_records)): ?>
                <p class="text-sm text-gray-600">
                    <?php echo format_date($selected_date, 'long'); ?> - 
                    <?php echo str_replace('{count}', number_format($total_records), t('price_change.total_records')); ?>
                </p>
            <?php endif; ?>
            <p class="text-sm text-gray-500 mt-1" id="selectedCount" style="display:none;">
                <?php echo t('price_change.selected_items'); ?>: <span class="font-semibold">0</span><?php echo t('common.items'); ?>
            </p>
        </div>
    </div>

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
                           onchange="window.location.href='?date=' + this.value">
                    <span class="text-sm text-gray-600">
                        <?php 
                        $day_names = ['일', '월', '화', '수', '목', '금', '토'];
                        $day_of_week = $day_names[date('w', strtotime($selected_date))];
                        echo "({$day_of_week}요일)";
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
            
            <div class="flex items-center space-x-2">
                <button id="deleteSelectedBtn" onclick="deleteSelected()" style="display:none;" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    <i class="fas fa-trash mr-2"></i>
                    <?php echo t('price_change.delete_selected'); ?>
                </button>
                <button id="printSelectedBtn" onclick="openSelectedPrintModal()" style="display:none;" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <i class="fas fa-print mr-2"></i>
                    <?php echo t('price_change.print_selected'); ?>
                </button>
                <button onclick="openPrintModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    <i class="fas fa-print mr-2"></i>
                    <?php echo t('common.print_preview'); ?>
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
        <div class="bg-white shadow-lg rounded-lg overflow-hidden border border-gray-300">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 border-collapse border border-gray-300">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-3 text-center border border-gray-300" style="width: 40px;">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">SKU</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.product_name'); ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.old_cost_price'); ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.new_cost_price'); ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.old_selling_price'); ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.new_selling_price'); ?></th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.change_type_col'); ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.changed_by'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($price_changes as $change): ?>
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="toggleRowSelection(this, event)" data-id="<?php echo $change['id']; ?>">
                                <td class="px-3 py-3 text-center border border-gray-300" onclick="event.stopPropagation();">
                                    <input type="checkbox" class="row-checkbox rounded border-gray-300 text-primary-600 focus:ring-primary-500" data-id="<?php echo $change['id']; ?>">
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 border border-gray-300">
                                    <?php echo htmlspecialchars($change['sku'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap border border-gray-300">
                                    <?php if (!empty($change['product_name_en'])): ?>
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($change['product_name_en']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($change['product_name_ko'] ?? 'N/A'); ?></div>
                                    <?php else: ?>
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($change['product_name_ko'] ?? 'N/A'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right border border-gray-300">
                                    <?php if ($change['old_cost_price']): ?>
                                        <span class="text-gray-900"><?php echo number_format($change['old_cost_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right border border-gray-300">
                                    <?php if ($change['new_cost_price']): ?>
                                        <span class="text-green-600 font-semibold"><?php echo number_format($change['new_cost_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right border border-gray-300">
                                    <?php if ($change['old_selling_price']): ?>
                                        <span class="text-gray-900"><?php echo number_format($change['old_selling_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right border border-gray-300">
                                    <?php if ($change['new_selling_price']): ?>
                                        <span class="text-blue-600 font-semibold"><?php echo number_format($change['new_selling_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-center border border-gray-300">
                                    <?php
                                    $type_colors = [
                                        'both' => 'bg-purple-100 text-purple-800',
                                        'cost_only' => 'bg-green-100 text-green-800',
                                        'selling_only' => 'bg-blue-100 text-blue-800',
                                        'margin_adjust' => 'bg-orange-100 text-orange-800'
                                    ];
                                    $type_labels = [
                                        'both' => t('price_change.both_price'),
                                        'cost_only' => t('price_change.cost_only'),
                                        'selling_only' => t('price_change.selling_only'),
                                        'margin_adjust' => t('price_change.margin_adjust')
                                    ];
                                    $color_class = $type_colors[$change['change_type']] ?? 'bg-gray-100 text-gray-800';
                                    $label = $type_labels[$change['change_type']] ?? $change['change_type'];
                                    ?>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $color_class; ?>">
                                        <?php echo $label; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap border border-gray-300">
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
    <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-6xl shadow-lg rounded-md bg-white">
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
                    <i class="fas fa-print"></i> 인쇄
                </button>
            </div>
            
            <!-- 인쇄 내용 -->
            <div id="modalPrintContent" class="border rounded-lg p-4 bg-white" style="max-height: 500px; overflow-y: auto;">
                <div class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-2xl text-gray-400"></i>
                    <p class="mt-2 text-gray-600">데이터 로딩 중...</p>
                </div>
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

// 전체 선택/해제
document.addEventListener('DOMContentLoaded', function() {
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
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    const printSelectedBtn = document.getElementById('printSelectedBtn');
    
    if (selectedIds.length > 0) {
        countElement.style.display = 'block';
        countElement.querySelector('span').textContent = selectedIds.length;
        deleteBtn.style.display = 'inline-flex';
        printSelectedBtn.style.display = 'inline-flex';
    } else {
        countElement.style.display = 'none';
        deleteBtn.style.display = 'none';
        printSelectedBtn.style.display = 'none';
    }
}

// 선택된 항목 삭제
function deleteSelected() {
    if (selectedIds.length === 0) {
        alert(t('price_change.select_items_to_print'));
        return;
    }
    
    if (!confirm(t('price_change.confirm_delete_selected', {count: selectedIds.length}))) {
        return;
    }
    
    // AJAX로 삭제 요청
    fetch('ajax_delete_price_changes.php', {
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
            alert(data.message || t('price_change.delete_success'));
            location.reload();
        } else {
            alert(data.message || t('price_change.delete_error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert(t('price_change.delete_error'));
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
    container.innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-gray-400"></i><p class="mt-2 text-gray-600">데이터 로딩 중...</p></div>';
    
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
        })
        .catch(error => {
            container.innerHTML = '<div class="text-center py-8 text-red-600">데이터 로딩 실패: ' + error.message + '</div>';
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
            // 인쇄용 헤더 추가 (선택된 항목 표시)
            const printHeader = `
                <div class="text-center mb-6 pb-4 border-b-2 border-gray-800">
                    <h2 class="text-lg font-bold">Price Change History (Selected Items)</h2>
                    <p class="text-sm text-gray-600">Date: ${date} | Selected Items: ${selectedIds.length} | Print Time: ${new Date().toLocaleString('en-US')}</p>
                </div>
            `;
            container.innerHTML = printHeader + html;
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
                @media print {
                    body { margin: 0; }
                    table { font-size: 10px; }
                    th, td { padding: 4px; }
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

// 모달 외부 클릭시 닫기
document.getElementById('printModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePrintModal();
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>