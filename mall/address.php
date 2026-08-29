<?php
require_once __DIR__ . '/lib/auth.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();

$mall_redesigned = true;
$page_title = '배송지 설정';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.addr-tabs { display: flex; gap: 8px; padding: var(--space-4) var(--space-5) 0; }
.addr-tabs button { flex: 1; height: 40px; border-radius: var(--radius-md); border: 1px solid var(--line-normal); background: var(--bg-normal); font: var(--t-label2) var(--font-sans); color: var(--label-alternative); }
.addr-tabs button.active { background: var(--label-normal); color: var(--static-white); border-color: var(--label-normal); }
.map-placeholder {
    margin: var(--space-4) var(--space-5) 0; height: 220px; border-radius: var(--radius-lg);
    background: var(--fill-normal); display: flex; align-items: center; justify-content: center;
    color: var(--label-assistive); font: var(--t-caption1) var(--font-sans); flex-direction: column; gap: 8px;
}
.addr-field { margin: 0 0 var(--space-4); }
.addr-field label { display: block; font: var(--t-label2) var(--font-sans); color: var(--label-neutral); margin-bottom: 6px; }
.addr-field input, .addr-field select {
    width: 100%; border: 1px solid var(--line-normal); border-radius: var(--radius-md);
    padding: 11px 12px; font: var(--t-body2) var(--font-sans); background: var(--bg-normal); color: var(--label-normal);
}
</style>

<div class="coming-soon-banner">
    <i class="fas fa-tools"></i>
    <span>배송지 저장 기능은 아직 준비중입니다. 지도/주소 입력 화면만 미리보기로 제공되며, 저장은 되지 않습니다.</span>
</div>

<div class="addr-tabs">
    <button type="button" class="active" data-tab="map">지도에서 핀</button>
    <button type="button" data-tab="form">주소 입력</button>
</div>

<div id="tab-map">
    <div class="map-placeholder">
        <i class="fas fa-map-location-dot" style="font-size:28px;"></i>
        <span>지도 기능 준비중 (Leaflet/Google Maps 연동 예정)</span>
    </div>
</div>

<div id="tab-form" style="display:none;" class="section">
    <div class="addr-field">
        <label>수령인</label>
        <input type="text" placeholder="이름" disabled>
    </div>
    <div class="addr-field">
        <label>휴대폰</label>
        <input type="text" placeholder="+63 9XX XXX XXXX" disabled>
    </div>
    <div class="addr-field">
        <label>지역(Region)</label>
        <select disabled><option>준비중</option></select>
    </div>
    <div class="addr-field">
        <label>시(City)</label>
        <select disabled><option>지역을 먼저 선택하세요</option></select>
    </div>
    <div class="addr-field">
        <label>바랑가이(Barangay)</label>
        <select disabled><option>시를 먼저 선택하세요</option></select>
    </div>
    <div class="addr-field">
        <label>상세주소/건물명</label>
        <input type="text" placeholder="건물명, 동/호수 등" disabled>
    </div>
    <div class="addr-field">
        <label>랜드마크 <span style="color:var(--brand-red);">*필수</span></label>
        <input type="text" placeholder="예: OOO 편의점 맞은편" disabled>
    </div>
</div>

<div class="sticky-cta">
    <button class="btn btn-primary btn-block" disabled>저장 (준비중)</button>
</div>

<script>
document.querySelectorAll('.addr-tabs button').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.addr-tabs button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const tab = btn.dataset.tab;
        document.getElementById('tab-map').style.display = tab === 'map' ? '' : 'none';
        document.getElementById('tab-form').style.display = tab === 'form' ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
