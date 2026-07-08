<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';

// 이미 로그인했다면, 기억된 접근 URL(없으면 메인 허브)로 보냅니다.
if (isset($_SESSION['user_id'])) {
    header('Location: ' . get_login_redirect_target('../index.php'));
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message = t('forms.required_field');
    } else {
        try {
            $conn = get_db_connection();

            $stmt = $conn->prepare("SELECT id, username, full_name, password, role, store_id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->bind_result($uid, $uname, $ufull_name, $upassword, $urole, $ustore_id);
            $stmt->fetch();
            $stmt->close();

            $user = $uid ? [
                'id'        => $uid,
                'username'  => $uname,
                'full_name' => $ufull_name,
                'password'  => $upassword,
                'role'      => $urole,
                'store_id'  => $ustore_id,
            ] : null;

            // 사용자가 존재하고 비밀번호가 일치하는지 확인합니다.
            if ($user && password_verify($password, $user['password'])) {
                // 로그인 성공: 세션에 사용자 정보 저장
                session_regenerate_id(true); // 보안을 위해 세션 ID 갱신
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['store_id'] = $user['store_id'];

                // "로그인 상태 유지" 처리
                if (!empty($_POST['remember_me'])) {
                    $token = bin2hex(random_bytes(32));
                    $expiry = date('Y-m-d H:i:s', time() + (86400 * 30)); // 30일 후 만료

                    $update_stmt = $conn->prepare("UPDATE users SET remember_token = ?, remember_token_expiry = ? WHERE id = ?");
                    $hashed_token = password_hash($token, PASSWORD_DEFAULT);
                    $update_stmt->bind_param("ssi", $hashed_token, $expiry, $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();

                    $cookie_value = base64_encode($user['id'] . ':' . $token);
                    setcookie('remember_me', $cookie_value, time() + (86400 * 30), '/', '', isset($_SERVER['HTTPS']), true); // HttpOnly
                }

                $conn->close();
                // 원래 접근하려던 URL이 있으면 그곳으로, 없으면 메인 허브로 이동합니다.
                header('Location: ' . get_login_redirect_target('../index.php'));
                exit();
            } else {
                $error_message = t('auth.invalid_credentials');
            }
            $conn->close();
        } catch (Exception $e) {
            error_log("login.php DB error: " . $e->getMessage());
            // 개발 환경에서는 상세 오류 표시, 운영에서는 일반 메시지
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $error_message = '데이터베이스 오류: ' . htmlspecialchars($e->getMessage());
            } else {
                $error_message = t('messages.database_error');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('company.name'); ?> - <?php echo t('auth.login'); ?></title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 50%, #0a0a0a 100%);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #e5e7eb;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .login-wrap { width: 100%; max-width: 420px; }

        .lang-bar { display: flex; justify-content: flex-end; margin-bottom: 1rem; }
        .lang-select {
            background: rgba(255,255,255,0.06);
            color: #e5e7eb;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 0.5rem;
            padding: 0.35rem 0.6rem;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .lang-select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.4); }
        .lang-select option { background: #1a1a1a; color: #e5e7eb; }

        .logo-box { text-align: center; margin-bottom: 1.75rem; }
        .logo-box img {
            max-width: 260px; width: 80%;
            filter: drop-shadow(0 4px 14px rgba(0,0,0,0.5));
        }
        .logo-sub {
            margin-top: 0.75rem;
            font-size: 0.8rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.45);
        }

        .card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 1.25rem;
            padding: 2rem 1.75rem;
            box-shadow: 0 20px 50px rgba(0,0,0,0.45);
        }

        .alert-error {
            display: flex; align-items: flex-start; gap: 0.6rem;
            background: rgba(220,38,38,0.12);
            border: 1px solid rgba(248,113,113,0.35);
            border-radius: 0.6rem;
            padding: 0.85rem 1rem;
            margin-bottom: 1.5rem;
            color: #fca5a5;
            font-size: 0.85rem;
        }
        .alert-error i { margin-top: 2px; }

        .field { margin-bottom: 1.25rem; }
        .field label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 0.4rem;
        }
        .field input[type="text"],
        .field input[type="password"] {
            width: 100%;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 0.6rem;
            padding: 0.7rem 0.9rem;
            color: #f8fafc;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .field input::placeholder { color: rgba(255,255,255,0.35); }
        .field input:focus {
            outline: none;
            border-color: #3b82f6;
            background: rgba(255,255,255,0.09);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.3);
        }

        .remember { display: flex; align-items: center; margin-bottom: 1.5rem; }
        .remember input { width: 16px; height: 16px; margin-right: 0.5rem; accent-color: #3b82f6; cursor: pointer; }
        .remember label { font-size: 0.85rem; color: #cbd5e1; cursor: pointer; }

        .btn-login {
            width: 100%;
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            border: none;
            border-radius: 0.6rem;
            padding: 0.8rem 1rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: #ffffff;
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.2s, filter 0.2s;
        }
        .btn-login:hover { filter: brightness(1.08); box-shadow: 0 8px 22px rgba(37,99,235,0.45); }
        .btn-login:active { transform: translateY(1px); }

        .register-row { margin-top: 1.5rem; text-align: center; font-size: 0.85rem; color: rgba(255,255,255,0.6); }
        .register-row a { color: #60a5fa; font-weight: 600; text-decoration: none; }
        .register-row a:hover { color: #93c5fd; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-wrap">
        <!-- Language Switcher -->
        <div class="lang-bar">
            <select id="language-switcher" class="lang-select">
                <option value="ko" <?php echo get_language() === 'ko' ? 'selected' : ''; ?>>한국어</option>
                <option value="en" <?php echo get_language() === 'en' ? 'selected' : ''; ?>>English</option>
            </select>
        </div>

        <div class="logo-box">
            <img src="../logo/homekmart_logo.png" alt="HOME K MART">
            <div class="logo-sub">Management System</div>
        </div>

        <div class="card">
            <?php if (!empty($error_message)): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="post">
                <div class="field">
                    <label for="username"><?php echo t('auth.username'); ?></label>
                    <input id="username" name="username" type="text" required
                           placeholder="<?php echo t('auth.username'); ?>">
                </div>

                <div class="field">
                    <label for="password"><?php echo t('auth.password'); ?></label>
                    <input id="password" name="password" type="password" required
                           placeholder="<?php echo t('auth.password'); ?>">
                </div>

                <div class="remember">
                    <input id="remember_me" name="remember_me" type="checkbox">
                    <label for="remember_me"><?php echo t('auth.remember_me'); ?></label>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    <?php echo t('auth.login'); ?>
                </button>
            </form>

            <div class="register-row">
                Don't have an account?
                <a href="register.php"><?php echo t('auth.register'); ?></a>
            </div>
        </div>
    </div>

    <script>
    // Language Switcher for Login Page
    document.addEventListener('DOMContentLoaded', function() {
        const languageSwitcher = document.getElementById('language-switcher');
        if (languageSwitcher) {
            languageSwitcher.addEventListener('change', function() {
                const selectedLang = this.value;
                
                // AJAX로 언어 변경
                fetch('ajax_set_language.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'language=' + encodeURIComponent(selectedLang)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 페이지 새로고침으로 변경된 언어 적용
                        window.location.reload();
                    } else {
                        alert('Language change failed: ' + data.message);
                        // 실패시 이전 선택으로 되돌리기
                        this.value = '<?php echo get_language(); ?>';
                    }
                })
                .catch(error => {
                    console.error('Language change error:', error);
                    alert('An error occurred while changing language.');
                    this.value = '<?php echo get_language(); ?>';
                });
            });
        }
    });
    </script>
</body>
</html>