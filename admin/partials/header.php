<?php
ob_start(); // 출력 버퍼링 시작
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
ensure_logged_in();

$current_page = basename($_SERVER['PHP_SELF']);

// 사이트 루트 경로 계산 (서버 환경에 따라 자동 감지, 예: /sunset)
$_adm_pos = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/');
$_admin_web_root = ($_adm_pos !== false) ? substr($_SERVER['SCRIPT_NAME'], 0, $_adm_pos) : '';

// 현재 사용자의 점포 정보 가져오기
// 슈퍼어드민은 admin/ 전역에서 $_SESSION['store_id']를 "현재 조회/작업 중인 점포"로 그대로 사용하는
// 페이지가 많아(ajax_*.php 다수가 header.php를 거치지 않고 $_SESSION['store_id']를 직접 읽음),
// 점포 전환 스위처도 header.php의 지역변수가 아닌 $_SESSION['store_id'] 자체를 갱신한다
// (ajax_switch_store.php). 그래야 전환 즉시 모든 admin 화면/AJAX가 일관되게 반영된다.
$current_store_name = '본점';
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $conn = get_db_connection();

        if ($_SESSION['role'] === 'super_admin') {
            $sid = (int)($_SESSION['store_id'] ?? 0);
            if ($sid <= 0) $sid = 1; // 기본값: CLARK HILLS
            $st = $conn->prepare("SELECT id, name FROM stores WHERE id=?");
            $st->bind_param('i', $sid);
            $st->execute();
            $srow = $st->get_result()->fetch_assoc();
            $st->close();
            if ($srow) {
                $current_store_id   = (int)$srow['id'];
                $current_store_name = $srow['name'];
            }
            $_SESSION['store_id'] = $current_store_id; // 세션 정규화(최초 로그인 시 null이었던 경우 등)
        } else {
            $user_stmt = $conn->prepare("SELECT s.name as store_name, s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
            $user_stmt->bind_param("i", $_SESSION['user_id']);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            if ($user_row = $user_result->fetch_assoc()) {
                $current_store_name = $user_row['store_name'] ?? '본점';
                $current_store_id = $user_row['store_id'];
            }
            $user_stmt->close();
        }

        $conn->close();
    } catch (Exception $e) {
        // 오류 발생시 기본값 유지
        error_log("Store info error: " . $e->getMessage());
    }
}

// 슈퍼어드민 점포 선택 드롭다운용 전체 점포 목록
$admin_all_stores = [];
if (($_SESSION['role'] ?? '') === 'super_admin') {
    try {
        $conn = get_db_connection();
        $r = $conn->query("SELECT id, name FROM stores ORDER BY name ASC");
        $admin_all_stores = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $conn->close();
    } catch (Exception $e) {
        error_log("Store list error: " . $e->getMessage());
    }
}

// 현재 사용자 등급(역할) 라벨
$admin_role_label = !empty($_SESSION['role']) ? get_role_label($_SESSION['role']) : '';

// 물류센터 지점 소속 여부 (role이 super_admin/admin이면 제한 없음)
$is_logistics_user = false;
if (!in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])) {
    $is_logistics_user = is_logistics_department();
}

// 지점 변경 요청 승인 대기 건수 (점장 이상에게만 노출)
$pending_store_change_count = 0;
$can_approve_store_changes = (current_user_level() >= LEVEL_BRANCH_MANAGER);
if ($can_approve_store_changes) {
    require_once __DIR__ . '/../../lib/store_change_request_helper.php';
    foreach (get_pending_store_change_requests() as $__scr) {
        if (can_approve_store_change($__scr['to_store_id'])) {
            $pending_store_change_count++;
        }
    }
}

