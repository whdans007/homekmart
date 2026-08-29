<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'wholesale_products.php';

$conn = get_db_connection();
$items = $conn->query(
    "SELECT wp.id, wp.wholesale_name_ko, wp.wholesale_price, wp.is_active AS wp_active,
            COALESCE(mwv.is_visible, 0) AS is_visible
     FROM wholesale_products wp
     LEFT JOIN mall_wholesale_visibility mwv ON mwv.wholesale_product_id = wp.id
     WHERE wp.store_id = " . (int)MALL_STORE_ID . "
     ORDER BY wp.wholesale_name_ko"
)->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>도매 상품 노출 - HOME K MART 쇼핑몰</title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
        <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-warehouse mr-2"></i>도매 상품 노출 관리</h1>
        <div id="flash-area"></div>

        <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">도매 상품명</th>
                        <th class="px-3 py-2 text-left">도매가</th>
                        <th class="px-3 py-2 text-left">상태</th>
                        <th class="px-3 py-2 text-left">몰 노출</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">등록된 도매 상품이 없습니다.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $it): ?>
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2"><?php echo htmlspecialchars($it['wholesale_name_ko'] ?? ''); ?></td>
                        <td class="px-3 py-2"><?php echo number_format((float)$it['wholesale_price'], 2); ?></td>
                        <td class="px-3 py-2"><?php echo $it['wp_active'] ? '<span class="text-green-600">활성</span>' : '<span class="text-gray-400">비활성</span>'; ?></td>
                        <td class="px-3 py-2">
                            <label class="inline-flex items-center gap-1 cursor-pointer">
                                <input type="checkbox" class="visibility-toggle" data-id="<?php echo (int)$it['id']; ?>" <?php echo $it['is_visible'] ? 'checked' : ''; ?>>
                                <span>노출</span>
                            </label>
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

document.querySelectorAll('.visibility-toggle').forEach(function (cb) {
    cb.addEventListener('change', function () {
        const params = new URLSearchParams();
        params.set('wholesale_product_id', cb.dataset.id);
        params.set('is_visible', cb.checked ? '1' : '0');
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/toggle_wholesale_visibility.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    cb.checked = !cb.checked;
                    showFlash(data.error?.message || '오류가 발생했습니다.', 'error');
                }
            });
    });
});
</script>
</body>
</html>
