<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'discount_rules.php';

$conn = get_db_connection();

$retail_rules = [];
$res = $conn->query('SELECT tier, discount_rate, min_cumulative_amount FROM mall_retail_discount_rules');
while ($row = $res->fetch_assoc()) {
    $retail_rules[$row['tier']] = $row;
}
$tier_labels = [
    'general' => t('mall_admin.members.tier_general'), 'good' => t('mall_admin.members.tier_good'),
    'vip' => t('mall_admin.members.tier_vip'), 'platinum' => t('mall_admin.members.tier_platinum'),
];

$instant_tiers = $conn->query(
    'SELECT id, min_order_amount, discount_rate, sort_order, is_active
     FROM mall_wholesale_instant_discount_tiers ORDER BY sort_order, min_order_amount'
)->fetch_all(MYSQLI_ASSOC);

$cumulative_tiers = $conn->query(
    'SELECT id, tier_name, min_cumulative_amount, additional_discount_rate, sort_order
     FROM mall_wholesale_cumulative_tiers ORDER BY sort_order, min_cumulative_amount'
)->fetch_all(MYSQLI_ASSOC);

// 큐레이션 화면의 "기준도매가" 계산에 쓰이는 원가 대비 마진율. 없으면 기본 15%.
$markup_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'mall_wholesale_reference_markup_rate'");
$markup_stmt->execute();
$markup_row = $markup_stmt->get_result()->fetch_assoc();
$markup_stmt->close();
$wholesale_reference_markup_rate = $markup_row ? (float)$markup_row['setting_value'] : 15.0;

// 체크아웃 화면의 기본 배송비/무료배송 기준금액. 없으면 mall_config.php와 동일한 기본값 사용.
$shipping_stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('mall_base_shipping_fee', 'mall_free_shipping_threshold')");
$shipping_stmt->execute();
$shipping_rows = $shipping_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$shipping_stmt->close();
$shipping_settings = [];
foreach ($shipping_rows as $row) {
    $shipping_settings[$row['setting_key']] = (float)$row['setting_value'];
}
$base_shipping_fee = $shipping_settings['mall_base_shipping_fee'] ?? 79.0;
$free_shipping_threshold = $shipping_settings['mall_free_shipping_threshold'] ?? 1200.0;

// 포인트 적립율(회사 규정: 구매 금액의 2% 적립). 없으면 기본 2%.
$point_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'mall_point_accrual_rate'");
$point_stmt->execute();
$point_row = $point_stmt->get_result()->fetch_assoc();
$point_stmt->close();
$point_accrual_rate = $point_row ? (float)$point_row['setting_value'] : 2.0;

