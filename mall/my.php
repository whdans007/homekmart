<?php
require_once __DIR__ . '/lib/auth.php';

$mall_redesigned = true;
$show_bottom_nav = true;
$active_nav = 'my';
$page_title = '마이';
require_once __DIR__ . '/partials/header.php';

$order_count = 0;
$wishlist_count = 0;
if ($member) {
    $conn = get_db_connection();
    $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM mall_orders WHERE member_id = ?');
    $stmt->bind_param('i', $member['id']);
    $stmt->execute();
    $order_count = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    $wstmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM mall_wishlist WHERE member_id = ?');
    $wstmt->bind_param('i', $member['id']);
    $wstmt->execute();
    $wishlist_count = (int)($wstmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $wstmt->close();
    $conn->close();
}
?>
<style>
.my-profile-card {
    margin: var(--space-4) var(--space-5) 0; padding: var(--space-5);
    border-radius: var(--radius-xl); color: var(--static-white);
    background: linear-gradient(135deg, var(--brand-blue-deep), var(--primary-normal));
}
.my-profile-card .name { font: var(--t-headline1) var(--font-sans); margin: 0 0 4px; }
.my-profile-card .email { font: var(--t-caption1) var(--font-sans); opacity: 0.85; }
.my-stats { display: flex; margin: var(--space-4) var(--space-5) 0; border: 1px solid var(--line-alternative); border-radius: var(--radius-lg); overflow: hidden; }
.my-stats a, .my-stats div { flex: 1; text-align: center; padding: var(--space-4) 0; border-right: 1px solid var(--line-alternative); color: inherit; }
.my-stats a:last-child, .my-stats div:last-child { border-right: none; }
.my-stats .val { font: 700 18px var(--font-sans); }
.my-stats .lbl { font: var(--t-caption1) var(--font-sans); color: var(--label-alternative); margin-top: 2px; }
.my-menu { margin: var(--space-4) var(--space-5) 0; border: 1px solid var(--line-alternative); border-radius: var(--radius-lg); overflow: hidden; }
.my-menu a, .my-menu .menu-row { display: flex; align-items: center; justify-content: space-between; padding: 14px var(--space-4); border-bottom: 1px solid var(--line-alternative); font: var(--t-label1) var(--font-sans); color: var(--label-normal); }
.my-menu a:last-child, .my-menu .menu-row:last-child { border-bottom: none; }
.my-menu .menu-row.disabled { color: var(--label-assistive); }
.guest-card { margin: var(--space-5); padding: var(--space-6) var(--space-5); text-align: center; border: 1px solid var(--line-alternative); border-radius: var(--radius-xl); }
</style>

<?php if (!$member): ?>
<div class="guest-card">
    <div style="font:var(--t-headline1) var(--font-sans);margin-bottom:6px;">로그인하고 더 많은 혜택을 받아보세요</div>
    <div style="font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);margin-bottom:var(--space-5);">주문내역, 위시리스트, 정기배송 관리를 이용하려면 로그인이 필요해요.</div>
    <div style="display:flex;gap:8px;">
        <a href="/mall/login.php" class="btn" style="flex:1;background:var(--fill-strong);color:var(--label-normal);">로그인</a>
        <a href="/mall/signup.php" class="btn btn-primary" style="flex:1;">회원가입</a>
    </div>
</div>
<?php else: ?>

<div class="my-profile-card">
    <div class="name"><?php echo htmlspecialchars($member['name']); ?>님</div>
    <div class="email"><?php echo htmlspecialchars($member['email']); ?></div>
    <?php echo mall_render_tier_badge($member); ?>
</div>

<div class="my-stats">
    <div>
        <div class="val">0<span class="tag-soon">준비중</span></div>
        <div class="lbl">포인트</div>
    </div>
    <div>
        <div class="val">0<span class="tag-soon">준비중</span></div>
        <div class="lbl">쿠폰</div>
    </div>
    <a href="/mall/mypage/orders.php">
        <div class="val"><?php echo $order_count; ?></div>
        <div class="lbl">주문</div>
    </a>
</div>

<div style="display:flex;gap:10px;margin:var(--space-4) var(--space-5) 0;">
    <a href="/mall/mypage/orders.php" class="btn" style="flex:1;background:var(--fill-normal);color:var(--label-normal);">주문내역</a>
    <a href="/mall/mypage/wishlist.php" class="btn" style="flex:1;background:var(--fill-normal);color:var(--label-normal);">위시리스트(<?php echo $wishlist_count; ?>)</a>
</div>

<div class="my-menu">
    <a href="/mall/mypage/profile.php">회원정보수정 <svg style="width:16px;height:16px;color:var(--label-assistive);"><use href="#i-chev-right"></use></svg></a>
    <a href="/mall/address.php">배송지 관리 <svg style="width:16px;height:16px;color:var(--label-assistive);"><use href="#i-chev-right"></use></svg></a>
    <a href="/mall/subscribe.php">정기배송 <svg style="width:16px;height:16px;color:var(--label-assistive);"><use href="#i-chev-right"></use></svg></a>
    <a href="/mall/settings.php">환경설정 <svg style="width:16px;height:16px;color:var(--label-assistive);"><use href="#i-chev-right"></use></svg></a>
    <div class="menu-row disabled">고객센터 <span class="tag-soon">준비중</span></div>
    <div class="menu-row disabled">알림설정 <span class="tag-soon">준비중</span></div>
    <a href="/mall/logout.php" style="color:var(--brand-red);">로그아웃</a>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