// 유통기한 임박(알림 기준일 이내) 로트 건수 — 점검기록 메뉴 배지
// Design Ref: docs/02-design/features/expiry-management.design.md §2.2, §5.4 네비게이션
$expiry_alert_count = 0;
if ((has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) && !empty($current_store_id)) {
    require_once __DIR__ . '/../../lib/expiry_helper.php';
    try {
        $expiry_conn = get_db_connection();
        $expiry_alert_count = get_expiry_alert_count($expiry_conn, $current_store_id);
        $expiry_conn->close();
    } catch (Exception $e) {
        error_log("Expiry alert count error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ko" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="msapplication-config" content="none">
    <title><?php echo $page_title ?? t('company.title'); ?></title>
    <link rel="icon" href="data:,">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="favicon.svg">
    <link href="css/style.css?v=20260619teal" rel="stylesheet">
    <link href="css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* logistics teal 디자인 통일 — 사이드바 메뉴 hover 강조 */
        #admin-nav a:hover { background: #ccfbf1 !important; color: #0f766e !important; }
        #admin-sidebar a, #admin-sidebar a:hover, #admin-sidebar a:focus { text-decoration: none; }
        #admin-nav a { font-size: 11px; padding-top: 4px; padding-bottom: 4px; }
    </style>
    <?php echo get_js_translation_script(); ?>
</head>
<body class="bg-gray-50 min-h-screen">

<div class="flex h-screen bg-gray-50">
    <!-- 사이드바 (logistics 1:1) -->
    <div class="hidden md:flex md:flex-shrink-0">
        <div class="flex flex-col w-52">
            <div id="admin-sidebar" class="flex flex-col flex-grow pt-1 pb-4 overflow-y-auto bg-white border-r border-teal-100">

                <!-- 로고 + MAIN -->
                <div class="flex flex-col flex-shrink-0 px-2 pt-1 mb-2">
                    <a href="index.php" class="block mb-2">
                        <img src="../logo/homekmart_logo.png" alt="<?php echo htmlspecialchars(t('company.name')); ?>" style="width:100%;height:auto;display:block;">
                    </a>
                    <a href="<?php echo $_admin_web_root; ?>/"
                       class="flex items-center gap-2 w-full px-2 py-1.5 text-xs font-semibold rounded-md transition-colors"
                       style="background:#1e40af;color:#ffffff;"
                       onmouseover="this.style.background='#1e3a8a'" onmouseout="this.style.background='#1e40af'">
                        <i class="fas fa-globe"></i> MAIN
                    </a>
                </div>

                <!-- 상단 사용자 정보 카드 -->
                <div class="flex-shrink-0 mx-2 mb-1.5" style="border:1px solid #ccfbf1;border-radius:0.6rem;background:linear-gradient(135deg,#f0fdfa 0%,#ecfdf5 100%);overflow:hidden;">
                    <div style="display:flex;align-items:center;gap:0.35rem;padding:0.5rem 0.55rem;background:#0d9488;color:#fff;">
                        <i class="fas fa-store" style="font-size:0.8rem;flex-shrink:0;"></i>
                        <?php if (($_SESSION['role'] ?? '') === 'super_admin' && !empty($admin_all_stores)): ?>
                        <select id="admin_store_switch" onchange="switchAdminStore(this.value)"
                                style="flex:1;min-width:0;font-weight:600;font-size:11px;line-height:1.15;background:#0d9488;color:#fff;border:1px solid rgba(255,255,255,0.4);border-radius:0.3rem;padding:0.15rem 0.25rem;">
                            <?php foreach ($admin_all_stores as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$s['id'] === (int)$current_store_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <span style="font-weight:600;font-size:11px;line-height:1.15;"><?php echo htmlspecialchars($current_store_name); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="padding:0.5rem 0.55rem;">
                        <a href="user_profile.php" style="display:flex;align-items:center;gap:0.35rem;color:#334155;font-size:11px;margin-bottom:0.5rem;">
                            <i class="fas fa-circle-user" style="color:#0d9488;font-size:0.9rem;"></i>
                            <span style="font-weight:500;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?></span>
                        </a>
                        <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.5rem;">
                            <?php if ($admin_role_label !== ''): ?>
                            <span style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.35rem 0.5rem;font-size:11px;font-weight:600;color:#0f766e;background:#ccfbf1;border-radius:0.4rem;white-space:nowrap;">
                                <i class="fas fa-id-badge" style="font-size:0.62rem;"></i><?php echo htmlspecialchars($admin_role_label); ?>
                            </span>
                            <?php endif; ?>
                            <a href="logout.php"
                               style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:0.3rem;padding:0.35rem 0.4rem;font-size:11px;font-weight:600;white-space:nowrap;color:#dc2626;background:#fef2f2;border-radius:0.4rem;transition:background 0.15s;"
                               onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                                <i class="fas fa-sign-out-alt"></i><?php echo t('auth.logout') ?: '로그아웃'; ?>
                            </a>
                        </div>
                        <select id="language-switcher" class="w-full" style="font-size:11px;border:1px solid #d1d5db;border-radius:0.4rem;padding:0.25rem 0.4rem;background:#fff;outline:none;">
                            <option value="ko" <?php echo get_language() === 'ko' ? 'selected' : ''; ?>>한국어</option>
                            <option value="en" <?php echo get_language() === 'en' ? 'selected' : ''; ?>>English</option>
                        </select>
                    </div>
                </div>

                <nav id="admin-nav" class="flex-grow px-2 space-y-1">
                    <!-- 자주 쓰는 기능 (강조) -->
                    <?php
                    $fav_items = [];
                    $fav_is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
                    // 2x2 배치: 매입관리(좌상) · 도매판매(우상) / 점간이동(좌하) · 외상판매(우하)
                    if (has_permission('purchase_management') || $fav_is_admin) {
                        $fav_items[] = ['href' => 'purchase_management.php', 'icon' => 'fa-shopping-cart', 'label' => t('navigation.purchase_management'), 'active' => in_array($current_page, ['purchase_management.php', 'add_purchase.php', 'edit_purchase.php'])];
                    }
                    if (has_permission('wholesale_management') || $fav_is_admin) {
                        $fav_items[] = ['href' => 'wholesale_sales_list.php', 'icon' => 'fa-handshake', 'label' => t('navigation.wholesale_sales_list'), 'active' => in_array($current_page, ['wholesale_sales_list.php', 'wholesale_sales.php', 'wholesale_sale_preview.php'])];
                    }
                    if (has_permission('store_transfer_management') || $fav_is_admin) {
                        $fav_items[] = ['href' => 'store_transfers_list.php', 'icon' => 'fa-exchange-alt', 'label' => t('navigation.store_transfer_section'), 'active' => in_array($current_page, ['store_transfers_list.php', 'store_transfers.php', 'store_transfer_preview.php'])];
                    }
                    if (has_permission('wholesale_management') || $fav_is_admin) {
                        $fav_items[] = ['href' => 'credit_transactions.php', 'icon' => 'fa-file-invoice-dollar', 'label' => t('navigation.credit_sales'), 'active' => ($current_page == 'credit_transactions.php')];
                    }
                    ?>
                    <?php if (!empty($fav_items)): ?>
                    <div class="rounded-lg px-1.5 py-2 mb-1.5" style="background:linear-gradient(135deg,#ecfeff 0%,#f0fdfa 100%);border:1px solid #99f6e4;">
                        <p class="px-2 py-1 mb-1.5 text-xs font-semibold uppercase tracking-wider rounded" style="background:#0d9488;color:#fff;"><i class="fas fa-star mr-1"></i><?php echo t('navigation.favorites_section'); ?></p>
                        <div class="grid grid-cols-2 gap-1.5">
                            <?php foreach ($fav_items as $fi):
                                $st = $fi['active'] ? 'background:#0d9488;border:1px solid #0d9488;color:#fff;' : 'background:#fff;border:1px solid #99f6e4;color:#0f766e;';
                            ?>
                            <a href="<?php echo $fi['href']; ?>" class="flex flex-col items-center justify-center gap-1 px-2 py-2.5 rounded-md text-xs font-semibold transition-colors"
                               style="<?php echo $st; ?>"
                               <?php if (!$fi['active']): ?>onmouseover="this.style.background='#ccfbf1'" onmouseout="this.style.background='#fff'"<?php endif; ?>>
                                <i class="fas <?php echo $fi['icon']; ?>" style="font-size:1.05rem;"></i>
                                <span><?php echo htmlspecialchars($fi['label']); ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 매입 관리 (green) -->
                    <?php if (has_permission('purchase_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="rounded-lg px-1.5 py-2" style="background:#f0fdf4;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#dcfce7;color:#15803d;"><?php echo t('navigation.purchase_management_section'); ?></p>
                        <a href="purchase_management.php" class="<?php echo in_array($current_page, ['purchase_management.php', 'add_purchase.php', 'edit_purchase.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-shopping-cart mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.purchase_management'); ?>
                        </a>
                        <a href="purchase_product_management.php" class="<?php echo ($current_page == 'purchase_product_management.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-box mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.purchase_product_management'); ?>
                        </a>
                        <a href="price_change_history.php" class="<?php echo ($current_page == 'price_change_history.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-chart-line mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.price_change_history'); ?>
                        </a>
                        <a href="price_adjustment.php" class="<?php echo ($current_page == 'price_adjustment.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-sliders-h mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.price_adjustment'); ?>
                        </a>
                        <a href="purchase_analysis.php" class="<?php echo ($current_page == 'purchase_analysis.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-chart-bar mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.purchase_analysis'); ?>
                        </a>
                        <a href="purchase_statistics.php" class="<?php echo ($current_page == 'purchase_statistics.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-chart-pie mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.purchase_statistics_by_supplier'); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- 도매 관리 (orange) -->
                    <?php if (has_permission('wholesale_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#fff7ed;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#ffedd5;color:#c2410c;"><?php echo t('navigation.wholesale_management_section'); ?></p>
                        <a href="wholesale_sales_list.php" class="<?php echo in_array($current_page, ['wholesale_sales_list.php', 'wholesale_sales.php', 'wholesale_sale_preview.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-handshake mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.wholesale_sales_list'); ?>
                        </a>
                        <a href="wholesale_customer_management.php" class="<?php echo in_array($current_page, ['wholesale_customer_management.php', 'add_wholesale_customer.php', 'edit_wholesale_customer.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-users mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.wholesale_customer_management'); ?>
                        </a>
                        <a href="wholesale_product_management.php" class="<?php echo in_array($current_page, ['wholesale_product_management.php', 'add_wholesale_product.php', 'edit_wholesale_product.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-box-open mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.wholesale_product_management'); ?>
                        </a>
                        <a href="wholesale_profit_report.php" class="<?php echo ($current_page == 'wholesale_profit_report.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-chart-pie mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.wholesale_profit_report'); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- 외상거래 (yellow) -->
                    <?php if (has_permission('wholesale_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#fefce8;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#fef9c3;color:#a16207;"><?php echo t('navigation.credit_section'); ?></p>
                        <a href="credit_transactions.php" class="<?php echo ($current_page == 'credit_transactions.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-file-invoice-dollar mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.credit_sales'); ?>
                        </a>
                        <a href="credit_receivables.php" class="<?php echo ($current_page == 'credit_receivables.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-hand-holding-usd mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.credit_receivables'); ?>
                        </a>
                        <a href="credit_customer_management.php" class="<?php echo ($current_page == 'credit_customer_management.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-address-book mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.credit_customer_management'); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- 점간이동 (purple) -->
                    <?php if (has_permission('store_transfer_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#faf5ff;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#f3e8ff;color:#7e22ce;"><?php echo t('navigation.store_transfer_section'); ?></p>
                        <a href="store_transfers_list.php" class="<?php echo in_array($current_page, ['store_transfers_list.php', 'store_transfers.php', 'store_transfer_preview.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-exchange-alt mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.store_transfer_section'); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- 유통기한 관리 (amber) -->
                    <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#fffbeb;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#fde68a;color:#92400e;">유통기한 관리</p>
                        <a href="expiry_inspection.php" class="<?php echo ($current_page == 'expiry_inspection.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center justify-between px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <span><i class="fas fa-calendar-times mr-2 text-xs w-4 text-center"></i>점검기록</span>
                            <?php if ($expiry_alert_count > 0): ?>
                            <span class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 font-bold leading-none text-white rounded-full" style="font-size:10px;background:#dc2626;"><?php echo $expiry_alert_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="expiry_disposal.php" class="<?php echo ($current_page == 'expiry_disposal.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-trash-alt mr-2 text-xs w-4 text-center"></i>폐기등록
                        </a>
                        <a href="expiry_disposal_report.php" class="<?php echo ($current_page == 'expiry_disposal_report.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-chart-bar mr-2 text-xs w-4 text-center"></i>폐기통계
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- 바코드 (red) -->
                    <?php if (has_permission('barcode_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#fef2f2;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#fee2e2;color:#b91c1c;"><?php echo t('navigation.barcode_section'); ?></p>
                        <a href="barcode_generate.php" class="<?php echo ($current_page == 'barcode_generate.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-barcode mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.barcode_generate'); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- 신선상품 관리 (lime) -->
                    <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#f7fee7;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#ecfccb;color:#4d7c0f;"><?php echo t('navigation.fresh_products_section'); ?></p>
                        <a href="fresh_products.php" class="<?php echo ($current_page == 'fresh_products.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-apple-whole mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.fresh_products'); ?>
                        </a>
                        <a href="fresh_purchase_items.php" class="<?php echo ($current_page == 'fresh_purchase_items.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-truck-ramp-box mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.fresh_purchase_items'); ?>
                        </a>
                        <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                        <a href="fresh_margin_management.php" class="<?php echo ($current_page == 'fresh_margin_management.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-percent mr-2 text-xs w-4 text-center"></i><?php echo t('mall_fresh_products.margin_management_link'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!$is_logistics_user): ?>
                    <!-- MASTER DATA (gray) -->
                    <?php if (has_permission('admin_access') || has_permission('shop_access') || has_permission('user_management') || has_permission('store_management') || has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#f8fafc;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#e2e8f0;color:#334155;"><?php echo t('navigation.basic_menu'); ?></p>
                        <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                        <a href="product_management.php" class="<?php echo in_array($current_page, ['product_management.php', 'add_product.php', 'edit_product.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-box-open mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.product_management'); ?>
                        </a>
                        <a href="new_products_management.php" class="<?php echo ($current_page == 'new_products_management.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-star mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.new_products_management'); ?>
                        </a>
                        <a href="product_name_history.php" class="<?php echo ($current_page == 'product_name_history.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-history mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.product_name_history'); ?>
                        </a>
                        <?php endif; ?>
                        <?php if (has_permission('supplier_management') || $_SESSION['role'] === 'super_admin'): ?>
                        <a href="supplier_management.php" class="<?php echo in_array($current_page, ['supplier_management.php', 'add_supplier.php', 'edit_supplier.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-truck mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.supplier_management'); ?>
                        </a>
                        <?php endif; ?>
                        <?php if (has_permission('brand_management') || $_SESSION['role'] === 'super_admin'): ?>
                        <a href="brand_management.php" class="<?php echo in_array($current_page, ['brand_management.php', 'add_brand.php', 'edit_brand.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-tags mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.brand_management'); ?>
                        </a>
                        <?php endif; ?>
                        <?php if (has_permission('category_management') || $_SESSION['role'] === 'super_admin'): ?>
                        <a href="category_management.php" class="<?php echo in_array($current_page, ['category_management.php', 'add_category.php', 'edit_category.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-sitemap mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.category_management'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- 발령 승인 / 내 지점 정보 (teal, 점장 이상) -->
                    <?php if ($can_approve_store_changes): ?>
                    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#f0fdfa;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#99f6e4;color:#115e59;"><?php echo t('navigation.store_change_approval_section'); ?></p>
                        <a href="store_change_requests.php" class="<?php echo ($current_page == 'store_change_requests.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center justify-between px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <span><i class="fas fa-people-arrows mr-2 text-xs w-4 text-center"></i><?php echo t('navigation.store_change_request'); ?></span>
                            <?php if ($pending_store_change_count > 0): ?>
                            <span class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 font-bold leading-none text-white rounded-full" style="font-size:10px;background:#dc2626;"><?php echo $pending_store_change_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <!-- Design Ref: homekmart-store-config — super_admin도 포스/근무시간 설정을 위해 접근 가능해야 함 -->
                        <a href="my_store.php" class="<?php echo ($current_page == 'my_store.php') ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-store mr-2 text-xs w-4 text-center"></i>내 지점 정보
                        </a>
                    </div>
                    <?php endif; ?>

                </nav>
            </div>
        </div>
    </div>

    <!-- 메인 콘텐츠 영역 -->
    <div class="flex flex-col flex-1 overflow-hidden">

        <!-- 모바일 상단 바 -->
        <div class="md:hidden bg-white border-b border-teal-100 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="index.php"><img src="../logo/homekmart_logo.png" alt="HOME K MART" style="height:28px;"></a>
                <a href="<?php echo $_admin_web_root; ?>/" class="inline-flex items-center px-2 py-1 text-xs font-semibold text-white bg-teal-600 hover:bg-teal-700 rounded-md transition-colors">
                    <i class="fas fa-globe mr-1"></i> MAIN
                </a>
            </div>
            <button id="mobile-menu-btn" class="text-gray-500 hover:text-gray-700"><i class="fas fa-bars text-xl"></i></button>
        </div>

        <!-- 모바일 드롭다운 메뉴 -->
        <div id="mobile-menu" class="hidden md:hidden bg-white border-b border-gray-200 px-4 py-3 space-y-1 max-h-96 overflow-y-auto">
            <?php if (has_permission('purchase_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
            <p class="px-2 pt-1 text-xs font-semibold uppercase tracking-wider" style="color:#15803d;"><?php echo t('navigation.purchase_management_section'); ?></p>
            <a href="purchase_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.purchase_management'); ?></a>
            <a href="purchase_product_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.purchase_product_management'); ?></a>
            <a href="price_change_history.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.price_change_history'); ?></a>
            <a href="price_adjustment.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.price_adjustment'); ?></a>
            <a href="purchase_analysis.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.purchase_analysis'); ?></a>
            <a href="purchase_statistics.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.purchase_statistics_by_supplier'); ?></a>
            <?php endif; ?>
            <?php if (has_permission('wholesale_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
            <p class="px-2 pt-2 text-xs font-semibold uppercase tracking-wider" style="color:#c2410c;"><?php echo t('navigation.wholesale_management_section'); ?></p>
            <a href="wholesale_sales_list.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.wholesale_sales_list'); ?></a>
            <a href="wholesale_customer_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.wholesale_customer_management'); ?></a>
            <a href="wholesale_product_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.wholesale_product_management'); ?></a>
            <a href="wholesale_profit_report.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.wholesale_profit_report'); ?></a>
            <?php endif; ?>
            <?php if (has_permission('store_transfer_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
            <p class="px-2 pt-2 text-xs font-semibold uppercase tracking-wider" style="color:#7e22ce;"><?php echo t('navigation.store_transfer_section'); ?></p>
            <a href="store_transfers_list.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.store_transfer_section'); ?></a>
            <?php endif; ?>
            <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
            <p class="px-2 pt-2 text-xs font-semibold uppercase tracking-wider" style="color:#92400e;">유통기한 관리</p>
            <a href="expiry_inspection.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md">점검기록<?php if ($expiry_alert_count > 0): ?> <span class="inline-flex items-center justify-center px-1.5 py-0.5 font-bold leading-none text-white rounded-full" style="font-size:10px;background:#dc2626;"><?php echo $expiry_alert_count; ?></span><?php endif; ?></a>
            <a href="expiry_disposal.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md">폐기등록</a>
            <a href="expiry_disposal_report.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md">폐기통계</a>
            <?php endif; ?>
            <?php if (has_permission('barcode_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
            <p class="px-2 pt-2 text-xs font-semibold uppercase tracking-wider" style="color:#b91c1c;"><?php echo t('navigation.barcode_section'); ?></p>
            <a href="barcode_generate.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.barcode_generate'); ?></a>
            <?php endif; ?>
            <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
            <p class="px-2 pt-2 text-xs font-semibold uppercase tracking-wider" style="color:#4d7c0f;"><?php echo t('navigation.fresh_products_section'); ?></p>
            <a href="fresh_products.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.fresh_products'); ?></a>
            <a href="fresh_purchase_items.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.fresh_purchase_items'); ?></a>
            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
            <a href="fresh_margin_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('mall_fresh_products.margin_management_link'); ?></a>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (!$is_logistics_user && (has_permission('admin_access') || has_permission('shop_access') || has_permission('user_management') || has_permission('store_management'))): ?>
            <p class="px-2 pt-2 text-xs font-semibold uppercase tracking-wider" style="color:#334155;"><?php echo t('navigation.basic_menu'); ?></p>
            <?php if (has_permission('supplier_management') || $_SESSION['role'] === 'super_admin'): ?><a href="supplier_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.supplier_management'); ?></a><?php endif; ?>
            <?php if (has_permission('brand_management') || $_SESSION['role'] === 'super_admin'): ?><a href="brand_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.brand_management'); ?></a><?php endif; ?>
            <?php if (has_permission('category_management') || $_SESSION['role'] === 'super_admin'): ?><a href="category_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.category_management'); ?></a><?php endif; ?>
            <?php endif; ?>
            <?php if ($can_approve_store_changes): ?>
            <p class="px-2 pt-2 text-xs font-semibold uppercase tracking-wider" style="color:#115e59;"><?php echo t('navigation.store_change_approval_section'); ?></p>
            <a href="store_change_requests.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.store_change_request'); ?><?php if ($pending_store_change_count > 0): ?> <span class="inline-flex items-center justify-center px-1.5 py-0.5 font-bold leading-none text-white rounded-full" style="font-size:10px;background:#dc2626;"><?php echo $pending_store_change_count; ?></span><?php endif; ?></a>
            <a href="my_store.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md">내 지점 정보</a>
            <?php endif; ?>
            <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
            <p class="px-2 pt-2 text-xs font-semibold uppercase tracking-wider" style="color:#1d4ed8;"><?php echo t('navigation.product_management_section'); ?></p>
            <a href="product_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.product_management'); ?></a>
            <a href="new_products_management.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.new_products_management'); ?></a>
            <a href="product_name_history.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><?php echo t('navigation.product_name_history'); ?></a>
            <?php endif; ?>
            <div class="border-t border-gray-100 mt-2 pt-2">
                <a href="user_profile.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 hover:text-teal-700 rounded-md"><i class="fas fa-user mr-2"></i><?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?></a>
                <a href="logout.php" class="block px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-md"><i class="fas fa-sign-out-alt mr-2"></i><?php echo t('auth.logout'); ?></a>
            </div>
        </div>

        <!-- 본문 -->
        <main class="flex-1 relative overflow-y-auto focus:outline-none">
            <div class="py-0">
                <div class="w-full px-0">

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 모바일 메뉴 토글
    document.getElementById('mobile-menu-btn')?.addEventListener('click', function() {
        document.getElementById('mobile-menu')?.classList.toggle('hidden');
    });

    // 언어 전환
    var languageSwitcher = document.getElementById('language-switcher');
    if (languageSwitcher) {
        languageSwitcher.addEventListener('change', function() {
            var selectedLang = this.value;
            fetch('ajax_set_language.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'language=' + encodeURIComponent(selectedLang)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) { window.location.reload(); }
                else { alert(t('common.error') + ': ' + data.message); languageSwitcher.value = '<?php echo get_language(); ?>'; }
            })
            .catch(function() { alert('언어 변경 중 오류가 발생했습니다.'); languageSwitcher.value = '<?php echo get_language(); ?>'; });
        });
    }
});
</script>
