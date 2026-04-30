<?php
// Design Ref: §6 — 오피스 전용 헤더. admin 세션 공유, office 전용 네비게이션.
// 호출 페이지에서 $css_base 설정 필요:
//   office/ 루트 페이지 → $css_base = '../admin/'
//   office/서브폴더 페이지 → $css_base = '../../admin/'
ob_start();
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../lib/office_helper.php';

// 미로그인 시 오피스 전용 로그인 페이지로 이동
if (!is_logged_in()) {
    $pos = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/office/');
    $login_url = ($pos !== false)
        ? substr($_SERVER['SCRIPT_NAME'], 0, $pos) . '/office/login.php'
        : '/office/login.php';
    header('Location: ' . $login_url);
    exit;
}
require_office_permission();

// 현재 사용자 점포 정보
$_office_store_name = '본점';
$_office_store_id   = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $conn = get_db_connection();
        $st = $conn->prepare("SELECT s.name AS sname, s.id AS sid FROM users u LEFT JOIN stores s ON u.store_id=s.id WHERE u.id=?");
        $st->bind_param('i', $_SESSION['user_id']);
        $st->execute();
        $sr = $st->get_result()->fetch_assoc();
        if ($sr) {
            $_office_store_name = $sr['sname'] ?? '본점';
            $_office_store_id   = $sr['sid'];
        }
        $st->close();
        $conn->close();
    } catch (Exception $e) {
        error_log('Office store info error: ' . $e->getMessage());
    }
}

// $css_base 기본값 (서브폴더 페이지용)
$css_base = $css_base ?? '../../admin/';
$office_nav_base = $office_nav_base ?? '../';  // office/ 루트 기준 상대경로
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title ?? '오피스 관리'); ?> — HOME K MART</title>
  <link rel="icon" href="data:,">
  <link href="<?php echo $css_base; ?>css/style.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer"/>
</head>
<body class="bg-gray-50 min-h-screen">

<nav class="bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between shadow-sm">
  <div class="flex items-center gap-6">
    <a href="<?php echo $office_nav_base; ?>index.php" class="text-lg font-bold text-blue-700 hover:text-blue-800">
      <i class="fa-solid fa-building-columns mr-1"></i>오피스 관리
    </a>
    <span class="text-gray-300">|</span>

    <?php
    // 현재 모듈 감지 (URL 경로 기반)
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $is_product  = str_contains($uri, '/product_purchase/');
    $is_equip    = str_contains($uri, '/equipment_purchase/');
    $is_schedule = str_contains($uri, '/schedule/');
    $is_dash     = !$is_product && !$is_equip && !$is_schedule;
    ?>

    <a href="<?php echo $office_nav_base; ?>index.php"
       class="text-sm font-medium px-3 py-1 rounded <?php echo $is_dash ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:text-blue-700'; ?>">
      <i class="fa-solid fa-chart-line mr-1"></i>대시보드
    </a>
    <a href="<?php echo $office_nav_base; ?>product_purchase/list.php"
       class="text-sm font-medium px-3 py-1 rounded <?php echo $is_product ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:text-blue-700'; ?>">
      <i class="fa-solid fa-cart-shopping mr-1"></i>상품구매지출
    </a>
    <a href="<?php echo $office_nav_base; ?>equipment_purchase/list.php"
       class="text-sm font-medium px-3 py-1 rounded <?php echo $is_equip ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:text-blue-700'; ?>">
      <i class="fa-solid fa-box mr-1"></i>비품구매지출
    </a>
    <a href="<?php echo $office_nav_base; ?>schedule/employees.php"
       class="text-sm font-medium px-3 py-1 rounded <?php echo $is_schedule ? 'bg-blue-100 text-blue-700' : 'text-gray-600 hover:text-blue-700'; ?>">
      <i class="fa-solid fa-calendar-days mr-1"></i>직원휴무관리
    </a>
  </div>

  <div class="flex items-center gap-3 text-sm text-gray-600">
    <span><i class="fa-solid fa-store mr-1"></i><?php echo htmlspecialchars($_office_store_name); ?></span>
    <span><i class="fa-solid fa-user mr-1"></i><?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?></span>
    <?php if (($_SESSION['role'] ?? '') === 'super_admin' || has_permission('admin_access')): ?>
    <a href="<?php echo $css_base; ?>index.php" class="text-blue-600 hover:underline">
      <i class="fa-solid fa-arrow-left mr-1"></i>관리자
    </a>
    <?php endif; ?>
    <a href="<?php echo $office_nav_base; ?>logout.php"
       class="text-red-500 hover:text-red-700 hover:underline"
       onclick="return confirm('로그아웃 하시겠습니까?')">
      <i class="fa-solid fa-right-from-bracket mr-1"></i>로그아웃
    </a>
  </div>
</nav>

<div class="container mx-auto px-4 py-6 max-w-7xl">
