<?php
ob_start(); // 출력 버퍼링 시작
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
ensure_logged_in();

$current_page = basename($_SERVER['PHP_SELF']);

// 현재 사용자의 점포 정보 가져오기
$current_store_name = '본점';
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $conn = get_db_connection();
        $user_stmt = $conn->prepare("SELECT s.name as store_name, s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $current_store_name = $user_row['store_name'] ?? '본점';
            $current_store_id = $user_row['store_id'];
            
            // super_admin이고 store_id가 없는 경우 기본 점포(CLARK HILLS) 설정
            if ($_SESSION['role'] === 'super_admin' && empty($current_store_id)) {
                $current_store_id = 1; // CLARK HILLS
                $current_store_name = 'CLARK HILLS';
                $_SESSION['store_id'] = $current_store_id; // 세션에도 저장
            }
        }
        $user_stmt->close();
        $conn->close();
    } catch (Exception $e) {
        // 오류 발생시 기본값 유지
        error_log("Store info error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="msapplication-config" content="none">
    <title><?php echo $page_title ?? t('company.title'); ?></title>
    <link rel="icon" href="data:,">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="favicon.svg">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- Bootstrap CSS for modal support -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <?php 
    // JavaScript 번역 시스템 포함
    echo get_js_translation_script(); 
    ?>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="flex h-screen bg-gray-50">
        <!-- Card-based Menu -->
        <div class="hidden md:flex md:flex-shrink-0">
            <div class="flex flex-col w-80">
                <div class="flex flex-col flex-grow pt-5 pb-4 overflow-y-auto bg-white border-r border-gray-200">
                    <div class="flex items-center flex-shrink-0 px-4 mb-6">
                        <h1 class="text-xl font-bold text-gray-900">
                            <a href="index.php" class="text-primary-600 hover:text-primary-700"><?php echo t('company.name'); ?></a>
                        </h1>
                    </div>
                    
                    <div class="flex-grow px-4 space-y-4">
                        <!-- 기본 메뉴 카드 -->
                        <?php if (has_permission('admin_access') || has_permission('shop_access') || has_permission('user_management') || has_permission('store_management')): ?>
                        <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-2 border border-gray-200 shadow-sm">
                            <div class="flex items-center mb-3">
                                <div class="w-8 h-8 bg-gray-600 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-home text-white text-sm"></i>
                                </div>
                                <h3 class="text-sm font-semibold text-gray-800"><?php echo t('navigation.basic_menu'); ?></h3>
                            </div>
                            <div class="space-y-1">
                                <?php if (has_permission('admin_access') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                                <a href="index.php" class="<?php echo ($current_page == 'index.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200 hover:text-gray-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-tachometer-alt mr-2 text-gray-500 group-hover:text-gray-600 text-xs"></i>
                                    <?php echo t('navigation.dashboard'); ?>
                                </a>
                                <?php endif; ?>
                                
                                <?php if (has_permission('shop_access')): ?>
                                <a href="shop_dashboard.php" class="<?php echo ($current_page == 'shop_dashboard.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200 hover:text-gray-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-shopping-cart mr-2 text-gray-500 group-hover:text-gray-600 text-xs"></i>
                                    <?php echo t('navigation.shop'); ?>
                                </a>
                                <?php endif; ?>
                                
                                <?php if (has_permission('user_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                                <a href="user_management.php" class="<?php echo in_array($current_page, ['user_management.php', 'add_user.php', 'edit_user.php']) ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200 hover:text-gray-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-users mr-2 text-gray-500 group-hover:text-gray-600 text-xs"></i>
                                    <?php echo t('navigation.user_management'); ?>
                                </a>
                                <?php endif; ?>
                                
                                <?php if (has_permission('store_management') || $_SESSION['role'] === 'super_admin'): ?>
                                <a href="store_management.php" class="<?php echo in_array($current_page, ['store_management.php', 'add_store.php', 'edit_store.php']) ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200 hover:text-gray-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-store mr-2 text-gray-500 group-hover:text-gray-600 text-xs"></i>
                                    <?php echo t('navigation.store_management'); ?>
                                </a>
                                <?php endif; ?>
                                
                                <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                                <a href="price_label_lists.php" class="<?php echo in_array($current_page, ['price_label_lists.php', 'price_label_project_edit.php']) ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200 hover:text-gray-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-list-ul mr-2 text-gray-500 group-hover:text-gray-600 text-xs"></i>
                                    <?php echo t('navigation.price_label_lists'); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 매입 관리 카드 (녹색) -->
                        <?php if (has_permission('purchase_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                        <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-2 border border-green-200 shadow-sm">
                            <div class="flex items-center mb-3">
                                <div class="w-8 h-8 bg-green-600 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-shopping-cart text-white text-sm"></i>
                                </div>
                                <h3 class="text-sm font-semibold text-green-800"><?php echo t('navigation.purchase_management_section'); ?></h3>
                            </div>
                            <div class="space-y-1">
                                <a href="purchase_management.php" class="<?php echo in_array($current_page, ['purchase_management.php', 'add_purchase.php', 'edit_purchase.php']) ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200 hover:text-green-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-shopping-cart mr-2 text-green-500 group-hover:text-green-600 text-xs"></i>
                                    <?php echo t('navigation.purchase_management'); ?>
                                </a>
                                
                                <a href="purchase_product_management.php" class="<?php echo ($current_page == 'purchase_product_management.php') ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200 hover:text-green-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-box mr-2 text-green-500 group-hover:text-green-600 text-xs"></i>
                                    <?php echo t('navigation.purchase_product_management'); ?>
                                </a>
                                
                                <a href="price_change_history.php" class="<?php echo ($current_page == 'price_change_history.php') ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200 hover:text-green-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-chart-line mr-2 text-green-500 group-hover:text-green-600 text-xs"></i>
                                    <?php echo t('navigation.price_change_history'); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 도매 관리 카드 (주황색) -->
                        <?php if (has_permission('wholesale_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                        <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-xl p-2 border border-orange-200 shadow-sm">
                            <div class="flex items-center mb-3">
                                <div class="w-8 h-8 bg-orange-600 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-handshake text-white text-sm"></i>
                                </div>
                                <h3 class="text-sm font-semibold text-orange-800"><?php echo t('navigation.wholesale_management_section'); ?></h3>
                            </div>
                            <div class="space-y-1">
                                <a href="wholesale_customer_management.php" class="<?php echo in_array($current_page, ['wholesale_customer_management.php', 'add_wholesale_customer.php', 'edit_wholesale_customer.php']) ? 'bg-orange-200 text-orange-900' : 'text-orange-700 hover:bg-orange-200 hover:text-orange-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-users mr-2 text-orange-500 group-hover:text-orange-600 text-xs"></i>
                                    <?php echo t('navigation.wholesale_customer_management'); ?>
                                </a>
                                
                                <a href="wholesale_product_management.php" class="<?php echo in_array($current_page, ['wholesale_product_management.php', 'add_wholesale_product.php', 'edit_wholesale_product.php']) ? 'bg-orange-200 text-orange-900' : 'text-orange-700 hover:bg-orange-200 hover:text-orange-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-box-open mr-2 text-orange-500 group-hover:text-orange-600 text-xs"></i>
                                    <?php echo t('navigation.wholesale_product_management'); ?>
                                </a>
                                
                                <a href="wholesale_sales_list.php" class="<?php echo in_array($current_page, ['wholesale_sales_list.php', 'wholesale_sales.php', 'wholesale_sale_preview.php']) ? 'bg-orange-200 text-orange-900' : 'text-orange-700 hover:bg-orange-200 hover:text-orange-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-handshake mr-2 text-orange-500 group-hover:text-orange-600 text-xs"></i>
                                    <?php echo t('navigation.wholesale_sales_list'); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 점간이동 카드 (보라색) -->
                        <?php if (has_permission('store_transfer_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                        <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-2 border border-purple-200 shadow-sm">
                            <div class="flex items-center mb-3">
                                <div class="w-8 h-8 bg-purple-600 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-exchange-alt text-white text-sm"></i>
                                </div>
                                <h3 class="text-sm font-semibold text-purple-800"><?php echo t('navigation.store_transfer_section'); ?></h3>
                            </div>
                            <div class="space-y-1">
                                <a href="store_transfers_list.php" class="<?php echo in_array($current_page, ['store_transfers_list.php', 'store_transfers.php', 'store_transfer_preview.php']) ? 'bg-purple-200 text-purple-900' : 'text-purple-700 hover:bg-purple-200 hover:text-purple-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-exchange-alt mr-2 text-purple-500 group-hover:text-purple-600 text-xs"></i>
                                    <?php echo t('navigation.store_transfer_section'); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 상품 관리 카드 (파란색) -->
                        <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                        <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-2 border border-blue-200 shadow-sm">
                            <div class="flex items-center mb-3">
                                <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-box-open text-white text-sm"></i>
                                </div>
                                <h3 class="text-sm font-semibold text-blue-800"><?php echo t('navigation.product_management_section'); ?></h3>
                            </div>
                            <div class="space-y-1">
                                <a href="product_management.php" class="<?php echo in_array($current_page, ['product_management.php', 'add_product.php', 'edit_product.php']) ? 'bg-blue-200 text-blue-900' : 'text-blue-700 hover:bg-blue-200 hover:text-blue-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-box-open mr-2 text-blue-500 group-hover:text-blue-600 text-xs"></i>
                                    <?php echo t('navigation.product_management'); ?>
                                </a>
                                
                                <a href="new_products_management.php" class="<?php echo ($current_page == 'new_products_management.php') ? 'bg-blue-200 text-blue-900' : 'text-blue-700 hover:bg-blue-200 hover:text-blue-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-star mr-2 text-blue-500 group-hover:text-blue-600 text-xs"></i>
                                    <?php echo t('navigation.new_products_management'); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 쇼핑몰 관리 카드 (빨간색) -->
                        <?php if (has_permission('admin_access') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                        <div class="bg-gradient-to-br from-red-50 to-red-100 rounded-xl p-2 border border-red-200 shadow-sm">
                            <div class="flex items-center mb-3">
                                <div class="w-8 h-8 bg-red-600 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-shopping-bag text-white text-sm"></i>
                                </div>
                                <h3 class="text-sm font-semibold text-red-800">쇼핑몰 관리</h3>
                            </div>
                            <div class="space-y-1">
                                <a href="shop_dashboard.php" class="<?php echo ($current_page == 'shop_dashboard.php') ? 'bg-red-300 text-red-900' : 'text-red-700 hover:bg-red-200'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-chart-pie mr-2 text-red-500 group-hover:text-red-600 text-xs"></i>
                                    통합 대시보드
                                </a>
                                
                                <a href="display_sections.php" class="<?php echo ($current_page == 'display_sections.php') ? 'bg-red-300 text-red-900' : 'text-red-700 hover:bg-red-200'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-th-large mr-2 text-red-500 group-hover:text-red-600 text-xs"></i>
                                    진열 섹션 관리
                                </a>
                                
                                <a href="product_display.php" class="<?php echo ($current_page == 'product_display.php') ? 'bg-red-300 text-red-900' : 'text-red-700 hover:bg-red-200'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-cubes mr-2 text-red-500 group-hover:text-red-600 text-xs"></i>
                                    상품 진열 관리
                                </a>
                                
                                <a href="orders.php" class="<?php echo ($current_page == 'orders.php') ? 'bg-red-300 text-red-900' : 'text-red-700 hover:bg-red-200'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-shopping-cart mr-2 text-red-500 group-hover:text-red-600 text-xs"></i>
                                    주문 관리
                                </a>
                                
                                <a href="store_product_planner.php" class="<?php echo ($current_page == 'store_product_planner.php') ? 'bg-red-300 text-red-900' : 'text-red-700 hover:bg-red-200'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-project-diagram mr-2 text-red-500 group-hover:text-red-600 text-xs"></i>
                                    점포별 상품 배치
                                </a>
                                
                                <a href="shop_category_manager.php" class="<?php echo ($current_page == 'shop_category_manager.php') ? 'bg-red-300 text-red-900' : 'text-red-700 hover:bg-red-200'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-layer-group mr-2 text-red-500 group-hover:text-red-600 text-xs"></i>
                                    카테고리별 관리
                                </a>
                                
                                <a href="shop_store_compare.php" class="<?php echo ($current_page == 'shop_store_compare.php') ? 'bg-red-300 text-red-900' : 'text-red-700 hover:bg-red-200'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-balance-scale mr-2 text-red-500 group-hover:text-red-600 text-xs"></i>
                                    점포 비교 분석
                                </a>
                                
                                <a href="layout_builder.php" class="<?php echo ($current_page == 'layout_builder.php') ? 'bg-red-300 text-red-900' : 'text-red-700 hover:bg-red-200'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-th-large mr-2 text-red-500 group-hover:text-red-600 text-xs"></i>
                                    레이아웃 빌더
                                </a>
                                
                                <a href="quick_layout_setup.php" class="<?php echo ($current_page == 'quick_layout_setup.php') ? 'bg-red-300 text-red-900' : 'text-red-700 hover:bg-red-200'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-magic mr-2 text-red-500 group-hover:text-red-600 text-xs"></i>
                                    빠른 레이아웃 설정
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 기타정보관리 카드 (녹색) -->
                        <?php if (has_permission('supplier_management') || has_permission('brand_management') || has_permission('category_management') || $_SESSION['role'] === 'super_admin'): ?>
                        <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-2 border border-green-200 shadow-sm">
                            <div class="flex items-center mb-3">
                                <div class="w-8 h-8 bg-green-600 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-database text-white text-sm"></i>
                                </div>
                                <h3 class="text-sm font-semibold text-green-800"><?php echo t('navigation.other_info_management_section'); ?></h3>
                            </div>
                            <div class="space-y-1">
                                <?php if (has_permission('supplier_management') || $_SESSION['role'] === 'super_admin'): ?>
                                <a href="supplier_management.php" class="<?php echo in_array($current_page, ['supplier_management.php', 'add_supplier.php', 'edit_supplier.php']) ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200 hover:text-green-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-truck mr-2 text-green-500 group-hover:text-green-600 text-xs"></i>
                                    <?php echo t('navigation.supplier_management'); ?>
                                </a>
                                <?php endif; ?>
                                
                                <?php if (has_permission('brand_management') || $_SESSION['role'] === 'super_admin'): ?>
                                <a href="brand_management.php" class="<?php echo in_array($current_page, ['brand_management.php', 'add_brand.php', 'edit_brand.php']) ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200 hover:text-green-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-tags mr-2 text-green-500 group-hover:text-green-600 text-xs"></i>
                                    <?php echo t('navigation.brand_management'); ?>
                                </a>
                                <?php endif; ?>
                                
                                <?php if (has_permission('category_management') || $_SESSION['role'] === 'super_admin'): ?>
                                <a href="category_management.php" class="<?php echo in_array($current_page, ['category_management.php', 'add_category.php', 'edit_category.php']) ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200 hover:text-green-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-sitemap mr-2 text-green-500 group-hover:text-green-600 text-xs"></i>
                                    <?php echo t('navigation.category_management'); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 시스템 설정 카드 -->
                        <?php if (has_permission('settings') || $_SESSION['role'] === 'super_admin'): ?>
                        <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-2 border border-gray-200 shadow-sm">
                            <div class="flex items-center mb-3">
                                <div class="w-8 h-8 bg-gray-600 rounded-lg flex items-center justify-center mr-3">
                                    <i class="fas fa-cog text-white text-sm"></i>
                                </div>
                                <h3 class="text-sm font-semibold text-gray-800"><?php echo t('navigation.system_settings_section'); ?></h3>
                            </div>
                            <div class="space-y-1">
                                <a href="settings.php" class="<?php echo ($current_page == 'settings.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200 hover:text-gray-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-cog mr-2 text-gray-500 group-hover:text-gray-600 text-xs"></i>
                                    <?php echo t('navigation.settings'); ?>
                                </a>
                                
                                <a href="backup_management.php" class="<?php echo ($current_page == 'backup_management.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200 hover:text-gray-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-database mr-2 text-gray-500 group-hover:text-gray-600 text-xs"></i>
                                    데이터베이스 백업
                                </a>
                                
                                <a href="excel_test.php" class="<?php echo ($current_page == 'excel_test.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200 hover:text-gray-900'; ?> group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-file-excel mr-2 text-gray-500 group-hover:text-gray-600 text-xs"></i>
                                    <?php echo t('navigation.excel_test'); ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main content -->
        <div class="flex flex-col flex-1 overflow-hidden">
            <!-- Top header -->
            <div class="relative z-10 flex-shrink-0 flex h-16 bg-white shadow">
                <div class="flex-1 px-4 flex justify-between">
                    <div class="flex-1 flex items-center">
                        <!-- Home button for mobile -->
                        <a href="index.php" class="md:hidden inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                            <i class="fas fa-home mr-2"></i>
                            <?php echo t('common.home'); ?>
                        </a>
                    </div>
                    <div class="ml-4 flex items-center md:ml-6">
                        <div class="flex items-center space-x-4">
                            <!-- Language Switcher -->
                            <div class="relative">
                                <select id="language-switcher" class="text-sm border border-gray-300 rounded-md px-2 py-1 bg-white focus:outline-none focus:ring-2 focus:ring-primary-500">
                                    <option value="ko" <?php echo get_language() === 'ko' ? 'selected' : ''; ?>>한국어</option>
                                    <option value="en" <?php echo get_language() === 'en' ? 'selected' : ''; ?>>English</option>
                                </select>
                            </div>
                            
                            <div class="text-sm text-gray-700">
                                <div class="flex items-center space-x-2">
                                    <a href="user_profile.php" class="font-medium text-primary-600 hover:text-primary-700 transition-colors duration-200"><?php echo htmlspecialchars($_SESSION['full_name']); ?></a>
                                    <span class="text-gray-500">(<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                                </div>
                                <div class="flex items-center space-x-1 text-xs text-blue-600">
                                    <i class="fas fa-store"></i>
                                    <span class="font-medium"><?php echo htmlspecialchars($current_store_name); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Mobile menu -->
            <div class="md:hidden hidden" id="mobile-menu">
                <div class="px-3 pt-3 pb-4 space-y-3 bg-white border-b border-gray-200 max-h-96 overflow-y-auto">
                    <!-- 모바일 기본 메뉴 카드 -->
                    <?php if (has_permission('admin_access') || has_permission('shop_access') || has_permission('user_management') || has_permission('store_management')): ?>
                    <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg p-1.5 border border-gray-200">
                        <div class="flex items-center mb-2">
                            <div class="w-6 h-6 bg-gray-600 rounded flex items-center justify-center mr-2">
                                <i class="fas fa-home text-white text-xs"></i>
                            </div>
                            <h4 class="text-xs font-semibold text-gray-800"><?php echo t('navigation.basic_menu'); ?></h4>
                        </div>
                        <div class="space-y-1">
                            <?php if (has_permission('admin_access') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                            <a href="index.php" class="<?php echo ($current_page == 'index.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-tachometer-alt mr-2 text-xs"></i><?php echo t('navigation.dashboard'); ?>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('shop_access')): ?>
                            <a href="shop.php" class="<?php echo ($current_page == 'shop.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-shopping-cart mr-2 text-xs"></i><?php echo t('navigation.shop'); ?>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('user_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                            <a href="user_management.php" class="<?php echo in_array($current_page, ['user_management.php', 'add_user.php', 'edit_user.php']) ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-users mr-2 text-xs"></i><?php echo t('navigation.user_management'); ?>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('store_management') || $_SESSION['role'] === 'super_admin'): ?>
                            <a href="store_management.php" class="<?php echo in_array($current_page, ['store_management.php', 'add_store.php', 'edit_store.php']) ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-store mr-2 text-xs"></i><?php echo t('navigation.store_management'); ?>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                            <a href="price_label_lists.php" class="<?php echo in_array($current_page, ['price_label_lists.php', 'price_label_project_edit.php']) ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-list-ul mr-2 text-xs"></i><?php echo t('navigation.price_label_lists'); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 모바일 매입 관리 카드 -->
                    <?php if (has_permission('purchase_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-1.5 border border-green-200">
                        <div class="flex items-center mb-2">
                            <div class="w-6 h-6 bg-green-600 rounded flex items-center justify-center mr-2">
                                <i class="fas fa-shopping-cart text-white text-xs"></i>
                            </div>
                            <h4 class="text-xs font-semibold text-green-800"><?php echo t('navigation.purchase_management_section'); ?></h4>
                        </div>
                        <div class="space-y-1">
                            <a href="purchase_management.php" class="<?php echo in_array($current_page, ['purchase_management.php', 'add_purchase.php', 'edit_purchase.php']) ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-shopping-cart mr-2 text-xs"></i><?php echo t('navigation.purchase_management'); ?>
                            </a>
                            
                            <a href="purchase_product_management.php" class="<?php echo ($current_page == 'purchase_product_management.php') ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-box mr-2 text-xs"></i><?php echo t('navigation.purchase_product_management'); ?>
                            </a>
                            
                            <a href="price_change_history.php" class="<?php echo ($current_page == 'price_change_history.php') ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-chart-line mr-2 text-xs"></i><?php echo t('navigation.price_change_history'); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 모바일 도매 관리 카드 -->
                    <?php if (has_permission('wholesale_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-lg p-1.5 border border-orange-200">
                        <div class="flex items-center mb-2">
                            <div class="w-6 h-6 bg-orange-600 rounded flex items-center justify-center mr-2">
                                <i class="fas fa-handshake text-white text-xs"></i>
                            </div>
                            <h4 class="text-xs font-semibold text-orange-800"><?php echo t('navigation.wholesale_management_section'); ?></h4>
                        </div>
                        <div class="space-y-1">
                            <a href="wholesale_customer_management.php" class="<?php echo in_array($current_page, ['wholesale_customer_management.php', 'add_wholesale_customer.php', 'edit_wholesale_customer.php']) ? 'bg-orange-200 text-orange-900' : 'text-orange-700 hover:bg-orange-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-users mr-2 text-xs"></i><?php echo t('navigation.wholesale_customer_management'); ?>
                            </a>
                            
                            <a href="wholesale_product_management.php" class="<?php echo in_array($current_page, ['wholesale_product_management.php', 'add_wholesale_product.php', 'edit_wholesale_product.php']) ? 'bg-orange-200 text-orange-900' : 'text-orange-700 hover:bg-orange-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-box-open mr-2 text-xs"></i><?php echo t('navigation.wholesale_product_management'); ?>
                            </a>
                            
                            <a href="wholesale_sales.php" class="<?php echo in_array($current_page, ['wholesale_sales.php', 'wholesale_sale_preview.php']) ? 'bg-orange-200 text-orange-900' : 'text-orange-700 hover:bg-orange-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-handshake mr-2 text-xs"></i><?php echo t('navigation.wholesale_sales'); ?>
                            </a>
                            
                            <a href="wholesale_sales_list.php" class="<?php echo ($current_page == 'wholesale_sales_list.php') ? 'bg-orange-200 text-orange-900' : 'text-orange-700 hover:bg-orange-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-list mr-2 text-xs"></i><?php echo t('navigation.wholesale_sales_list'); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 모바일 점간이동 카드 -->
                    <?php if (has_permission('store_transfer_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-1.5 border border-purple-200">
                        <div class="flex items-center mb-2">
                            <div class="w-6 h-6 bg-purple-600 rounded flex items-center justify-center mr-2">
                                <i class="fas fa-exchange-alt text-white text-xs"></i>
                            </div>
                            <h4 class="text-xs font-semibold text-purple-800"><?php echo t('navigation.store_transfer_section'); ?></h4>
                        </div>
                        <div class="space-y-1">
                            <a href="store_transfers_list.php" class="<?php echo in_array($current_page, ['store_transfers_list.php', 'store_transfers.php', 'store_transfer_preview.php']) ? 'bg-purple-200 text-purple-900' : 'text-purple-700 hover:bg-purple-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-exchange-alt mr-2 text-xs"></i><?php echo t('navigation.store_transfer_section'); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 모바일 상품 관리 카드 -->
                    <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-1.5 border border-blue-200">
                        <div class="flex items-center mb-2">
                            <div class="w-6 h-6 bg-blue-600 rounded flex items-center justify-center mr-2">
                                <i class="fas fa-box-open text-white text-xs"></i>
                            </div>
                            <h4 class="text-xs font-semibold text-blue-800"><?php echo t('navigation.product_management_section'); ?></h4>
                        </div>
                        <div class="space-y-1">
                            <a href="product_management.php" class="<?php echo in_array($current_page, ['product_management.php', 'add_product.php', 'edit_product.php']) ? 'bg-blue-200 text-blue-900' : 'text-blue-700 hover:bg-blue-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-box-open mr-2 text-xs"></i><?php echo t('navigation.product_management'); ?>
                            </a>
                            
                            <a href="new_products_management.php" class="<?php echo ($current_page == 'new_products_management.php') ? 'bg-blue-200 text-blue-900' : 'text-blue-700 hover:bg-blue-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-star mr-2 text-xs"></i><?php echo t('navigation.new_products_management'); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 모바일 쇼핑몰 관리 카드 -->
                    <?php if (has_permission('admin_access') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                    <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 rounded-lg p-1.5 border border-indigo-200">
                        <div class="flex items-center mb-2">
                            <div class="w-6 h-6 bg-indigo-600 rounded flex items-center justify-center mr-2">
                                <i class="fas fa-shopping-bag text-white text-xs"></i>
                            </div>
                            <h4 class="text-xs font-semibold text-indigo-800">쇼핑몰 관리</h4>
                        </div>
                        <div class="space-y-1">
                            <a href="shop_dashboard.php" class="<?php echo ($current_page == 'shop_dashboard.php') ? 'bg-indigo-200 text-indigo-900' : 'text-indigo-700 hover:bg-indigo-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-chart-pie mr-2 text-xs"></i>통합 대시보드
                            </a>
                            
                            <a href="display_sections.php" class="<?php echo ($current_page == 'display_sections.php') ? 'bg-indigo-200 text-indigo-900' : 'text-indigo-700 hover:bg-indigo-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-th-large mr-2 text-xs"></i>진열 섹션 관리
                            </a>
                            
                            <a href="product_display.php" class="<?php echo ($current_page == 'product_display.php') ? 'bg-indigo-200 text-indigo-900' : 'text-indigo-700 hover:bg-indigo-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-cubes mr-2 text-xs"></i>상품 진열 관리
                            </a>
                            
                            <a href="orders.php" class="<?php echo ($current_page == 'orders.php') ? 'bg-indigo-200 text-indigo-900' : 'text-indigo-700 hover:bg-indigo-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-shopping-cart mr-2 text-xs"></i>주문 관리
                            </a>
                            
                            <a href="store_product_planner.php" class="<?php echo ($current_page == 'store_product_planner.php') ? 'bg-indigo-200 text-indigo-900' : 'text-indigo-700 hover:bg-indigo-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-project-diagram mr-2 text-xs"></i>점포별 상품 배치
                            </a>
                            
                            <a href="shop_category_manager.php" class="<?php echo ($current_page == 'shop_category_manager.php') ? 'bg-indigo-200 text-indigo-900' : 'text-indigo-700 hover:bg-indigo-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-layer-group mr-2 text-xs"></i>카테고리별 관리
                            </a>
                            
                            <a href="shop_store_compare.php" class="<?php echo ($current_page == 'shop_store_compare.php') ? 'bg-indigo-200 text-indigo-900' : 'text-indigo-700 hover:bg-indigo-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-balance-scale mr-2 text-xs"></i>점포 비교 분석
                            </a>
                            
                            <a href="layout_builder.php" class="<?php echo ($current_page == 'layout_builder.php') ? 'bg-indigo-200 text-indigo-900' : 'text-indigo-700 hover:bg-indigo-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-th-large mr-2 text-xs"></i>레이아웃 빌더
                            </a>
                            
                            <a href="quick_layout_setup.php" class="<?php echo ($current_page == 'quick_layout_setup.php') ? 'bg-indigo-200 text-indigo-900' : 'text-indigo-700 hover:bg-indigo-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-magic mr-2 text-xs"></i>빠른 레이아웃 설정
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 모바일 기타정보관리 카드 -->
                    <?php if (has_permission('supplier_management') || has_permission('brand_management') || has_permission('category_management') || $_SESSION['role'] === 'super_admin'): ?>
                    <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-1.5 border border-green-200">
                        <div class="flex items-center mb-2">
                            <div class="w-6 h-6 bg-green-600 rounded flex items-center justify-center mr-2">
                                <i class="fas fa-database text-white text-xs"></i>
                            </div>
                            <h4 class="text-xs font-semibold text-green-800"><?php echo t('navigation.other_info_management_section'); ?></h4>
                        </div>
                        <div class="space-y-1">
                            <?php if (has_permission('supplier_management') || $_SESSION['role'] === 'super_admin'): ?>
                            <a href="supplier_management.php" class="<?php echo in_array($current_page, ['supplier_management.php', 'add_supplier.php', 'edit_supplier.php']) ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-truck mr-2 text-xs"></i><?php echo t('navigation.supplier_management'); ?>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('brand_management') || $_SESSION['role'] === 'super_admin'): ?>
                            <a href="brand_management.php" class="<?php echo in_array($current_page, ['brand_management.php', 'add_brand.php', 'edit_brand.php']) ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-tags mr-2 text-xs"></i><?php echo t('navigation.brand_management'); ?>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('category_management') || $_SESSION['role'] === 'super_admin'): ?>
                            <a href="category_management.php" class="<?php echo in_array($current_page, ['category_management.php', 'add_category.php', 'edit_category.php']) ? 'bg-green-200 text-green-900' : 'text-green-700 hover:bg-green-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-sitemap mr-2 text-xs"></i><?php echo t('navigation.category_management'); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 모바일 시스템 설정 카드 -->
                    <?php if (has_permission('settings') || $_SESSION['role'] === 'super_admin'): ?>
                    <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg p-1.5 border border-gray-200">
                        <div class="flex items-center mb-2">
                            <div class="w-6 h-6 bg-gray-600 rounded flex items-center justify-center mr-2">
                                <i class="fas fa-cog text-white text-xs"></i>
                            </div>
                            <h4 class="text-xs font-semibold text-gray-800"><?php echo t('navigation.system_settings'); ?></h4>
                        </div>
                        <div class="space-y-1">
                            <a href="settings.php" class="<?php echo ($current_page == 'settings.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-cog mr-2 text-xs"></i><?php echo t('navigation.settings'); ?>
                            </a>
                            
                            <a href="backup_management.php" class="<?php echo ($current_page == 'backup_management.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-database mr-2 text-xs"></i>데이터베이스 백업
                            </a>
                            
                            <a href="excel_test.php" class="<?php echo ($current_page == 'excel_test.php') ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-200'; ?> block px-2 py-1 rounded text-sm">
                                <i class="fas fa-file-excel mr-2 text-xs"></i><?php echo t('navigation.excel_test'); ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Main content area -->
            <main class="flex-1 relative overflow-y-auto focus:outline-none">
                <div class="py-0">
                    <div class="w-full px-0">

<script>
// Language Switcher
document.addEventListener('DOMContentLoaded', function() {
    const languageSwitcher = document.getElementById('language-switcher');
    if (languageSwitcher) {
        languageSwitcher.addEventListener('change', function() {
            const selectedLang = this.value;
            
            // AJAX로 언어 변경
            fetch('ajax_set_language.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'language=' + encodeURIComponent(selectedLang)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 페이지 새로고침으로 변경된 언어 적용
                    window.location.reload();
                } else {
                    alert(t('common.error') + ': ' + data.message);
                    // 실패시 이전 선택으로 되돌리기
                    this.value = '<?php echo get_language(); ?>';
                }
            })
            .catch(error => {
                console.error('Language change error:', error);
                alert('언어 변경 중 오류가 발생했습니다.');
                this.value = '<?php echo get_language(); ?>';
            });
        });
    }

    // Mobile menu toggle removed
});
</script>