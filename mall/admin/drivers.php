<?php
/**
 * 배송기사 관리 화면
 * Design Ref: mall-delivery-dispatch.design.md §5.4 — 기사 목록/추가/활성화 토글/통계
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../lib/delivery.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'drivers.php';

$conn = get_db_connection();
$drivers = $conn->query(
    'SELECT id, name, phone, vehicle_info, is_active, driver_type FROM mall_drivers ORDER BY name'
)->fetch_all(MYSQLI_ASSOC);
$conn->close();

$stats = mall_driver_stats();
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.nav.drivers'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6 max-w-4xl">
    <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-motorcycle mr-2"></i><?php echo t('mall_admin.nav.drivers'); ?></h1>
    <div id="flash-area"></div>

    <section class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo t('mall_admin.drivers.driver_list'); ?></h2>
        <table id="driver-table" class="min-w-full text-xs mb-2">
            <thead class="bg-gray-100 text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.members.name'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.drivers.phone'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.drivers.vehicle_info'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.members.type'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('common.active'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.drivers.manage'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($drivers)): ?>
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400"><?php echo t('mall_admin.drivers.empty'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($drivers as $d): ?>
                <tr class="border-t border-gray-100" data-driver-id="<?php echo (int)$d['id']; ?>">
                    <td class="px-3 py-2 font-semibold view-cell" data-field="name"><?php echo htmlspecialchars($d['name']); ?></td>
                    <td class="px-3 py-2 view-cell" data-field="phone"><?php echo htmlspecialchars($d['phone']); ?></td>
                    <td class="px-3 py-2 view-cell" data-field="vehicle_info"><?php echo htmlspecialchars($d['vehicle_info'] ?? ''); ?></td>
                    <td class="px-3 py-2"><?php echo $d['driver_type'] === 'external' ? t('mall_admin.drivers.type_external') : t('mall_admin.drivers.type_internal'); ?></td>
                    <td class="px-3 py-2">
                        <button type="button" class="toggle-active-btn px-2 py-1 text-xs font-semibold rounded-md <?php echo $d['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $d['is_active'] ? t('common.active') : t('common.inactive'); ?>
                        </button>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap manage-cell">
                        <button type="button" class="edit-driver-btn px-2 py-1 text-xs font-semibold bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200"><?php echo t('common.edit'); ?></button>
                        <button type="button" class="delete-driver-btn px-2 py-1 text-xs font-semibold bg-red-50 text-red-600 rounded-md hover:bg-red-100"><?php echo t('common.delete'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="flex items-end gap-2 flex-wrap">
            <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.members.name'); ?></label><input id="new-name" type="text" class="border border-gray-300 rounded px-2 py-1 w-28"></div>
            <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.drivers.phone'); ?></label><input id="new-phone" type="text" class="border border-gray-300 rounded px-2 py-1 w-32"></div>
            <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.drivers.password_hint'); ?></label><input id="new-password" type="password" class="border border-gray-300 rounded px-2 py-1 w-32"></div>
            <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.drivers.vehicle_info'); ?></label><input id="new-vehicle" type="text" class="border border-gray-300 rounded px-2 py-1 w-32"></div>
            <button id="create-driver-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo t('mall_admin.drivers.add_driver'); ?></button>
        </div>
    </section>

    <section class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo t('mall_admin.drivers.stats_title'); ?></h2>
        <table class="min-w-full text-xs">
            <thead class="bg-gray-100 text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.members.name'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.drivers.completed_count'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.drivers.failed_count'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.drivers.avg_minutes'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stats)): ?>
                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400"><?php echo t('mall_admin.drivers.no_data'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($stats as $s): ?>
                <tr class="border-t border-gray-100">
                    <td class="px-3 py-2 font-semibold"><?php echo htmlspecialchars($s['name']); ?></td>
                    <td class="px-3 py-2"><?php echo (int)$s['completed_count']; ?></td>
                    <td class="px-3 py-2"><?php echo (int)$s['failed_count']; ?></td>
                    <td class="px-3 py-2"><?php echo $s['avg_minutes'] !== null ? number_format((float)$s['avg_minutes'], 1) : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>

<script>
function showFlash(message, type) {
    const area = document.getElementById('flash-area');
    const color = type === 'error' ? 'bg-red-100 text-red-700 border-red-300' : 'bg-green-100 text-green-700 border-green-300';
    area.innerHTML = '<div class="mb-3 px-3 py-2 text-xs rounded border ' + color + '">' + message + '</div>';
    setTimeout(() => { area.innerHTML = ''; }, 4000);
}

function postAjax(body) {
    const withToken = body + '&csrf_token=' + encodeURIComponent(window.MALL_CSRF_TOKEN);
    return fetch('ajax/save_driver.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: withToken
    }).then(r => r.json());
}

document.getElementById('create-driver-btn').addEventListener('click', function () {
    const params = new URLSearchParams();
    params.set('action', 'create');
    params.set('name', document.getElementById('new-name').value);
    params.set('phone', document.getElementById('new-phone').value);
    params.set('password', document.getElementById('new-password').value);
    params.set('vehicle_info', document.getElementById('new-vehicle').value);
    postAjax(params.toString()).then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error');
        }
    });
});

document.querySelectorAll('.toggle-active-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const id = btn.closest('tr').dataset.driverId;
        postAjax('action=toggle_active&id=' + encodeURIComponent(id)).then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error');
            }
        });
    });
});

// 수정/삭제/저장/취소는 행이 새로 렌더되지 않으므로(수정 모드가 버튼을 갈아끼움) 테이블 전체에
// 위임 리스너 하나만 걸어둔다 — 버튼마다 개별로 다시 바인딩할 필요가 없다.
document.querySelector('#driver-table tbody').addEventListener('click', function (e) {
    const editBtn = e.target.closest('.edit-driver-btn');
    const deleteBtn = e.target.closest('.delete-driver-btn');
    const saveBtn = e.target.closest('.save-driver-btn');
    const cancelBtn = e.target.closest('.cancel-driver-btn');

    if (editBtn) {
        const row = editBtn.closest('tr');
        row.querySelectorAll('.view-cell').forEach(function (cell) {
            cell.dataset.original = cell.textContent;
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'edit-input border border-gray-300 rounded px-1.5 py-1 text-xs w-full';
            input.value = cell.textContent;
            cell.textContent = '';
            cell.appendChild(input);
        });
        const manageCell = row.querySelector('.manage-cell');
        manageCell.dataset.original = manageCell.innerHTML;
        manageCell.innerHTML =
            '<input type="password" class="edit-password border border-gray-300 rounded px-1.5 py-1 text-xs w-28 mb-1" placeholder="<?php echo addslashes(t('mall_admin.drivers.new_password_optional')); ?>">' +
            '<div class="flex gap-1">' +
            '<button type="button" class="save-driver-btn px-2 py-1 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo addslashes(t('common.save')); ?></button>' +
            '<button type="button" class="cancel-driver-btn px-2 py-1 text-xs font-semibold bg-gray-100 text-gray-600 rounded-md"><?php echo addslashes(t('common.cancel')); ?></button>' +
            '</div>';
        return;
    }

    if (cancelBtn) {
        const row = cancelBtn.closest('tr');
        row.querySelectorAll('.view-cell').forEach(function (cell) {
            cell.textContent = cell.dataset.original;
        });
        const manageCell = row.querySelector('.manage-cell');
        manageCell.innerHTML = manageCell.dataset.original;
        return;
    }

    if (saveBtn) {
        const row = saveBtn.closest('tr');
        const manageCell = row.querySelector('.manage-cell');
        saveBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('action', 'update');
        params.set('id', row.dataset.driverId);
        params.set('name', row.querySelector('.view-cell[data-field="name"] input').value.trim());
        params.set('phone', row.querySelector('.view-cell[data-field="phone"] input').value.trim());
        params.set('vehicle_info', row.querySelector('.view-cell[data-field="vehicle_info"] input').value.trim());
        params.set('password', manageCell.querySelector('.edit-password').value);
        postAjax(params.toString()).then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                saveBtn.disabled = false;
                showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error');
            }
        });
        return;
    }

    if (deleteBtn) {
        const row = deleteBtn.closest('tr');
        const name = row.querySelector('.view-cell[data-field="name"]').textContent;
        if (!confirm(name + '<?php echo addslashes(t('mall_admin.drivers.delete_confirm')); ?>')) return;
        postAjax('action=delete&id=' + encodeURIComponent(row.dataset.driverId)).then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error');
            }
        });
    }
});
</script>
</body>
</html>
