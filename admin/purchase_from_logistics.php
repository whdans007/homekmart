<?php
// Design Ref: purchase-from-logistics — 물류센터 배송완료(lc_orders.status=delivered) 건을
// 매입(purchases)으로 등록하기 전, 관리자가 검토/확정하는 목록 화면.
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/mobile_detect.php';

$page_title = '물류센터 입고분 매입등록';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();

// lc_orders.converted_purchase_id 컬럼 자동 추가 (이미 매입등록된 주문 재등록 방지용)
$chk_col = $conn->query("SHOW COLUMNS FROM lc_orders LIKE 'converted_purchase_id'");
if ($chk_col && $chk_col->num_rows === 0) {
    $conn->query("ALTER TABLE lc_orders ADD COLUMN converted_purchase_id INT UNSIGNED NULL DEFAULT NULL AFTER status");
}

// 점포 필터링 (super_admin이 아닌 경우 자신의 점포만 조회) — purchase_management.php와 동일한 스코프 규칙
$where = "o.status = 'delivered' AND o.converted_purchase_id IS NULL";
$params = [];
$types  = '';
if ($_SESSION['role'] !== 'super_admin') {
    if (!empty($current_store_id)) {
        $where .= " AND o.store_id = ?";
        $params[] = $current_store_id;
        $types   .= 'i';
    } else {
        $where .= " AND 1 = 0";
    }
}

$sql = "SELECT o.id, o.store_id, o.delivered_at, s.name AS store_name,
               (SELECT COUNT(*) FROM lc_order_items oi WHERE oi.order_id = o.id) AS item_count,
               COALESCE((SELECT SUM(oi.total_amount) FROM lc_order_items oi WHERE oi.order_id = o.id), 0) AS total_amount
        FROM lc_orders o
        LEFT JOIN stores s ON o.store_id = s.id
        WHERE {$where}
        ORDER BY o.delivered_at DESC";
if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $pending_orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $pending_orders = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}
$conn->close();
?>

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-bold text-gray-900">
        <i class="fas fa-truck-loading mr-2 text-indigo-600"></i>물류센터 입고분 매입등록
    </h1>
    <a href="purchase_management.php" class="btn"><i class="fas fa-arrow-left mr-2"></i>매입 목록으로</a>
</div>

<div class="bg-white shadow rounded-lg overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">배송완료일</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase">점포</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-700 uppercase">품목수</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-700 uppercase">금액</th>
                <th class="px-6 py-3 text-center text-xs font-semibold text-gray-700 uppercase">매입등록</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
        <?php if (empty($pending_orders)): ?>
            <tr>
                <td colspan="5" class="px-6 py-12 text-center text-gray-400">
                    <i class="fas fa-check-circle text-3xl mb-3 block"></i>
                    매입등록 대기 중인 물류센터 입고 건이 없습니다.
                </td>
            </tr>
        <?php else: foreach ($pending_orders as $o): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm text-gray-700"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($o['delivered_at']))); ?></td>
                <td class="px-6 py-4 text-sm text-gray-700"><?php echo htmlspecialchars($o['store_name'] ?? '미지정'); ?></td>
                <td class="px-6 py-4 text-sm text-right text-gray-700"><?php echo (int)$o['item_count']; ?></td>
                <td class="px-6 py-4 text-sm text-right text-gray-700"><?php echo number_format($o['total_amount'], 2); ?></td>
                <td class="px-6 py-4 text-center">
                    <a href="match_logistics_purchase.php?lc_order_id=<?php echo (int)$o['id']; ?>"
                       class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                        <i class="fas fa-list-check mr-1"></i>확인 및 등록
                    </a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
