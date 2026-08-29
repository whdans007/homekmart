<?php
require_once __DIR__ . '/lib/auth.php';

mall_require_login('/mall/login.php');

$mall_redesigned = true;
$page_title = '결제 승인';
require_once __DIR__ . '/partials/header.php';

$method = $_GET['method'] ?? 'gcash';
$labels = ['gcash' => 'GCash', 'maya' => 'Maya', 'bank' => 'InstaPay'];
$label = $labels[$method] ?? 'GCash';
?>
<style>
.pay-wrap { display: flex; flex-direction: column; align-items: center; text-align: center; padding: var(--space-6) var(--space-5) 0; }
.pay-spinner { width: 56px; height: 56px; border-radius: 50%; border: 4px solid var(--fill-strong); border-top-color: var(--primary-normal); margin-bottom: var(--space-5); }
.pay-steps { display: flex; flex-direction: column; gap: 10px; width: 100%; max-width: 320px; margin-top: var(--space-5); text-align: left; }
.pay-step { display: flex; align-items: center; gap: 10px; font: var(--t-label2) var(--font-sans); color: var(--label-assistive); }
.pay-step.done { color: var(--label-normal); }
.pay-step .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--fill-strong); flex-shrink: 0; }
.pay-step.done .dot { background: var(--brand-green); }
</style>

<div class="coming-soon-banner">
    <i class="fas fa-tools"></i>
    <span><?php echo htmlspecialchars($label); ?> 실결제 연동은 준비중입니다. 아래는 결제 승인 화면의 디자인 미리보기이며, 실제 결제는 진행되지 않습니다.</span>
</div>

<div class="pay-wrap">
    <div class="pay-spinner spin"></div>
    <div style="font:var(--t-headline1) var(--font-sans);"><?php echo htmlspecialchars($label); ?> 결제 승인 대기중</div>
    <div style="font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);margin-top:6px;">가맹점: HOME K MART · 수단: <?php echo htmlspecialchars($label); ?></div>

    <div class="pay-steps">
        <div class="pay-step done"><span class="dot"></span> 결제 요청 전송됨</div>
        <div class="pay-step"><span class="dot"></span> 결제사 승인 대기</div>
        <div class="pay-step"><span class="dot"></span> 주문 확정</div>
    </div>

    <a href="/mall/checkout.php" style="margin-top:var(--space-6);color:var(--label-alternative);font:var(--t-caption1) var(--font-sans);">취소하고 돌아가기</a>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
