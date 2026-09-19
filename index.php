<?php
// 세션을 읽어 super_admin 여부를 확인합니다 (관리자 카드 노출용)
require_once __DIR__ . '/lib/session_helper.php';
require_once __DIR__ . '/lib/permission_helper.php';

// 로그인하지 않은 사용자는 허브 메뉴를 볼 수 없습니다.
// 세션이 없으면 remember_me 쿠키로 자동 로그인을 시도하고, 그래도 안 되면 로그인 페이지로 보냅니다.
if (!is_logged_in()) {
    if (isset($_COOKIE['remember_me'])) {
        try_login_from_cookie();
    }
    if (!is_logged_in()) {
        header('Location: admin/login.php');
        exit();
    }
}

$is_super_admin = (($_SESSION['role'] ?? '') === 'super_admin');
$is_main_office_admin = is_main_office_admin();
$can_access_foodpang_admin = has_permission('foodpang_management');
$current_user_info = get_user_info();
$current_role_label = !empty($_SESSION['role']) ? get_role_label($_SESSION['role']) : '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOME K MART ANGELES</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
          crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body {
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 50%, #0a0a0a 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .main-container {
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .logo-section {
            text-align: center;
            margin-bottom: 3rem;
        }
        .logo-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: 0.05em;
            text-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .logo-subtitle {
            font-size: 1rem;
            color: rgba(255,255,255,0.7);
            margin-top: 0.5rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 1.5rem;
            max-width: 1200px;
            width: 100%;
        }
        .menu-section {
            width: 100%;
            max-width: 1200px;
            margin-bottom: 2.5rem;
        }
        .menu-section-title {
            margin: 0 0 1rem;
            color: rgba(255,255,255,0.9);
            font-size: 1.2rem;
            font-weight: 700;
            text-align: left;
        }
        .menu-card {
            width: auto;
        }
        .menu-card {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 1.25rem;
            padding: 2rem 1.5rem;
            text-align: center;
            text-decoration: none;
            color: #ffffff;
            transition: all 0.3s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }
        .menu-card:hover {
            background: rgba(255, 255, 255, 0.22);
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
            color: #ffffff;
            text-decoration: none;
        }
        .menu-card:active {
            transform: translateY(-2px);
        }
        .card-icon {
            width: 64px;
            height: 64px;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }
        .card-icon.admin    { background: linear-gradient(135deg, #16a34a, #22c55e); }
        .card-icon.office   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .card-icon.pricing  { background: linear-gradient(135deg, #dc2626, #ef4444); }
        .card-icon.store    { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .card-icon.logistics { background: linear-gradient(135deg, #7c3aed, #8b5cf6); }
        .card-icon.kimsmall  { background: linear-gradient(135deg, #be185d, #ec4899); }
        .card-icon.order     { background: linear-gradient(135deg, #0891b2, #06b6d4); }
        .card-icon.lookup    { background: linear-gradient(135deg, #0ea5e9, #38bdf8); }
        .card-icon.mainoffice { background: linear-gradient(135deg, #4f46e5, #6366f1); }
        .card-icon.malladmin { background: linear-gradient(135deg, #db2777, #f472b6); }
        .card-icon.mallapp   { background: linear-gradient(135deg, #16a34a, #4ade80); }
        .card-icon.foodpang  { background: linear-gradient(135deg, #e11d48, #fb7185); }
        .card-icon.system    { background: linear-gradient(135deg, #475569, #64748b); }
        .card-icon.user      { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .card-icon.role      { background: linear-gradient(135deg, #7c3aed, #8b5cf6); }
        .card-icon.price     { background: linear-gradient(135deg, #0891b2, #06b6d4); }
        .card-label {
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .beta-badge {
            position: relative;
            display: inline-block;
            margin-left: 0.35rem;
            padding: 0.15rem 0.45rem;
            border-radius: 999px;
            background: #fbbf24;
            color: #78350f;
            font-size: 0.65em;
            font-weight: 800;
            line-height: 1.25;
            vertical-align: middle;
            letter-spacing: 0;
        }
        .beta-badge::after {
            content: '';
            position: absolute;
            left: 0.35rem;
            bottom: -0.18rem;
            width: 0;
            height: 0;
            border-top: 0.25rem solid #fbbf24;
            border-right: 0.25rem solid transparent;
        }
        .card-desc {
            font-size: 0.8rem;
            color: rgba(255,255,255,0.65);
            line-height: 1.4;
        }
        .footer-text {
            margin-top: 3rem;
            color: rgba(255,255,255,0.4);
            font-size: 0.8rem;
            text-align: center;
        }
        .user-bar {
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 999px;
            padding: 0.45rem 0.6rem 0.45rem 1rem;
            color: #ffffff;
        }
        .user-bar .user-name {
            font-size: 0.85rem;
            font-weight: 600;
        }
        .user-bar .user-meta {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.6);
        }
        .user-bar .role-badge {
            font-size: 0.7rem;
            font-weight: 600;
            color: #ccfbf1;
            background: rgba(13, 148, 136, 0.35);
            border-radius: 0.4rem;
            padding: 0.2rem 0.5rem;
            white-space: nowrap;
        }
        .user-bar .logout-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #fca5a5;
            background: rgba(220, 38, 38, 0.15);
            border-radius: 0.4rem;
            padding: 0.35rem 0.6rem;
            text-decoration: none;
            white-space: nowrap;
            transition: background 0.15s;
        }
        .user-bar .logout-btn:hover {
            background: rgba(220, 38, 38, 0.3);
            color: #fecaca;
        }
        @media (max-width: 600px) {
            .menu-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
            .menu-section { margin-bottom: 2rem; }
            .menu-section-title { font-size: 1.05rem; }
            .menu-card { padding: 1.5rem 1rem; }
            .card-icon { width: 52px; height: 52px; font-size: 1.4rem; }
            .card-label { font-size: 1rem; }
            .user-bar { position: static; margin-bottom: 1.5rem; align-self: center; }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="user-bar">
            <i class="fas fa-circle-user" style="font-size:1.4rem;color:rgba(255,255,255,0.85);"></i>
            <div>
                <div class="user-name"><?php echo htmlspecialchars($current_user_info['full_name'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?></div>
                <div class="user-meta"><?php echo htmlspecialchars($current_user_info['store_name'] ?? ''); ?></div>
            </div>
            <?php if ($current_role_label !== ''): ?>
            <span class="role-badge"><?php echo htmlspecialchars($current_role_label); ?></span>
            <?php endif; ?>
            <a href="admin/logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>

        <div class="logo-section">
            <img src="logo/homekmart_logo.png" alt="HOME K MART" style="max-width: 280px; width: 100%; filter: drop-shadow(0 4px 12px rgba(0,0,0,0.35));">
            <div class="logo-subtitle">Management System</div>
        </div>

        <section class="menu-section">
            <h2 class="menu-section-title">점포용</h2>
            <div class="menu-grid">
                <a href="admin/" class="menu-card">
                    <div class="card-icon admin"><i class="fas fa-cogs"></i></div>
                    <div>
                        <div class="card-label">Store Purchase</div>
                        <div class="card-desc">Purchase / Wholesale<br>Products / Barcode</div>
                    </div>
                </a>
                <a href="office/" class="menu-card">
                    <div class="card-icon office"><i class="fas fa-building"></i></div>
                    <div>
                        <div class="card-label">Office</div>
                        <div class="card-desc">Expenses / Equipment<br>Schedule Management</div>
                    </div>
                </a>
                <a href="pricing/" class="menu-card">
                    <div class="card-icon pricing"><i class="fas fa-tags"></i></div>
                    <div>
                        <div class="card-label">Pricing</div>
                        <div class="card-desc">Product Price Lookup<br>Price Label Management</div>
                    </div>
                </a>
                <a href="store/" class="menu-card">
                    <div class="card-icon store"><i class="fas fa-store"></i></div>
                    <div>
                        <div class="card-label">점포 물류센터 오더</div>
                        <div class="card-desc">Store Orders / Stock<br>Order Management</div>
                    </div>
                </a>
                <a href="order/" class="menu-card">
                    <div class="card-icon order"><i class="fas fa-clipboard-list"></i></div>
                    <div>
                        <div class="card-label">재고리스트 주문</div>
                        <div class="card-desc">Stock List / Orders<br>Inventory Management</div>
                    </div>
                </a>
                <a href="admin/price_lookup.php" class="menu-card">
                    <div class="card-icon lookup"><i class="fas fa-magnifying-glass-dollar"></i></div>
                    <div>
                        <div class="card-label">전점포 가격조회</div>
                        <div class="card-desc">All-Store Price Lookup<br>원가 / 판매가 조회</div>
                    </div>
                </a>
            </div>
        </section>

        <section class="menu-section">
            <h2 class="menu-section-title">창고용</h2>
            <div class="menu-grid">
                <a href="logistics/" class="menu-card">
                    <div class="card-icon logistics"><i class="fas fa-truck"></i></div>
                    <div>
                        <div class="card-label">M TOWN 물류센터</div>
                        <div class="card-desc">Inbound / Outbound<br>Warehouse Management</div>
                    </div>
                </a>
                <a href="kimsmall_wherehouse/" class="menu-card">
                    <div class="card-icon kimsmall"><i class="fas fa-warehouse"></i></div>
                    <div>
                        <div class="card-label">KIM'S MALL 창고</div>
                        <div class="card-desc">Inbound / Outbound<br>Warehouse Management</div>
                    </div>
                </a>
            </div>
        </section>

        <section class="menu-section">
            <h2 class="menu-section-title">쇼핑몰용</h2>
            <div class="menu-grid">
                <a href="mall/admin/dashboard.php" class="menu-card">
                    <div class="card-icon malladmin"><i class="fas fa-bag-shopping"></i></div>
                    <div>
                        <div class="card-label">쇼핑몰 관리자</div>
                        <div class="card-desc">Mall Orders / Products<br>Members Management</div>
                    </div>
                </a>
                <a href="mall/app_download.php?v=2" class="menu-card">
                    <div class="card-icon mallapp"><i class="fab fa-android"></i></div>
                    <div>
                        <div class="card-label">쇼핑몰 앱 <span class="beta-badge">베타</span></div>
                        <div class="card-desc">Android Test App<br>APK Download</div>
                    </div>
                </a>
                <?php if ($can_access_foodpang_admin): ?>
                <a href="foodpang/admin/dashboard.php" class="menu-card">
                    <div class="card-icon foodpang"><i class="fas fa-motorcycle"></i></div>
                    <div>
                        <div class="card-label">Foodpang</div>
                        <div class="card-desc">Foodpang Channel<br>Product Curation</div>
                    </div>
                </a>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($is_main_office_admin): ?>
        <section class="menu-section">
            <h2 class="menu-section-title">메인오피스용</h2>
            <div class="menu-grid">
                <a href="main_office/" class="menu-card">
                    <div class="card-icon mainoffice"><i class="fas fa-building-columns"></i></div>
                    <div>
                        <div class="card-label">메인 오피스</div>
                        <div class="card-desc">전 점포 입력 자료 열람<br>All-Store Data Lookup</div>
                    </div>
                </a>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($is_super_admin): ?>
        <section class="menu-section">
            <h2 class="menu-section-title">슈퍼관리자용</h2>
            <div class="menu-grid">
                <a href="admin/user_management.php" class="menu-card">
                    <div class="card-icon user"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="card-label">User Management</div>
                        <div class="card-desc">User Management</div>
                    </div>
                </a>
                <a href="admin/role_management.php" class="menu-card">
                    <div class="card-icon role"><i class="fas fa-user-shield"></i></div>
                    <div>
                        <div class="card-label">Role Management</div>
                        <div class="card-desc">Role &amp; Permission</div>
                    </div>
                </a>
                <a href="admin/store_management.php" class="menu-card">
                    <div class="card-icon store"><i class="fas fa-store"></i></div>
                    <div>
                        <div class="card-label">Store Management</div>
                        <div class="card-desc">Store Management</div>
                    </div>
                </a>
            </div>
        </section>
        <?php endif; ?>

        <div class="footer-text">
            &copy; <?php echo date('Y'); ?> HOME K MART &mdash; All rights reserved
        </div>
    </div>
</body>
</html>
