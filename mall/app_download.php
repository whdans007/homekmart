<?php
$page_title = '앱 다운로드 / App Download';
$mall_redesigned = true;
$mall_show_back = true;
$show_bottom_nav = true;
$active_nav = 'my';
require_once __DIR__ . '/partials/header.php';

$apk_path = __DIR__ . '/../mall-app/dist/HOME-K-MART-test.apk';
$apk_available = is_file($apk_path) && is_readable($apk_path);
$apk_size = $apk_available ? number_format(filesize($apk_path) / 1024 / 1024, 1) . ' MB' : null;
$driver_apk_path = __DIR__ . '/../driver-app/android/app/build/outputs/apk/debug/app-debug.apk';
$driver_apk_available = is_file($driver_apk_path) && is_readable($driver_apk_path);
?>
<style>
.app-download-card {
    margin: var(--space-5); padding: 28px var(--space-5); text-align: center;
    border: 1px solid var(--line-alternative); border-radius: var(--radius-xl); background: var(--bg-normal);
}
.app-download-card .app-icon { width: 92px; height: 92px; object-fit: contain; border-radius: 22px; box-shadow: 0 8px 24px rgba(0,0,0,.12); }
.app-download-card h1 { margin: 16px 0 5px; font: var(--t-headline1) var(--font-sans); color: var(--label-normal); }
.app-download-card .sub { margin: 0; font: var(--t-body2) var(--font-sans); color: var(--label-alternative); }
.app-download-card .meta { margin: 16px 0; font: var(--t-caption1) var(--font-sans); color: var(--label-assistive); }
.app-download-card .download-btn { width: 100%; text-decoration: none; }
.app-download-card .download-btn + .download-btn { margin-top: 10px; }
.app-download-card .driver-download-btn {
    border-color: #ea580c;
    background: #f97316;
    color: #ffffff;
}
.app-download-card .driver-download-btn:hover {
    border-color: #c2410c;
    background: #ea580c;
    color: #ffffff;
}
.app-install-guide { margin: 0 var(--space-5) var(--space-5); padding: var(--space-4); border-radius: var(--radius-lg); background: var(--bg-alternative); }
.app-install-guide h2 { margin: 0 0 10px; font: var(--t-label1) var(--font-sans); }
.app-install-guide ol { margin: 0; padding-left: 20px; color: var(--label-alternative); font: var(--t-caption1) var(--font-sans); line-height: 1.8; }
</style>

<div class="app-download-card">
    <img src="/logo/homekmart_logo.png" class="app-icon" alt="HOME K MART">
    <h1>HOME K MART</h1>
    <p class="sub">Android 테스트 앱 / Android Test App</p>
    <div class="meta">Version 1.0 · Android 7.0 이상<?php echo $apk_size ? ' · ' . htmlspecialchars($apk_size) : ''; ?></div>
    <?php if ($apk_available): ?>
        <a href="/mall/download_android_app.php" class="btn btn-primary download-btn"><i class="fab fa-android"></i> 쇼핑몰 앱 APK 다운로드</a>
    <?php else: ?>
        <button type="button" class="btn btn-block" disabled>설치 파일 준비 중 / File unavailable</button>
    <?php endif; ?>
    <?php if ($driver_apk_available): ?>
        <a href="/mall/download_driver_app.php" class="btn btn-outline-primary download-btn driver-download-btn"><i class="fas fa-motorcycle"></i> 드라이버용 앱 APK 다운로드</a>
    <?php endif; ?>
</div>

<div class="app-install-guide">
    <h2>설치 방법 / How to install</h2>
    <ol>
        <li>APK 다운로드 버튼을 누릅니다.</li>
        <li>다운로드된 파일을 실행합니다.</li>
        <li>요청 시 브라우저의 ‘알 수 없는 앱 설치’를 허용합니다.</li>
        <li>Android에서만 설치할 수 있습니다.</li>
    </ol>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
