<?php
require_once __DIR__ . '/lib/auth.php';

$error_message = '';
$reset_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = '올바른 이메일을 입력해주세요.';
    } else {
        try {
            $conn = get_db_connection();
            $stmt = $conn->prepare('SELECT id FROM mall_members WHERE email = ? AND is_active = 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $member = $result->fetch_assoc();
            $stmt->close();

            // 이메일 존재 여부와 무관하게 동일한 안내 문구를 보여줘 계정 존재 여부 노출(계정 스캐닝)을 방지한다.
            if ($member) {
                $token = bin2hex(random_bytes(32));
                $token_hash = hash('sha256', $token);
                $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1시간 유효

                $insert = $conn->prepare(
                    'INSERT INTO mall_password_resets (member_id, token_hash, expires_at) VALUES (?, ?, ?)'
                );
                $insert->bind_param('iss', $member['id'], $token_hash, $expires_at);
                $insert->execute();
                $insert->close();

                // TODO: 이메일 발송 인프라 미구축 — 발송 대신 링크를 화면에 직접 표시(운영 전 반드시 이메일 발송으로 교체할 것)
                $reset_link = '/mall/reset_password.php?token=' . urlencode($token);
            }
            $conn->close();
        } catch (Exception $e) {
            error_log('mall/forgot_password.php error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOME K MART - 비밀번호 찾기</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 50%, #0a0a0a 100%);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #e5e7eb; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 2rem 1rem;
        }
        .wrap { width: 100%; max-width: 420px; }
        .card {
            background: rgba(255,255,255,0.05); backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.12); border-radius: 1.25rem;
            padding: 2rem 1.75rem; box-shadow: 0 20px 50px rgba(0,0,0,0.45);
        }
        h1 { font-size: 1.15rem; margin: 0 0 1.25rem; }
        .alert-error, .alert-info {
            display: flex; align-items: flex-start; gap: 0.6rem;
            border-radius: 0.6rem; padding: 0.85rem 1rem; margin-bottom: 1.5rem; font-size: 0.85rem;
        }
        .alert-error { background: rgba(220,38,38,0.12); border: 1px solid rgba(248,113,113,0.35); color: #fca5a5; }
        .alert-info { background: rgba(37,99,235,0.12); border: 1px solid rgba(96,165,250,0.35); color: #93c5fd; word-break: break-all; }
        .field { margin-bottom: 1.25rem; }
        .field label { display: block; font-size: 0.82rem; font-weight: 600; color: #cbd5e1; margin-bottom: 0.4rem; }
        .field input {
            width: 100%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);
            border-radius: 0.6rem; padding: 0.7rem 0.9rem; color: #f8fafc; font-size: 0.95rem;
        }
        .btn-submit {
            width: 100%; border: none; border-radius: 0.6rem; padding: 0.8rem 1rem; font-size: 0.95rem;
            font-weight: 700; color: #fff; background: linear-gradient(135deg, #2563eb, #3b82f6); cursor: pointer;
        }
        .login-row { margin-top: 1.25rem; text-align: center; font-size: 0.85rem; }
        .login-row a { color: #60a5fa; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>비밀번호 찾기</h1>
            <?php if ($error_message): ?>
                <div class="alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error_message); ?></span></div>
            <?php endif; ?>
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error_message): ?>
                <div class="alert-info">
                    가입된 이메일이면 재설정 링크가 발송됩니다.
                    <?php if ($reset_link): ?>
                        <br><br>(이메일 발송 미구축 — 임시로 링크를 직접 표시합니다)<br>
                        <a href="<?php echo htmlspecialchars($reset_link); ?>" style="color:#93c5fd;"><?php echo htmlspecialchars($reset_link); ?></a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form action="forgot_password.php" method="post">
                    <div class="field">
                        <label for="email">이메일</label>
                        <input id="email" name="email" type="email" required>
                    </div>
                    <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> 재설정 링크 받기</button>
                </form>
            <?php endif; ?>
            <div class="login-row"><a href="login.php">로그인으로 돌아가기</a></div>
        </div>
    </div>
</body>
</html>
