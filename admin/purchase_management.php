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

// 페이지네이션 변수
$current_page = max(1, (int)($_GET['page'] ?? 1));
$items_per_page = 20;
$offset = ($current_page - 1) * $items_per_page;

// 검색 조건 구성 (삭제 상태 + 점포 필터링)
$where_conditions = [];
$params = [];
$param_types = '';

// deleted_at 컬럼이 존재하는지 확인
$check_deleted_at_column = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
$has_deleted_at = $check_deleted_at_column->num_rows > 0;

// status 컬럼이 존재하는지 확인 (대안)
$check_status_column = $conn->query("SHOW COLUMNS FROM purchases LIKE 'status'");
$has_status = $check_status_column->num_rows > 0;

// 삭제되지 않은 매입 내역만 조회 (soft delete 적용)
if ($has_deleted_at) {
    $where_conditions[] = "p.deleted_at IS NULL";
} elseif ($has_status) {
    // status 컬럼이 있는 경우 deleted 상태가 아닌 것만 조회
    $where_conditions[] = "p.status != 'deleted'";
}

// 점포 필터링 (super_admin이 아닌 경우 자신의 점포만 조회)
if ($_SESSION['role'] !== 'super_admin') {
    if (!empty($current_store_id)) {
        // 점포가 지정된 경우: 해당 점포의 데이터만 조회
        $where_conditions[] = "p.store_id = ?";
        $params[] = $current_store_id;
        $param_types .= 'i';
    } else {
        // 점포가 지정되지 않은 경우: 아무 데이터도 보이지 않게 함
        $where_conditions[] = "1 = 0";
    }
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
    p.store_id,
    st.name AS store_name,
    s.name AS supplier_name,
    p.total_items,
    p.total_amount,
    COALESCE(p.is_confirmed, 0) AS is_confirmed,
    p.confirmed_at,
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
LEFT JOIN stores st ON p.store_id = st.id
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

// 디버그: 데이터 확인
$data_count = $result ? $result->num_rows : 0;

// 디버그: SQL 쿼리 확인
if (isset($_GET['debug'])) {
    echo "<!-- 실행된 SQL: " . $sql . " -->";
    echo "<!-- 파라미터: " . implode(', ', $params ?? []) . " -->";
    echo "<!-- 결과 행 수: " . $data_count . " -->";
    echo "<!-- WHERE 조건: " . $where_clause . " -->";
    echo "<!-- deleted_at 컬럼 존재: " . ($has_deleted_at ? 'Yes' : 'No') . " -->";
    echo "<!-- status 컬럼 존재: " . ($has_status ? 'Yes' : 'No') . " -->";
}

// 임시 디버그: WHERE 절 없이 전체 데이터 확인
if (isset($_GET['nofilter'])) {
    $debug_sql = "SELECT COUNT(*) as total FROM purchases p JOIN suppliers s ON p.supplier_id = s.id";
    $debug_result = $conn->query($debug_sql);
    if ($debug_result) {
        $debug_row = $debug_result->fetch_assoc();
        echo "<!-- 필터 없는 전체 데이터: " . $debug_row['total'] . "개 -->";
    }
}

// 삭제 데이터 디버그
if (isset($_GET['debug'])) {
    if ($has_deleted_at) {
        $deleted_sql = "SELECT COUNT(*) as deleted_count FROM purchases WHERE deleted_at IS NOT NULL";
        $deleted_result = $conn->query($deleted_sql);
        if ($deleted_result) {
            $deleted_row = $deleted_result->fetch_assoc();
            echo "<!-- 삭제된 데이터 (deleted_at): " . $deleted_row['deleted_count'] . "개 -->";
        }
        
        $active_sql = "SELECT COUNT(*) as active_count FROM purchases WHERE deleted_at IS NULL";
        $active_result = $conn->query($active_sql);
        if ($active_result) {
            $active_row = $active_result->fetch_assoc();
            echo "<!-- 활성 데이터 (deleted_at): " . $active_row['active_count'] . "개 -->";
        }
    }
    
    if ($has_status) {
        $status_sql = "SELECT status, COUNT(*) as count FROM purchases GROUP BY status";
        $status_result = $conn->query($status_sql);
        if ($status_result) {
            echo "<!-- Status 분포: ";
            while ($status_row = $status_result->fetch_assoc()) {
                echo $status_row['status'] . "=" . $status_row['count'] . " ";
            }
            echo "-->";
        }
    }
}
?>

<style>
/* 반응형 테이블 스타일 */
.responsive-table {
    overflow-x: auto;
    width: 100%;
}

/* 전체 화면 사용을 위한 추가 스타일 */
body > div > div.flex.flex-col.flex-1.overflow-hidden > main {
    max-width: none !important;
    width: 100% !important;
}

body > div > div.flex.flex-col.flex-1.overflow-hidden > main > div > div {
    max-width: none !important;
    width: 100% !important;
}

/* 1920px 화면에서 전체 너비 활용 */
#purchaseManagementContainer {
    width: 100% !important;
    max-width: none !important;
}

