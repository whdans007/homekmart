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

// 미로그인 시 admin 통합 Login 이동 (로그인 후 원래 페이지로 복귀)
if (!is_logged_in()) {
    $pos = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/office/');
    $login_url = ($pos !== false)
        ? substr($_SERVER['SCRIPT_NAME'], 0, $pos) . '/admin/login.php'
        : '/admin/login.php';
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: ' . $login_url);
    exit;
}
require_office_permission();

// 사이트 루트 경로 계산 (서버 환경에 따라 자동 감지, 예: /sunset)
$_ofc_pos = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/office/');
$_office_web_root = ($_ofc_pos !== false) ? substr($_SERVER['SCRIPT_NAME'], 0, $_ofc_pos) : '';

// 현재 사용자 Store 정보
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

// 현재 사용자 등급(역할) 라벨
$_office_role_key   = $_SESSION['role'] ?? '';
$_office_role_label = $_office_role_key ? get_role_label($_office_role_key) : '';

// store_id가 없는 사용자는 office 접근 불가 (다른 점포 데이터 노출 방지)
if (empty($_office_store_id)) {
    $pos = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/office/');
    $admin_url = ($pos !== false)
        ? substr($_SERVER['SCRIPT_NAME'], 0, $pos) . '/admin/index.php'
        : '/admin/index.php';
    header('Location: ' . $admin_url . '?error=no_store_assigned');
    exit;
}

// 세션 store_id를 DB 값으로 강제 동기화 (변조 방지)
$_SESSION['store_id'] = (int)$_office_store_id;

// $css_base 기본값 (서브폴더 페이지용)
$css_base = $css_base ?? '../../admin/';
$office_nav_base = $office_nav_base ?? '../';  // office/ 루트 기준 상대경로
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title ?? 'Office Management'); ?> — HOME K MART</title>
  <link rel="icon" href="data:,">
  <link href="<?php echo $css_base; ?>css/style.css?v=20260619teal" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer"/>
</head>
<body class="bg-gray-50" style="display:flex;min-height:100vh;margin:0;padding:0;align-items:flex-start">

<?php
$uri          = $_SERVER['REQUEST_URI'] ?? '';
$current_file = basename($_SERVER['PHP_SELF']);
$is_product   = str_contains($uri, '/product_purchase/');
$is_equip     = str_contains($uri, '/equipment_purchase/');
$is_employees    = str_contains($uri, '/schedule/employees');
$is_sched        = str_contains($uri, '/schedule/schedule');
$is_schedule     = $is_employees || $is_sched;
$is_fingerprints = str_contains($uri, '/attendance/fingerprints');
$is_att_dash     = str_contains($uri, '/attendance/index');
$is_att_report   = str_contains($uri, '/attendance/report');
$is_attendance   = $is_fingerprints || $is_att_dash || $is_att_report;
$is_receipts  = str_contains($uri, '/receipts/');
$is_sales     = str_contains($uri, '/sales/');
$is_cd        = str_contains($uri, '/cash_disbursement/') && !str_contains($uri, 'fixed_expenses');
$is_fixed_exp = str_contains($uri, '/cash_disbursement/fixed_expenses');
$is_er        = str_contains($uri, '/expense_report/');
$is_dtr       = str_contains($uri, '/deferred_tracker/');
$_nav_today   = date('Y-m-d');
$_nav_year    = date('Y');
$_nav_month   = date('n');
$is_pos2      = $is_sales && str_contains($uri, 'pos2_entry');
$is_pos       = $is_sales && str_contains($uri, 'daily_entry');
$is_dk        = $is_sales && str_contains($uri, 'dk_entry');
$is_ws        = $is_sales && str_contains($uri, 'ws_entry');
$is_credit    = $is_sales && str_contains($uri, 'credit_entry');
$is_transfer  = $is_sales && str_contains($uri, 'transfer');
$is_cer       = str_contains($uri, '/cheque_expense_report/');
$is_pos_data        = str_contains($uri, '/pos_data/') && !str_contains($uri, 'data.php') && !str_contains($uri, 'item_edit.php') && !str_contains($uri, 'report.php');
$is_pos_sales_data  = str_contains($uri, '/pos_data/data.php');
$is_pos_item_edit   = str_contains($uri, '/pos_data/item_edit.php');
$is_pos_report      = str_contains($uri, '/pos_data/report.php');
$is_purchase_report = str_contains($uri, '/product_purchase/monthly_report');
$is_monthly_closing = str_contains($uri, '/product_purchase/monthly_closing');

