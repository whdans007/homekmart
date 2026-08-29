<?php
/**
 * 쇼핑몰 고객용 공용 헤더
 * 포함 전 반드시 $page_title(optional)을 설정할 수 있다. $member는 여기서 계산한다.
 * 바텀 탭바를 보여주려면 include 전에 $show_bottom_nav = true; 와 $active_nav = 'home'|'category'|'search'|'cart'|'my'; 를 설정한다.
 * 새 디자인 시스템으로 재작성된 화면은 include 전에 $mall_redesigned = true; 를 설정한다 — 그 화면들은
 * 자체 <div class="section">/커스텀 클래스로 좌우 여백을 직접 관리하므로 <main>의 기본 여백이 빠진다.
 * 아직 재작성 안 된 화면(체크아웃/로그인/마이페이지 등)은 이 플래그가 없으면 <main>에 기본 여백이 들어간다.
 * $mall_hide_topbar = true; 를 설정하면 공용 .mall-topbar를 건너뛴다 — 홈 화면은 디자인 원본처럼
 * 로고/아이콘/검색을 자체 sticky 블록(.home-topbar, home_body.php)으로 직접 그리기 때문에 필요하다.
 */
require_once __DIR__ . '/../lib/auth.php';

// lang_helper.php의 언어 세션 로직(get_language/set_language)은 $_SESSION을 직접 쓰는데,
// mall은 세션이 지연 시작(mall_current_member() 등을 호출할 때 비로소 session_start())되므로
// 세션을 미리 시작해두지 않으면 언어 선택이 저장되지 않고 매 요청마다 날아갔다. 먼저 세션부터 연다.
mall_session_start();
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = 'ko'; // mall은 한국어 기본(관리자 쪽 lang_helper.php 기본값 'en'과는 별개로 여기서 지정)
}
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/tier_badge.php';

// 상품명 등 일부 콘텐츠는 display_name_en 컬럼으로 부분 번역을 지원한다(FR-15, module-7 범위).
// 화면 문구 전체 다국어화는 별도 작업으로 남아있다(마이페이지 환경설정/하단 언어전환은 상품명 등 일부에만 적용됨).
if (isset($_GET['lang'])) {
    set_language($_GET['lang']);
}
$mall_lang = get_language();

$member = mall_current_member();
$active_nav = $active_nav ?? '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' : ''; ?>HOME K MART</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <?php
    // 파일 수정시각을 쿼리스트링에 붙여 캐시버스팅한다 — CSS를 고쳐도 모바일 브라우저가 예전 캐시를
    // 계속 쓰는 바람에 반영이 안 된 것처럼 보이는 문제(예: 뱃지 위치 CSS가 옛날 걸로 남아있던 것) 방지.
    $__wanted_tokens_v = @filemtime(__DIR__ . '/../css/wanted-tokens.css') ?: time();
    $__mall_css_v = @filemtime(__DIR__ . '/../css/mall.css') ?: time();
    ?>
    <link rel="stylesheet" href="/mall/css/wanted-tokens.css?v=<?php echo $__wanted_tokens_v; ?>">
    <link rel="stylesheet" href="/mall/css/mall.css?v=<?php echo $__mall_css_v; ?>">
</head>
<body class="<?php echo !empty($mall_redesigned) ? 'redesigned' : ''; ?>">
<?php include __DIR__ . '/icon_sprite.php'; ?>
<div class="mall-shell">
    <?php if (empty($mall_hide_topbar)): ?>
    <header class="mall-topbar">
        <?php if (!empty($mall_show_back)): ?>
            <a href="javascript:history.back()" class="icon-btn"><svg><use href="#i-chev-left"></use></svg></a>
            <div style="font:var(--t-headline2) var(--font-sans);"><?php echo isset($page_title) ? htmlspecialchars($page_title) : ''; ?></div>
        <?php else: ?>
            <a href="/mall/index.php" class="logo"><img src="/logo/homekmart_logo.png" alt="HOME K MART"></a>
            <?php echo mall_render_tier_badge($member); ?>
        <?php endif; ?>
        <div class="spacer"></div>
        <?php if (empty($show_bottom_nav)): ?>
            <?php if ($member): ?>
                <a href="/mall/my.php" class="icon-btn" title="마이페이지"><svg><use href="#i-person"></use></svg></a>
            <?php else: ?>
                <a href="/mall/login.php" class="icon-btn" title="로그인"><svg><use href="#i-person"></use></svg></a>
            <?php endif; ?>
            <?php include __DIR__ . '/cart_widget.php'; ?>
        <?php endif; ?>
    </header>
    <?php endif; ?>
    <main>
