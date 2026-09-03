<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cart.php';

if (mall_is_logged_in()) {
    header('Location: ' . mall_get_login_redirect_target());
    exit;
}

mall_session_start();
// 로그인/회원가입 화면의 구글 버튼(ajax/google_auth.php)이 기존 계정을 못 찾으면 구글 프로필을
// 여기 세션에 잠깐 담아두고 이 화면으로 보낸다 — 이메일/비밀번호 없이 회원유형만 골라 가입을 끝낸다.
$google_pending = ($_GET['google'] ?? '') === '1' ? ($_SESSION['mall_pending_google'] ?? null) : null;

$error_message = '';
$success_message = '';
$member_type = $_POST['member_type'] ?? 'retail';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['google_pending'] ?? '') === '1') {
    $member_type = in_array($_POST['member_type'] ?? '', ['retail', 'wholesale'], true) ? $_POST['member_type'] : 'retail';
    $business_name = trim($_POST['business_name'] ?? '');
    $business_reg_no = trim($_POST['business_reg_no'] ?? '');
    $english_name = trim($_POST['english_name'] ?? '');
    $google = $_SESSION['mall_pending_google'] ?? null;

    if (!$google) {
        $error_message = '구글 인증 정보가 만료되었습니다. 다시 시도해주세요.';
    } elseif ($english_name === '') {
        $error_message = '영문 이름을 입력해주세요.';
        $google_pending = $google;
    } elseif ($member_type === 'wholesale' && $business_name === '') {
        $error_message = '사업자 회원은 상호명을 입력해야 합니다.';
        $google_pending = $google;
    } else {
        $guest_token_before_login = mall_guest_token();
        $result = mall_google_signup($google, $member_type, $business_name, $business_reg_no, $english_name);
        if ($result['success']) {
            unset($_SESSION['mall_pending_google']);
            mall_cart_merge_guest_into_member($result['member']['id'], $guest_token_before_login);
            header('Location: ' . mall_get_login_redirect_target());
            exit;
        }
        $error_message = $result['error'] === 'ALREADY_REGISTERED'
            ? '이미 가입된 계정입니다. 로그인해주세요.'
            : '가입 처리 중 오류가 발생했습니다.';
        $google_pending = $google;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_type = in_array($_POST['member_type'] ?? '', ['retail', 'wholesale'], true) ? $_POST['member_type'] : 'retail';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $english_name = trim($_POST['english_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $business_name = trim($_POST['business_name'] ?? '');
    $business_reg_no = trim($_POST['business_reg_no'] ?? '');

    if ($email === '' || $password === '' || $name === '') {
        $error_message = '이메일, 비밀번호, 이름은 필수 입력 항목입니다.';
    } elseif ($english_name === '') {
        $error_message = '영문 이름을 입력해주세요.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = '이메일 형식이 올바르지 않습니다.';
    } elseif (strlen($password) < 8) {
        $error_message = '비밀번호는 8자 이상이어야 합니다.';
    } elseif ($password !== $password_confirm) {
        $error_message = '비밀번호가 일치하지 않습니다.';
    } elseif ($member_type === 'wholesale' && $business_name === '') {
        $error_message = '사업자 회원은 상호명을 입력해야 합니다.';
    } else {
        try {
            $conn = get_db_connection();

            $check = $conn->prepare('SELECT id FROM mall_members WHERE email = ?');
            $check->bind_param('s', $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error_message = '이미 가입된 이메일입니다.';
                $check->close();
            } else {
                $check->close();

                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $wholesale_status = ($member_type === 'wholesale') ? 'pending' : null;
                $business_name_val = ($member_type === 'wholesale') ? $business_name : null;
                $business_reg_no_val = ($member_type === 'wholesale') ? $business_reg_no : null;

                $insert = $conn->prepare(
                    'INSERT INTO mall_members
                        (member_type, email, password_hash, name, english_name, phone, business_name, business_reg_no, store_id, retail_tier, wholesale_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "general", ?)'
                );
                $store_id = MALL_STORE_ID;
                $insert->bind_param(
                    'ssssssssis',
                    $member_type, $email, $password_hash, $name, $english_name, $phone,
                    $business_name_val, $business_reg_no_val, $store_id, $wholesale_status
                );

                if ($insert->execute()) {
                    $success_message = ($member_type === 'wholesale')
                        ? '가입이 완료되었습니다. 도매가는 관리자 승인 후 노출됩니다. 로그인해주세요.'
                        : '가입이 완료되었습니다. 로그인해주세요.';
                } else {
                    $error_message = '가입 처리 중 오류가 발생했습니다.';
                }
                $insert->close();
            }
            $conn->close();
        } catch (Exception $e) {
            error_log('mall/signup.php error: ' . $e->getMessage());
            $error_message = '데이터베이스 오류가 발생했습니다.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>HOME K MART - 회원가입</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/mall/css/wanted-tokens.css">
    <link rel="stylesheet" href="/mall/css/mall.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: var(--space-6) var(--space-5); }
        .auth-wrap { width: 100%; max-width: 420px; }
        .auth-logo { text-align: center; margin-bottom: var(--space-6); }
        .auth-logo img { max-width: 220px; width: 70%; }
        .auth-card { background: var(--bg-normal); border: 1px solid var(--line-alternative); border-radius: var(--radius-xl); padding: var(--space-6) var(--space-5); box-shadow: var(--shadow-md); }
        .alert-error, .alert-success {
            display: flex; align-items: flex-start; gap: 8px; border-radius: var(--radius-md);
            padding: var(--space-3) var(--space-4); margin-bottom: var(--space-4); font: var(--t-caption1) var(--font-sans);
        }
        .alert-error { background: rgba(200,16,46,0.08); color: var(--brand-red); }
        .alert-success { background: rgba(0,117,46,0.08); color: var(--brand-green); }
        .type-toggle { display: flex; gap: 8px; margin-bottom: var(--space-5); }
        .type-toggle label {
            flex: 1; text-align: center; padding: 10px; border-radius: var(--radius-md); cursor: pointer;
            border: 1px solid var(--line-normal); font: var(--t-label2) var(--font-sans); color: var(--label-neutral);
        }
        .type-toggle input { display: none; }
        .type-toggle label:has(input:checked) { background: var(--primary-bg); border-color: var(--primary-normal); color: var(--primary-strong); font-weight: 700; }
        .auth-field { margin-bottom: var(--space-4); }
        .auth-field label { display: block; font: var(--t-label2) var(--font-sans); color: var(--label-neutral); margin-bottom: 6px; }
        .auth-field input {
            width: 100%; border: 1px solid var(--line-normal); border-radius: var(--radius-md);
            padding: 12px 14px; font: var(--t-body2) var(--font-sans); color: var(--label-normal); background: var(--bg-normal);
        }
        .auth-field input:focus { outline: none; border-color: var(--primary-normal); }
        .wholesale-fields { display: none; }
        .wholesale-fields.active { display: block; }
        .login-row { margin-top: var(--space-5); text-align: center; font: var(--t-caption1) var(--font-sans); color: var(--label-alternative); }
        .login-row a { color: var(--primary-normal); font-weight: 700; }
        .auth-divider { display: flex; align-items: center; gap: 10px; margin: var(--space-5) 0; color: var(--label-assistive); font: var(--t-caption1) var(--font-sans); }
        .auth-divider::before, .auth-divider::after { content: ''; flex: 1; height: 1px; background: var(--line-alternative); }
        .google-btn-wrap { display: flex; justify-content: center; }
        .google-profile-box { display: flex; align-items: center; gap: 10px; background: var(--bg-alternative); border-radius: var(--radius-md); padding: var(--space-3) var(--space-4); margin-bottom: var(--space-5); font: var(--t-label2) var(--font-sans); }
    </style>
</head>
<body>
    <div class="auth-wrap">
        <div class="auth-logo"><img src="/logo/homekmart_logo.png" alt="HOME K MART"></div>
        <div class="auth-card">
            <?php if ($error_message): ?>
                <div class="alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error_message); ?></span></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success_message); ?></span></div>
            <?php endif; ?>

            <?php if (!$success_message && $google_pending): ?>
            <div class="google-profile-box"><i class="fab fa-google"></i> <span><?php echo htmlspecialchars($google_pending['name']); ?> (<?php echo htmlspecialchars($google_pending['email']); ?>)</span></div>
            <form action="signup.php?google=1" method="post" id="signup-form">
                <input type="hidden" name="google_pending" value="1">
                <div class="auth-field">
                    <label for="english_name_google">영문 이름 (배송/실무 확인용)</label>
                    <input id="english_name_google" name="english_name" type="text" required placeholder="예: Hong Gildong" value="<?php echo htmlspecialchars($_POST['english_name'] ?? ''); ?>">
                </div>
                <div class="type-toggle">
                    <label><input type="radio" name="member_type" value="retail" <?php echo $member_type === 'retail' ? 'checked' : ''; ?>><span>소매 회원</span></label>
                    <label><input type="radio" name="member_type" value="wholesale" <?php echo $member_type === 'wholesale' ? 'checked' : ''; ?>><span>사업자(도매) 회원</span></label>
                </div>

                <div class="wholesale-fields <?php echo $member_type === 'wholesale' ? 'active' : ''; ?>" id="wholesale-fields">
                    <div class="auth-field">
                        <label for="business_name">사업자 상호</label>
                        <input id="business_name" name="business_name" type="text" value="<?php echo htmlspecialchars($_POST['business_name'] ?? ''); ?>">
                    </div>
                    <div class="auth-field">
                        <label for="business_reg_no">사업자등록번호</label>
                        <input id="business_reg_no" name="business_reg_no" type="text" value="<?php echo htmlspecialchars($_POST['business_reg_no'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-user-plus"></i> 가입 완료</button>
            </form>
            <?php elseif (!$success_message): ?>
            <form action="signup.php" method="post" id="signup-form">
                <div class="type-toggle">
                    <label><input type="radio" name="member_type" value="retail" <?php echo $member_type === 'retail' ? 'checked' : ''; ?>><span>소매 회원</span></label>
                    <label><input type="radio" name="member_type" value="wholesale" <?php echo $member_type === 'wholesale' ? 'checked' : ''; ?>><span>사업자(도매) 회원</span></label>
                </div>

                <div class="auth-field">
                    <label for="email">이메일</label>
                    <input id="email" name="email" type="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <div class="auth-field">
                    <label for="password">비밀번호 (8자 이상)</label>
                    <input id="password" name="password" type="password" required minlength="8">
                </div>
                <div class="auth-field">
                    <label for="password_confirm">비밀번호 확인</label>
                    <input id="password_confirm" name="password_confirm" type="password" required minlength="8">
                </div>
                <div class="auth-field">
                    <label for="name">이름</label>
                    <input id="name" name="name" type="text" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                </div>
                <div class="auth-field">
                    <label for="english_name">영문 이름 (배송/실무 확인용)</label>
                    <input id="english_name" name="english_name" type="text" required placeholder="예: Hong Gildong" value="<?php echo htmlspecialchars($_POST['english_name'] ?? ''); ?>">
                </div>
                <div class="auth-field">
                    <label for="phone">연락처</label>
                    <input id="phone" name="phone" type="text" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                </div>

                <div class="wholesale-fields <?php echo $member_type === 'wholesale' ? 'active' : ''; ?>" id="wholesale-fields">
                    <div class="auth-field">
                        <label for="business_name">사업자 상호</label>
                        <input id="business_name" name="business_name" type="text" value="<?php echo htmlspecialchars($_POST['business_name'] ?? ''); ?>">
                    </div>
                    <div class="auth-field">
                        <label for="business_reg_no">사업자등록번호</label>
                        <input id="business_reg_no" name="business_reg_no" type="text" value="<?php echo htmlspecialchars($_POST['business_reg_no'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-user-plus"></i> 가입하기</button>
            </form>

            <div class="auth-divider">또는</div>
            <div class="google-btn-wrap">
                <div id="g_id_onload"
                     data-client_id="<?php echo htmlspecialchars(MALL_GOOGLE_CLIENT_ID); ?>"
                     data-callback="mallHandleGoogleCredential">
                </div>
                <div class="g_id_signin" data-type="standard" data-shape="pill" data-width="320" data-text="signup_with"></div>
            </div>
            <?php endif; ?>

            <div class="login-row">이미 계정이 있으신가요? <a href="login.php">로그인</a></div>
        </div>
    </div>

    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        document.querySelectorAll('input[name="member_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                document.getElementById('wholesale-fields').classList.toggle('active', this.value === 'wholesale');
            });
        });

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
