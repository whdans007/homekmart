<?php
require_once __DIR__ . '/lib/home_layout.php';

$page_title = '홈';
$mall_redesigned = true;
$show_bottom_nav = true;
$active_nav = 'home';
require_once __DIR__ . '/partials/header.php';

// 고객 화면은 항상 발행(published)된 섹션만 본다 — 관리자가 편집 중인 초안은 여기 절대 노출되지 않는다.
$sections = mall_get_active_home_sections(true, 'published');

require __DIR__ . '/partials/home_body.php';

require_once __DIR__ . '/partials/footer.php';
