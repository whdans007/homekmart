<?php
// Design Ref: §6.1 — 역할별 네비게이션, teal 테마
ob_start();
require_once __DIR__ . '/../lib/auth.php';
lc_require_login();

$_lc_page    = basename($_SERVER['PHP_SELF']);
$_lc_is_staff = lc_is_staff();
$_lc_flash    = lc_get_flash();

// 현재 사용자 점포명 조회 (staff 포함 — store_id 있으면 조회)
$_lc_store_name = '';
if (!empty($_SESSION['store_id'])) {
    try {
        require_once __DIR__ . '/../config/db.php';
        $conn = get_lc_db();
        $st = $conn->prepare("SELECT name FROM stores WHERE id = ?");
        $st->bind_param('i', $_SESSION['store_id']);
        $st->execute();
        $_lc_store_name = $st->get_result()->fetch_row()[0] ?? '';
        $st->close();
        $conn->close();
    } catch (Exception $e) { /* 조회 실패 시 빈 문자열 */ }
}

// 현재 사용자 이름 / 등급(역할) 라벨
$_lc_user_name  = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$_lc_role_label = !empty($_SESSION['role']) ? get_role_label($_SESSION['role']) : '';
// 직원(staff)은 실제 소속 점포와 무관하게 항상 "M TOWN CENTER"로 표시
$_lc_store_label = $_lc_is_staff ? t('logistics.common.center') : ($_lc_store_name !== '' ? $_lc_store_name : t('logistics.common.store'));

// Order List 배지용 — 처리 대기 주문 수 (pending = "Order Received")
$_lc_pending_orders = 0;
// Store Requests 배지용 — 미확인(대기) 요청 수
// Design Ref: docs/02-design/features/store-request-board.design.md §5
$_lc_pending_requests = 0;
if ($_lc_is_staff) {
    try {
        require_once __DIR__ . '/../config/db.php';
        require_once __DIR__ . '/../../lib/store_request_helper.php';
        $conn = get_lc_db();
        $_lc_pending_orders   = (int)$conn->query("SELECT COUNT(*) FROM lc_orders WHERE status = 'pending'")->fetch_row()[0];
        $_lc_pending_requests = count_pending_store_requests($conn);
        $conn->close();
    } catch (Throwable $e) { /* 조회 실패 시 0 (마이그레이션 미적용 등 Error도 포함) */ }
}
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? t('logistics.common.center_title')); ?> - <?php echo htmlspecialchars(t('logistics.common.center_title')); ?></title>
    <link rel="icon" href="data:,">
    <link href="<?php echo LC_WEB_ROOT; ?>/admin/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* 사이드바 메뉴 폰트 강제 축소 (컴파일된 CSS 누락 대비) */
        #lc-nav a { font-size: 11px !important; line-height: 1.2 !important; }
        #lc-nav p { font-size: 9px !important; }
        #lc-nav a i { font-size: 10px !important; }
        /* 마우스 호버 시 배경 강조 */
        #lc-nav a:hover { background: #ccfbf1 !important; color: #0f766e !important; }
        /* Inventory Status 강조 버튼은 더 진하게 */
        #lc-inventory:hover { background: #0f766e !important; color: #ffffff !important; }
        /* Dashboard 강조 버튼 (indigo) — 기본 hover 규칙 덮어쓰기 */
        #lc-dashboard:hover { background: #4338ca !important; color: #ffffff !important; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

