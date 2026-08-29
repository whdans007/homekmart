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
        $english_name = trim($_POST['english_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '') {
            $error_message = '이름을 입력해주세요.';
        } elseif ($english_name === '') {
            $error_message = '영문 이름을 입력해주세요.';
        } else {
            $stmt = $conn->prepare('UPDATE mall_members SET name = ?, english_name = ?, phone = ? WHERE id = ?');
            $stmt->bind_param('sssi', $name, $english_name, $phone, $member['id']);
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

        if (!empty($member['is_google_linked'])) {
            $error_message = '구글 계정이 연동된 회원은 비밀번호를 변경할 수 없습니다.';
        } elseif (!$row || $row['password_hash'] === null) {
            $error_message = '구글 계정으로 로그인된 회원은 비밀번호를 변경할 수 없습니다.';
        } elseif (!password_verify($current_password, $row['password_hash'])) {
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
$mall_show_back = true;
$show_bottom_nav = true;
$active_nav = 'my';
require_once __DIR__ . '/../partials/header.php';
?>

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
        <div style="margin-bottom:0.9rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.3rem;">영문 이름</label>
            <input type="text" name="english_name" value="<?php echo htmlspecialchars($member['english_name'] ?? ''); ?>" placeholder="예: Hong Gil Dong" required style="width:100%;border:1px solid #d1d5db;border-radius:0.4rem;padding:0.55rem;">
            <p style="font-size:0.72rem;color:#6b7280;margin:0.3rem 0 0;">해외(필리핀 등) 배송 시 현지 배송기사가 확인할 수 있도록 사용됩니다.</p>
        </div>
        <div style="margin-bottom:1rem;">
            <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.3rem;">연락처</label>
            <input type="text" name="phone" value="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>" style="width:100%;border:1px solid #d1d5db;border-radius:0.4rem;padding:0.55rem;">
        </div>
        <button type="submit" style="background:#111827;color:#fff;border:none;border-radius:0.4rem;padding:0.6rem 1.25rem;font-weight:700;cursor:pointer;">저장</button>
    </form>
</div>

<?php if (empty($member['is_google_linked'])): ?>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
