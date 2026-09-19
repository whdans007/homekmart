<?php
/**
 * 시스템 관리 (System Management)
 * super_admin 전용 진입 페이지.
 * 회원 관리 / 역할 관리 / 지점 관리 3개 메뉴로 연결합니다.
 */
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';

// 로그인 필수
ensure_logged_in();

// super_admin 전용
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('company.name'); ?> - 시스템 관리</title>
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
        .card-icon.user  { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .card-icon.role  { background: linear-gradient(135deg, #7c3aed, #8b5cf6); }
        .card-icon.store { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .card-icon.price { background: linear-gradient(135deg, #0891b2, #06b6d4); }
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
        .back-link {
            margin-top: 2.5rem;
            color: rgba(255,255,255,0.6);
            font-size: 0.85rem;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .back-link:hover { color: rgba(255,255,255,0.9); }
        @media (max-width: 600px) {
            .menu-grid { gap: 1rem; }
            .menu-card { width: calc(50% - 0.5rem); padding: 1.5rem 1rem; }
            .card-icon { width: 52px; height: 52px; font-size: 1.4rem; }
            .card-label { font-size: 1rem; }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="logo-section">
            <h1 style="font-size: 2rem; font-weight: 800; color: #fff; letter-spacing: 0.05em;">
                <i class="fas fa-cog mr-2"></i> 시스템 관리
            </h1>
            <div class="logo-subtitle">System Management</div>
        </div>

        <a href="../index.php" class="back-link">
            <i class="fas fa-arrow-left mr-1"></i> 홈으로 돌아가기
        </a>
    </div>
</body>
</html>
