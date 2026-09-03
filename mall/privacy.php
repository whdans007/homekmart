<?php
/**
 * 개인정보처리방침 (Privacy Policy) — Google Play 스토어 등록에 필요한 공개 페이지.
 * 필리핀 Data Privacy Act of 2012 (RA 10173) 기준으로 작성. 앱 밖(Play Console)에서도
 * 링크로 접근하므로 로그인/장바구니 등 회원 상태에 의존하지 않는다.
 */
require_once __DIR__ . '/lib/auth.php';

$mall_show_back = true;
$page_title = 'Privacy Policy';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.privacy-doc { padding: var(--space-5); max-width: 720px; margin: 0 auto; }
.privacy-doc h1 { font: var(--t-headline1) var(--font-sans); color: var(--label-normal); margin-bottom: var(--space-2); }
.privacy-doc .updated { font: var(--t-caption1) var(--font-sans); color: var(--label-assistive); margin-bottom: var(--space-6); }
.privacy-doc h2 { font: var(--t-headline2) var(--font-sans); color: var(--label-normal); margin: var(--space-6) 0 var(--space-2); }
.privacy-doc p, .privacy-doc li { font: var(--t-body1) var(--font-sans); color: var(--label-neutral); line-height: 1.6; }
.privacy-doc ul { padding-left: 1.2em; margin: var(--space-2) 0; }
.privacy-doc li { margin-bottom: 6px; }
.privacy-doc a { color: var(--primary-normal); }
</style>

<div class="privacy-doc">
    <h1>Privacy Policy</h1>
    <p class="updated">Last updated: <?php echo date('F j, Y'); ?></p>

    <p>HOME K MART ("we", "us", "our") operates the HOME K MART mobile app and website (the "Service"),
    an online grocery and retail shopping platform serving customers in the Philippines. This Privacy
    Policy explains how we collect, use, disclose, and protect your personal information in accordance
    with the Data Privacy Act of 2012 (Republic Act No. 10173) and its Implementing Rules and Regulations.</p>

    <h2>1. Information We Collect</h2>
    <ul>
        <li><strong>Account information:</strong> name, phone number, email address, and password when you register.</li>
        <li><strong>Delivery information:</strong> shipping address, contact number, and delivery notes for order fulfillment.</li>
        <li><strong>Order &amp; payment information:</strong> order history, cart contents, and payment/transaction records. We do not store full card numbers; payments are processed by our payment partners.</li>
        <li><strong>Usage data:</strong> pages viewed, products browsed, app version, and device information (OS, device model) collected automatically for app performance and security.</li>
    </ul>

    <h2>2. How We Use Your Information</h2>
    <ul>
        <li>To process and deliver your orders.</li>
        <li>To create and manage your account, and provide customer support.</li>
        <li>To send order status updates, receipts, and service notices.</li>
        <li>To improve the Service, prevent fraud, and maintain security.</li>
        <li>To send promotional messages, only where you have given consent (you may opt out at any time).</li>
    </ul>

    <h2>3. Sharing of Information</h2>
    <p>We do not sell your personal information. We may share it only with:</p>
    <ul>
        <li>Delivery/logistics partners, to fulfill and deliver your orders.</li>
        <li>Payment processors, to complete transactions securely.</li>
        <li>Government authorities, when required by Philippine law or a valid legal order.</li>
    </ul>

    <h2>4. Data Storage &amp; Security</h2>
    <p>Your data is stored on secured servers with access restricted to authorized personnel. We apply
    reasonable technical and organizational measures to protect your information against unauthorized
    access, alteration, disclosure, or destruction.</p>

    <h2>5. Data Retention</h2>
    <p>We retain your personal information for as long as your account is active or as needed to provide
    the Service, comply with legal obligations, resolve disputes, and enforce our agreements.</p>

    <h2>6. Your Rights</h2>
    <p>Under the Data Privacy Act of 2012, you have the right to:</p>
    <ul>
        <li>Be informed of how your data is processed.</li>
        <li>Access and correct your personal information.</li>
        <li>Object to or withdraw consent for processing.</li>
        <li>Request deletion or blocking of your data, subject to legal retention requirements.</li>
        <li>File a complaint with the National Privacy Commission (NPC) if you believe your rights have been violated.</li>
    </ul>
    <p>You can review or update most account details in <a href="/mall/my.php">My Page</a>, or contact us using the details below.</p>

    <h2>7. Children's Privacy</h2>
    <p>The Service is not directed to children under 18. We do not knowingly collect personal information
    from children without parental consent.</p>

    <h2>8. Changes to This Policy</h2>
    <p>We may update this Privacy Policy from time to time. Material changes will be posted on this page
    with an updated "Last updated" date.</p>

    <h2>9. Contact Us</h2>
    <p>If you have questions about this Privacy Policy or wish to exercise your data privacy rights, please
    contact us through the channels provided in the app or at <a href="https://homekmart.net">homekmart.net</a>.</p>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