/* 모바일에서 사이드바가 숨겨질 때 */
@media (max-width: 767px) {
    #purchaseManagementContainer {
        width: 100vw !important;
    }
}

/* 데스크톱에서 사이드바 제외한 전체 너비 */
@media (min-width: 768px) {
    #purchaseManagementContainer {
        width: calc(100vw - 320px) !important; /* 사이드바 320px 제외 */
    }
}

.table-compact td, .table-compact th {
    padding: 0.5rem 0.75rem !important;
    font-size: 0.875rem;
}

/* 테이블 전체 너비 사용 */
#purchaseTable {
    width: 100% !important;
    table-layout: auto !important;
}

/* 각 컬럼 최적화 */
th[data-column="number"] { width: 50px !important; }
th[data-column="datetime"] { min-width: 150px !important; }
th[data-column="store"] { min-width: 150px !important; }
th[data-column="supplier"] { min-width: 200px !important; }
th[data-column="total_items"] { min-width: 100px !important; }
th[data-column="total_pieces"] { min-width: 100px !important; }
th[data-column="amount"] { min-width: 120px !important; }
th[data-column="actions"] { min-width: 150px !important; }

/* 컬럼 토글 관련 스타일 */
.column-toggle-btn {
    background-color: #f3f4f6;
    padding: 0.25rem 0.75rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
    border: 1px solid #d1d5db;
}

.column-toggle-btn:hover {
    background-color: #e5e7eb;
}

.column-toggle-btn.active {
    background-color: #dbeafe;
    color: #1d4ed8;
    border-color: #93c5fd;
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
    .mobile-show { display: table-cell !important; }
    
    /* 모바일 카드 레이아웃 */
    .mobile-card {
        display: block;
        background-color: white;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        margin-bottom: 1rem;
        padding: 1rem;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
    }
    
    .mobile-card-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.25rem 0;
        border-bottom: 1px solid #f3f4f6;
    }
    
    .mobile-card-row:last-child {
        border-bottom: none;
    }
    
    .mobile-card-label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #4b5563;
    }
    
    .mobile-card-value {
        font-size: 0.875rem;
        color: #111827;
        font-weight: 500;
    }
}

/* 컬럼 우선순위별 표시 */
.priority-high { /* 필수 컬럼 - 항상 표시 */ }
.priority-medium { /* 중요 컬럼 - 태블릿에서 숨김 */ }
.priority-low { /* 부가 컬럼 - 모바일에서 숨김 */ }

/* 페이지네이션 스타일 */
.pagination-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid #e5e7eb;
    background-color: white;
    padding: 0.75rem 1rem;
}

@media (min-width: 640px) {
    .pagination-container {
        padding: 0.75rem 1.5rem;
    }
}

