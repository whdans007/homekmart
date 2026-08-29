<?php
require_once __DIR__ . '/lib/auth.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error_message = '';
$success_message = '';
$token_valid = false;

if ($token === '') {
    $error_message = '유효하지 않은 링크입니다.';
} else {
    try {
        $conn = get_db_connection();
        $token_hash = hash('sha256', $token);

        $stmt = $conn->prepare(
            'SELECT id, member_id FROM mall_password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->bind_param('s', $token_hash);
        $stmt->execute();
        $result = $stmt->get_result();
        $reset_row = $result->fetch_assoc();
        $stmt->close();

        if (!$reset_row) {
            $error_message = '만료되었거나 이미 사용된 링크입니다. 비밀번호 찾기를 다시 시도해주세요.';
        } else {
            $token_valid = true;
        }

        if ($token_valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';

            if (strlen($password) < 8) {
                $error_message = '비밀번호는 8자 이상이어야 합니다.';
            } elseif ($password !== $password_confirm) {
                $error_message = '비밀번호가 일치하지 않습니다.';
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $conn->begin_transaction();
                $update_pw = $conn->prepare('UPDATE mall_members SET password_hash = ? WHERE id = ?');
                $update_pw->bind_param('si', $password_hash, $reset_row['member_id']);
                $update_pw->execute();
                $update_pw->close();

                $mark_used = $conn->prepare('UPDATE mall_password_resets SET used_at = NOW() WHERE id = ?');
                $mark_used->bind_param('i', $reset_row['id']);
                $mark_used->execute();
                $mark_used->close();
                $conn->commit();

                $success_message = '비밀번호가 재설정되었습니다. 로그인해주세요.';
                $token_valid = false; // 재사용 방지: 폼 다시 노출하지 않음
            }
        }

        $conn->close();
    } catch (Exception $e) {
        error_log('mall/reset_password.php error: ' . $e->getMessage());
        $error_message = '처리 중 오류가 발생했습니다.';
    }
}
?>
<!DOCTYPE html>
<html lang="ko" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOME K MART - 비밀번호 재설정</title>
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
        .alert-error, .alert-success {
            display: flex; align-items: flex-start; gap: 0.6rem;
            border-radius: 0.6rem; padding: 0.85rem 1rem; margin-bottom: 1.5rem; font-size: 0.85rem;
        }
        .alert-error { background: rgba(220,38,38,0.12); border: 1px solid rgba(248,113,113,0.35); color: #fca5a5; }
        .alert-success { background: rgba(22,163,74,0.12); border: 1px solid rgba(74,222,128,0.35); color: #86efac; }
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
            <h1>비밀번호 재설정</h1>
            <?php if ($error_message): ?>
                <div class="alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error_message); ?></span></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success_message); ?></span></div>
            <?php endif; ?>

            <?php if ($token_valid): ?>
                <form action="reset_password.php" method="post">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div class="field">
                        <label for="password">새 비밀번호 (8자 이상)</label>
                        <input id="password" name="password" type="password" required minlength="8">
                    </div>
                    <div class="field">
                        <label for="password_confirm">새 비밀번호 확인</label>
                        <input id="password_confirm" name="password_confirm" type="password" required minlength="8">
                    </div>
                    <button type="submit" class="btn-submit"><i class="fas fa-key"></i> 비밀번호 변경</button>
                </form>
            <?php endif; ?>

            <div class="login-row"><a href="login.php">로그인으로 돌아가기</a></div>
        </div>
    </div>
</body>
</html>
