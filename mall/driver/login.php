<?php
/**
 * 배송기사 로그인
 * Design Ref: mall-delivery-dispatch.design.md §5.4 — 기사 앱 로그인
 */
require_once __DIR__ . '/../lib/driver.php';

if (mall_driver_is_logged_in()) {
    header('Location: /mall/driver/index.php');
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($phone === '' || $password === '') {
        $error_message = '연락처와 비밀번호를 입력해주세요.';
    } else {
        $result = mall_driver_attempt_login($phone, $password);
        if ($result['success']) {
            header('Location: /mall/driver/index.php');
            exit;
        }
        $error_message = '연락처 또는 비밀번호가 올바르지 않습니다.';
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>배송기사 로그인 - HOME K MART</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/mall/css/wanted-tokens.css">
    <link rel="stylesheet" href="/mall/css/mall.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: var(--space-6) var(--space-5); }
        .auth-wrap { width: 100%; max-width: 360px; }
        .auth-logo { text-align: center; margin-bottom: var(--space-5); font: 700 1.1rem var(--font-sans); }
        .auth-card { background: var(--bg-normal); border: 1px solid var(--line-alternative); border-radius: var(--radius-xl); padding: var(--space-6) var(--space-5); box-shadow: var(--shadow-md); }
        .alert-error { display: flex; gap: 8px; border-radius: var(--radius-md); padding: var(--space-3) var(--space-4); margin-bottom: var(--space-4); font: var(--t-caption1) var(--font-sans); background: rgba(200,16,46,0.08); color: var(--brand-red); }
        .auth-field { margin-bottom: var(--space-4); }
        .auth-field label { display: block; font: var(--t-label2) var(--font-sans); color: var(--label-neutral); margin-bottom: 6px; }
        .auth-field input { width: 100%; border: 1px solid var(--line-normal); border-radius: var(--radius-md); padding: 12px 14px; font: var(--t-body2) var(--font-sans); }
    </style>
</head>
<body>
    <div class="auth-wrap">
        <div class="auth-logo"><i class="fas fa-motorcycle"></i> HOME K MART 배송기사</div>
        <div class="auth-card">
            <?php if ($error_message): ?>
                <div class="alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error_message); ?></span></div>
            <?php endif; ?>
            <form method="post">
                <div class="auth-field">
                    <label for="phone">연락처</label>
                    <input id="phone" name="phone" type="text" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                </div>
                <div class="auth-field">
                    <label for="password">비밀번호</label>
                    <input id="password" name="password" type="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">로그인</button>
            </form>
        </div>
    </div>
</body>
</html>
