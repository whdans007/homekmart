<?php
// Design Ref: §3.2 — 발주 전용 헤더, 인증만 공유, 좌측 사이드바 레이아웃 (logistics teal 디자인 통일)
ob_start();
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/order_helper.php';
ord_require_manager();

$_ord_page  = basename($_SERVER['PHP_SELF']);
$_ord_flash = ord_get_flash();
$_ord_cart_count = ord_cart_count(ord_current_store_id());

// 점포명 / 등급(역할) 라벨 — office 사용자 정보 카드와 동일
$_ord_role_label = !empty($_SESSION['role']) ? get_role_label($_SESSION['role']) : '';
$_ord_store_name = '';
try {
    $_oc = get_ord_db();
    $_os = $_oc->prepare("SELECT name FROM stores WHERE id = ?");
    $_osid = ord_current_store_id();
    $_os->bind_param('i', $_osid); $_os->execute();
    $_ord_store_name = $_os->get_result()->fetch_row()[0] ?? '';
    $_os->close(); $_oc->close();
} catch (Exception $e) {}

$navItems = [
    ['file' => 'index.php',            'label' => '검색 / 발주',  'icon' => 'fa-search'],
    ['file' => 'upload_inventory.php', 'label' => '재고리스트 업로드', 'icon' => 'fa-upload', 'also' => 'upload_excel.php'],
    ['file' => 'order_history.php',    'label' => '발주 이력',    'icon' => 'fa-history'],
];
$adminItems = [];
if (ord_is_admin()) {
    $adminItems[] = ['file' => 'vendors.php', 'label' => '업체 관리', 'icon' => 'fa-building'];
    $adminItems[] = ['file' => 'users.php',   'label' => '사용자 관리', 'icon' => 'fa-users'];
}
?>
<!DOCTYPE html>
<html lang="ko" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? '발주 관리'); ?> — HOME K MART</title>
    <link rel="icon" href="data:,">
    <link href="<?php echo ORD_WEB_ROOT; ?>/admin/css/style.css?v=20260619teal" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 h-full">

