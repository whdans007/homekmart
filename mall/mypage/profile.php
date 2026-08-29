<?php
require_once __DIR__ . '/../lib/auth.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();

$info_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';
    $conn = get_db_connection();

    if ($form === 'profile') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '') {
            $error_message = '이름을 입력해주세요.';
        } else {
            $stmt = $conn->prepare('UPDATE mall_members SET name = ?, phone = ? WHERE id = ?');
            $stmt->bind_param('ssi', $name, $phone, $member['id']);
            $stmt->execute();
            $stmt->close();
            $info_message = '회원정보가 수정되었습니다.';
            $member = mall_current_member();
        }
    } elseif ($form === 'password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $new_password_confirm = $_POST['new_password_confirm'] ?? '';

        $stmt = $conn->prepare('SELECT password_hash FROM mall_members WHERE id = ?');
        $stmt->bind_param('i', $member['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($current_password, $row['password_hash'])) {
            $error_message = '현재 비밀번호가 올바르지 않습니다.';
        } elseif (strlen($new_password) < 8) {
            $error_message = '새 비밀번호는 8자 이상이어야 합니다.';
        } elseif ($new_password !== $new_password_confirm) {
            $error_message = '새 비밀번호가 일치하지 않습니다.';
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare('UPDATE mall_members SET password_hash = ? WHERE id = ?');
            $update->bind_param('si', $new_hash, $member['id']);
            $update->execute();
            $update->close();
            $info_message = '비밀번호가 변경되었습니다.';
        }
    }
    $conn->close();
}

$page_title = '회원정보수정';
require_once __DIR__ . '/../partials/header.php';
?>

<h1 style="font-size:1.2rem;font-weight:800;margin-bottom:1rem;">회원정보수정</h1>

<div style="display:flex;gap:1rem;margin-bottom:1rem;font-size:0.85rem;">
    <a href="/mall/mypage/orders.php" style="color:#6b7280;">주문내역</a>
    <a href="/mall/mypage/wishlist.php" style="color:#6b7280;">위시리스트</a>
    <a href="/mall/mypage/profile.php" style="font-weight:700;">회원정보수정</a>
</div>

<?php if ($info_message): ?><div class="wholesale-notice" style="background:#ecfdf5;border-color:#6ee7b7;color:#065f46;"><?php echo htmlspecialchars($info_message); ?></div><?php endif; ?>
<?php if ($error_message): ?><div class="wholesale-notice" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b;"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<div style="background:#fff;border:1px solid #e5e7eb;border-radius:0.6rem;padding:1.25rem;max-width:420px;margin-bottom:1.25rem;">
    <h2 style="font-size:0.92rem;font-weight:700;margin:0 0 0.75rem;">기본 정보</h2>
    <form method="post">
        <input type="hidden" name="form" value="profile">
        <div style="margin-bottom:0.9rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.3rem;">이메일</label>
            <input type="text" value="<?php echo htmlspecialchars($member['email']); ?>" disabled style="width:100%;border:1px solid #e5e7eb;background:#f3f4f6;border-radius:0.4rem;padding:0.55rem;">
        </div>
        <div style="margin-bottom:0.9rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.3rem;">이름</label>
            <input type="text" name="name" value="<?php echo htmlspecialchars($member['name']); ?>" required style="width:100%;border:1px solid #d1d5db;border-radius:0.4rem;padding:0.55rem;">
        </div>
        <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.3rem;">연락처</label>
            <input type="text" name="phone" value="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>" style="width:100%;border:1px solid #d1d5db;border-radius:0.4rem;padding:0.55rem;">
        </div>
        <button type="submit" style="background:#111827;color:#fff;border:none;border-radius:0.4rem;padding:0.6rem 1.25rem;font-weight:700;cursor:pointer;">저장</button>
    </form>
</div>

<div style="background:#fff;border:1px solid #e5e7eb;border-radius:0.6rem;padding:1.25rem;max-width:420px;">
    <h2 style="font-size:0.92rem;font-weight:700;margin:0 0 0.75rem;">비밀번호 변경</h2>
    <form method="post">
        <input type="hidden" name="form" value="password">
        <div style="margin-bottom:0.9rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.3rem;">현재 비밀번호</label>
            <input type="password" name="current_password" required style="width:100%;border:1px solid #d1d5db;border-radius:0.4rem;padding:0.55rem;">
        </div>
        <div style="margin-bottom:0.9rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.3rem;">새 비밀번호 (8자 이상)</label>
            <input type="password" name="new_password" minlength="8" required style="width:100%;border:1px solid #d1d5db;border-radius:0.4rem;padding:0.55rem;">
        </div>
        <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.3rem;">새 비밀번호 확인</label>
            <input type="password" name="new_password_confirm" minlength="8" required style="width:100%;border:1px solid #d1d5db;border-radius:0.4rem;padding:0.55rem;">
        </div>
        <button type="submit" style="background:#111827;color:#fff;border:none;border-radius:0.4rem;padding:0.6rem 1.25rem;font-weight:700;cursor:pointer;">비밀번호 변경</button>
    </form>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
