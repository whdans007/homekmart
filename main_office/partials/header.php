<?php
// Main Office shared header. Calling pages may set $page_title before require_once.
require_once __DIR__ . '/../lib/auth.php';
mo_require_admin();

$_mo_role_label = get_role_label($_SESSION['role'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? 'Main Office'); ?> — HOME K MART</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
          crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body { background: #f4f5f7; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        .mo-topbar {
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 60%, #312e81 100%);
            color: #fff;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .mo-topbar .mo-title { display: flex; align-items: center; gap: 0.6rem; font-weight: 700; font-size: 1.1rem; }
        .mo-topbar a.mo-back { color: rgba(255,255,255,0.75); text-decoration: none; font-size: 0.85rem; }
        .mo-topbar a.mo-back:hover { color: #fff; }
        .mo-user { display: flex; align-items: center; gap: 0.6rem; font-size: 0.82rem; }
        .mo-user .role-badge { background: rgba(99,102,241,0.35); border-radius: 0.4rem; padding: 0.2rem 0.55rem; font-weight: 600; }
        .mo-user a.logout { color: #fca5a5; text-decoration: none; }
        .mo-content { padding: 1.5rem; max-width: 1100px; margin: 0 auto; }
        .mo-nav { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 1.5rem; display: flex; gap: 0.25rem; }
        .mo-nav a { padding: 0.7rem 1rem; font-size: 0.85rem; font-weight: 600; color: #6b7280; text-decoration: none; border-bottom: 2px solid transparent; }
        .mo-nav a:hover { color: #4338ca; }
        .mo-nav a.active { color: #4338ca; border-bottom-color: #4f46e5; }
    </style>
</head>
<body>
<div class="mo-topbar">
    <div>
        <a href="../index.php" class="mo-back"><i class="fas fa-arrow-left"></i> MAIN</a>
        <div class="mo-title"><i class="fas fa-building-columns"></i> Main Office — All-Store Data Viewer</div>
    </div>
    <div class="mo-user">
        <span><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?></span>
        <?php if ($_mo_role_label !== ''): ?>
        <span class="role-badge"><?php echo htmlspecialchars($_mo_role_label); ?></span>
        <?php endif; ?>
        <a href="../admin/logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>
<?php
$_mo_page = basename($_SERVER['PHP_SELF']);

// Keep the currently selected store/date when switching menus — each page falls back to
// the first store / today's date on its own if no params are given, so we just pass them
// along when present and omit them otherwise.
// Date-based pages (date=Y-m-d) and SALES REPORT (year+month) use different formats,
// so we convert between them here.
$_mo_carry_store = (int)($_GET['store_id'] ?? 0);

$_mo_get_date  = $_GET['date'] ?? '';
$_mo_get_year  = $_GET['year'] ?? '';
$_mo_get_month = $_GET['month'] ?? '';

$_mo_carry_date = null;
$_mo_carry_year = null;
$_mo_carry_month = null;

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_mo_get_date)) {
    $_mo_carry_date  = $_mo_get_date;
    $_mo_carry_year  = (int)date('Y', strtotime($_mo_carry_date));
    $_mo_carry_month = (int)date('n', strtotime($_mo_carry_date));
} elseif (ctype_digit((string)$_mo_get_year) && ctype_digit((string)$_mo_get_month)) {
    $_mo_carry_year  = (int)$_mo_get_year;
    $_mo_carry_month = (int)$_mo_get_month;
    // Date to pass to date-based pages — today if it falls within that month, otherwise the 1st
    $_mo_last_day = (int)date('t', mktime(0, 0, 0, $_mo_carry_month, 1, $_mo_carry_year));
    $_mo_day      = ((int)date('Y') === $_mo_carry_year && (int)date('n') === $_mo_carry_month) ? (int)date('j') : 1;
    $_mo_carry_date = sprintf('%04d-%02d-%02d', $_mo_carry_year, $_mo_carry_month, min($_mo_day, $_mo_last_day));
}

function mo_nav_qs(int $store, ?string $date = null, ?int $year = null, ?int $month = null): string {
    $parts = [];
    if ($store > 0) $parts[] = 'store_id=' . $store;
    if ($date !== null) $parts[] = 'date=' . $date;
    if ($year !== null) $parts[] = 'year=' . $year;
    if ($month !== null) $parts[] = 'month=' . $month;
    return $parts ? ('?' . implode('&', $parts)) : '';
}

$_mo_qs_date  = mo_nav_qs($_mo_carry_store, $_mo_carry_date);
$_mo_qs_sales = mo_nav_qs($_mo_carry_store, null, $_mo_carry_year, $_mo_carry_month);
?>
<nav class="mo-nav">
    <a href="index.php" class="<?php echo $_mo_page === 'index.php' ? 'active' : ''; ?>"><i class="fa-solid fa-store me-1"></i>By Store</a>
    <a href="sales_report.php<?php echo $_mo_qs_sales; ?>" class="<?php echo $_mo_page === 'sales_report.php' ? 'active' : ''; ?>"><i class="fa-solid fa-chart-bar me-1"></i>SALES REPORT</a>
    <a href="sales_expenses_report.php<?php echo $_mo_qs_sales; ?>" class="<?php echo $_mo_page === 'sales_expenses_report.php' ? 'active' : ''; ?>"><i class="fa-solid fa-scale-balanced me-1"></i>Sales &amp; Expenses</a>
    <a href="expense_report.php<?php echo $_mo_qs_date; ?>" class="<?php echo $_mo_page === 'expense_report.php' ? 'active' : ''; ?>"><i class="fa-solid fa-receipt me-1"></i>Expense Report</a>
    <a href="cash_disbursement.php<?php echo $_mo_qs_date; ?>" class="<?php echo $_mo_page === 'cash_disbursement.php' ? 'active' : ''; ?>"><i class="fa-solid fa-money-bill-wave me-1"></i>Cash Disbursement</a>
    <a href="cheque_expense_report.php<?php echo $_mo_qs_date; ?>" class="<?php echo $_mo_page === 'cheque_expense_report.php' ? 'active' : ''; ?>"><i class="fa-solid fa-file-invoice-dollar me-1"></i>Cheque Expense</a>
    <a href="daily_report.php<?php echo $_mo_qs_date; ?>" class="<?php echo $_mo_page === 'daily_report.php' ? 'active' : ''; ?>"><i class="fa-solid fa-file-invoice me-1"></i>Daily Report</a>
</nav>
<div class="mo-content">
