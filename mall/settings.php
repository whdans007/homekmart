<?php
require_once __DIR__ . '/lib/auth.php';

$mall_redesigned = true;
$mall_show_back = true;
$show_bottom_nav = true;
$active_nav = 'my';
$page_title = '환경설정 / Settings';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.settings-group { margin: var(--space-4) var(--space-5) 0; }
.settings-group .group-title { font: var(--t-label2) var(--font-sans); color: var(--label-alternative); margin-bottom: var(--space-2); }
.settings-options { display: flex; gap: var(--space-2); }
.settings-options a {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px;
    height: 48px; border-radius: var(--radius-md); border: 1.5px solid var(--line-normal);
    font: var(--t-label1) var(--font-sans); color: var(--label-neutral);
}
.settings-options a.active { background: var(--primary-bg); border-color: var(--primary-normal); color: var(--primary-strong); font-weight: 700; }
</style>

<div class="settings-group">
    <div class="group-title">언어 / Language</div>
    <div class="settings-options">
        <a href="?lang=ko" class="<?php echo $mall_lang === 'ko' ? 'active' : ''; ?>">
            <?php if ($mall_lang === 'ko'): ?><svg style="width:16px;height:16px;"><use href="#i-check"></use></svg><?php endif; ?>
            한국어
        </a>
        <a href="?lang=en" class="<?php echo $mall_lang === 'en' ? 'active' : ''; ?>">
            <?php if ($mall_lang === 'en'): ?><svg style="width:16px;height:16px;"><use href="#i-check"></use></svg><?php endif; ?>
            English
        </a>
    </div>
</div>

<?php if ($member): ?>
<div class="settings-group" style="margin-top:var(--space-7);">
    <div class="group-title">계정 / Account</div>
    <a href="/mall/delete-account.php" style="display:flex;align-items:center;justify-content:space-between;min-height:48px;padding:0 14px;border:1px solid var(--line-normal);border-radius:var(--radius-md);color:var(--brand-red);">
        <span>계정 삭제 / Delete account</span>
        <svg style="width:16px;height:16px;"><use href="#i-chev-right"></use></svg>
    </a>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
