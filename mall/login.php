<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cart.php';

if (mall_is_logged_in()) {
    header('Location: ' . mall_get_login_redirect_target());
    exit;
}

$error_message = '';
$info_message = isset($_GET['registered']) ? '가입이 완료되었습니다. 로그인해주세요.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error_message = '이메일과 비밀번호를 입력해주세요.';
    } else {
        // mall_attempt_login()이 성공 시 session_regenerate_id()를 호출해 게스트 토큰이 바뀌므로 미리 캡처해둔다.
        $guest_token_before_login = mall_guest_token();
        $result = mall_attempt_login($email, $password);
        if ($result['success']) {
            mall_cart_merge_guest_into_member($result['member']['id'], $guest_token_before_login);
            header('Location: ' . mall_get_login_redirect_target());
            exit;
        }
        $error_message = '이메일 또는 비밀번호가 올바르지 않습니다.';
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>HOME K MART - 로그인</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/mall/css/wanted-tokens.css">
    <link rel="stylesheet" href="/mall/css/mall.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: var(--space-6) var(--space-5); }
        .auth-wrap { width: 100%; max-width: 380px; }
        .auth-logo { text-align: center; margin-bottom: var(--space-6); }
        .auth-logo img { max-width: 220px; width: 70%; }
        .auth-card { background: var(--bg-normal); border: 1px solid var(--line-alternative); border-radius: var(--radius-xl); padding: var(--space-6) var(--space-5); box-shadow: var(--shadow-md); }
        .alert-error, .alert-info {
            display: flex; align-items: flex-start; gap: 8px; border-radius: var(--radius-md);
            padding: var(--space-3) var(--space-4); margin-bottom: var(--space-4); font: var(--t-caption1) var(--font-sans);
        }
        .alert-error { background: rgba(200,16,46,0.08); color: var(--brand-red); }
        .alert-info { background: var(--primary-bg); color: var(--primary-strong); }
        .auth-field { margin-bottom: var(--space-4); }
        .auth-field label { display: block; font: var(--t-label2) var(--font-sans); color: var(--label-neutral); margin-bottom: 6px; }
        .auth-field input {
            width: 100%; border: 1px solid var(--line-normal); border-radius: var(--radius-md);
            padding: 12px 14px; font: var(--t-body2) var(--font-sans); color: var(--label-normal); background: var(--bg-normal);
        }
        .auth-field input:focus { outline: none; border-color: var(--primary-normal); }
        .auth-links { margin-top: var(--space-4); display: flex; justify-content: space-between; font: var(--t-caption1) var(--font-sans); color: var(--label-alternative); }
        .auth-links a { color: var(--primary-normal); font-weight: 700; }
        .auth-divider { display: flex; align-items: center; gap: 10px; margin: var(--space-5) 0; color: var(--label-assistive); font: var(--t-caption1) var(--font-sans); }
        .auth-divider::before, .auth-divider::after { content: ''; flex: 1; height: 1px; background: var(--line-alternative); }
        .google-btn-wrap { display: flex; justify-content: center; }
    </style>
</head>
<body>
    <div class="auth-wrap">
        <div class="auth-logo"><img src="/logo/homekmart_logo.png" alt="HOME K MART"></div>
        <div class="auth-card">
            <?php if ($error_message): ?>
                <div class="alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error_message); ?></span></div>
            <?php endif; ?>
            <?php if ($info_message): ?>
                <div class="alert-info"><i class="fas fa-info-circle"></i><span><?php echo htmlspecialchars($info_message); ?></span></div>
            <?php endif; ?>

            <form action="login.php" method="post">
                <div class="auth-field">
                    <label for="email">이메일</label>
                    <input id="email" name="email" type="email" required>
                </div>
                <div class="auth-field">
                    <label for="password">비밀번호</label>
                    <input id="password" name="password" type="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-sign-in-alt"></i> 로그인</button>
            </form>

            <div class="auth-divider">또는</div>
            <div class="google-btn-wrap">
                <div id="g_id_onload"
                     data-client_id="<?php echo htmlspecialchars(MALL_GOOGLE_CLIENT_ID); ?>"
                     data-callback="mallHandleGoogleCredential">
                </div>
                <div class="g_id_signin" data-type="standard" data-shape="pill" data-width="320"></div>
            </div>

            <div class="auth-links">
                <a href="signup.php">회원가입</a>
                <a href="forgot_password.php">비밀번호 찾기</a>
            </div>
        </div>
    </div>

    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        function mallHandleGoogleCredential(response) {
            const params = new URLSearchParams();
            params.set('credential', response.credential);
            fetch('/mall/ajax/google_auth.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = data.data.redirect;
                    } else if (data.error?.code === 'NEEDS_SIGNUP') {
                        window.location.href = '/mall/signup.php?google=1';
                    } else {
                        alert(data.error?.message || '구글 로그인에 실패했습니다');
                    }
                })
                .catch(() => alert('구글 로그인 중 오류가 발생했습니다'));
        }
    </script>
</body>
</html>
