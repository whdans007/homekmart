<?php
// Design Ref: docs/02-design/features/role-permission-management.design.md §4.2, §5.2
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('role_management.matrix_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/system_header.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 권한 매트릭스는 settings 권한(super_admin)만 접근 가능
require_permission('settings');

$roles = get_all_roles();
$permission_keys = get_all_permission_keys();
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

<div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400 mb-6">
    <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
        <div>
            <h3 class="text-lg leading-6 font-semibold text-gray-900"><?php echo t('role_management.matrix_title'); ?></h3>
            <p class="mt-1 text-sm text-gray-500"><?php echo t('role_management.matrix_notice'); ?></p>
        </div>
        <a href="role_management.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-list mr-2"></i>
            <?php echo t('role_management.go_to_roles'); ?>
        </a>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full border-collapse">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider sticky left-0 bg-gray-50 z-10"><?php echo t('role_management.role_column'); ?></th>
                    <?php foreach ($permission_keys as $perm_key): ?>
                        <th class="px-3 py-3 text-center text-xs font-semibold text-gray-700 whitespace-nowrap">
                            <?php echo htmlspecialchars(get_permission_label($perm_key)); ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="bg-white">
                <?php foreach ($roles as $role): ?>
                    <?php
                        $role_key = $role['role_key'];
                        $is_super_admin = ($role_key === 'super_admin');
                        $perms = get_role_permissions($role_key);
                    ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                        <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900 sticky left-0 bg-white z-10">
                            <?php echo htmlspecialchars($role['label']); ?>
                            <span class="text-xs text-gray-400 font-mono">(<?php echo htmlspecialchars($role_key); ?>)</span>
                        </td>
                        <?php foreach ($permission_keys as $perm_key): ?>
                            <?php $enabled = !empty($perms[$perm_key]); ?>
                            <td class="px-3 py-2 text-center">
                                <input
                                    type="checkbox"
                                    class="perm-checkbox h-4 w-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500"
                                    data-role-key="<?php echo htmlspecialchars($role_key); ?>"
                                    data-permission-key="<?php echo htmlspecialchars($perm_key); ?>"
                                    <?php echo $enabled ? 'checked' : ''; ?>
                                    <?php echo $is_super_admin ? 'disabled' : ''; ?>
                                >
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<!-- 저장 결과 토스트 -->
<div id="matrix-toast" class="fixed bottom-6 right-6 hidden px-4 py-3 rounded-md shadow-lg text-sm font-medium text-white z-50"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toast = document.getElementById('matrix-toast');
    let toastTimer = null;

    function showToast(message, isSuccess) {
        toast.textContent = message;
        toast.className = 'fixed bottom-6 right-6 px-4 py-3 rounded-md shadow-lg text-sm font-medium text-white z-50 '
            + (isSuccess ? 'bg-green-600' : 'bg-red-600');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toast.className += ' hidden'; }, 2000);
    }

    document.querySelectorAll('.perm-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const cb = this;
            const payload = {
                role_key: cb.dataset.roleKey,
                permission_key: cb.dataset.permissionKey,
                enabled: cb.checked ? 1 : 0
            };

            fetch('ajax_update_role_permission.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('<?php echo t('role_management.save_success'); ?>', true);
                } else {
                    cb.checked = !cb.checked; // 롤백
                    showToast(data.message || '<?php echo t('role_management.save_failed'); ?>', false);
                }
            })
            .catch(() => {
                cb.checked = !cb.checked; // 롤백
                showToast('<?php echo t('role_management.save_failed'); ?>', false);
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/partials/system_footer.php'; ?>
