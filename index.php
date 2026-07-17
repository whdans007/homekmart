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
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1.5rem;
            max-width: 700px;
            width: 100%;
        }
        .menu-card {
            width: calc(33.333% - 1rem);
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
        .card-icon.order     { background: linear-gradient(135deg, #0891b2, #06b6d4); }
        .card-icon.lookup    { background: linear-gradient(135deg, #0ea5e9, #38bdf8); }
        .card-icon.system    { background: linear-gradient(135deg, #475569, #64748b); }
        .card-label {
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.02em;
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
            .menu-grid { gap: 1rem; }
            .menu-card { width: calc(50% - 0.5rem); padding: 1.5rem 1rem; }
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

        <div class="menu-grid">
            <a href="admin/" class="menu-card">
                <div class="card-icon admin">
                    <i class="fas fa-cogs"></i>
                </div>
                <div>
                    <div class="card-label">Store Purchase</div>
                    <div class="card-desc">Purchase / Wholesale<br>Products / Barcode</div>
                </div>
            </a>

            <a href="office/" class="menu-card">
                <div class="card-icon office">
                    <i class="fas fa-building"></i>
                </div>
                <div>
                    <div class="card-label">Office</div>
                    <div class="card-desc">Expenses / Equipment<br>Schedule Management</div>
                </div>
            </a>

            <a href="pricing/" class="menu-card">
                <div class="card-icon pricing">
                    <i class="fas fa-tags"></i>
                </div>
                <div>
                    <div class="card-label">Pricing</div>
                    <div class="card-desc">Product Price Lookup<br>Price Label Management</div>
                </div>
            </a>

            <a href="store/" class="menu-card">
                <div class="card-icon store">
                    <i class="fas fa-store"></i>
                </div>
                <div>
                    <div class="card-label">점포 물류센터 오더</div>
                    <div class="card-desc">Store Orders / Stock<br>Order Management</div>
                </div>
            </a>

            <a href="logistics/" class="menu-card">
                <div class="card-icon logistics">
                    <i class="fas fa-truck"></i>
                </div>
                <div>
                    <div class="card-label">물류센터</div>
                    <div class="card-desc">Inbound / Outbound<br>Warehouse Management</div>
                </div>
            </a>

            <a href="order/" class="menu-card">
                <div class="card-icon order">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div>
                    <div class="card-label">재고리스트 주문</div>
                    <div class="card-desc">Stock List / Orders<br>Inventory Management</div>
                </div>
            </a>

            <a href="admin/price_lookup.php" class="menu-card">
                <div class="card-icon lookup">
                    <i class="fas fa-magnifying-glass-dollar"></i>
                </div>
                <div>
                    <div class="card-label">전점포 가격조회</div>
                    <div class="card-desc">All-Store Price Lookup<br>원가 / 판매가 조회</div>
                </div>
            </a>

            <?php if ($is_super_admin): ?>
            <a href="admin/system_management.php" class="menu-card">
                <div class="card-icon system">
                    <i class="fas fa-cog"></i>
                </div>
                <div>
                    <div class="card-label">관리자</div>
                    <div class="card-desc">User / Role<br>Store Management</div>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <div class="footer-text">
            &copy; <?php echo date('Y'); ?> HOME K MART &mdash; All rights reserved
        </div>
    </div>
</body>
</html>
