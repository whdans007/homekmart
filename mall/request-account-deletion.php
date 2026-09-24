<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/account_deletion.php';
mall_session_start();

$success = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['website'])) {
        $success = true;
    } elseif (!mall_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = '요청이 만료되었습니다. 페이지를 새로고침해 주세요.';
    } else {
        $result = mall_account_deletion_submit_web_request(
            (string)($_POST['email'] ?? ''),
            (string)($_POST['note'] ?? ''),
            (string)($_SERVER['REMOTE_ADDR'] ?? '')
        );
        if ($result['success']) {
            $success = true;
        } else {
            $error = ($result['error'] ?? '') === 'INVALID_EMAIL'
                ? '올바른 이메일 주소를 입력해 주세요.'
                : '요청을 접수하지 못했습니다. 잠시 후 다시 시도해 주세요.';
        }
    }
}

$mall_redesigned = true;
$mall_show_back = true;
$page_title = 'Account deletion request';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.request-wrap{max-width:640px;margin:0 auto;padding:var(--space-5)}
.request-card{border:1px solid var(--line-alternative);border-radius:var(--radius-lg);padding:var(--space-5)}
.request-card h1{font:var(--t-headline1) var(--font-sans);margin:0 0 10px}.request-card p,.request-card li{line-height:1.6;color:var(--label-neutral)}
.request-field{margin-top:16px}.request-field label{display:block;font-weight:700;margin-bottom:6px}.request-field input,.request-field textarea{width:100%;box-sizing:border-box;border:1px solid var(--line-normal);border-radius:var(--radius-md);padding:12px}.request-field textarea{min-height:100px;resize:vertical}
.request-submit{width:100%;min-height:48px;margin-top:20px;border:0;border-radius:var(--radius-md);background:var(--primary-normal);color:#fff;font-weight:700}
.request-alert{padding:14px;border-radius:var(--radius-md);margin-bottom:16px}.request-alert.ok{background:#ebfaef;color:#176b32}.request-alert.error{background:#fff0f0;color:var(--brand-red)}
.hp-field{position:absolute;left:-9999px}
</style>
<div class="request-wrap"><div class="request-card">
  <h1>HOME K MART 계정 삭제 요청</h1>
  <p>앱에 로그인할 수 있다면 <a href="/mall/delete-account.php">앱 내 계정 삭제</a>를 이용하면 즉시 처리됩니다. 로그인할 수 없는 경우 아래 양식으로 요청해 주세요.</p>
  <ul><li>본인 확인 후 계정과 관련 개인정보를 삭제합니다.</li><li>진행 중인 주문은 먼저 완료 또는 취소해야 합니다.</li><li>거래 기록은 법률·회계상 필요한 범위에서 익명화하여 보관할 수 있습니다.</li></ul>
  <?php if ($success): ?><div class="request-alert ok">요청을 접수했습니다. 보안을 위해 계정 존재 여부와 관계없이 동일하게 안내됩니다. 본인 확인이 필요한 경우 입력한 이메일로 연락드립니다.</div>
  <?php else: ?>
    <?php if ($error): ?><div class="request-alert error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="post">
      <?php echo mall_csrf_field(); ?>
      <div class="hp-field" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="request-field"><label for="email">가입 이메일</label><input id="email" name="email" type="email" required autocomplete="email"></div>
      <div class="request-field"><label for="note">추가 정보 (선택)</label><textarea id="note" name="note" maxlength="1000" placeholder="로그인이 어려운 이유나 확인에 필요한 내용을 적어 주세요."></textarea></div>
      <button class="request-submit" type="submit">삭제 요청 접수</button>
    </form>
  <?php endif; ?>
  <p style="margin-top:20px;font-size:13px;"><a href="/mall/privacy.php">개인정보처리방침 보기</a></p>
</div></div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