<div class="flex h-screen overflow-hidden">

    <!-- 좌측 사이드바 (logistics teal 디자인) -->
    <div class="flex flex-col w-52 flex-shrink-0 bg-white border-r border-teal-100">

        <!-- 로고 + MAIN -->
        <div class="flex flex-col flex-shrink-0 px-2 pt-1 mb-2">
            <div class="block mb-2">
                <img src="<?php echo ORD_WEB_ROOT; ?>/logo/homekmart_logo.png" alt="HOME K MART" style="width:100%;height:auto;display:block;">
            </div>
            <div class="flex items-center justify-center text-teal-700 mb-2">
                <span class="font-bold" style="font-size:0.72rem;letter-spacing:0.02em;">OFFICE MANAGEMENT</span>
            </div>
            <a href="/"
               class="flex items-center gap-2 w-full px-2 py-1.5 text-xs font-semibold rounded-md transition-colors"
               style="background:#1e40af;color:#ffffff;"
               onmouseover="this.style.background='#1e3a8a'" onmouseout="this.style.background='#1e40af'">
                <i class="fa-solid fa-house"></i> MAIN
            </a>
        </div>

        <!-- 상단 사용자 정보 카드 (office 1:1) -->
        <div class="flex-shrink-0 mx-2 mb-1.5" style="border:1px solid #ccfbf1;border-radius:0.6rem;background:linear-gradient(135deg,#f0fdfa 0%,#ecfdf5 100%);overflow:hidden;">
            <div style="display:flex;align-items:center;gap:0.35rem;padding:0.5rem 0.55rem;background:#0d9488;color:#fff;">
                <i class="fa-solid fa-store" style="font-size:0.8rem;flex-shrink:0;"></i>
                <span style="font-weight:600;font-size:0.78rem;line-height:1.15;"><?php echo htmlspecialchars($_ord_store_name ?: 'Store'); ?></span>
            </div>
            <div style="padding:0.5rem 0.55rem;">
                <div style="display:flex;align-items:center;gap:0.35rem;color:#334155;font-size:11px;margin-bottom:0.5rem;">
                    <i class="fa-solid fa-circle-user" style="color:#0d9488;font-size:0.9rem;"></i>
                    <span style="font-weight:500;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:0.4rem;">
                    <?php if ($_ord_role_label !== ''): ?>
                    <span style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.35rem 0.5rem;font-size:11px;font-weight:600;color:#0f766e;background:#ccfbf1;border-radius:0.4rem;white-space:nowrap;">
                        <i class="fa-solid fa-id-badge" style="font-size:0.62rem;"></i><?php echo htmlspecialchars($_ord_role_label); ?>
                    </span>
                    <?php endif; ?>
                    <a href="<?php echo ORD_BASE; ?>/logout.php"
                       style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:0.3rem;padding:0.35rem 0.4rem;font-size:11px;font-weight:600;color:#dc2626;background:#fef2f2;border-radius:0.4rem;text-decoration:none;transition:background 0.15s;"
                       onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                        <i class="fa-solid fa-right-from-bracket"></i>Logout
                    </a>
                </div>
            </div>
        </div>

        <!-- 네비게이션 -->
        <nav class="flex-grow px-2 space-y-1 overflow-y-auto">

            <div class="pt-1 pb-1">
                <p class="px-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">발주</p>
            </div>
            <?php foreach ($navItems as $nav): ?>
            <?php $active = $_ord_page === $nav['file'] || $_ord_page === ($nav['also'] ?? ''); ?>
            <a href="<?php echo ORD_BASE . '/' . $nav['file']; ?>"
               class="<?php echo $active ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                <i class="fas <?php echo $nav['icon']; ?> mr-2 text-xs w-4 text-center"></i>
                <?php echo $nav['label']; ?>
                <?php if ($nav['file'] === 'index.php' && $_ord_cart_count > 0): ?>
                <span class="ml-auto inline-flex items-center justify-center font-bold"
                      style="background:#dc2626;color:#fff;min-width:1.15rem;height:1.15rem;padding:0 0.3rem;border-radius:9999px;font-size:0.65rem;line-height:1">
                    <?php echo $_ord_cart_count; ?>
                </span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>

            <?php if (!empty($adminItems)): ?>
            <!-- 관리자 섹션 (slate) -->
            <div class="rounded-lg px-1.5 py-2 mt-3" style="background:#f8fafc;">
                <p class="px-2 py-1 mb-1 text-xs font-semibold text-slate-700 uppercase tracking-wider rounded" style="background:#e2e8f0;">관리자</p>
                <?php foreach ($adminItems as $nav): ?>
                <?php $active = $_ord_page === $nav['file']; ?>
                <a href="<?php echo ORD_BASE . '/' . $nav['file']; ?>"
                   class="<?php echo $active ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                    <i class="fas <?php echo $nav['icon']; ?> mr-2 text-xs w-4 text-center"></i>
                    <?php echo $nav['label']; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </nav>

        <!-- 하단: 관리자 화면 이동 -->
        <div class="flex-shrink-0 px-3 pb-4 border-t border-gray-100 pt-4">
            <a href="<?php echo ORD_WEB_ROOT; ?>/admin/"
               class="flex items-center w-full px-3 py-2 text-sm text-gray-500 hover:bg-gray-50 rounded-md transition-colors">
                <i class="fas fa-arrow-left mr-2 text-xs"></i>관리자 화면
            </a>
        </div>
    </div>

    <!-- 메인 콘텐츠 영역 -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- 상단 바 -->
        <div class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between flex-shrink-0">
            <h1 class="text-base font-semibold text-gray-700">
                <?php echo htmlspecialchars($page_title ?? '발주 관리'); ?>
            </h1>
            <?php if ($_ord_cart_count > 0): ?>
            <a href="<?php echo ORD_BASE; ?>/index.php#cart"
               class="inline-flex items-center text-xs bg-yellow-50 border border-yellow-300 text-yellow-800 px-3 py-1.5 rounded-full font-medium hover:bg-yellow-100 transition-colors">
                <i class="fas fa-shopping-cart mr-1.5"></i>장바구니 <?php echo $_ord_cart_count; ?>건
            </a>
            <?php endif; ?>
        </div>

        <!-- 페이지 본문 -->
        <div class="flex-1 overflow-y-auto">
        <div class="px-6 py-5">

        <?php if ($_ord_flash): ?>
        <div class="mb-4 px-4 py-3 rounded-lg border text-sm flex items-center
                    <?php echo $_ord_flash['type'] === 'success'
                        ? 'bg-green-50 border-green-200 text-green-800'
                        : 'bg-red-50 border-red-200 text-red-800'; ?>">
            <i class="fas <?php echo $_ord_flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
            <?php echo htmlspecialchars($_ord_flash['message']); ?>
        </div>
        <?php endif; ?>