/* 클릭 가능한 테이블 행 스타일 */
.clickable-row {
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.clickable-row:hover {
    background-color: #f9fafb !important;
}

.clickable-row:active {
    background-color: #f3f4f6 !important;
}
</style>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">



    <!-- 모바일 카드 뷰 (비활성화) -->
    <div class="hidden">
        <?php if ($result && $result->num_rows > 0): ?>
            <?php $result->data_seek(0); // 결과 포인터 리셋 ?>
            <?php $row_number = 1; ?>
            <?php while($row = $result->fetch_assoc()): ?>
                <div class="mobile-card cursor-pointer" data-purchase-id="<?php echo $row['purchase_id']; ?>" onclick="window.location.href='edit_purchase.php?id=<?php echo $row['purchase_id']; ?>'" title="클릭하여 상세내역 보기">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">
                                #<?php echo htmlspecialchars($row['purchase_id']); ?>
                                <?php if ($row['is_confirmed']): ?>
                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <i class="fas fa-check-circle mr-1"></i>확정
                                    </span>
                                <?php else: ?>
                                    <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                        <i class="fas fa-clock mr-1"></i>미확정
                                    </span>
                                <?php endif; ?>
                            </h3>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($row['supplier_name']); ?></p>
                            <p class="text-xs text-gray-400 mt-0.5"><?php echo htmlspecialchars($row['store_name'] ?? '미지정'); ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-base font-bold text-primary-600"><?php echo number_format($row['total_amount'], 2); ?>원</p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($row['purchase_datetime']); ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 mb-3">
                        <div class="mobile-card-row">
                            <span class="mobile-card-label"><?php echo t('purchase.total_items'); ?></span>
                            <span class="mobile-card-value"><?php echo htmlspecialchars($row['total_items']); ?>개</span>
                        </div>
                        <div class="mobile-card-row">
                            <span class="mobile-card-label"><?php echo t('purchase.total_pieces'); ?></span>
                            <span class="mobile-card-value"><?php echo number_format($row['total_pieces'] ?? 0); ?>개</span>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-2 pt-3 border-t border-gray-100" onclick="event.stopPropagation()">
                        <a href="edit_purchase.php?id=<?php echo $row['purchase_id']; ?>"
                           class="inline-flex items-center px-3 py-2 text-sm font-medium text-indigo-600 bg-indigo-50 rounded-md hover:bg-indigo-100">
                            <i class="fas fa-eye mr-1"></i>
                            <?php echo t('purchase.detail_view'); ?>
                        </a>
                        <?php if (!$row['is_confirmed']): ?>
                        <a href="purchase_price_change.php?purchase_id=<?php echo $row['purchase_id']; ?>"
                           class="inline-flex items-center px-3 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700">
                            <i class="fas fa-edit mr-1"></i>
                            <?php echo t('purchase.price_change_confirm'); ?>
                        </a>
                        <?php endif; ?>
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
    <!-- 디버그: 데이터 <?php echo $data_count; ?>개 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <!-- 테이블 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <?php echo t('purchase.list'); ?>
            </h3>
            <div class="flex space-x-3">
                <?php if (!$has_deleted_at): ?>
                <a href="setup_soft_delete_purchases.php" class="inline-flex items-center px-4 py-2 border border-yellow-300 rounded-md shadow-sm text-sm font-medium text-yellow-700 bg-yellow-50 hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                    <i class="fas fa-database mr-2"></i>
                    <?php echo t('purchase.soft_delete_setup'); ?>
                </a>
                <?php endif; ?>
                <a href="add_purchase.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-plus mr-2"></i>
                    <?php echo t('purchase.new_purchase'); ?>
                </a>
            </div>
        </div>
        <div class="responsive-table w-full">
            <table class="min-w-full table-compact" id="purchaseTable">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 priority-high mobile-hidden" data-column="number"><?php echo t('purchase.number'); ?></th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 priority-high" data-column="datetime"><?php echo t('purchase.date_time'); ?></th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 priority-high mobile-hidden" data-column="store">점포</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 priority-high mobile-show" data-column="supplier"><?php echo t('purchase.supplier'); ?></th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 priority-medium tablet-hidden mobile-hidden" data-column="total_items"><?php echo t('purchase.total_items'); ?></th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 priority-medium tablet-hidden mobile-hidden" data-column="total_pieces"><?php echo t('purchase.total_pieces'); ?></th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 priority-high mobile-hidden" data-column="amount"><?php echo t('purchase.purchase_amount'); ?></th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 priority-medium mobile-hidden" data-column="confirmed">확정상태</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 priority-high mobile-hidden" data-column="actions"><?php echo t('common.actions'); ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php $result->data_seek(0); // 결과 포인터 리셋 ?>
                        <?php $row_number = 1; ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 clickable-row" data-purchase-id="<?php echo $row['purchase_id']; ?>">
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-center border border-gray-100 priority-high mobile-hidden" data-column="number"><?php echo $row_number++; ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 border border-gray-100 priority-high" data-column="datetime"><?php echo htmlspecialchars($row['purchase_datetime']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 border border-gray-100 priority-high mobile-hidden" data-column="store"><?php echo htmlspecialchars($row['store_name'] ?? '미지정'); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 border border-gray-100 priority-high mobile-show" data-column="supplier"><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-right border border-gray-100 priority-medium tablet-hidden mobile-hidden" data-column="total_items"><?php echo htmlspecialchars($row['total_items']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-right border border-gray-100 priority-medium tablet-hidden mobile-hidden" data-column="total_pieces">
                                    <span class="font-medium"><?php echo number_format($row['total_pieces'] ?? 0); ?></span>
                                    <span class="text-xs text-gray-400 ml-1"><?php echo t('purchase.pieces'); ?></span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-right border border-gray-100 priority-high mobile-hidden" data-column="amount"><?php echo number_format($row['total_amount'], 2); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-center text-sm border border-gray-100 priority-medium mobile-hidden" data-column="confirmed">
                                    <?php if ($row['is_confirmed']): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            매입확정
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                            <i class="fas fa-clock mr-1"></i>
                                            미확정
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-center text-sm font-medium border border-gray-100 priority-high mobile-hidden" data-column="actions">
                                    <?php if ($row['is_confirmed']): ?>
                                        <span class="inline-flex items-center px-3 py-1 text-xs font-medium text-green-700 bg-green-100 border border-green-200 rounded-md">
                                            <i class="fas fa-check mr-1"></i>
                                            매입확정
                                        </span>
                                    <?php else: ?>
                                        <a href="purchase_price_change.php?purchase_id=<?php echo $row['purchase_id']; ?>"
                                           class="inline-flex items-center px-3 py-1 text-xs font-medium text-white bg-green-600 border border-transparent rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                            <i class="fas fa-edit mr-1"></i>
                                            <?php echo t('purchase.price_change_confirm'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-100 md:hidden">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-dolly-flatbed text-4xl text-gray-400"></i>
                                    <p class="mt-4"><?php echo t('purchase.no_purchases'); ?></p>
                                    <p class="text-xs text-gray-400"><?php echo t('purchase.add_first_purchase'); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr class="hidden md:table-row">
                            <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-100">
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
        <div class="bg-white px-4 py-3 flex items-center justify-center border-t border-gray-200 sm:px-6">
            <div class="flex-1 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php
                    // 현재 페이지가 속한 10페이지 그룹 계산
                    $current_group = ceil($current_page / 10);
                    $group_start = ($current_group - 1) * 10 + 1;
                    $group_end = min($current_group * 10, $total_pages);

                    // 이전 그룹이 있으면 이전 버튼 표시
                    if ($group_start > 1): ?>
                        <a href="?page=<?php echo $group_start - 1; ?>"
                           class="relative inline-flex items-center px-4 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <i class="fas fa-chevron-left mr-2"></i>이전
                        </a>
                    <?php endif; ?>

                    <?php
                    // 현재 그룹의 페이지들 표시 (1-10, 11-20, ...)
                    for ($i = $group_start; $i <= $group_end; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?>"
                           class="<?php echo $i == $current_page ? 'bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>
                                  relative inline-flex items-center px-4 py-2 border text-sm font-medium
                                  <?php echo ($i == $group_start && $group_start == 1) ? 'rounded-l-md' : ''; ?>
                                  <?php echo ($i == $group_end && $group_end == $total_pages) ? 'rounded-r-md' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php
                    // 다음 그룹이 있으면 다음 버튼 표시
                    if ($group_end < $total_pages): ?>
                        <a href="?page=<?php echo $group_end + 1; ?>"
                           class="relative inline-flex items-center px-4 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            다음<i class="fas fa-chevron-right ml-2"></i>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>

            <!-- 하단 그룹 정보 -->
            <div class="text-center mt-3">
                <span class="text-sm text-gray-700">
                    페이지 <?php echo $current_page; ?> / <?php echo $total_pages; ?>
                    (총 <?php echo number_format($total_count); ?>개 항목)
                </span>
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
                const btn = document.querySelector(`button[data-column="${col}"]`);
                if (btn && btn.classList.contains('active')) {
                    toggleColumn(col, false);
                }
            });
        }
        // 태블릿 모드 (768px - 1023px)
        else if (viewportWidth <= 1023) {
            // 부가적인 컬럼만 숨김
            ['total_items', 'total_pieces'].forEach(col => {
                const btn = document.querySelector(`button[data-column="${col}"]`);
                if (btn && btn.classList.contains('active')) {
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
    
    // 테이블 행 클릭 이벤트 처리
    const clickableRows = document.querySelectorAll('.clickable-row');
    clickableRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // 액션 버튼 영역 클릭 시에는 이벤트 무시
            if (e.target.closest('td[data-column="actions"]') || 
                e.target.closest('a') || 
                e.target.tagName === 'A' || 
                e.target.tagName === 'I') {
                return;
            }
            
            // 매입 ID 가져오기
            const purchaseId = this.dataset.purchaseId;
            if (purchaseId) {
                // 매입 상세 페이지로 이동
                window.location.href = `edit_purchase.php?id=${purchaseId}`;
            }
        });
        
        // 행에 타이틀 추가 (툴팁)
        row.title = '클릭하여 상세내역 보기';
    });
});
</script>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>

