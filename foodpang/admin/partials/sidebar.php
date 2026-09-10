<?php
$foodpang_nav = [
    ['href' => 'dashboard.php', 'icon' => 'fa-gauge', 'label' => t('foodpang_admin.dashboard')],
    ['href' => 'products.php', 'icon' => 'fa-box', 'label' => t('foodpang_admin.title')],
];
if (function_exists('has_permission') && has_permission('foodpang_wholesale_management')) {
    $foodpang_nav[] = ['href' => 'wholesale_dashboard.php', 'icon' => 'fa-truck-ramp-box', 'label' => t('foodpang_wholesale.nav_dashboard')];
    $foodpang_nav[] = ['href' => 'wholesale_upload.php', 'icon' => 'fa-file-arrow-up', 'label' => t('foodpang_wholesale.nav_upload')];
    $foodpang_nav[] = ['href' => 'wholesale_review.php', 'icon' => 'fa-triangle-exclamation', 'label' => t('foodpang_wholesale.nav_review')];
    $foodpang_nav[] = ['href' => 'wholesale_mappings.php', 'icon' => 'fa-link', 'label' => t('foodpang_wholesale.nav_mappings')];
}
$__foodpang_current_lang = get_language();
?>
<div class="bg-white border-b border-gray-200">
    <div class="flex items-center justify-between gap-4 px-4 py-2 flex-wrap">
        <div class="flex items-center gap-4 flex-wrap">
            <div class="font-bold text-sm text-gray-800 whitespace-nowrap"><i class="fas fa-motorcycle mr-1.5 text-pink-600"></i><?php echo t('foodpang_admin.management'); ?></div>
            <nav class="flex items-center gap-1 flex-wrap">
                <?php foreach ($foodpang_nav as $item): ?>
                <a href="<?php echo $item['href']; ?>" class="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-md whitespace-nowrap <?php echo $current_page === $item['href'] ? 'bg-pink-100 text-pink-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                    <i class="fas <?php echo $item['icon']; ?>"></i><?php echo htmlspecialchars($item['label']); ?>
                </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <div id="foodpang-lang-toggle" class="flex items-center rounded-md border border-gray-200 overflow-hidden flex-shrink-0" role="group" aria-label="Language">
                <button type="button" class="foodpang-lang-btn px-2 py-1 text-[11px] font-semibold whitespace-nowrap <?php echo $__foodpang_current_lang === 'ko' ? 'bg-pink-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-100'; ?>" data-lang="ko">한국어</button>
                <button type="button" class="foodpang-lang-btn px-2 py-1 text-[11px] font-semibold whitespace-nowrap <?php echo $__foodpang_current_lang === 'en' ? 'bg-pink-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-100'; ?>" data-lang="en">English</button>
            </div>
            <a href="/index.php" class="text-xs text-gray-500 hover:text-gray-700 flex-shrink-0 whitespace-nowrap"><i class="fas fa-arrow-left mr-1"></i><?php echo t('mall_admin.back_to_home'); ?></a>
        </div>
    </div>
</div>
<script>
// 한/영 전환 토글 — admin/ajax_set_language.php(기존 admin/mall 언어 스위처와 동일 세션/엔드포인트)를 그대로 재사용.
// foodpang/admin은 lib/session_helper.php로 admin과 같은 세션을 쓰므로 언어 설정도 공유된다.
// location.reload()로 새로고침하므로 현재 URL의 쿼리 파라미터(batch_id, reason, status, search 등)가 그대로 보존된다.
document.querySelectorAll('.foodpang-lang-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var lang = btn.dataset.lang;
        if (btn.classList.contains('bg-pink-600')) return;
        fetch('../../admin/ajax_set_language.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'language=' + encodeURIComponent(lang)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { window.location.reload(); }
        })
        .catch(function () {});
    });
});
</script>
