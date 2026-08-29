<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'orders.php';

$channel_filter = $_GET['channel'] ?? '';
$status_filter = $_GET['status'] ?? '';

$where = ['o.store_id = ?'];
$params = [MALL_STORE_ID];
$types = 'i';

if (in_array($channel_filter, ['retail', 'wholesale'], true)) {
    $where[] = 'o.channel = ?';
    $params[] = $channel_filter;
    $types .= 's';
}
if (in_array($status_filter, ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'], true)) {
    $where[] = 'o.status = ?';
    $params[] = $status_filter;
    $types .= 's';
}

$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT o.id, o.order_number, o.channel, o.total_amount, o.status, o.created_at, m.name AS member_name, m.email
     FROM mall_orders o
     INNER JOIN mall_members m ON m.id = o.member_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY o.created_at DESC LIMIT 200"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$status_labels = ['pending' => '접수대기', 'confirmed' => '확인됨', 'preparing' => '준비중', 'ready' => '준비완료', 'completed' => '완료', 'cancelled' => '취소'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>주문 관리 - HOME K MART 쇼핑몰</title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
        <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-receipt mr-2"></i>주문 관리</h1>
        <div id="flash-area"></div>

        <form method="get" class="flex gap-2 mb-4">
            <select name="channel" class="border border-gray-300 rounded-md px-2 py-1 text-xs">
                <option value="">전체 채널</option>
                <option value="retail" <?php echo $channel_filter === 'retail' ? 'selected' : ''; ?>>소매</option>
                <option value="wholesale" <?php echo $channel_filter === 'wholesale' ? 'selected' : ''; ?>>도매</option>
            </select>
            <select name="status" class="border border-gray-300 rounded-md px-2 py-1 text-xs">
                <option value="">전체 상태</option>
                <?php foreach ($status_labels as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md">필터 적용</button>
        </form>

        <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">주문번호</th>
                        <th class="px-3 py-2 text-left">회원</th>
                        <th class="px-3 py-2 text-left">채널</th>
                        <th class="px-3 py-2 text-left">합계</th>
                        <th class="px-3 py-2 text-left">주문일시</th>
                        <th class="px-3 py-2 text-left">상태</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">주문이 없습니다.</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $o): ?>
                    <tr class="border-t border-gray-100" data-order-id="<?php echo (int)$o['id']; ?>">
                        <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($o['order_number']); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars($o['member_name']); ?> <span class="text-gray-400">(<?php echo htmlspecialchars($o['email']); ?>)</span></td>
                        <td class="px-3 py-2"><?php echo $o['channel'] === 'wholesale' ? '<span class="text-purple-700 font-semibold">도매</span>' : '<span class="text-teal-700 font-semibold">소매</span>'; ?></td>
                        <td class="px-3 py-2"><?php echo number_format((float)$o['total_amount'], 2); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars($o['created_at']); ?></td>
                        <td class="px-3 py-2">
                            <select class="status-select border border-gray-300 rounded px-1 py-0.5 text-xs">
                                <?php foreach ($status_labels as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $o['status'] === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

<script>
function showFlash(message, type) {
    const area = document.getElementById('flash-area');
    const color = type === 'error' ? 'bg-red-100 text-red-700 border-red-300' : 'bg-green-100 text-green-700 border-green-300';
    area.innerHTML = '<div class="mb-3 px-3 py-2 text-xs rounded border ' + color + '">' + message + '</div>';
    setTimeout(() => { area.innerHTML = ''; }, 4000);
}

document.querySelectorAll('.status-select').forEach(function (select) {
    const original = select.value;
    select.addEventListener('change', function () {
        const orderId = select.closest('tr').dataset.orderId;
        const params = new URLSearchParams();
        params.set('order_id', orderId);
        params.set('status', select.value);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/update_order_status.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                showFlash(data.success ? '상태가 변경되었습니다.' : (data.error?.message || '오류가 발생했습니다.'), data.success ? 'success' : 'error');
            });
    });
});
</script>
</body>
</html>
