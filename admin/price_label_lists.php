<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/mobile_detect.php';

// 모바일 기기에서 모바일 메인으로 리다이렉트
redirect_if_mobile('mobile_main.php', true);
$page_title = t('navigation.price_label_lists') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 가격표 관리 권한 확인 (상품 관리 권한으로 대체)
if (!has_permission('product_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

// POST 요청 처리 (프로젝트 삭제)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    $project_id = (int)($_POST['project_id'] ?? 0);
    
    if ($project_id > 0) {
        try {
            $conn = get_db_connection();
            $conn->autocommit(false);
            
            $current_store_id = $_SESSION['store_id'] ?? 0;
            
            // 프로젝트 소유권 확인
            $check_sql = "SELECT id, created_at FROM price_label_projects WHERE id = ? AND store_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $project_id, $current_store_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception('프로젝트를 찾을 수 없거나 접근 권한이 없습니다.');
            }
            
            $project_info = $check_result->fetch_assoc();
            
            // 프로젝트 아이템들 삭제
            $delete_items_sql = "DELETE FROM price_label_project_items WHERE project_id = ?";
            $delete_items_stmt = $conn->prepare($delete_items_sql);
            $delete_items_stmt->bind_param("i", $project_id);
            $delete_items_stmt->execute();
            
            // 프로젝트 삭제
            $delete_project_sql = "DELETE FROM price_label_projects WHERE id = ? AND store_id = ?";
            $delete_project_stmt = $conn->prepare($delete_project_sql);
            $delete_project_stmt->bind_param("ii", $project_id, $current_store_id);
            $delete_project_stmt->execute();
            
            $conn->commit();
            $conn->close();
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => t('price_label.project_deleted_success')
            ];
            
            header('Location: price_label_lists.php');
            exit;
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollback();
                $conn->close();
            }
            
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => t('price_label.delete_error') . ': ' . $e->getMessage()
            ];
            
            header('Location: price_label_lists.php');
            exit;
        }
    }
}

$conn = get_db_connection();

// 페이지네이션 변수
$current_page = max(1, (int)($_GET['page'] ?? 1));
$items_per_page = 20;
$offset = ($current_page - 1) * $items_per_page;

// 현재 점포 ID 가져오기
$current_store_id = $_SESSION['store_id'] ?? 0;

// 검색 조건 구성
$where_conditions = ["p.store_id = ?"];
$params = [$current_store_id];
$param_types = 'i';

$where_clause = ' WHERE ' . implode(' AND ', $where_conditions);

// 총 개수 조회
$count_sql = "SELECT COUNT(*) as total_count FROM price_label_projects p {$where_clause}";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($param_types, ...$params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();

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

// 가격표 프로젝트 리스트 조회
$sql = "SELECT 
    p.id,
    p.created_at,
    p.updated_at,
    u.username as creator_name,
    (
        SELECT COUNT(*)
        FROM price_label_project_items pi 
        WHERE pi.project_id = p.id
    ) as item_count,
    (
        SELECT SUM(pi.quantity)
        FROM price_label_project_items pi 
        WHERE pi.project_id = p.id
    ) as total_quantity
FROM price_label_projects p
LEFT JOIN users u ON p.created_by = u.id
{$where_clause}
ORDER BY p.updated_at DESC, p.id DESC
LIMIT {$items_per_page} OFFSET {$offset}";

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Check for query errors
if (!$result) {
    die("SQL Error: " . $conn->error);
}

$data_count = $result ? $result->num_rows : 0;
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

.table-compact td, .table-compact th {
    padding: 0.5rem 0.75rem !important;
    font-size: 0.875rem;
}

/* 테이블 전체 너비 사용 */
#priceLabelsTable {
    width: 100% !important;
    table-layout: auto !important;
}

