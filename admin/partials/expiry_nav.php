<?php
// 유통기한 관리 하위 화면(점검기록/폐기등록/폐기통계) 간 이동 탭
// $current_page 는 admin/partials/header.php 에서 설정됨
$expiry_nav_items = [
    'expiry_inspection.php' => '점검기록',
    'expiry_disposal.php' => '폐기등록',
    'expiry_disposal_report.php' => '폐기통계',
];
?>
<div class="inline-flex items-center gap-1 bg-gray-100 rounded-lg p-1">
    <?php foreach ($expiry_nav_items as $href => $label): ?>
    <a href="<?php echo $href; ?>" class="px-3 py-1.5 text-sm font-medium rounded-md whitespace-nowrap transition-colors <?php echo $current_page === $href ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500 hover:text-teal-600'; ?>">
        <?php echo $label; ?>
    </a>
    <?php endforeach; ?>
</div>
