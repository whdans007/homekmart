<?php
require_once __DIR__ . '/../lib/driver.php';
require_once __DIR__ . '/partials/i18n.php';
if (mall_driver_is_logged_in()) { header('Location: /mall/driver/index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if ($password !== ($_POST['password_confirm'] ?? '')) $error = '비밀번호 확인이 일치하지 않습니다.';
    else {
        $result = mall_driver_signup($_POST['name'] ?? '', $_POST['phone'] ?? '', $password, $_POST['vehicle_info'] ?? '');
        if ($result['success']) { header('Location: /mall/driver/login.php?registered=1'); exit; }
        $messages = ['VALIDATION_ERROR'=>'이름과 연락처를 입력하고 비밀번호는 8자 이상으로 설정해 주세요.','DUPLICATE_PHONE'=>'이미 등록된 연락처입니다.','SERVER_ERROR'=>'가입 처리 중 오류가 발생했습니다.'];
        $error = $messages[$result['error']] ?? '가입 처리 중 오류가 발생했습니다.';
    }
}
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>드라이버 회원가입</title><link rel="stylesheet" href="/mall/css/wanted-tokens.css"><link rel="stylesheet" href="/mall/css/mall.css"><style>
body{min-height:100vh;padding:24px 20px;background:#f7f7f8}.wrap{max-width:380px;margin:auto}.card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:22px}.field{margin-bottom:15px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}.field input{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:10px;font-size:15px}.error{padding:12px;margin-bottom:15px;border-radius:10px;background:#fef2f2;color:#b91c1c;font-size:13px}.note{font-size:12px;color:#6b7280;line-height:1.5;margin:12px 0}</style></head><body>
<div class="wrap"><h2>🏍️ 드라이버 회원가입</h2><div class="card"><?php if($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post"><div class="field"><label>이름</label><input name="name" required maxlength="50" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"></div><div class="field"><label>연락처</label><input name="phone" type="tel" required maxlength="30" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"></div><div class="field"><label>차량 정보</label><input name="vehicle_info" maxlength="100" placeholder="예: 오토바이 ABC-123" value="<?php echo htmlspecialchars($_POST['vehicle_info'] ?? ''); ?>"></div><div class="field"><label>비밀번호</label><input name="password" type="password" required minlength="8"></div><div class="field"><label>비밀번호 확인</label><input name="password_confirm" type="password" required minlength="8"></div><p class="note">가입 신청 후 관리자의 승인을 받아야 로그인하고 배달을 받을 수 있습니다.</p><button class="btn btn-primary btn-block">가입 신청</button></form><div style="text-align:center;margin-top:16px"><a href="/mall/driver/login.php">로그인으로 돌아가기</a></div>
</div></div><?php driver_i18n_ui(); ?></body></html>
