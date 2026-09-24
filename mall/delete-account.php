<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/account_deletion.php';

mall_require_login();
$member = mall_current_member();
if (!$member) {
    mall_logout();
    header('Location: /mall/login.php');
    exit;
}

$error = '';
$active_order_count = 0;
try {
    $conn = get_db_connection();
    $active_order_count = mall_account_deletion_active_order_count($conn, (int)$member['id']);
} catch (Throwable $e) {
    $error = '계정 상태를 확인할 수 없습니다. 잠시 후 다시 시도해 주세요.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!mall_csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = '요청이 만료되었습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.';
    } elseif ($active_order_count > 0) {
        $error = '진행 중인 주문이 있어 계정을 삭제할 수 없습니다. 주문 완료 또는 취소 후 다시 시도해 주세요.';
    } elseif (strcasecmp(trim((string)($_POST['email'] ?? '')), (string)$member['email']) !== 0) {
        $error = '가입한 이메일 주소가 일치하지 않습니다.';
    } elseif (trim((string)($_POST['confirmation'] ?? '')) !== 'DELETE') {
        $error = '확인란에 DELETE를 정확히 입력해 주세요.';
    } elseif (!empty($member['has_password']) && !mall_account_deletion_verify_password($conn, (int)$member['id'], (string)($_POST['current_password'] ?? ''))) {
        $error = '현재 비밀번호가 올바르지 않습니다.';
    } else {
        $result = mall_account_delete((int)$member['id'], (string)$member['email']);
        if ($result['success']) {
            mall_logout();
            header('Location: /mall/account-deleted.php');
            exit;
        }
        $error = ($result['error'] ?? '') === 'ACTIVE_ORDERS'
            ? '진행 중인 주문이 있어 계정을 삭제할 수 없습니다.'
            : '계정 삭제를 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.';
    }
}

$mall_redesigned = true;
$mall_show_back = true;
$page_title = '계정 삭제 / Delete account';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.delete-wrap{max-width:640px;margin:0 auto;padding:var(--space-5)}
.delete-box{border:1px solid var(--line-alternative);border-radius:var(--radius-lg);padding:var(--space-5);margin-bottom:var(--space-4)}
.delete-box h1{font:var(--t-headline1) var(--font-sans);margin:0 0 var(--space-3)}
.delete-box p,.delete-box li{font:var(--t-body1) var(--font-sans);line-height:1.55;color:var(--label-neutral)}
.delete-box ul{padding-left:20px}.delete-field{margin-top:var(--space-4)}
.delete-field label{display:block;font:var(--t-label1) var(--font-sans);margin-bottom:6px}
.delete-field input{width:100%;min-height:46px;padding:0 12px;border:1px solid var(--line-normal);border-radius:var(--radius-md);box-sizing:border-box}
.delete-error{padding:12px;border-radius:var(--radius-md);background:#fff0f0;color:var(--brand-red);margin-bottom:var(--space-4)}
.delete-warning{padding:12px;border-radius:var(--radius-md);background:#fff8e6;color:#7a5200;margin-bottom:var(--space-4)}
.delete-submit{width:100%;margin-top:var(--space-5);background:var(--brand-red);color:#fff;border:0;min-height:48px;border-radius:var(--radius-md);font-weight:700}
.delete-submit:disabled{opacity:.45}
</style>
<div class="delete-wrap">
  <?php if ($error): ?><div class="delete-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($active_order_count > 0): ?><div class="delete-warning">진행 중인 주문 <?php echo $active_order_count; ?>건이 있습니다. 배송 완료 또는 주문 취소 후 계정 삭제를 진행해 주세요.</div><?php endif; ?>
  <div class="delete-box">
    <h1>계정을 영구 삭제합니다</h1>
    <p>삭제하면 로그인할 수 없으며 복구할 수 없습니다.</p>
    <ul>
      <li>프로필, 배송지, 장바구니, 위시리스트, 리뷰, 푸시 토큰을 삭제합니다.</li>
      <li>완료된 주문의 배송 연락처와 주소는 익명화합니다.</li>
      <li>법률·회계·분쟁 대응에 필요한 주문 금액과 상품 거래 기록은 개인 식별정보 없이 보관될 수 있습니다.</li>
    </ul>
    <form method="post" autocomplete="off">
      <?php echo mall_csrf_field(); ?>
      <div class="delete-field"><label for="email">가입 이메일</label><input id="email" name="email" type="email" required autocomplete="email"></div>
      <?php if (!empty($member['has_password'])): ?><div class="delete-field"><label for="current_password">현재 비밀번호</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div><?php endif; ?>
      <div class="delete-field"><label for="confirmation">확인을 위해 DELETE 입력</label><input id="confirmation" name="confirmation" type="text" required autocomplete="off"></div>
      <button class="delete-submit" type="submit" <?php echo $active_order_count > 0 ? 'disabled' : ''; ?>>계정 영구 삭제</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
