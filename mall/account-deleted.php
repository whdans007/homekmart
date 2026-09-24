<?php
$mall_redesigned = true;
$mall_show_back = false;
$page_title = 'Account deleted';
require_once __DIR__ . '/partials/header.php';
?>
<div style="max-width:600px;margin:0 auto;padding:56px 20px;text-align:center;">
  <h1 style="font:var(--t-headline1) var(--font-sans);">계정이 삭제되었습니다</h1>
  <p style="color:var(--label-neutral);line-height:1.6;">프로필과 관련 데이터가 삭제되었으며 모든 기기에서 로그아웃되었습니다.</p>
  <a href="/mall/index.php" class="btn btn-primary" style="display:inline-flex;margin-top:20px;">홈으로 이동</a>
</div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
