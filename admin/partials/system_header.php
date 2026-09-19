<?php
/**
 * 시스템 관리 전용 레이아웃 헤더 (logistics teal 디자인 1:1).
 * 회원 관리 / 역할 관리 / 지점 관리 메뉴만 노출하는 독립 사이드바. super_admin 전용.
 */
ob_start();
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';

ensure_logged_in();

// super_admin 전용 영역
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: index.php');
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);

// 메뉴 정의: [라벨, 진입 페이지, 활성 처리 대상 페이지들, 아이콘]
$system_menu = [
    ['label' => t('navigation.user_management'),  'href' => 'user_management.php',  'pages' => ['user_management.php', 'add_user.php', 'edit_user.php'],                'icon' => 'fa-users'],
    ['label' => t('role_management.title'),        'href' => 'role_management.php',  'pages' => ['role_management.php', 'role_permissions_matrix.php'],                   'icon' => 'fa-user-shield'],
    ['label' => t('navigation.store_management'),  'href' => 'store_management.php', 'pages' => ['store_management.php', 'add_store.php', 'edit_store.php'],              'icon' => 'fa-store'],
];

// office 스타일 사용자 카드용: 점포명 / 사용자명 / 역할 라벨
$_sys_user_name  = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$_sys_role_label = !empty($_SESSION['role']) ? get_role_label($_SESSION['role']) : '';
$_sys_store_name = '';
if (!empty($_SESSION['user_id'])) {
    try {
        $conn = get_db_connection();
        $st = $conn->prepare("SELECT s.name FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
        $st->bind_param('i', $_SESSION['user_id']);
        $st->execute();
        $_sys_store_name = $st->get_result()->fetch_row()[0] ?? '';
        $st->close();
        $conn->close();
    } catch (Exception $e) { /* 조회 실패 시 빈 문자열 */ }
}
$_sys_store_label = $_sys_store_name !== '' ? $_sys_store_name : 'System Admin';
?>
<!DOCTYPE html>
<html lang="ko" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="msapplication-config" content="none">
    <title><?php echo $page_title ?? ('시스템 관리 - ' . t('company.name')); ?></title>
    <link rel="icon" href="data:,">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link href="css/style.css?v=20260619teal" rel="stylesheet">
    <link href="css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #sys-nav a:hover { background: #ccfbf1 !important; color: #0f766e !important; }
    </style>
    <?php echo get_js_translation_script(); ?>
</head>
<body class="bg-gray-50 min-h-screen">

<div class="flex h-screen bg-gray-50">
    <!-- 시스템 관리 사이드바 (logistics 1:1) -->
    <div class="hidden md:flex md:flex-shrink-0">
        <div class="flex flex-col w-52">
            <div class="flex flex-col flex-grow pt-5 pb-4 overflow-y-auto bg-white border-r border-teal-100">

                <!-- 로고 + SYSTEM MANAGEMENT + MAIN -->
                <div class="flex flex-col flex-shrink-0 px-2 pt-1 mb-2">
                    <a href="https://main.homekmart.net/admin/system_management.php" class="block mb-2">
                        <img src="../logo/homekmart_logo.png" alt="<?php echo htmlspecialchars(t('company.name')); ?>" style="width:100%;height:auto;display:block;">
                    </a>
                    <div class="flex items-center justify-center text-teal-700 mb-2">
                        <span class="font-bold" style="font-size:0.72rem;letter-spacing:0.02em;">SYSTEM MANAGEMENT</span>
                    </div>
                    <a href="../index.php"
                       class="flex items-center gap-2 w-full px-2 py-1.5 text-xs font-semibold rounded-md transition-colors"
                       style="background:#1e40af;color:#ffffff;"
                       onmouseover="this.style.background='#1e3a8a'" onmouseout="this.style.background='#1e40af'">
                        <i class="fa-solid fa-house"></i> MAIN
                    </a>
                </div>

                <!-- 상단 사용자 정보 카드 (office 1:1) -->
                <div class="flex-shrink-0 mx-2 mb-2" style="border:1px solid #ccfbf1;border-radius:0.6rem;background:linear-gradient(135deg,#f0fdfa 0%,#ecfdf5 100%);overflow:hidden;">
                    <div style="display:flex;align-items:center;gap:0.35rem;padding:0.5rem 0.55rem;background:#0d9488;color:#fff;">
                        <i class="fas fa-store" style="font-size:0.8rem;flex-shrink:0;"></i>
                        <span style="font-weight:600;font-size:11px;line-height:1.15;"><?php echo htmlspecialchars($_sys_store_label); ?></span>
                    </div>
                    <div style="padding:0.5rem 0.55rem;">
                        <div style="display:flex;align-items:center;gap:0.35rem;color:#334155;font-size:11px;margin-bottom:0.5rem;">
                            <i class="fas fa-circle-user" style="color:#0d9488;font-size:0.9rem;"></i>
                            <span style="font-weight:500;"><?php echo htmlspecialchars($_sys_user_name); ?></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.4rem;">
                            <?php if ($_sys_role_label !== ''): ?>
                            <span style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.35rem 0.5rem;font-size:11px;font-weight:600;color:#0f766e;background:#ccfbf1;border-radius:0.4rem;white-space:nowrap;">
                                <i class="fas fa-id-badge" style="font-size:0.62rem;"></i><?php echo htmlspecialchars($_sys_role_label); ?>
                            </span>
                            <?php endif; ?>
                            <a href="logout.php"
                               style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:0.3rem;padding:0.35rem 0.4rem;font-size:11px;font-weight:600;white-space:nowrap;color:#dc2626;background:#fef2f2;border-radius:0.4rem;transition:background 0.15s;"
                               onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'"
                               onclick="return confirm('Sign out?')">
                                <i class="fas fa-sign-out-alt"></i>Logout
                            </a>
                        </div>
                    </div>
                </div>

                <nav id="sys-nav" class="flex-grow px-2 space-y-1">
                    <div class="rounded-lg px-1.5 py-2" style="background:#f8fafc;">
                        <p class="px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded" style="background:#e2e8f0;color:#334155;">시스템 관리</p>
                        <?php foreach ($system_menu as $item): ?>
                        <a href="<?php echo $item['href']; ?>" class="<?php echo in_array($current_page, $item['pages']) ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                            <i class="fas <?php echo $item['icon']; ?> mr-2 text-xs w-4 text-center"></i><?php echo htmlspecialchars($item['label']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </nav>

                <!-- 하단: 프로필 + 언어 선택 (로그아웃은 상단 카드로 이동) -->
                <div class="flex-shrink-0 px-3 pb-4 border-t border-gray-100 pt-4">
                    <a href="user_profile.php" class="flex items-center text-xs font-medium text-teal-700 hover:text-teal-800 mb-2">
                        <i class="fas fa-id-card mr-2 text-xs"></i>Profile
                    </a>
                    <select id="language-switcher" class="w-full text-xs border border-gray-300 rounded-md px-2 py-1 bg-white focus:outline-none">
                        <option value="ko" <?php echo get_language() === 'ko' ? 'selected' : ''; ?>>한국어</option>
                        <option value="en" <?php echo get_language() === 'en' ? 'selected' : ''; ?>>English</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- 메인 영역 -->
    <div class="flex flex-col flex-1 overflow-hidden">

        <!-- 모바일 상단 바 -->
        <div class="md:hidden bg-white border-b border-teal-100 px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="../index.php"><img src="../logo/homekmart_logo.png" alt="HOME K MART" style="height:28px;"></a>
                <span class="text-sm font-semibold text-gray-700"><i class="fas fa-cog mr-1 text-slate-500"></i> 시스템 관리</span>
            </div>
            <button id="mobile-menu-btn" class="text-gray-500 hover:text-gray-700"><i class="fas fa-bars text-xl"></i></button>
        </div>

        <!-- 모바일 드롭다운 메뉴 -->
        <div id="mobile-menu" class="hidden md:hidden bg-white border-b border-gray-200 px-4 py-3 space-y-1">
            <?php foreach ($system_menu as $item): ?>
            <a href="<?php echo $item['href']; ?>" class="<?php echo in_array($current_page, $item['pages']) ? 'bg-teal-100 text-teal-800' : 'text-gray-700 hover:bg-teal-50 hover:text-teal-700'; ?> block px-3 py-2 text-sm rounded-md">
                <i class="fas <?php echo $item['icon']; ?> mr-2 text-xs"></i><?php echo htmlspecialchars($item['label']); ?>
            </a>
            <?php endforeach; ?>
            <div class="border-t border-gray-100 mt-2 pt-2">
                <div class="px-3 py-1 text-xs text-gray-500"><i class="fas fa-store mr-2"></i><?php echo htmlspecialchars($_sys_store_label); ?></div>
                <div class="px-3 py-1 text-sm text-gray-700"><i class="fas fa-circle-user mr-2"></i><?php echo htmlspecialchars($_sys_user_name); ?><?php if ($_sys_role_label !== ''): ?> <span class="text-xs text-teal-700">(<?php echo htmlspecialchars($_sys_role_label); ?>)</span><?php endif; ?></div>
                <a href="logout.php" class="block px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-md"><i class="fas fa-sign-out-alt mr-2"></i>로그아웃</a>
            </div>
        </div>

        <!-- 본문 -->
        <main class="flex-1 relative overflow-y-auto focus:outline-none">
            <div class="w-full px-0">

<script>
// 모바일 메뉴 토글만 처리. 언어 전환(#language-switcher)은 system_footer.php 가 처리.
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('mobile-menu-btn')?.addEventListener('click', function() {
        document.getElementById('mobile-menu')?.classList.toggle('hidden');
    });
});
</script>
