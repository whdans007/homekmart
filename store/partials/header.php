<?php
ob_start();
require_once __DIR__ . '/../lib/auth.php';
store_require_store_user();

$_sp       = basename($_SERVER['PHP_SELF']);
$_sf       = store_get_flash();
$_sid      = store_current_store_id();
$_sname    = '';
try {
    $c = get_store_db();
    $st = $c->prepare("SELECT name FROM stores WHERE id = ?");
    $st->bind_param('i', $_sid); $st->execute();
    $_sname = $st->get_result()->fetch_row()[0] ?? '';
    $st->close(); $c->close();
} catch (Exception $e) {}

// 현재 사용자 이름 / 등급(역할) 라벨
$_uname     = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$_role_label = !empty($_SESSION['role']) ? get_role_label($_SESSION['role']) : '';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title><?php echo htmlspecialchars($page_title ?? 'Order'); ?> — <?php echo htmlspecialchars($_sname ?: 'Store'); ?></title>
<link rel="icon" href="data:,">
<link href="<?php echo STORE_WEB_ROOT; ?>/admin/css/style.css?v=20260619teal" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 min-h-screen">

<div class="flex h-screen bg-gray-50">

    <!-- 사이드바 -->
    <div class="hidden md:flex md:flex-shrink-0">
        <div class="flex flex-col w-52">
            <div class="flex flex-col flex-grow pt-5 pb-4 overflow-y-auto bg-white border-r border-teal-100">

                <!-- 로고 + MAIN -->
                <div class="flex flex-col flex-shrink-0 px-3 mb-3">
                    <div class="block mb-2">
                        <img src="<?php echo STORE_WEB_ROOT; ?>/logo/homekmart_logo.png" alt="Home K Mart" style="max-width:150px; width:100%;">
                    </div>
                    <a href="<?php echo STORE_WEB_ROOT; ?>/"
                       class="flex items-center gap-2 w-full px-2 py-1.5 text-xs font-semibold rounded-md transition-colors"
                       style="background:#1e40af;color:#ffffff;"
                       onmouseover="this.style.background='#1e3a8a'" onmouseout="this.style.background='#1e40af'">
                        <i class="fas fa-globe"></i> MAIN
                    </a>
                </div>

                <!-- 상단 사용자 정보 카드 (admin 1:1) -->
                <div class="flex-shrink-0 mx-2 mb-2" style="border:1px solid #ccfbf1;border-radius:0.6rem;background:linear-gradient(135deg,#f0fdfa 0%,#ecfdf5 100%);overflow:hidden;">
                    <div style="display:flex;align-items:center;gap:0.35rem;padding:0.5rem 0.55rem;background:#0d9488;color:#fff;">
                        <i class="fas fa-store" style="font-size:0.8rem;flex-shrink:0;"></i>
                        <span style="font-weight:600;font-size:11px;line-height:1.15;"><?php echo htmlspecialchars($_sname ?: 'Store'); ?></span>
                    </div>
                    <div style="padding:0.5rem 0.55rem;">
                        <div style="display:flex;align-items:center;gap:0.35rem;color:#334155;font-size:11px;margin-bottom:0.5rem;">
                            <i class="fas fa-circle-user" style="color:#0d9488;font-size:0.9rem;"></i>
                            <span style="font-weight:500;"><?php echo htmlspecialchars($_uname); ?></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.4rem;">
                            <?php if ($_role_label !== ''): ?>
                            <span style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.35rem 0.5rem;font-size:11px;font-weight:600;color:#0f766e;background:#ccfbf1;border-radius:0.4rem;white-space:nowrap;">
                                <i class="fas fa-id-badge" style="font-size:0.62rem;"></i><?php echo htmlspecialchars($_role_label); ?>
                            </span>
                            <?php endif; ?>
                            <a href="<?php echo STORE_BASE; ?>/logout.php"
                               style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:0.3rem;padding:0.35rem 0.4rem;font-size:11px;font-weight:600;white-space:nowrap;color:#dc2626;background:#fef2f2;border-radius:0.4rem;transition:background 0.15s;"
                               onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                                <i class="fas fa-sign-out-alt"></i>로그아웃
                            </a>
                        </div>
                    </div>
                </div>

                <nav id="admin-nav" class="flex-grow px-2 space-y-1">
                    <!-- Orders (teal) -->
                    <div class="rounded-lg px-1.5 py-2" style="background:#f0fdfa;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#ccfbf1;color:#0f766e;">Orders</p>
                        <a href="<?php echo STORE_BASE; ?>/order.php"
                           class="<?php echo in_array($_sp, ['order.php','index.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-cart-plus mr-2 text-xs w-4 text-center"></i>Order
                        </a>
                        <a href="<?php echo STORE_BASE; ?>/orders.php"
                           class="<?php echo in_array($_sp, ['orders.php','order_detail.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-list-alt mr-2 text-xs w-4 text-center"></i>Order History
                        </a>
                    </div>

                    <!-- Requests (amber) -->
                    <div class="rounded-lg px-1.5 py-2" style="background:#fffbeb;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#fef3c7;color:#92400e;">Requests</p>
                        <a href="<?php echo STORE_BASE; ?>/requests.php"
                           class="<?php echo in_array($_sp, ['requests.php','request_new.php','request_detail.php']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas fa-comment-dots mr-2 text-xs w-4 text-center"></i>Requests
                        </a>
                    </div>
                </nav>

            </div>
        </div>
    </div>

    <!-- 메인 콘텐츠 영역 -->
    <div class="flex flex-col flex-1 overflow-hidden">

        <!-- 모바일 상단 바 -->
        <div class="md:hidden bg-white border-b border-teal-100 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <img src="<?php echo STORE_WEB_ROOT; ?>/logo/homekmart_logo.png" alt="Home K Mart" style="height:28px;">
                <a href="<?php echo STORE_WEB_ROOT; ?>/"
                   class="inline-flex items-center px-2 py-1 text-xs font-semibold text-white bg-teal-600 hover:bg-teal-700 rounded-md transition-colors">
                    <i class="fas fa-globe mr-1"></i> MAIN
                </a>
            </div>
            <button id="mobile-menu-btn" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <!-- 모바일 드롭다운 메뉴 -->
        <div id="mobile-menu" class="hidden md:hidden bg-white border-b border-gray-200 px-4 py-3 space-y-1">
            <a href="<?php echo STORE_BASE; ?>/order.php"  class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md">Order</a>
            <a href="<?php echo STORE_BASE; ?>/orders.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md">Order History</a>
            <a href="<?php echo STORE_BASE; ?>/requests.php" class="block px-3 py-2 text-sm text-gray-700 hover:bg-teal-50 rounded-md">Requests</a>
            <div class="border-t border-gray-100 mt-2 pt-2">
                <div class="px-3 py-1 text-xs text-gray-500"><i class="fas fa-store mr-2"></i><?php echo htmlspecialchars($_sname ?: 'Store'); ?></div>
                <div class="px-3 py-1 text-sm text-gray-700"><i class="fas fa-circle-user mr-2"></i><?php echo htmlspecialchars($_uname); ?><?php if ($_role_label !== ''): ?> <span class="text-xs text-teal-700">(<?php echo htmlspecialchars($_role_label); ?>)</span><?php endif; ?></div>
                <a href="<?php echo STORE_BASE; ?>/logout.php" class="block px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-md"><i class="fas fa-sign-out-alt mr-2"></i>로그아웃</a>
            </div>
        </div>

        <!-- 플래시 메시지 -->
        <?php if ($_sf): ?>
        <div class="mx-4 mt-4 px-4 py-3 rounded-md text-sm flex items-center
            <?php echo $_sf['type'] === 'error' ? 'bg-red-50 text-red-800 border border-red-200' : 'bg-green-50 text-green-800 border border-green-200'; ?>">
            <i class="fas <?php echo $_sf['type'] === 'error' ? 'fa-exclamation-circle text-red-400' : 'fa-check-circle text-green-400'; ?> mr-2"></i>
            <?php echo htmlspecialchars($_sf['message']); ?>
        </div>
        <?php endif; ?>

        <!-- 페이지 콘텐츠 시작 -->
        <main class="flex-1 overflow-y-auto p-5 pb-28">

<script>
document.getElementById('mobile-menu-btn')?.addEventListener('click', function() {
    document.getElementById('mobile-menu')?.classList.toggle('hidden');
});
</script>
