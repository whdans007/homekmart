<?php
ob_start(); // 출력 버퍼링 시작
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
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
    <title><?php echo $page_title ?? 'HOME K MART'; ?></title>
    <link rel="icon" href="data:,">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="favicon.svg">
    <link href="css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="flex h-screen bg-gray-50">
        <!-- Sidebar -->
        <div class="hidden md:flex md:flex-shrink-0">
            <div class="flex flex-col w-64">
                <div class="flex flex-col flex-grow pt-5 pb-4 overflow-y-auto bg-white border-r border-gray-200">
                    <div class="flex items-center flex-shrink-0 px-4">
                        <h1 class="text-xl font-bold text-gray-900">
                            <a href="index.php" class="text-primary-600 hover:text-primary-700">HOME K MART</a>
                        </h1>
                    </div>
                    <div class="mt-5 flex-grow flex flex-col">
                        <nav class="flex-1 px-2 space-y-1">
                            <div class="space-y-1">
                                <?php if (has_permission('admin_access') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                                <a href="index.php" class="<?php echo ($current_page == 'index.php') ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-tachometer-alt mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                    대시보드
                                </a>
                                <?php endif; ?>
                                
                                <?php if (has_permission('shop_access')): ?>
                                <a href="shop.php" class="<?php echo ($current_page == 'shop.php') ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-shopping-cart mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                    쇼핑몰
                                </a>
                                <?php endif; ?>
                                
                                <?php if (has_permission('user_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                                <a href="user_management.php" class="<?php echo in_array($current_page, ['user_management.php', 'add_user.php', 'edit_user.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-users mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                    회원관리
                                </a>
                                <?php endif; ?>
                                
                                <?php if (has_permission('store_management') || $_SESSION['role'] === 'super_admin'): ?>
                                <a href="store_management.php" class="<?php echo in_array($current_page, ['store_management.php', 'add_store.php', 'edit_store.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                    <i class="fas fa-store mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                    지점관리
                                </a>
                                <?php endif; ?>
                            </div>
                            
                            <?php 
                            // 관리 메뉴가 있는지 확인 (기존 role 체크도 포함)
                            $has_management_menus = has_permission('brand_management') || 
                                                  has_permission('category_management') || 
                                                  has_permission('supplier_management') || 
                                                  has_permission('product_management') || 
                                                  has_permission('purchase_management') || 
                                                  has_permission('settings') ||
                                                  $_SESSION['role'] === 'super_admin';
                            
                            if ($has_management_menus): ?>
                            <div class="pt-6">
                                <h3 class="px-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">상품 설정</h3>
                                <div class="mt-2 space-y-1">
                                    <?php if (has_permission('brand_management') || $_SESSION['role'] === 'super_admin'): ?>
                                    <a href="brand_management.php" class="<?php echo in_array($current_page, ['brand_management.php', 'add_brand.php', 'edit_brand.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                        <i class="fas fa-tags mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                        브랜드관리
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (has_permission('category_management') || $_SESSION['role'] === 'super_admin'): ?>
                                    <a href="category_management.php" class="<?php echo in_array($current_page, ['category_management.php', 'add_category.php', 'edit_category.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                        <i class="fas fa-sitemap mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                        카테고리 관리
                                    </a>
                                    <?php endif; ?>
                                    
                                    <a href="margin_management.php" class="<?php echo in_array($current_page, ['margin_management.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                        <i class="fas fa-percentage mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                        마진관리
                                    </a>
                                    
                                    <?php if (has_permission('supplier_management') || $_SESSION['role'] === 'super_admin'): ?>
                                    <a href="supplier_management.php" class="<?php echo in_array($current_page, ['supplier_management.php', 'add_supplier.php', 'edit_supplier.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                        <i class="fas fa-truck mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                        공급처관리
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (has_permission('product_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                                    <a href="product_management.php" class="<?php echo in_array($current_page, ['product_management.php', 'add_product.php', 'edit_product.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                        <i class="fas fa-box-open mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                        상품관리
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (has_permission('purchase_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])): ?>
                                    <a href="purchase_management.php" class="<?php echo in_array($current_page, ['purchase_management.php', 'add_purchase.php', 'edit_purchase.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                        <i class="fas fa-shopping-cart mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                        매입관리
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (has_permission('settings') || $_SESSION['role'] === 'super_admin'): ?>
                                    <a href="settings.php" class="<?php echo ($current_page == 'settings.php') ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                        <i class="fas fa-cog mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                        환경설정
                                    </a>
                                    
                                    <a href="excel_test.php" class="<?php echo ($current_page == 'excel_test.php') ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors duration-200">
                                        <i class="fas fa-file-excel mr-3 text-gray-400 group-hover:text-gray-500"></i>
                                        엑셀 테스트
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main content -->
        <div class="flex flex-col w-0 flex-1 overflow-hidden">
            <!-- Top header -->
            <div class="relative z-10 flex-shrink-0 flex h-16 bg-white shadow">
                <div class="flex-1 px-4 flex justify-between">
                    <div class="flex-1 flex items-center">
                        <!-- Mobile menu button -->
                        <button type="button" class="md:hidden px-4 text-gray-500 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500" id="mobile-menu-button">
                            <i class="fas fa-bars"></i>
                        </button>
                    </div>
                    <div class="ml-4 flex items-center md:ml-6">
                        <div class="flex items-center space-x-4">
                            <div class="text-sm text-gray-700">
                                <div class="flex items-center space-x-2">
                                    <span class="font-medium"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                                    <span class="text-gray-500">(<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                                </div>
                                <div class="flex items-center space-x-1 text-xs text-blue-600">
                                    <i class="fas fa-store"></i>
                                    <span class="font-medium"><?php echo htmlspecialchars($current_store_name); ?></span>
                                </div>
                            </div>
                            <a href="logout.php" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200">
                                <i class="fas fa-sign-out-alt mr-2"></i>
                                로그아웃
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Mobile menu -->
            <div class="md:hidden hidden" id="mobile-menu">
                <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3 bg-white border-b border-gray-200">
                    <?php if (has_permission('admin_access')): ?>
                    <a href="index.php" class="<?php echo ($current_page == 'index.php') ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> block px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-tachometer-alt mr-2"></i>대시보드
                    </a>
                    <?php endif; ?>
                    
                    <?php if (has_permission('shop_access')): ?>
                    <a href="shop.php" class="<?php echo ($current_page == 'shop.php') ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> block px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-shopping-cart mr-2"></i>쇼핑몰
                    </a>
                    <?php endif; ?>
                    
                    <?php if (has_permission('user_management')): ?>
                    <a href="user_management.php" class="<?php echo in_array($current_page, ['user_management.php', 'add_user.php', 'edit_user.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> block px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-users mr-2"></i>회원관리
                    </a>
                    <?php endif; ?>
                    
                    <?php if (has_permission('store_management')): ?>
                    <a href="store_management.php" class="<?php echo in_array($current_page, ['store_management.php', 'add_store.php', 'edit_store.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> block px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-store mr-2"></i>지점관리
                    </a>
                    <?php endif; ?>
                    
                    <?php if (has_permission('brand_management')): ?>
                    <a href="brand_management.php" class="<?php echo in_array($current_page, ['brand_management.php', 'add_brand.php', 'edit_brand.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> block px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-tags mr-2"></i>브랜드관리
                    </a>
                    <?php endif; ?>
                    
                    <?php if (has_permission('category_management')): ?>
                    <a href="category_management.php" class="<?php echo in_array($current_page, ['category_management.php', 'add_category.php', 'edit_category.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> block px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-sitemap mr-2"></i>카테고리 관리
                    </a>
                    <?php endif; ?>
                    
                    <?php if (has_permission('supplier_management')): ?>
                    <a href="supplier_management.php" class="<?php echo in_array($current_page, ['supplier_management.php', 'add_supplier.php', 'edit_supplier.php']) ? 'bg-primary-100 text-primary-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?> block px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-truck mr-2"></i>공급처관리
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Main content area -->
            <main class="flex-1 relative overflow-y-auto focus:outline-none">
                <div class="py-6">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 md:px-8">