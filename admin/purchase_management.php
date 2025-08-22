<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('purchase.list') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';


// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();

// 검색 필터 변수
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$supplier_filter = $_GET['supplier_id'] ?? '';

// 페이지네이션 변수
$current_page = max(1, (int)($_GET['page'] ?? 1));
$items_per_page = 20;
$offset = ($current_page - 1) * $items_per_page;

// 거래처 목록 가져오기 (필터용)
$suppliers_sql = "SELECT id, name FROM suppliers ORDER BY name";
$suppliers_result = $conn->query($suppliers_sql);

// 검색 조건 구성
$where_conditions = [];
$params = [];
$param_types = '';

// deleted_at 컬럼이 존재하는지 확인
$check_deleted_at_column = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
$has_deleted_at = $check_deleted_at_column->num_rows > 0;

// 삭제되지 않은 매입 내역만 조회 (soft delete 적용)
if ($has_deleted_at) {
    $where_conditions[] = "p.deleted_at IS NULL";
}

if (!empty($start_date)) {
    $where_conditions[] = "p.purchase_date >= ?";
    $params[] = $start_date;
    $param_types .= 's';
}

if (!empty($end_date)) {
    $where_conditions[] = "p.purchase_date <= ?";
    $params[] = $end_date;
    $param_types .= 's';
}

if (!empty($supplier_filter)) {
    $where_conditions[] = "p.supplier_id = ?";
    $params[] = $supplier_filter;
    $param_types .= 'i';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
}

// 총 개수 조회
$count_sql = "SELECT COUNT(*) as total_count FROM purchases p JOIN suppliers s ON p.supplier_id = s.id {$where_clause}";

if (!empty($params)) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
} else {
    $count_result = $conn->query($count_sql);
}

$total_count = 0;
if ($count_result && $count_result->num_rows > 0) {
    $count_row = $count_result->fetch_assoc();
    $total_count = $count_row['total_count'];
}

// 총 페이지 수 계산
$total_pages = max(1, ceil($total_count / $items_per_page));

// 현재 페이지가 총 페이지를 벗어나면 조정
if ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $items_per_page;
}

// 매입 리스트 조회 (총 입고수량 낱개 환산 포함 + 점포 정보)
$sql = "SELECT 
    p.purchase_id, 
    p.purchase_date, 
    p.created_at,
    CONCAT(
        DATE_FORMAT(p.purchase_date, '%Y-%m-%d'),
        CASE 
            WHEN p.created_at IS NOT NULL THEN CONCAT(' ', TIME_FORMAT(p.created_at, '%H:%i'))
            ELSE ''
        END
    ) AS purchase_datetime,
    s.name AS supplier_name, 
    p.total_items, 
    p.total_amount,
    (
        SELECT SUM(
            CASE 
                WHEN pi.purchase_type = 'box' THEN pi.quantity * COALESCE(pr.pieces_per_box, 1)
                ELSE pi.quantity
            END
        ) 
        FROM purchase_items pi 
        JOIN products pr ON pi.product_id = pr.id 
        WHERE pi.purchase_id = p.purchase_id
    ) AS total_pieces
FROM purchases p
JOIN suppliers s ON p.supplier_id = s.id
{$where_clause}
ORDER BY p.purchase_date DESC, p.purchase_id DESC
LIMIT {$items_per_page} OFFSET {$offset}";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

// Check for query errors
if (!$result) {
    die("SQL Error: " . $conn->error);
}
?>

<style>
/* 반응형 테이블 스타일 */
.responsive-table {
    overflow-x: auto;
}

.table-compact td, .table-compact th {
    padding: 0.5rem 0.75rem !important;
    font-size: 0.875rem;
}

/* 컬럼 토글 관련 스타일 */
.column-toggle-btn {
    @apply bg-gray-100 hover:bg-gray-200 px-3 py-1 rounded text-xs border;
}

