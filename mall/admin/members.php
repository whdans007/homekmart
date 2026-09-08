<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'members.php';

$tab = $_GET['tab'] ?? 'all'; // all | wholesale_pending | retail
$search = trim($_GET['q'] ?? '');

$conn = get_db_connection();

$where = ['1=1'];
$params = [];
$types = '';

if ($tab === 'wholesale_pending') {
    $where[] = "member_type = 'wholesale' AND wholesale_status = 'pending'";
} elseif ($tab === 'retail') {
    $where[] = "member_type = 'retail'";
}

if ($search !== '') {
    $where[] = '(email LIKE ? OR name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

$sql = "SELECT id, member_type, email, name, phone, business_name, retail_tier,
               wholesale_status, wholesale_customer_id, created_at
        FROM mall_members
        WHERE " . implode(' AND ', $where) . "
        ORDER BY created_at DESC
        LIMIT 200";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 도매 승인 대기 건수 (사이드바/탭 배지용)
$pending_count_row = $conn->query(
    "SELECT COUNT(*) AS cnt FROM mall_members WHERE member_type='wholesale' AND wholesale_status='pending'"
)->fetch_assoc();
$wholesale_pending_count = (int)($pending_count_row['cnt'] ?? 0);

// 승인 시 매칭할 기존 도매 거래처 목록
$customers = $conn->query(
    "SELECT id, name FROM wholesale_customers WHERE is_active = 1 ORDER BY name"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();

$tier_labels = [
    'general' => t('mall_admin.members.tier_general'), 'good' => t('mall_admin.members.tier_good'),
    'vip' => t('mall_admin.members.tier_vip'), 'platinum' => t('mall_admin.members.tier_platinum'),
];
$status_labels = [
    'pending' => t('mall_admin.members.status_pending'), 'approved' => t('mall_admin.members.status_approved'),
    'rejected' => t('mall_admin.members.status_rejected'),
];
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.nav.members'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
        <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-users mr-2"></i><?php echo t('mall_admin.nav.members'); ?></h1>

        <div id="flash-area"></div>

        <div class="flex items-center gap-2 mb-4">
            <a href="?tab=all" class="px-3 py-1.5 text-xs font-semibold rounded-md <?php echo $tab === 'all' ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-600'; ?>"><?php echo t('common.all'); ?></a>
            <a href="?tab=wholesale_pending" class="px-3 py-1.5 text-xs font-semibold rounded-md <?php echo $tab === 'wholesale_pending' ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-600'; ?>">
                <?php echo t('mall_admin.dashboard.wholesale_pending'); ?><?php echo $wholesale_pending_count > 0 ? ' (' . $wholesale_pending_count . ')' : ''; ?>
            </a>
            <a href="?tab=retail" class="px-3 py-1.5 text-xs font-semibold rounded-md <?php echo $tab === 'retail' ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 text-gray-600'; ?>"><?php echo t('mall_admin.members.retail_members'); ?></a>

            <form method="get" class="ml-auto flex gap-1">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo htmlspecialchars(t('mall_admin.members.search_placeholder')); ?>"
                       class="border border-gray-300 rounded-md px-2 py-1 text-xs">
                <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md"><?php echo t('common.search'); ?></button>
            </form>
        </div>

        <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.members.type'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.members.email'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.members.name'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.members.business_name'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.members.joined_date'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.members.tier_status'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('common.actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($members)): ?>
                    <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400"><?php echo t('mall_admin.members.empty'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($members as $m): ?>
                    <tr class="border-t border-gray-100" data-member-id="<?php echo (int)$m['id']; ?>">
                        <td class="px-3 py-2"><?php echo $m['member_type'] === 'wholesale' ? '<span class="text-purple-700 font-semibold">' . t('mall_admin.orders.channel_wholesale') . '</span>' : '<span class="text-teal-700 font-semibold">' . t('mall_admin.orders.channel_retail') . '</span>'; ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars($m['email']); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars($m['name']); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars($m['business_name'] ?? ''); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars(substr($m['created_at'], 0, 10)); ?></td>
                        <td class="px-3 py-2">
                            <?php if ($m['member_type'] === 'retail'): ?>
                                <select class="tier-select border border-gray-300 rounded px-1 py-0.5 text-xs" data-member-id="<?php echo (int)$m['id']; ?>">
                                    <?php foreach ($tier_labels as $key => $label): ?>
                                        <option value="<?php echo $key; ?>" <?php echo $m['retail_tier'] === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded text-xs font-semibold
                                    <?php echo $m['wholesale_status'] === 'approved' ? 'bg-green-100 text-green-700' : ($m['wholesale_status'] === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'); ?>">
                                    <?php echo $status_labels[$m['wholesale_status']] ?? $m['wholesale_status']; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2">
                            <?php if ($m['member_type'] === 'wholesale' && $m['wholesale_status'] === 'pending'): ?>
                                <div class="flex items-center gap-1">
                                    <select class="customer-select border border-gray-300 rounded px-1 py-0.5 text-xs">
                                        <option value=""><?php echo t('mall_admin.members.new_customer'); ?></option>
                                        <?php foreach ($customers as $c): ?>
                                            <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="approve-btn px-2 py-1 bg-green-600 text-white rounded text-xs" data-member-id="<?php echo (int)$m['id']; ?>"><?php echo t('mall_admin.members.approve'); ?></button>
                                    <button class="reject-btn px-2 py-1 bg-red-500 text-white rounded text-xs" data-member-id="<?php echo (int)$m['id']; ?>"><?php echo t('mall_admin.members.reject'); ?></button>
                                </div>
                            <?php endif; ?>
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

document.querySelectorAll('.approve-btn, .reject-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const row = btn.closest('tr');
        const memberId = btn.dataset.memberId;
        const action = btn.classList.contains('approve-btn') ? 'approve' : 'reject';
        const customerSelect = row.querySelector('.customer-select');
        const wholesaleCustomerId = customerSelect ? customerSelect.value : '';

        fetch('ajax/approve_wholesale_member.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'member_id=' + encodeURIComponent(memberId) + '&action=' + action + '&wholesale_customer_id=' + encodeURIComponent(wholesaleCustomerId) + '&csrf_token=' + encodeURIComponent(window.MALL_CSRF_TOKEN)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showFlash(action === 'approve' ? '<?php echo addslashes(t('mall_admin.members.approved_msg')); ?>' : '<?php echo addslashes(t('mall_admin.members.rejected_msg')); ?>', 'success');
                setTimeout(() => window.location.reload(), 800);
            } else {
                showFlash(data.error && data.error.message ? data.error.message : '<?php echo addslashes(t('mall_admin.members.process_error')); ?>', 'error');
            }
        })
        .catch(() => showFlash('<?php echo addslashes(t('mall_admin.members.network_error')); ?>', 'error'));
    });
});

document.querySelectorAll('.tier-select').forEach(function (select) {
    select.addEventListener('change', function () {
        const memberId = select.dataset.memberId;
        fetch('ajax/update_member_tier.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'member_id=' + encodeURIComponent(memberId) + '&retail_tier=' + encodeURIComponent(select.value) + '&csrf_token=' + encodeURIComponent(window.MALL_CSRF_TOKEN)
        })
        .then(r => r.json())
        .then(data => {
            showFlash(data.success ? '<?php echo addslashes(t('mall_admin.members.tier_changed_msg')); ?>' : (data.error && data.error.message ? data.error.message : '<?php echo addslashes(t('common.error_occurred')); ?>'), data.success ? 'success' : 'error');
        })
        .catch(() => showFlash('<?php echo addslashes(t('mall_admin.members.network_error')); ?>', 'error'));
    });
});
</script>
</body>
</html>