// 주문 관리 화면 "접수확인" 버튼에 기본값으로 채워지는 상품 준비 소요시간(분). 없으면 기본 30분.
$prep_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'mall_default_prep_minutes'");
$prep_stmt->execute();
$prep_row = $prep_stmt->get_result()->fetch_assoc();
$prep_stmt->close();
$default_prep_minutes = $prep_row ? (int)$prep_row['setting_value'] : 30;

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.nav.settings'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6 max-w-4xl">
        <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-percent mr-2"></i><?php echo t('mall_admin.discount_rules.page_title'); ?></h1>
        <div id="flash-area"></div>

        <!-- 회원등급(소매)별 할인율 -->
        <section class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
            <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo t('mall_admin.discount_rules.member_tier'); ?></h2>
            <table class="min-w-full text-xs mb-2">
                <thead class="bg-gray-100 text-gray-600">
                    <tr><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.tier'); ?></th><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.discount_rate'); ?></th><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.tier_min_cumulative'); ?></th></tr>
                </thead>
                <tbody id="retail-rules-body">
                    <?php foreach ($tier_labels as $key => $label): $r = $retail_rules[$key] ?? ['discount_rate' => 0, 'min_cumulative_amount' => 0]; ?>
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2 font-semibold"><?php echo htmlspecialchars($label); ?></td>
                        <td class="px-3 py-2"><input type="number" step="0.01" min="0" max="100" class="retail-rate border border-gray-300 rounded px-2 py-1 w-24" data-tier="<?php echo $key; ?>" value="<?php echo htmlspecialchars($r['discount_rate']); ?>"></td>
                        <td class="px-3 py-2"><input type="number" step="0.01" min="0" class="retail-min border border-gray-300 rounded px-2 py-1 w-32" data-tier="<?php echo $key; ?>" value="<?php echo htmlspecialchars($r['min_cumulative_amount']); ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button id="save-retail-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo t('common.save'); ?></button>
        </section>

        <!-- 기준도매가 마진율 (상품 큐레이션 화면 참고용 표시값 계산 기준) -->
        <section class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
            <h2 class="text-sm font-bold text-gray-700 mb-1"><?php echo t('mall_admin.discount_rules.wholesale_reference_rate'); ?></h2>
            <p class="text-xs text-gray-400 mb-3"><?php echo t('mall_admin.discount_rules.wholesale_reference_rate_hint'); ?></p>
            <div class="flex items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.markup_rate'); ?></label>
                    <input id="wholesale-reference-rate" type="number" step="0.01" min="0" max="100"
                           value="<?php echo htmlspecialchars($wholesale_reference_markup_rate); ?>"
                           class="border border-gray-300 rounded px-2 py-1 w-24">
                </div>
                <button id="save-wholesale-reference-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo t('common.save'); ?></button>
            </div>
        </section>

        <!-- 포인트 적립율 (회사 규정) -->
        <section class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
            <h2 class="text-sm font-bold text-gray-700 mb-1"><?php echo t('mall_admin.discount_rules.point_accrual_rate'); ?></h2>
            <p class="text-xs text-gray-400 mb-3"><?php echo t('mall_admin.discount_rules.point_accrual_rate_hint'); ?></p>
            <div class="flex items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.accrual_rate'); ?></label>
                    <input id="point-accrual-rate" type="number" step="0.01" min="0" max="100"
                           value="<?php echo htmlspecialchars($point_accrual_rate); ?>"
                           class="border border-gray-300 rounded px-2 py-1 w-24">
                </div>
                <button id="save-point-accrual-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo t('common.save'); ?></button>
            </div>
        </section>

        <!-- 배송비 설정 (일반배송) -->
        <section class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
            <h2 class="text-sm font-bold text-gray-700 mb-1"><?php echo t('mall_admin.discount_rules.shipping_settings'); ?></h2>
            <p class="text-xs text-gray-400 mb-3"><?php echo t('mall_admin.discount_rules.shipping_settings_hint'); ?></p>
            <div class="flex items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.base_shipping_fee'); ?></label>
                    <input id="base-shipping-fee" type="number" step="0.01" min="0"
                           value="<?php echo htmlspecialchars($base_shipping_fee); ?>"
                           class="border border-gray-300 rounded px-2 py-1 w-28">
                </div>
                <div>
                    <label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.free_shipping_threshold'); ?></label>
                    <input id="free-shipping-threshold" type="number" step="0.01" min="0"
                           value="<?php echo htmlspecialchars($free_shipping_threshold); ?>"
                           class="border border-gray-300 rounded px-2 py-1 w-32">
                </div>
                <button id="save-shipping-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo t('common.save'); ?></button>
            </div>
        </section>

        <!-- 상품 준비 시간 설정 -->
        <section class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
            <h2 class="text-sm font-bold text-gray-700 mb-1"><?php echo t('mall_admin.discount_rules.prep_time_settings'); ?></h2>
            <p class="text-xs text-gray-400 mb-3"><?php echo t('mall_admin.discount_rules.prep_time_settings_hint'); ?></p>
            <div class="flex items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.default_prep_minutes'); ?></label>
                    <input id="default-prep-minutes" type="number" step="1" min="0"
                           value="<?php echo htmlspecialchars($default_prep_minutes); ?>"
                           class="border border-gray-300 rounded px-2 py-1 w-28">
                </div>
                <button id="save-prep-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo t('common.save'); ?></button>
            </div>
        </section>

        <!-- 도매 즉석할인 구간 -->
        <section class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
            <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo t('mall_admin.discount_rules.instant_tiers'); ?></h2>
            <table class="min-w-full text-xs mb-2">
                <thead class="bg-gray-100 text-gray-600">
                    <tr><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.min_order_amount'); ?></th><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.discount_rate'); ?></th><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.sort_order'); ?></th><th class="px-3 py-2 text-left"><?php echo t('common.active'); ?></th><th class="px-3 py-2 text-left"><?php echo t('common.delete'); ?></th></tr>
                </thead>
                <tbody id="instant-tiers-body">
                    <?php foreach ($instant_tiers as $t): ?>
                    <tr class="border-t border-gray-100" data-id="<?php echo (int)$t['id']; ?>">
                        <td class="px-3 py-2"><?php echo number_format((float)$t['min_order_amount'], 2); ?></td>
                        <td class="px-3 py-2"><?php echo number_format((float)$t['discount_rate'], 2); ?>%</td>
                        <td class="px-3 py-2"><?php echo (int)$t['sort_order']; ?></td>
                        <td class="px-3 py-2"><?php echo $t['is_active'] ? '✅' : '➖'; ?></td>
                        <td class="px-3 py-2"><button class="instant-delete-btn text-red-600" data-id="<?php echo (int)$t['id']; ?>"><?php echo t('common.delete'); ?></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="flex items-end gap-2">
                <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.min_order_amount'); ?></label><input id="instant-min" type="number" step="0.01" min="0" class="border border-gray-300 rounded px-2 py-1 w-32"></div>
                <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.discount_rate'); ?></label><input id="instant-rate" type="number" step="0.01" min="0" max="100" class="border border-gray-300 rounded px-2 py-1 w-24"></div>
                <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.sort_order'); ?></label><input id="instant-sort" type="number" step="1" value="0" class="border border-gray-300 rounded px-2 py-1 w-20"></div>
                <button id="instant-add-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo t('mall_admin.discount_rules.add_tier'); ?></button>
            </div>
        </section>

        <!-- 도매 누적실적 등급 -->
        <section class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
            <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo t('mall_admin.discount_rules.cumulative_tiers'); ?></h2>
            <table class="min-w-full text-xs mb-2">
                <thead class="bg-gray-100 text-gray-600">
                    <tr><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.tier_name'); ?></th><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.min_cumulative_amount'); ?></th><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.additional_discount_rate'); ?></th><th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.sort_order'); ?></th><th class="px-3 py-2 text-left"><?php echo t('common.delete'); ?></th></tr>
                </thead>
                <tbody id="cumulative-tiers-body">
                    <?php foreach ($cumulative_tiers as $t): ?>
                    <tr class="border-t border-gray-100" data-id="<?php echo (int)$t['id']; ?>">
                        <td class="px-3 py-2"><?php echo htmlspecialchars($t['tier_name']); ?></td>
                        <td class="px-3 py-2"><?php echo number_format((float)$t['min_cumulative_amount'], 2); ?></td>
                        <td class="px-3 py-2"><?php echo number_format((float)$t['additional_discount_rate'], 2); ?>%</td>
                        <td class="px-3 py-2"><?php echo (int)$t['sort_order']; ?></td>
                        <td class="px-3 py-2"><button class="cumulative-delete-btn text-red-600" data-id="<?php echo (int)$t['id']; ?>"><?php echo t('common.delete'); ?></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="flex items-end gap-2">
                <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.tier_name'); ?></label><input id="cumulative-name" type="text" class="border border-gray-300 rounded px-2 py-1 w-28"></div>
                <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.min_cumulative_amount'); ?></label><input id="cumulative-min" type="number" step="0.01" min="0" class="border border-gray-300 rounded px-2 py-1 w-32"></div>
                <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.additional_discount_rate'); ?></label><input id="cumulative-rate" type="number" step="0.01" min="0" max="100" class="border border-gray-300 rounded px-2 py-1 w-24"></div>
                <div><label class="block text-xs text-gray-500"><?php echo t('mall_admin.discount_rules.sort_order'); ?></label><input id="cumulative-sort" type="number" step="1" value="0" class="border border-gray-300 rounded px-2 py-1 w-20"></div>
                <button id="cumulative-add-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md"><?php echo t('mall_admin.discount_rules.add_grade'); ?></button>
            </div>
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
    return fetch('ajax/save_discount_rules.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: withToken
    }).then(r => r.json());
}

