<?php
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/mobile_detect.php';

// 모바일 기기에서 모바일 메인으로 리다이렉트
redirect_if_mobile('mobile_main.php', true);
$page_title = t('store_transfer.list') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 점간이동 권한 확인
if (!has_permission('store_transfer_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$transfers = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 권한 확인 및 WHERE 절 구성
    $where_conditions = [];
    $params = [];

    // 점포 필터 — super_admin도 선택된 점포($current_store_id, 상단 점포 스위처) 관련 이동만 조회
    $where_conditions[] = "(st.from_store_id = ? OR st.to_store_id = ?)";
    $params[] = $current_store_id;
    $params[] = $current_store_id;

    // WHERE 절 구성
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // 이동 목록 조회 (최근 100건)
    $sql = "
        SELECT 
            st.id,
            st.from_store_id,
            st.to_store_id,
            st.transfer_date,
            st.total_amount,
            st.final_amount,
            st.status,
            st.notes,
            st.created_at,
            st.updated_at,
            fs.name as from_store_name,
            ts.name as to_store_name,
            u.full_name as user_name,
            (SELECT COUNT(*) FROM store_transfer_items WHERE transfer_id = st.id) as item_count
        FROM store_transfers st
        LEFT JOIN stores fs ON st.from_store_id = fs.id
        LEFT JOIN stores ts ON st.to_store_id = ts.id
        LEFT JOIN users u ON st.user_id = u.id
        {$where_clause}
        ORDER BY st.created_at DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('store_transfer.database_error') . $e->getMessage();
    error_log("Store transfers list error: " . $e->getMessage());
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="w-full mx-auto">

        <?php if (isset($flash)): ?>
            <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                            <?php echo htmlspecialchars($flash['message']); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>


        <!-- 이동 목록 테이블 -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
            <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
                <h3 class="text-lg leading-6 font-semibold text-gray-900">
                    <?php echo t('store_transfer.history'); ?> 
                    <span class="text-sm font-normal text-gray-500">(<?php echo str_replace('{count}', count($transfers), t('store_transfer.total_count_label')); ?>)</span>
                </h3>
                <a href="store_transfers.php" 
                   class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i>
                    <?php echo t('store_transfer.register'); ?>
                </a>
            </div>
            
            <?php if (empty($transfers)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-exchange-alt text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo t('store_transfer.no_transfers'); ?></h3>
                    <p class="text-gray-500 mb-6"><?php echo t('store_transfer.no_matching_transfers'); ?></p>
                    <a href="store_transfers.php" 
                       class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                        <i class="fas fa-plus mr-2"></i>
                        <?php echo t('store_transfer.register_first'); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo t('store_transfer.number'); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo t('store_transfer.transfer_date'); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo t('store_transfer.route'); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo t('store_transfer.product_count'); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo t('store_transfer.total_amount'); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo t('store_transfer.status'); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo t('store_transfer.handler'); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo t('store_transfer.action'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($transfers as $transfer): ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="window.location.href='store_transfer_preview.php?id=<?php echo $transfer['id']; ?>'">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            #<?php echo str_pad($transfer['id'], 6, '0', STR_PAD_LEFT); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo date('m-d H:i', strtotime($transfer['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo date('Y-m-d', strtotime($transfer['transfer_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900">
                                            <span class="font-medium text-red-600"><?php echo htmlspecialchars($transfer['from_store_name']); ?></span>
                                            <i class="fas fa-arrow-right mx-2 text-gray-400"></i>
                                            <span class="font-medium text-blue-600"><?php echo htmlspecialchars($transfer['to_store_name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        <?php echo $transfer['item_count']; ?><?php echo t('common.items'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                        <?php echo number_format($transfer['total_amount'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <?php 
                                        $status_classes = [
                                            'draft' => 'bg-yellow-100 text-yellow-800',
                                            'confirmed' => 'bg-green-100 text-green-800',
                                            'cancelled' => 'bg-red-100 text-red-800'
                                        ];
                                        $status_names = [
                                            'draft' => t('store_transfer.draft'),
                                            'confirmed' => t('store_transfer.confirmed'),
                                            'cancelled' => t('store_transfer.cancelled')
                                        ];
                                        $status_class = $status_classes[$transfer['status']] ?? 'bg-gray-100 text-gray-800';
                                        $status_name = $status_names[$transfer['status']] ?? $transfer['status'];
                                        ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>">
                                            <?php echo $status_name; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($transfer['user_name'] ?? t('store_transfer.unknown')); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <div class="flex justify-center space-x-1">
                                            <a href="store_transfers.php?edit=<?php echo $transfer['id']; ?>" 
                                               class="text-green-600 hover:text-green-900" title="<?php echo t('store_transfer.edit_tooltip'); ?>"
                                               onclick="event.stopPropagation();">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (count($transfers) >= 100): ?>
            <div class="mt-4 text-center text-sm text-gray-600">
                <?php echo t('store_transfer.recent_100_only'); ?>
            </div>
        <?php endif; ?>
    </div>
</div>


<style>
/* 테이블 반응형 스타일 */
@media (max-width: 768px) {
    .overflow-x-auto table {
        font-size: 0.875rem;
    }
    
    .overflow-x-auto th,
    .overflow-x-auto td {
        padding: 0.5rem 0.25rem;
    }
    
}

/* 상태 배지 스타일 개선 */
.bg-yellow-100 {
    background-color: #fef3c7;
}
.text-yellow-800 {
    color: #92400e;
}
.bg-green-100 {
    background-color: #dcfce7;
}
.text-green-800 {
    color: #166534;
}
.bg-red-100 {
    background-color: #fee2e2;
}
.text-red-800 {
    color: #991b1b;
}

/* 호버 효과 */
tbody tr:hover {
    background-color: #f9fafb;
}

.text-red-600 {
    color: #dc2626;
}
.text-blue-600 {
    color: #2563eb;
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>