.column-toggle-btn.active {
    @apply bg-primary-100 text-primary-700 border-primary-300;
}

/* 데스크톱 (1024px 이상) */
@media (min-width: 1024px) {
    .desktop-only { display: table-cell !important; }
    .tablet-hidden { display: table-cell !important; }
    .mobile-hidden { display: table-cell !important; }
}

/* 태블릿 (768px - 1023px) */
@media (min-width: 768px) and (max-width: 1023px) {
    .tablet-hidden { display: none !important; }
    .mobile-hidden { display: table-cell !important; }
    .desktop-only { display: table-cell !important; }
}

/* 모바일 (767px 이하) */
@media (max-width: 767px) {
    .mobile-hidden { display: none !important; }
    .tablet-hidden { display: none !important; }
    .desktop-only { display: none !important; }
    
    /* 모바일 카드 레이아웃 */
    .mobile-card {
        @apply block bg-white border rounded-lg mb-4 p-4 shadow-sm;
    }
    
    .mobile-card-row {
        @apply flex justify-between items-center py-1 border-b border-gray-100 last:border-b-0;
    }
    
    .mobile-card-label {
        @apply text-sm font-medium text-gray-600;
    }
    
    .mobile-card-value {
        @apply text-sm text-gray-900 font-medium;
    }
}

/* 컬럼 우선순위별 표시 */
.priority-high { /* 필수 컬럼 - 항상 표시 */ }
.priority-medium { /* 중요 컬럼 - 태블릿에서 숨김 */ }
.priority-low { /* 부가 컬럼 - 모바일에서 숨김 */ }