document.getElementById('save-retail-btn').addEventListener('click', function () {
    const params = new URLSearchParams();
    params.set('action', 'retail_save');
    document.querySelectorAll('.retail-rate').forEach(el => params.set('rate_' + el.dataset.tier, el.value));
    document.querySelectorAll('.retail-min').forEach(el => params.set('min_' + el.dataset.tier, el.value));
    postAjax(params.toString()).then(data => {
        showFlash(data.success ? '<?php echo addslashes(t('common.save_success')); ?>' : (data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>'), data.success ? 'success' : 'error');
    });
});

document.getElementById('save-wholesale-reference-btn').addEventListener('click', function () {
    const params = new URLSearchParams();
    params.set('action', 'wholesale_reference_save');
    params.set('rate', document.getElementById('wholesale-reference-rate').value);
    postAjax(params.toString()).then(data => {
        showFlash(data.success ? '<?php echo addslashes(t('common.save_success')); ?>' : (data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>'), data.success ? 'success' : 'error');
    });
});

document.getElementById('save-point-accrual-btn').addEventListener('click', function () {
    const params = new URLSearchParams();
    params.set('action', 'point_accrual_save');
    params.set('rate', document.getElementById('point-accrual-rate').value);
    postAjax(params.toString()).then(data => {
        showFlash(data.success ? '<?php echo addslashes(t('common.save_success')); ?>' : (data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>'), data.success ? 'success' : 'error');
    });
});

document.getElementById('save-shipping-btn').addEventListener('click', function () {
    const params = new URLSearchParams();
    params.set('action', 'shipping_save');
    params.set('base_shipping_fee', document.getElementById('base-shipping-fee').value);
    params.set('free_shipping_threshold', document.getElementById('free-shipping-threshold').value);
    postAjax(params.toString()).then(data => {
        showFlash(data.success ? '<?php echo addslashes(t('common.save_success')); ?>' : (data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>'), data.success ? 'success' : 'error');
    });
});

document.getElementById('save-prep-btn').addEventListener('click', function () {
    const params = new URLSearchParams();
    params.set('action', 'prep_minutes_save');
    params.set('minutes', document.getElementById('default-prep-minutes').value);
    postAjax(params.toString()).then(data => {
        showFlash(data.success ? '<?php echo addslashes(t('common.save_success')); ?>' : (data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>'), data.success ? 'success' : 'error');
    });
});

document.getElementById('instant-add-btn').addEventListener('click', function () {
    const params = new URLSearchParams();
    params.set('action', 'instant_add');
    params.set('min_order_amount', document.getElementById('instant-min').value);
    params.set('discount_rate', document.getElementById('instant-rate').value);
    params.set('sort_order', document.getElementById('instant-sort').value);
    postAjax(params.toString()).then(data => {
        if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); }
    });
});

document.querySelectorAll('.instant-delete-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        postAjax('action=instant_delete&id=' + encodeURIComponent(btn.dataset.id)).then(data => {
            if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); }
        });
    });
});

document.getElementById('cumulative-add-btn').addEventListener('click', function () {
    const params = new URLSearchParams();
    params.set('action', 'cumulative_add');
    params.set('tier_name', document.getElementById('cumulative-name').value);
    params.set('min_cumulative_amount', document.getElementById('cumulative-min').value);
    params.set('additional_discount_rate', document.getElementById('cumulative-rate').value);
    params.set('sort_order', document.getElementById('cumulative-sort').value);
    postAjax(params.toString()).then(data => {
        if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); }
    });
});

document.querySelectorAll('.cumulative-delete-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        postAjax('action=cumulative_delete&id=' + encodeURIComponent(btn.dataset.id)).then(data => {
            if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); }
        });
    });
});
</script>
</body>
</html>