function nav_link($href, $icon, $label, $active, $section = '') {
    $cls = $active
        ? 'flex items-center px-2 py-1.5 rounded-md text-xs font-medium bg-teal-100 text-teal-800 transition-colors'
        : 'flex items-center px-2 py-1.5 rounded-md text-xs font-medium text-gray-600 hover:bg-teal-50 hover:text-teal-700 transition-colors';
    $data_attr = $section ? " data-section-parent=\"{$section}\"" : '';
    echo "<a href=\"{$href}\" class=\"{$cls} menu-item\"{$data_attr}><i class=\"{$icon} mr-2 text-xs w-4 text-center\"></i>{$label}</a>";
}
?>

<style>
aside a, aside a:hover, aside a:focus { text-decoration: none; }
aside .menu-item { font-size: 11px; padding-top: 4px; padding-bottom: 4px; }
</style>

<!-- ── 좌측 사이드바 (logistics teal 디자인) ──────────────────── -->
<aside class="w-52 flex-shrink-0 bg-white border-r border-teal-100 flex flex-col z-30" style="position:fixed;top:0;left:0;width:13rem;height:100vh;overflow-y:auto;z-index:40">

  <!-- 로고 + MAIN -->
  <div class="flex flex-col flex-shrink-0 px-2 pt-1 mb-2">
    <a href="<?php echo $office_nav_base; ?>sales/monthly_report.php" class="block mb-2">
      <img src="<?php echo $css_base; ?>../logo/homekmart_logo.png" alt="HOME K MART" style="width:100%;height:auto;display:block;">
    </a>
    <div class="flex items-center justify-center text-teal-700 mb-2">
      <span class="font-bold" style="font-size:0.72rem;letter-spacing:0.02em;">OFFICE MANAGEMENT</span>
    </div>
    <a href="<?php echo $_office_web_root; ?>/"
       class="flex items-center gap-2 w-full px-2 py-1.5 text-xs font-semibold rounded-md transition-colors"
       style="background:#1e40af;color:#ffffff;"
       onmouseover="this.style.background='#1e3a8a'" onmouseout="this.style.background='#1e40af'">
      <i class="fa-solid fa-house"></i> MAIN
    </a>
  </div>

  <!-- 상단 사용자 정보 카드 -->
  <div class="flex-shrink-0 mx-2 mb-1.5" style="border:1px solid #ccfbf1;border-radius:0.6rem;background:linear-gradient(135deg,#f0fdfa 0%,#ecfdf5 100%);overflow:hidden;">
    <div style="display:flex;align-items:center;gap:0.35rem;padding:0.5rem 0.55rem;background:#0d9488;color:#fff;">
      <i class="fa-solid fa-store" style="font-size:0.8rem;flex-shrink:0;"></i>
      <span style="font-weight:600;font-size:0.78rem;line-height:1.15;"><?php echo htmlspecialchars($_office_store_name); ?></span>
    </div>
    <div style="padding:0.5rem 0.55rem;">
      <div style="display:flex;align-items:center;gap:0.35rem;color:#334155;font-size:11px;margin-bottom:0.5rem;">
        <i class="fa-solid fa-circle-user" style="color:#0d9488;font-size:0.9rem;"></i>
        <span style="font-weight:500;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?></span>
      </div>
      <div style="display:flex;align-items:center;gap:0.4rem;">
        <?php if ($_office_role_label !== ''): ?>
        <span style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.35rem 0.5rem;font-size:11px;font-weight:600;color:#0f766e;background:#ccfbf1;border-radius:0.4rem;white-space:nowrap;">
          <i class="fa-solid fa-id-badge" style="font-size:0.62rem;"></i><?php echo htmlspecialchars($_office_role_label); ?>
        </span>
        <?php endif; ?>
        <a href="<?php echo $office_nav_base; ?>logout.php"
           style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:0.3rem;padding:0.35rem 0.4rem;font-size:11px;font-weight:600;color:#dc2626;background:#fef2f2;border-radius:0.4rem;text-decoration:none;transition:background 0.15s;"
           onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'"
           onclick="return confirm('Sign out?')">
          <i class="fa-solid fa-right-from-bracket"></i>Logout
        </a>
      </div>
    </div>
  </div>

  <!-- 메뉴 -->
  <nav class="px-2 py-1 pb-4">

    <!-- Sales 섹션 (blue) -->
    <div class="rounded-lg px-1.5 py-2" style="background:#eff6ff;">
      <p class="menu-section-header flex items-center justify-between px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded cursor-pointer" data-section="sales" style="background:#dbeafe;color:#1d4ed8;" title="Click to toggle">Sales <span class="menu-toggle-icon font-bold text-sm">-</span></p>
      <?php nav_link($office_nav_base.'sales/monthly_report.php', 'fa-solid fa-chart-bar', 'Sales Report', $is_sales && !$is_pos && !$is_pos2 && !$is_dk && !$is_ws && !$is_credit && !$is_transfer, 'sales'); ?>
      <?php nav_link($office_nav_base.'sales/daily_entry.php?date='.$_nav_today, 'fa-solid fa-cash-register', 'POS', $is_pos, 'sales'); ?>
      <?php nav_link($office_nav_base.'sales/dk_entry.php?date='.$_nav_today, 'fa-solid fa-motorcycle', 'Delivery K', $is_dk, 'sales'); ?>
      <?php nav_link($office_nav_base.'sales/ws_entry.php?date='.$_nav_today, 'fa-solid fa-boxes-stacked', 'Whole Sale', $is_ws, 'sales'); ?>
      <?php nav_link($office_nav_base.'sales/credit_entry.php?year='.$_nav_year.'&month='.$_nav_month, 'fa-solid fa-file-invoice-dollar', 'Credit Sale', $is_credit, 'sales'); ?>
      <?php nav_link($office_nav_base.'sales/transfer.php?year='.$_nav_year.'&month='.$_nav_month, 'fa-solid fa-arrow-right-arrow-left', 'Store Transfers', $is_transfer, 'sales'); ?>
    </div>

    <!-- Finance 섹션 (green) -->
    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#f0fdf4;">
      <p class="menu-section-header flex items-center justify-between px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded cursor-pointer" data-section="finance" style="background:#dcfce7;color:#15803d;" title="Click to toggle">Finance <span class="menu-toggle-icon font-bold text-sm">-</span></p>
      <?php nav_link($office_nav_base.'receipts/list.php', 'fa-solid fa-receipt', 'Receipts', $is_receipts, 'finance'); ?>
      <?php nav_link($office_nav_base.'expense_report/index.php', 'fa-solid fa-file-lines', 'Expense Report', $is_er, 'finance'); ?>
      <?php nav_link($office_nav_base.'deferred_tracker/index.php', 'fa-solid fa-truck-ramp-box', 'Deferred', $is_dtr, 'finance'); ?>
      <?php nav_link($office_nav_base.'cheque_expense_report/index.php', 'fa-solid fa-file-invoice-dollar', 'Cheque Expense', $is_cer, 'finance'); ?>
      <?php nav_link($office_nav_base.'cash_disbursement/index.php', 'fa-solid fa-money-bill-wave', 'Cash Disbursement', $is_cd, 'finance'); ?>
    </div>

    <!-- HR 섹션 (amber) -->
    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#fffbeb;">
      <p class="menu-section-header flex items-center justify-between px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded cursor-pointer" data-section="hr" style="background:#fef3c7;color:#b45309;" title="Click to toggle">HR <span class="menu-toggle-icon font-bold text-sm">+</span></p>
      <?php nav_link($office_nav_base.'schedule/employees.php', 'fa-solid fa-users', 'Employees', $is_employees, 'hr'); ?>
      <?php nav_link($office_nav_base.'schedule/schedule.php', 'fa-solid fa-calendar-days', 'Schedule', $is_sched, 'hr'); ?>
      <?php nav_link($office_nav_base.'attendance/index.php', 'fa-solid fa-clock', 'Attendance', $is_att_dash, 'hr'); ?>
      <?php nav_link($office_nav_base.'attendance/fingerprints.php', 'fa-solid fa-fingerprint', 'Fingerprints', $is_fingerprints, 'hr'); ?>
      <?php nav_link($office_nav_base.'attendance/report.php', 'fa-solid fa-chart-bar', 'ATT Report', $is_att_report, 'hr'); ?>
    </div>

    <!-- Monthly Report 섹션 (purple) -->
    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#faf5ff;">
      <p class="menu-section-header flex items-center justify-between px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded cursor-pointer" data-section="monthly-report" style="background:#f3e8ff;color:#7e22ce;" title="Click to toggle">Monthly Report <span class="menu-toggle-icon font-bold text-sm">+</span></p>
      <?php nav_link($office_nav_base.'product_purchase/monthly_report.php', 'fa-solid fa-file-invoice-dollar', '업체별 월간 매입', $is_purchase_report, 'monthly-report'); ?>
      <?php nav_link($office_nav_base.'product_purchase/monthly_closing.php', 'fa-solid fa-flag-checkered', '월마감 REPORT', $is_monthly_closing, 'monthly-report'); ?>
      <?php nav_link($office_nav_base.'cash_disbursement/fixed_expenses.php', 'fa-solid fa-file-lines', 'Fixed Expenses', $is_fixed_exp, 'monthly-report'); ?>
    </div>

    <!-- POS Sales Data Upload 섹션 (red) -->
    <div class="rounded-lg px-1.5 py-2 mt-1.5" style="background:#fef2f2;">
      <p class="menu-section-header flex items-center justify-between px-2 py-1 mb-1 text-xs font-semibold uppercase tracking-wider rounded cursor-pointer" data-section="pos-data" style="background:#fee2e2;color:#b91c1c;" title="Click to toggle">POS Sales Data <span class="menu-toggle-icon font-bold text-sm">+</span></p>
      <?php nav_link($office_nav_base.'pos_data/report.php', 'fa-solid fa-chart-line', 'POS Data Report', $is_pos_report, 'pos-data'); ?>
      <?php nav_link($office_nav_base.'pos_data/data.php', 'fa-solid fa-table', 'POS Sales Data', $is_pos_sales_data, 'pos-data'); ?>
      <?php nav_link($office_nav_base.'pos_data/item_edit.php', 'fa-solid fa-pen-to-square', 'Item Bulk Edit', $is_pos_item_edit, 'pos-data'); ?>
      <?php nav_link($office_nav_base.'pos_data/index.php', 'fa-solid fa-file-arrow-up', 'POS Sales Data Upload', $is_pos_data, 'pos-data'); ?>
    </div>

  </nav>

</aside>

<!-- ── 메인 콘텐츠 (고정 사이드바 폭만큼 좌측 여백) ──────────────── -->
<div class="flex-1 min-w-0 overflow-x-hidden" style="margin-left:13rem">
<div class="px-5 py-5">
