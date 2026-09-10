<?php
require_once __DIR__ . '/../lib/driver.php';
require_once __DIR__ . '/partials/i18n.php';
if (mall_driver_is_logged_in()) { header('Location: /mall/driver/index.php'); exit; }
$error_message = '';
$info_message = isset($_GET['registered']) ? '가입 신청이 완료되었습니다. 관리자 승인 후 로그인할 수 있습니다.' : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($phone === '' || $password === '') $error_message = '연락처와 비밀번호를 입력해 주세요.';
    else {
        $result = mall_driver_attempt_login($phone, $password);
        if ($result['success']) { header('Location: /mall/driver/index.php'); exit; }
        if (($result['error'] ?? '') === 'PENDING_APPROVAL') $error_message = '관리자 승인 대기 중입니다.';
        elseif (($result['error'] ?? '') === 'INACTIVE') $error_message = '사용이 중지된 계정입니다. 관리자에게 문의해 주세요.';
        else $error_message = '연락처 또는 비밀번호가 올바르지 않습니다.';
    }
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>배송기사 로그인 - HOME K MART</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"><link rel="stylesheet" href="/mall/css/wanted-tokens.css"><link rel="stylesheet" href="/mall/css/mall.css"><style>
body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px 20px}.auth-wrap{width:100%;max-width:360px}.auth-logo{text-align:center;margin-bottom:20px;font-size:18px;font-weight:700}.auth-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:24px 20px;box-shadow:var(--shadow-md)}.alert{padding:12px;margin-bottom:15px;border-radius:10px;font-size:13px}.error{background:#fef2f2;color:#b91c1c}.info{background:#eff6ff;color:#1d4ed8}.field{margin-bottom:16px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}.field input{width:100%;padding:12px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:15px}</style></head><body>
<div class="auth-wrap"><div class="auth-logo"><i class="fas fa-motorcycle"></i> HOME K MART 배송기사</div><div class="auth-card">
<?php if($error_message): ?><div class="alert error"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if($info_message): ?><div class="alert info"><?php echo htmlspecialchars($info_message); ?></div><?php endif; ?>
<form method="post"><div class="field"><label for="phone">연락처</label><input id="phone" name="phone" type="tel" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"></div>
<div class="field"><label for="password">비밀번호</label><input id="password" name="password" type="password" required></div><button class="btn btn-primary btn-block">로그인</button></form>
<div style="text-align:center;margin-top:16px;font-size:13px"><a href="/mall/driver/signup.php" style="color:var(--primary-normal);font-weight:700">드라이버 회원가입</a></div>
</div></div><?php driver_i18n_ui(); ?></body></html>