/* 각 컬럼 최적화 */
th[data-column="number"] { width: 50px !important; }
th[data-column="project_name"] { min-width: 200px !important; }
th[data-column="item_count"] { min-width: 80px !important; }
th[data-column="total_quantity"] { min-width: 100px !important; }
th[data-column="creator"] { min-width: 100px !important; }
th[data-column="updated_at"] { min-width: 150px !important; }
th[data-column="actions"] { min-width: 200px !important; }

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
    <div class="mb-6">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li>
                    <a href="index.php" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-home mr-1"></i>
                        <?php echo t('common.dashboard'); ?>
                    </a>
                </li>
                <li>
                    <div class="flex items-center">
                        <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                        <span class="text-gray-600"><?php echo t('navigation.price_label_lists'); ?></span>
                    </div>
                </li>
            </ol>
        </nav>
    </div>

    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <!-- 테이블 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <i class="fas fa-tags mr-2 text-purple-500"></i>
                <?php echo t('navigation.price_label_lists'); ?>
            </h3>
            <div class="flex space-x-3">
                <a href="price_label_project_edit.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500" style="color: white !important;">
                    <i class="fas fa-plus mr-2" style="color: white !important;"></i>
                    <?php echo t('price_label.create_project'); ?>
                </a>
            </div>
        </div>
        
        <div class="responsive-table w-full">
            <table class="min-w-full table-compact" id="priceLabelsTable">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100" data-column="number"><?php echo t('price_label.project_number'); ?></th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100" data-column="created_at"><?php echo t('price_label.created_at'); ?></th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 hidden md:table-cell" data-column="item_count"><?php echo t('price_label.item_count'); ?></th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 hidden lg:table-cell" data-column="total_quantity"><?php echo t('price_label.total_quantity'); ?></th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100 hidden md:table-cell" data-column="creator"><?php echo t('price_label.creator'); ?></th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100" data-column="updated_at"><?php echo t('price_label.updated_at'); ?></th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider border border-gray-100" data-column="actions"><?php echo t('price_label.actions'); ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php $result->data_seek(0); ?>
                        <?php $row_number = ($current_page - 1) * $items_per_page + 1; ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 clickable-row" 
                                data-project-id="<?php echo $row['id']; ?>"
                                onclick="viewPriceLabels(<?php echo $row['id']; ?>)">
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-center border border-gray-100" data-column="number">
                                    <?php echo $row_number++; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900 border border-gray-100" data-column="created_at">
                                    <div class="font-medium"><?php echo date('Y-m-d H:i', strtotime($row['created_at'])); ?></div>
                                    <div class="text-xs text-gray-500 mt-1"><?php echo date('M j', strtotime($row['created_at'])); ?></div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-center border border-gray-100 hidden md:table-cell" data-column="item_count">
                                    <span class="font-medium"><?php echo number_format($row['item_count'] ?? 0); ?></span>
                                    <span class="text-xs text-gray-400 ml-1"><?php echo t('price_label.items_unit'); ?></span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-center border border-gray-100 hidden lg:table-cell" data-column="total_quantity">
                                    <span class="font-medium"><?php echo number_format($row['total_quantity'] ?? 0); ?></span>
                                    <span class="text-xs text-gray-400 ml-1"><?php echo t('price_label.sheets_unit'); ?></span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-center border border-gray-100 hidden md:table-cell" data-column="creator">
                                    <?php echo htmlspecialchars($row['creator_name'] ?? t('price_label.unknown_creator')); ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 text-center border border-gray-100" data-column="updated_at">
                                    <?php echo date('Y-m-d H:i', strtotime($row['updated_at'])); ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-center text-sm font-medium border border-gray-100" data-column="actions">
                                    <form method="POST" class="inline" onsubmit="return confirm('<?php echo t('price_label.confirm_delete_project'); ?>')">
                                        <input type="hidden" name="project_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="delete_project"
                                                onclick="event.stopPropagation();" 
                                                class="inline-flex items-center px-2 py-1 text-xs font-medium text-white bg-red-600 border border-transparent rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                            <i class="fas fa-trash mr-1"></i>
                                            <?php echo t('price_label.delete'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-100">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-tags text-4xl text-gray-400"></i>
                                    <p class="mt-4"><?php echo t('price_label.no_projects'); ?></p>
                                    <p class="text-xs text-gray-400 mt-2"><?php echo t('price_label.create_first_project'); ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 페이지네이션 -->
        <?php if ($total_pages > 1): ?>
        <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex items-center justify-between w-full">
            <div class="w-full flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    <?php 
                    $start_item = ($current_page - 1) * $items_per_page + 1;
                    $end_item = min($current_page * $items_per_page, $total_count);
                    echo sprintf(
                        t('price_label.page_info'),
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
                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-100 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-purple-500">
                            <i class="fas fa-chevron-left mr-1"></i>
                            <?php echo t('price_label.previous'); ?>
                        </a>
                    <?php endif; ?>
                    
                    <!-- 페이지 번호 -->
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    // 첫 페이지
                    if ($start_page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" 
                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-100 rounded-md hover:bg-gray-50">1</a>
                        <?php if ($start_page > 2): ?>
                            <span class="px-2 py-2 text-sm text-gray-500">...</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- 현재 페이지 주변 -->
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="px-3 py-2 text-sm font-medium text-white bg-purple-600 border border-purple-600 rounded-md">
                                <?php echo $i; ?>
                            </span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-100 rounded-md hover:bg-gray-50">
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
                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-100 rounded-md hover:bg-gray-50"><?php echo $total_pages; ?></a>
                    <?php endif; ?>
                    
                    <!-- 다음 페이지 -->
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" 
                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-100 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-purple-500">
                            <?php echo t('price_label.next'); ?>
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
    // 알림 표시 함수
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 z-50 px-4 py-3 rounded-md shadow-lg max-w-sm ${
            type === 'success' ? 'bg-green-100 border border-green-200 text-green-800' : 
            type === 'error' ? 'bg-red-100 border border-red-200 text-red-800' : 
            'bg-blue-100 border border-blue-200 text-blue-800'
        }`;
        
        notification.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${
                    type === 'success' ? 'fa-check-circle' : 
                    type === 'error' ? 'fa-exclamation-triangle' : 
                    'fa-info-circle'
                } mr-2"></i>
                <span class="text-sm font-medium">${message}</span>
                <button class="ml-3 text-gray-400 hover:text-gray-600" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }
    
    // 가격표 보기 (행 클릭)
    window.viewPriceLabels = function(projectId) {
        window.location.href = `price_label_print.php?project_id=${projectId}`;
    };
    
    // deleteProject 함수는 더 이상 필요하지 않음 (전통적인 폼 제출로 변경됨)
});
</script>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>