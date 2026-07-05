<?php
/**
 * 미수금 현황 (플레이스홀더)
 * 추후 외상 미수금 집계/수금 관리 기능 구현 예정.
 */
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '미수금 현황 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 도매 관리 권한과 동일하게 접근 제어
if (!has_permission('wholesale_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: index.php');
    exit;
}
?>

<div class="p-4 sm:p-6">
    <div class="flex items-center mb-6">
        <i class="fas fa-hand-holding-usd text-yellow-600 text-2xl mr-3"></i>
        <h1 class="text-xl font-bold text-gray-800">미수금 현황</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
        <i class="fas fa-tools text-gray-300 text-4xl mb-3"></i>
        <p>준비 중인 기능입니다.</p>
    </div>
</div>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