<div class="flex h-screen bg-gray-50">
    <!-- 사이드바 -->
    <div class="hidden md:flex md:flex-shrink-0">
        <div class="flex flex-col w-52">
            <div class="flex flex-col flex-grow pt-5 pb-4 overflow-y-auto bg-white border-r border-teal-100">

                <!-- 로고 -->
                <div class="flex flex-col flex-shrink-0 px-2 pt-1 mb-2">
                    <div class="block mb-2">
                        <img src="<?php echo LC_WEB_ROOT; ?>/logo/homekmart_logo.png" alt="Home K Mart" style="width:100%;height:auto;display:block;">
                    </div>
                    <a href="<?php echo LC_WEB_ROOT; ?>/"
                       class="no-print flex items-center gap-2 w-full px-2 py-1.5 text-xs font-semibold rounded-md transition-colors"
                       style="background:#1e40af;color:#ffffff;"
                       onmouseover="this.style.background='#1e3a8a'" onmouseout="this.style.background='#1e40af'">
                        <i class="fas fa-globe"></i> <?php echo t('logistics.nav.main'); ?>
                    </a>
                </div>

                <!-- 상단 사용자 정보 카드 (office/store 1:1) -->
                <div class="flex-shrink-0 mx-2 mb-2" style="border:1px solid #ccfbf1;border-radius:0.6rem;background:linear-gradient(135deg,#f0fdfa 0%,#ecfdf5 100%);overflow:hidden;">
                    <div style="display:flex;align-items:center;gap:0.35rem;padding:0.5rem 0.55rem;background:#0d9488;color:#fff;">
                        <i class="fas fa-store" style="font-size:0.8rem;flex-shrink:0;"></i>
                        <span style="font-weight:600;font-size:11px;line-height:1.15;"><?php echo htmlspecialchars($_lc_store_label); ?></span>
                    </div>
                    <div style="padding:0.5rem 0.55rem;">
                        <div style="display:flex;align-items:center;gap:0.35rem;color:#334155;font-size:11px;margin-bottom:0.5rem;">
                            <i class="fas fa-circle-user" style="color:#0d9488;font-size:0.9rem;"></i>
                            <span style="font-weight:500;"><?php echo htmlspecialchars($_lc_user_name); ?></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.4rem;">
                            <?php if ($_lc_role_label !== ''): ?>
                            <span style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.35rem 0.5rem;font-size:11px;font-weight:600;color:#0f766e;background:#ccfbf1;border-radius:0.4rem;white-space:nowrap;">
                                <i class="fas fa-id-badge" style="font-size:0.62rem;"></i><?php echo htmlspecialchars($_lc_role_label); ?>
                            </span>
                            <?php endif; ?>
                            <a href="<?php echo LC_BASE; ?>/logout.php"
                               style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:0.3rem;padding:0.35rem 0.4rem;font-size:11px;font-weight:600;white-space:nowrap;color:#dc2626;background:#fef2f2;border-radius:0.4rem;transition:background 0.15s;"
                               onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'"
                                onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('logistics.nav.sign_out_confirm'), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)">
                                 <i class="fas fa-sign-out-alt"></i><?php echo t('logistics.nav.logout'); ?>
                            </a>
                        </div>
                        <div id="language-switcher" style="display:flex;width:100%;border:1px solid #99f6e4;border-radius:0.4rem;overflow:hidden;font-size:11px;font-weight:600;">
                            <button type="button" data-lang="ko" style="flex:1;padding:0.35rem 0;border:none;cursor:pointer;transition:background 0.15s,color 0.15s;<?php echo get_language() === 'ko' ? 'background:#0f766e;color:#fff;' : 'background:#fff;color:#0f766e;'; ?>">한글</button>
                            <button type="button" data-lang="en" style="flex:1;padding:0.35rem 0;border:none;cursor:pointer;transition:background 0.15s,color 0.15s;<?php echo get_language() === 'en' ? 'background:#0f766e;color:#fff;' : 'background:#fff;color:#0f766e;'; ?>">ENGLISH</button>
                        </div>
                    </div>
                </div>

                <nav id="lc-nav" class="flex-grow px-2 space-y-1">

                    <?php if ($_lc_is_staff): ?>
                    <!-- Dashboard (컬러 강조 버튼) -->
                    <a id="lc-dashboard" href="<?php echo LC_BASE; ?>/index.php"
                       class="flex items-center px-3 py-2.5 text-sm font-bold rounded-lg shadow-md transition-colors"
                       style="<?php echo $_lc_page === 'index.php' ? 'background:#4f46e5;color:#fff;' : 'background:#6366f1;color:#fff;'; ?>">
                        <i class="fas fa-tachometer-alt mr-2 w-4 text-center"></i>
                        <?php echo t('logistics.nav.dashboard'); ?>
                    </a>

                    <!-- Inventory Status 강조 섹션 -->
                    <a id="lc-inventory" href="<?php echo LC_BASE; ?>/inventory.php"
                       class="flex items-center px-3 py-2.5 mt-3 text-sm font-bold rounded-lg shadow-md transition-colors"
                       style="<?php echo $_lc_page === 'inventory.php' ? 'background:#0d9488;color:#fff;' : 'background:#14b8a6;color:#fff;'; ?>">
                        <i class="fas fa-cubes mr-2 w-4 text-center"></i>
                        <?php echo t('logistics.nav.inventory_status'); ?>
                    </a>

                    <!-- Master Data 섹션 (blue) -->
                    <div class="rounded-lg px-1.5 py-2 mt-3" style="background:#eff6ff;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold text-blue-700 uppercase tracking-wider rounded" style="background:#dbeafe;"><?php echo t('logistics.nav.master_data'); ?></p>
                        <a href="<?php echo LC_BASE; ?>/products.php"
                           class="<?php echo in_array($_lc_page, ['products.php','product_add.php','product_edit.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-box-open mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.product_master'); ?>
                        </a>
                        <a href="<?php echo LC_BASE; ?>/suppliers.php"
                           class="<?php echo $_lc_page === 'suppliers.php' ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-truck mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.suppliers'); ?>
                        </a>
                        <a href="<?php echo LC_BASE; ?>/brand_manage.php"
                           class="<?php echo $_lc_page === 'brand_manage.php' ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-tags mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.brand_management'); ?>
                        </a>
                        <a href="<?php echo LC_BASE; ?>/category_manage.php"
                           class="<?php echo $_lc_page === 'category_manage.php' ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-sitemap mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.category_management'); ?>
                        </a>
                    </div>

                    <!-- Inbound/Outbound 섹션 (green) -->
                    <div class="rounded-lg px-1.5 py-2 mt-3" style="background:#f0fdf4;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold text-green-700 uppercase tracking-wider rounded" style="background:#dcfce7;"><?php echo t('logistics.nav.inbound_outbound'); ?></p>
                        <a href="<?php echo LC_BASE; ?>/inbound.php"
                           class="<?php echo in_array($_lc_page, ['inbound.php','inbound_add.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-arrow-down mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.inbound_management'); ?>
                        </a>
                        <a href="<?php echo LC_BASE; ?>/inbound_items.php"
                           class="<?php echo $_lc_page === 'inbound_items.php' ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-list mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.inbound_items'); ?>
                        </a>
                        <a href="<?php echo LC_BASE; ?>/outbound.php"
                           class="<?php echo $_lc_page === 'outbound.php' ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-arrow-up mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.outbound_history'); ?>
                        </a>
                        <a href="<?php echo LC_BASE; ?>/inbound_damages.php"
                           class="<?php echo $_lc_page === 'inbound_damages.php' ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-triangle-exclamation mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.damaged_goods'); ?>
                        </a>
                    </div>

                    <!-- Order Management 섹션 (amber) -->
                    <div class="rounded-lg px-1.5 py-2 mt-3" style="background:#fffbeb;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold text-amber-700 uppercase tracking-wider rounded" style="background:#fef3c7;"><?php echo t('logistics.nav.order_management'); ?></p>
                        <a href="<?php echo LC_BASE; ?>/orders.php"
                           class="<?php echo in_array($_lc_page, ['orders.php','order_detail.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-clipboard-list mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.order_list'); ?>
                            <?php if ($_lc_pending_orders > 0): ?>
                            <span class="ml-auto inline-flex items-center justify-center font-bold"
                                  style="background:#dc2626;color:#fff;min-width:1.15rem;height:1.15rem;padding:0 0.3rem;border-radius:9999px;font-size:0.65rem;line-height:1"
                                  title="<?php echo htmlspecialchars(t('logistics.nav.pending_orders', ['count' => $_lc_pending_orders])); ?>">
                                <?php echo $_lc_pending_orders > 99 ? '99+' : $_lc_pending_orders; ?>
                            </span>
                            <?php endif; ?>
                        </a>
                        <a href="<?php echo LC_BASE; ?>/branch_outbound_list.php"
                           class="<?php echo in_array($_lc_page, ['branch_outbound.php','branch_outbound_list.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-dolly mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.branch_outbound'); ?>
                        </a>
                    </div>

                    <!-- Store Requests 섹션 (rose) -->
                    <div class="rounded-lg px-1.5 py-2 mt-3" style="background:#fff1f2;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold text-rose-700 uppercase tracking-wider rounded" style="background:#ffe4e6;"><?php echo t('logistics.nav.store_requests'); ?></p>
                        <a href="<?php echo LC_BASE; ?>/requests.php"
                           class="<?php echo in_array($_lc_page, ['requests.php','request_detail.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-comment-dots mr-2 text-xs w-4 text-center"></i>
                            <?php echo t('logistics.nav.store_requests'); ?>
                            <?php if ($_lc_pending_requests > 0): ?>
                            <span class="ml-auto inline-flex items-center justify-center font-bold"
                                  style="background:#dc2626;color:#fff;min-width:1.15rem;height:1.15rem;padding:0 0.3rem;border-radius:9999px;font-size:0.65rem;line-height:1"
                                  title="<?php echo htmlspecialchars(t('logistics.nav.pending_requests', ['count' => $_lc_pending_requests])); ?>">
                                <?php echo $_lc_pending_requests > 99 ? '99+' : $_lc_pending_requests; ?>
                            </span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <?php else: ?>
                    <!-- Store staff menu -->
                    <div class="pt-3">
                        <p class="px-2 text-xs font-semibold text-gray-400 uppercase tracking-wider"><?php echo t('logistics.nav.orders'); ?></p>
                    </div>
                    <a href="<?php echo LC_BASE; ?>/order_new.php"
                       class="<?php echo $_lc_page === 'order_new.php' ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                        <i class="fas fa-cart-plus mr-2 text-xs w-4 text-center"></i>
                        <?php echo t('logistics.nav.place_order'); ?>
                    </a>
                    <a href="<?php echo LC_BASE; ?>/orders.php"
                       class="<?php echo in_array($_lc_page, ['orders.php','order_detail.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                        <i class="fas fa-list-alt mr-2 text-xs w-4 text-center"></i>
                        <?php echo t('logistics.nav.my_orders'); ?>
                    </a>
                    <?php endif; ?>

                </nav>
            </div>
        </div>
    </div>

    <!-- 메인 콘텐츠 영역 -->
    <div class="flex flex-col flex-1 overflow-hidden">

        <!-- 모바일 상단 바 -->
        <div class="no-print md:hidden bg-white border-b border-teal-100 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <img src="<?php echo LC_WEB_ROOT; ?>/logo/homekmart_logo.png" alt="Home K Mart" style="height:28px;">
                <a href="<?php echo LC_WEB_ROOT; ?>/"
                   class="inline-flex items-center px-2 py-1 text-xs font-semibold text-white bg-teal-600 hover:bg-teal-700 rounded-md transition-colors">
                    <i class="fas fa-globe mr-1"></i> <?php echo t('logistics.nav.main'); ?>
                </a>
            </div>
            <button id="mobile-menu-btn" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <!-- 모바일 드롭다운 메뉴 -->
        <div id="mobile-menu" class="hidden md:hidden bg-white border-b border-gray-200 px-4 py-3 space-y-1">
            <?php if ($_lc_is_staff): ?>
            <a href="<?php echo LC_BASE; ?>/index.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.dashboard'); ?></a>
            <a href="<?php echo LC_BASE; ?>/products.php"  class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.product_master'); ?></a>
            <a href="<?php echo LC_BASE; ?>/inbound.php"   class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.inbound_management'); ?></a>
            <a href="<?php echo LC_BASE; ?>/inventory.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.inventory_status'); ?></a>
            <a href="<?php echo LC_BASE; ?>/outbound.php"  class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.outbound_history'); ?></a>
            <a href="<?php echo LC_BASE; ?>/inbound_damages.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.damaged_goods'); ?></a>
            <a href="<?php echo LC_BASE; ?>/suppliers.php"  class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.suppliers'); ?></a>
            <a href="<?php echo LC_BASE; ?>/brand_manage.php"    class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.brand_management'); ?></a>
            <a href="<?php echo LC_BASE; ?>/category_manage.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.category_management'); ?></a>
            <a href="<?php echo LC_BASE; ?>/orders.php"    class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.order_list'); ?></a>
            <a href="<?php echo LC_BASE; ?>/branch_outbound_list.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.branch_outbound'); ?></a>
            <a href="<?php echo LC_BASE; ?>/requests.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.store_requests'); ?></a>
            <?php else: ?>
            <a href="<?php echo LC_BASE; ?>/order_new.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.place_order'); ?></a>
            <a href="<?php echo LC_BASE; ?>/orders.php"    class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md"><?php echo t('logistics.nav.my_orders'); ?></a>
            <?php endif; ?>
            <div class="border-t border-gray-100 mt-2 pt-2">
                <div class="px-3 py-1 text-xs text-gray-500"><i class="fas fa-store mr-2"></i><?php echo htmlspecialchars($_lc_store_label); ?></div>
                <div class="px-3 py-1 text-sm text-gray-700"><i class="fas fa-circle-user mr-2"></i><?php echo htmlspecialchars($_lc_user_name); ?><?php if ($_lc_role_label !== ''): ?> <span class="text-xs text-teal-700">(<?php echo htmlspecialchars($_lc_role_label); ?>)</span><?php endif; ?></div>
                <a href="<?php echo LC_BASE; ?>/logout.php" onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('logistics.nav.sign_out_confirm'), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)" class="block px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-md"><i class="fas fa-sign-out-alt mr-2"></i><?php echo t('logistics.nav.logout'); ?></a>
            </div>
        </div>

        <!-- 플래시 메시지 -->
        <?php if ($_lc_flash): ?>
        <div class="no-print mx-4 mt-4 px-4 py-3 rounded-md text-sm flex items-center
            <?php echo $_lc_flash['type'] === 'error' ? 'bg-red-50 text-red-800 border border-red-200' : 'bg-green-50 text-green-800 border border-green-200'; ?>">
            <i class="fas <?php echo $_lc_flash['type'] === 'error' ? 'fa-exclamation-circle text-red-400' : 'fa-check-circle text-green-400'; ?> mr-2"></i>
            <?php echo htmlspecialchars($_lc_flash['message']); ?>
        </div>
        <?php endif; ?>

        <!-- 페이지 콘텐츠 시작 -->
        <main class="flex-1 overflow-y-auto p-6">

<script>
document.getElementById('language-switcher')?.addEventListener('click', function(e) {
    var btn = e.target.closest('button[data-lang]');
    if (!btn) return;
    var lang = btn.getAttribute('data-lang');
    if (lang === '<?php echo get_language(); ?>') return;
    fetch('<?php echo LC_BASE; ?>/ajax_set_language.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'language=' + encodeURIComponent(lang)
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.success) { window.location.reload(); }
        else { alert('<?php echo htmlspecialchars(t('common.error'), ENT_QUOTES, 'UTF-8'); ?>: ' + data.message); }
    }).catch(function() {
        alert('<?php echo htmlspecialchars(t('logistics.common.language_error'), ENT_QUOTES, 'UTF-8'); ?>');
    });
});
document.getElementById('mobile-menu-btn')?.addEventListener('click', function() {
    const menu = document.getElementById('mobile-menu');
    menu?.classList.toggle('hidden');
});
</script>