/* 페이지네이션 스타일 */
.pagination-container {
    @apply flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6;
}
</style>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-900"><?php echo t('purchase.list'); ?></h1>
        <div class="flex space-x-3">
            <?php if (!$has_deleted_at): ?>
            <a href="setup_soft_delete_purchases.php" class="inline-flex items-center justify-center rounded-md border border-yellow-300 bg-yellow-50 px-4 py-2 text-sm font-medium text-yellow-700 shadow-sm hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                <i class="fas fa-database mr-2"></i> <?php echo t('purchase.soft_delete_setup'); ?>
            </a>
            <?php endif; ?>
            <a href="add_purchase.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                <i class="fas fa-plus mr-2"></i> <?php echo t('purchase.new_purchase'); ?>
            </a>
        </div>
    </div>

    <!-- 검색 필터 폼 -->
    <div class="bg-white shadow rounded-lg mb-6 p-6">
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('purchase.start_date'); ?></label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('purchase.end_date'); ?></label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                </div>
                <div>
                    <label for="supplier_id" class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('purchase.supplier'); ?></label>
                    <select id="supplier_id" name="supplier_id" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                        <option value=""><?php echo t('purchase.all_suppliers'); ?></option>
                        <?php if ($suppliers_result && $suppliers_result->num_rows > 0): ?>
                            <?php $suppliers_result->data_seek(0); // 결과 포인터 리셋 ?>
                            <?php while ($supplier = $suppliers_result->fetch_assoc()): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo ($supplier_filter == $supplier['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="flex-1 bg-primary-600 text-white px-4 py-2 rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                        <i class="fas fa-search mr-2"></i><?php echo t('purchase.search'); ?>
                    </button>
                    <a href="purchase_management.php" class="flex-1 bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 text-center">
                        <i class="fas fa-redo mr-2"></i><?php echo t('purchase.reset'); ?>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- 컬럼 토글 컨트롤 (데스크톱만) -->
    <div class="hidden md:block bg-white shadow rounded-lg mb-4 p-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-medium text-gray-700 mr-3"><?php echo t('common.show_columns'); ?>:</span>
            <button class="column-toggle-btn active" data-column="number"><?php echo t('purchase.number'); ?></button>
            <button class="column-toggle-btn active" data-column="purchase_id"><?php echo t('purchase.purchase_id'); ?></button>
            <button class="column-toggle-btn active" data-column="datetime"><?php echo t('purchase.date_time'); ?></button>
            <button class="column-toggle-btn active" data-column="supplier"><?php echo t('purchase.supplier'); ?></button>
            <button class="column-toggle-btn tablet-hidden" data-column="total_items"><?php echo t('purchase.total_items'); ?></button>
            <button class="column-toggle-btn tablet-hidden" data-column="total_pieces"><?php echo t('purchase.total_pieces'); ?></button>
            <button class="column-toggle-btn active" data-column="amount"><?php echo t('purchase.purchase_amount'); ?></button>
            <button class="column-toggle-btn active" data-column="actions"><?php echo t('common.actions'); ?></button>
        </div>
    </div>

    <!-- 모바일 카드 뷰 -->
    <div class="block md:hidden">
        <?php if ($result && $result->num_rows > 0): ?>
            <?php $result->data_seek(0); // 결과 포인터 리셋 ?>
            <?php $row_number = 1; ?>
            <?php while($row = $result->fetch_assoc()): ?>
                <div class="mobile-card" data-purchase-id="<?php echo $row['purchase_id']; ?>">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">
                                #<?php echo htmlspecialchars($row['purchase_id']); ?>
                            </h3>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($row['supplier_name']); ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-primary-600"><?php echo number_format($row['total_amount'], 2); ?>원</p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($row['purchase_datetime']); ?></p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-3">
                        <div class="mobile-card-row">
                            <span class="mobile-card-label"><?php echo t('purchase.total_items'); ?></span>
                            <span class="mobile-card-value"><?php echo htmlspecialchars($row['total_items']); ?>개</span>
                        </div>
                        <div class="mobile-card-row">
                            <span class="mobile-card-label"><?php echo t('purchase.total_pieces'); ?></span>
                            <span class="mobile-card-value"><?php echo number_format($row['total_pieces'] ?? 0); ?>개</span>
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-2 pt-3 border-t border-gray-100">
                        <a href="edit_purchase.php?id=<?php echo $row['purchase_id']; ?>" 
                           class="inline-flex items-center px-3 py-2 text-sm font-medium text-indigo-600 bg-indigo-50 rounded-md hover:bg-indigo-100">
                            <i class="fas fa-eye mr-1"></i>
                            <?php echo t('purchase.detail_view'); ?>
                        </a>
                        <a href="purchase_price_change.php?purchase_id=<?php echo $row['purchase_id']; ?>" 
                           class="inline-flex items-center px-3 py-2 text-sm font-medium text-green-600 bg-green-50 rounded-md hover:bg-green-100">
                            <i class="fas fa-edit mr-1"></i>
                            <?php echo t('purchase.price_change'); ?>
                        </a>
                    </div>
                </div>
                <?php $row_number++; ?>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="text-center py-12">
                <div class="text-gray-500">
                    <i class="fas fa-dolly-flatbed text-4xl mb-4"></i>
                    <p class="text-lg"><?php echo t('purchase.no_purchases'); ?></p>
                    <p class="text-sm mt-2"><?php echo t('purchase.add_first_purchase'); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 데스크톱 테이블 뷰 -->
    <div class="hidden md:block bg-white shadow-lg rounded-lg overflow-hidden border border-gray-300">
        <div class="responsive-table">
            <table class="min-w-full divide-y divide-gray-200 border-collapse border border-gray-300 table-compact" id="purchaseTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 priority-high" data-column="number"><?php echo t('purchase.number'); ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 priority-high" data-column="purchase_id"><?php echo t('purchase.purchase_id'); ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 priority-high" data-column="datetime"><?php echo t('purchase.date_time'); ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 priority-high mobile-hidden" data-column="supplier"><?php echo t('purchase.supplier'); ?></th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 priority-medium tablet-hidden" data-column="total_items"><?php echo t('purchase.total_items'); ?></th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 priority-medium tablet-hidden" data-column="total_pieces"><?php echo t('purchase.total_pieces'); ?></th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 priority-high" data-column="amount"><?php echo t('purchase.purchase_amount'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300 priority-high" data-column="actions"><?php echo t('common.actions'); ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php $row_number = 1; ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50" data-purchase-id="<?php echo $row['purchase_id']; ?>">
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-center border border-gray-300 priority-high" data-column="number"><?php echo $row_number++; ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 border border-gray-300 priority-high" data-column="purchase_id"><?php echo htmlspecialchars($row['purchase_id']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 border border-gray-300 priority-high" data-column="datetime"><?php echo htmlspecialchars($row['purchase_datetime']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 border border-gray-300 priority-high mobile-hidden" data-column="supplier"><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-right border border-gray-300 priority-medium tablet-hidden" data-column="total_items"><?php echo htmlspecialchars($row['total_items']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-right border border-gray-300 priority-medium tablet-hidden" data-column="total_pieces">
                                    <span class="font-medium"><?php echo number_format($row['total_pieces'] ?? 0); ?></span>
                                    <span class="text-xs text-gray-400 ml-1"><?php echo t('purchase.pieces'); ?></span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-right border border-gray-300 priority-high" data-column="amount"><?php echo number_format($row['total_amount'], 2); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-center text-sm font-medium border border-gray-300 priority-high" data-column="actions">
                                    <div class="flex justify-center space-x-2">
                                        <a href="edit_purchase.php?id=<?php echo $row['purchase_id']; ?>" class="text-indigo-600 hover:text-indigo-900 text-xs" title="<?php echo t('purchase.detail_view'); ?>">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="purchase_price_change.php?purchase_id=<?php echo $row['purchase_id']; ?>" class="text-green-600 hover:text-green-900 text-xs" title="<?php echo t('purchase.price_change'); ?>">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-300">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-dolly-flatbed text-4xl text-gray-400"></i>
                                    <p class="mt-4"><?php echo t('purchase.no_purchases'); ?></p>
                                    <p class="text-xs text-gray-400"><?php echo t('purchase.add_first_purchase'); ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 페이지네이션 -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    <?php 
                    $start_item = ($current_page - 1) * $items_per_page + 1;
                    $end_item = min($current_page * $items_per_page, $total_count);
                    echo sprintf(
                        t('purchase.showing_results'),
                        number_format($start_item),
                        number_format($end_item),
                        number_format($total_count)
                    );
                    ?>
                </div>
                
                <div class="flex items-center space-x-2">
                    <!-- 이전 페이지 -->
                    <?php if ($current_page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>" 
                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <i class="fas fa-chevron-left mr-1"></i>
                            <?php echo t('common.previous'); ?>
                        </a>
                    <?php endif; ?>
                    
                    <!-- 페이지 번호 -->
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    // 첫 페이지
                    if ($start_page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" 
                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">1</a>
                        <?php if ($start_page > 2): ?>
                            <span class="px-2 py-2 text-sm text-gray-500">...</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- 현재 페이지 주변 -->
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="px-3 py-2 text-sm font-medium text-white bg-primary-600 border border-primary-600 rounded-md">
                                <?php echo $i; ?>
                            </span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <!-- 마지막 페이지 -->
                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <span class="px-2 py-2 text-sm text-gray-500">...</span>
                        <?php endif; ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" 
                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"><?php echo $total_pages; ?></a>
                    <?php endif; ?>
                    
                    <!-- 다음 페이지 -->
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" 
                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <?php echo t('common.next'); ?>
                            <i class="fas fa-chevron-right ml-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('purchaseTable');
    const toggleButtons = document.querySelectorAll('.column-toggle-btn');
    const STORAGE_KEY = 'purchase_table_columns';
    
    // 저장된 컬럼 설정 로드
    function loadColumnSettings() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            try {
                return JSON.parse(saved);
            } catch (e) {
                console.error('컬럼 설정 로드 오류:', e);
            }
        }
        // 기본 설정 (모든 컬럼 표시)
        return {
            number: true,
            purchase_id: true,
            datetime: true,
            supplier: true,
            total_items: true,
            total_pieces: true,
            amount: true,
            actions: true
        };
    }
    
    // 컬럼 설정 저장
    function saveColumnSettings(settings) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    }
    
    // 컬럼 표시/숨김 토글
    function toggleColumn(columnName, show) {
        const headers = table.querySelectorAll(`th[data-column="${columnName}"]`);
        const cells = table.querySelectorAll(`td[data-column="${columnName}"]`);
        
        headers.forEach(header => {
            header.style.display = show ? '' : 'none';
        });
        
        cells.forEach(cell => {
            cell.style.display = show ? '' : 'none';
        });
    }
    
    // 토글 버튼 상태 업데이트
    function updateToggleButton(columnName, show) {
        const button = document.querySelector(`button[data-column="${columnName}"]`);
        if (button) {
            if (show) {
                button.classList.add('active');
                button.classList.remove('bg-gray-100');
                button.classList.add('bg-primary-100', 'text-primary-700', 'border-primary-300');
            } else {
                button.classList.remove('active');
                button.classList.remove('bg-primary-100', 'text-primary-700', 'border-primary-300');
                button.classList.add('bg-gray-100');
            }
        }
    }
    
    // 초기 설정 적용
    function applyInitialSettings() {
        const settings = loadColumnSettings();
        
        Object.keys(settings).forEach(columnName => {
            const show = settings[columnName];
            toggleColumn(columnName, show);
            updateToggleButton(columnName, show);
        });
    }
    
    // 토글 버튼 이벤트 리스너 등록
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const columnName = this.dataset.column;
            const isCurrentlyVisible = this.classList.contains('active');
            const newVisibility = !isCurrentlyVisible;
            
            // 최소 하나의 컬럼은 보이도록 체크
            if (!newVisibility) {
                const visibleColumns = Array.from(toggleButtons).filter(btn => 
                    btn.classList.contains('active') && btn !== this
                ).length;
                
                if (visibleColumns === 0) {
                    alert('<?php echo t("purchase.at_least_one_column"); ?>');
                    return;
                }
            }
            
            // 컬럼 토글
            toggleColumn(columnName, newVisibility);
            updateToggleButton(columnName, newVisibility);
            
            // 설정 저장
            const currentSettings = loadColumnSettings();
            currentSettings[columnName] = newVisibility;
            saveColumnSettings(currentSettings);
        });
    });
    
    // 초기 설정 적용
    applyInitialSettings();
    
    // 테이블 반응형 처리
    function handleResponsiveTable() {
        const viewportWidth = window.innerWidth;
        
        // 모바일 모드 (767px 이하)
        if (viewportWidth <= 767) {
            // 중요하지 않은 컬럼 자동 숨김
            ['total_items', 'total_pieces', 'supplier'].forEach(col => {
                if (document.querySelector(`button[data-column="${col}"]`).classList.contains('active')) {
                    toggleColumn(col, false);
                }
            });
        }
        // 태블릿 모드 (768px - 1023px)
        else if (viewportWidth <= 1023) {
            // 부가적인 컬럼만 숨김
            ['total_items', 'total_pieces'].forEach(col => {
                if (document.querySelector(`button[data-column="${col}"]`).classList.contains('active')) {
                    toggleColumn(col, false);
                }
            });
        }
        // 데스크톱 모드
        else {
            // 저장된 설정 복원
            applyInitialSettings();
        }
    }
    
    // 윈도우 리사이즈 이벤트
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(handleResponsiveTable, 250);
    });
    
    // 초기 반응형 처리
    handleResponsiveTable();
});
</script>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>

