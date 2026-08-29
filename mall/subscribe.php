<?php
require_once __DIR__ . '/lib/auth.php';

mall_require_login('/mall/login.php');

$mall_redesigned = true;
$page_title = '정기배송';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.sub-intro { margin: var(--space-4) var(--space-5) 0; padding: var(--space-5); border-radius: var(--radius-xl); background: linear-gradient(135deg, var(--brand-blue-deep), var(--primary-normal)); color: var(--static-white); }
.sub-intro .title { font: var(--t-headline1) var(--font-sans); margin: 0 0 4px; }
.sub-intro .sub { font: var(--t-caption1) var(--font-sans); opacity: 0.85; margin: 0; }
.cycle-options { display: flex; gap: 8px; }
.cycle-options label { flex: 1; text-align: center; padding: 12px 0; border-radius: var(--radius-md); border: 1px solid var(--line-normal); font: var(--t-label2) var(--font-sans); color: var(--label-neutral); }
.cycle-options input { display: none; }
.cycle-options label:has(input:checked) { background: var(--primary-bg); border-color: var(--primary-normal); color: var(--primary-strong); font-weight: 700; }
</style>

<div class="coming-soon-banner">
    <i class="fas fa-tools"></i>
    <span>정기배송은 아직 준비중인 기능입니다. 아래는 디자인 미리보기이며 실제로 신청되지 않습니다.</span>
</div>

<div class="sub-intro">
    <p class="title">정기배송으로 5% 더 할인받으세요</p>
    <p class="sub">배송비 무료 · 원하는 주기로 자동 주문</p>
</div>

<div class="section">
    <div class="section-title"><h2>배송 주기</h2></div>
    <div class="cycle-options">
        <label><input type="radio" name="cycle" checked disabled> 2주</label>
        <label><input type="radio" name="cycle" disabled> 4주</label>
        <label><input type="radio" name="cycle" disabled> 6주</label>
    </div>
</div>

<div class="section">
    <div class="section-title"><h2>다음 배송일</h2></div>
    <p style="font:var(--t-label1) var(--font-sans);color:var(--label-assistive);">장바구니 상품을 담고 정기배송을 시작하면 표시됩니다.</p>
</div>

<div class="sticky-cta">
    <button class="btn" style="flex:1;background:var(--fill-strong);color:var(--label-assistive);" disabled>건너뛰기</button>
    <button class="btn btn-primary" style="flex:1;" disabled>정기배송 시작 (준비중)</button>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
