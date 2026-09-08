<?php
// Design Ref: purchase-from-store-transfer — 타점 이동(store_transfers, confirmed)으로 받은 상품을
// 매입(purchases)으로 등록하기 전, 관리자가 검토/확정하는 목록 화면.
// Ref: admin/store_transfers_list.php (동일 store_transfers/store_transfer_items 조회 패턴)
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/mobile_detect.php';

$page_title = t('purchase.store_transfer_purchase_register');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();

// store_transfers.converted_purchase_id 컬럼 자동 추가 (이미 매입등록된 이동 재등록 방지용)
$chk_col = $conn->query("SHOW COLUMNS FROM store_transfers LIKE 'converted_purchase_id'");
if ($chk_col && $chk_col->num_rows === 0) {
    $conn->query("ALTER TABLE store_transfers ADD COLUMN converted_purchase_id INT NULL DEFAULT NULL AFTER status");
}

// 점포 필터링 (super_admin이 아닌 경우 자신의 점포가 받은 이동만 조회) — purchase_management.php와 동일한 스코프 규칙
$where = "st.status = 'confirmed' AND st.converted_purchase_id IS NULL";
$params = [];
$types  = '';
if ($_SESSION['role'] !== 'super_admin') {
    if (!empty($current_store_id)) {
        $where .= " AND st.to_store_id = ?";
        $params[] = $current_store_id;
        $types   .= 'i';
    } else {
        $where .= " AND 1 = 0";
    }
}

$sql = "SELECT st.id, st.from_store_id, st.to_store_id, st.transfer_date, st.total_amount,
               fs.name AS from_store_name, ts.name AS to_store_name,
               (SELECT COUNT(*) FROM store_transfer_items WHERE transfer_id = st.id) AS item_count
        FROM store_transfers st
        LEFT JOIN stores fs ON st.from_store_id = fs.id
        LEFT JOIN stores ts ON st.to_store_id = ts.id
        WHERE {$where}
        ORDER BY st.transfer_date DESC, st.id DESC";
if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $pending_transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $pending_transfers = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}
$conn->close();
?>

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-bold text-gray-900">
        <i class="fas fa-right-left mr-2 text-teal-600"></i><?php echo t('purchase.store_transfer_purchase_register'); ?>
    </h1>
    <a href="purchase_management.php" class="btn"><i class="fas fa-arrow-left mr-2"></i><?php echo t('purchase.back_to_purchase_list'); ?></a>
</div>

<div class="bg-white shadow rounded-lg overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase"><?php echo t('purchase.transfer_date_column'); ?></th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase"><?php echo t('purchase.transfer_route'); ?></th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-700 uppercase"><?php echo t('purchase.total_items'); ?></th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-700 uppercase"><?php echo t('purchase.amount_column'); ?></th>
                <th class="px-6 py-3 text-center text-xs font-semibold text-gray-700 uppercase"><?php echo t('purchase.register_column'); ?></th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
        <?php if (empty($pending_transfers)): ?>
            <tr>
                <td colspan="5" class="px-6 py-12 text-center text-gray-400">
                    <i class="fas fa-check-circle text-3xl mb-3 block"></i>
                    <?php echo t('purchase.no_transfer_pending'); ?>
                </td>
            </tr>
        <?php else: foreach ($pending_transfers as $t): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm text-gray-700"><?php echo htmlspecialchars($t['transfer_date']); ?></td>
                <td class="px-6 py-4 text-sm">
                    <span class="font-medium text-red-600"><?php echo htmlspecialchars($t['from_store_name'] ?? t('purchase.unassigned')); ?></span>
                    <i class="fas fa-arrow-right mx-2 text-gray-400"></i>
                    <span class="font-medium text-blue-600"><?php echo htmlspecialchars($t['to_store_name'] ?? t('purchase.unassigned')); ?></span>
                </td>
                <td class="px-6 py-4 text-sm text-right text-gray-700"><?php echo (int)$t['item_count']; ?></td>
                <td class="px-6 py-4 text-sm text-right text-gray-700"><?php echo number_format($t['total_amount'], 2); ?></td>
                <td class="px-6 py-4 text-center">
                    <a href="match_store_transfer_purchase.php?transfer_id=<?php echo (int)$t['id']; ?>"
                       class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-teal-600 rounded-md hover:bg-teal-700">
                        <i class="fas fa-list-check mr-1"></i><?php echo t('purchase.confirm_and_register'); ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
