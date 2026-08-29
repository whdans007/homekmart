<?php
/**
 * mall/admin 공용 상단 메뉴바
 * Design Ref: shopping-mall.design.md §5.3 Component List, §7 CSRF 토큰
 * 포함하는 쪽에서 $current_page(basename)를 미리 정의해야 활성 메뉴가 표시된다.
 * CSRF 토큰을 window.MALL_CSRF_TOKEN으로 노출해 각 페이지의 fetch() 호출에서 재사용한다.
 * 좌측 고정 사이드바 대신 상단 가로 메뉴로 배치해 본문 영역을 더 넓게 쓴다.
 */
require_once __DIR__ . '/../../lib/csrf.php';
$__mall_csrf_token = mall_csrf_token();

$__mall_nav_items = [
    ['href' => 'dashboard.php', 'icon' => 'fa-gauge', 'label' => '대시보드'],
    ['href' => 'home_layout.php', 'icon' => 'fa-swatchbook', 'label' => '홈 레이아웃'],
    ['href' => 'products.php', 'icon' => 'fa-box', 'label' => '상품 큐레이션'],
    ['href' => 'wholesale_products.php', 'icon' => 'fa-warehouse', 'label' => '도매 상품 노출'],
    ['href' => 'members.php', 'icon' => 'fa-users', 'label' => '회원 관리'],
    ['href' => 'discount_rules.php', 'icon' => 'fa-percent', 'label' => '할인 규칙'],
    ['href' => 'orders.php', 'icon' => 'fa-receipt', 'label' => '주문 관리'],
];
?>
<div class="bg-white border-b border-gray-200">
    <div class="flex items-center justify-between gap-4 px-4 py-2 flex-wrap">
        <div class="flex items-center gap-4 flex-wrap">
            <div class="font-bold text-sm text-gray-800 flex-shrink-0 whitespace-nowrap"><i class="fas fa-store mr-1.5"></i>쇼핑몰 관리</div>
            <nav class="flex items-center gap-1 flex-wrap">
                <?php foreach ($__mall_nav_items as $__item): ?>
                    <a href="<?php echo $__item['href']; ?>"
                       class="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-md whitespace-nowrap transition-colors <?php echo ($current_page ?? '') === $__item['href'] ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <i class="fas <?php echo $__item['icon']; ?>"></i><?php echo $__item['label']; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <a href="../../admin/index.php" class="text-xs text-gray-500 hover:text-gray-700 flex-shrink-0 whitespace-nowrap"><i class="fas fa-arrow-left mr-1"></i>관리자 메인으로</a>
    </div>
</div>
<script>window.MALL_CSRF_TOKEN = <?php echo json_encode($__mall_csrf_token); ?>;</script>
