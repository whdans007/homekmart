<?php
/**
 * 외상 거래 목록 / 미수금 현황 / 수금 관리
 */
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('credit_transactions.title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 외상거래(도매판매) 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$transactions = [];
$total_tx = 0;
$total_pages = 1;
$customer_balances = [];
$summary = ['outstanding' => 0, 'month_sales' => 0, 'month_paid' => 0];
$errors = [];

$store_id = $current_store_id;

// 선택한 업체(카드 클릭) 필터
$filter_customer_id   = (int)($_GET['customer_id'] ?? 0);
$filter_customer_name = '';

// ── 조회 기준 날짜(선택 시) / 월 ──
$filter_date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filter_date)) { $filter_date = ''; }

$sel_month = $_GET['month'] ?? date('Y-m');
if ($filter_date !== '') { $sel_month = substr($filter_date, 0, 7); } // 날짜 선택 시 그 달 데이터를 로드
if (!preg_match('/^\d{4}-\d{2}$/', $sel_month)) { $sel_month = date('Y-m'); }
$month_start      = $sel_month . '-01';
$month_end        = date('Y-m-t', strtotime($month_start)); // 해당 월 말일
$prev_month       = date('Y-m', strtotime($month_start . ' -1 month'));
$next_month       = date('Y-m', strtotime($month_start . ' +1 month'));
$current_month    = date('Y-m');
$is_current_month = ($sel_month === $current_month);
$next_disabled    = ($next_month > $current_month);
$month_label      = date('Y', strtotime($month_start)) . '년 ' . (int)date('n', strtotime($month_start)) . '월';

// ── 일자 네비게이션 변수 ──
$day_today         = date('Y-m-d');
$active_day        = $filter_date !== '' ? $filter_date : ($is_current_month ? $day_today : $month_start);
$prev_day          = date('Y-m-d', strtotime($active_day . ' -1 day'));
$next_day          = date('Y-m-d', strtotime($active_day . ' +1 day'));
$next_day_disabled = ($next_day > $day_today);

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 점포 필터 조건 — super_admin도 선택된 점포($current_store_id, 상단 점포 스위처) 기준으로 스코프
    $store_cond_tx = " AND ct.store_id = " . (int)$store_id;
    $store_cond_pay = " AND cp.store_id = " . (int)$store_id;
    $store_cond_cc = " AND cc.store_id = " . (int)$store_id;

    // 선택한 달 기간 조건 (매월 1일~말일, 누계 매월 리셋)
    $date_cond_tx  = " AND ct.transaction_date BETWEEN '{$month_start}' AND '{$month_end}'";
    $date_cond_pay = " AND cp.payment_date BETWEEN '{$month_start}' AND '{$month_end}'";

    // POS 외상(sales_pos_wholesale_pick, source_type='credit') 통합 조건
    $store_cond_pos = " AND wp.store_id = " . (int)$store_id;
    $date_cond_pos  = " AND wp.sale_date BETWEEN '{$month_start}' AND '{$month_end}'";
    $has_pos = false;
    try { $has_pos = (bool)$pdo->query("SHOW TABLES LIKE 'sales_pos_wholesale_pick'")->fetchColumn(); } catch (PDOException $e) { $has_pos = false; }

    // ── 요약: 선택한 달 외상매출(거래명세서 + POS) / 수금 / 미수 ──
    $doc_sales = (float)$pdo->query("SELECT COALESCE(SUM(ct.final_amount),0) FROM credit_transactions ct WHERE ct.status='confirmed'{$date_cond_tx}{$store_cond_tx}")->fetchColumn();
    $pos_sales = $has_pos
        ? (float)$pdo->query("SELECT COALESCE(SUM(wp.amount),0) FROM sales_pos_wholesale_pick wp WHERE wp.source_type='credit'{$date_cond_pos}{$store_cond_pos}")->fetchColumn()
        : 0.0;
    $summary['month_sales'] = $doc_sales + $pos_sales;
    $summary['month_paid']  = (float)$pdo->query("SELECT COALESCE(SUM(cp.amount),0) FROM credit_payments cp WHERE 1=1{$date_cond_pay}{$store_cond_pay}")->fetchColumn();
    $summary['outstanding'] = $summary['month_sales'] - $summary['month_paid'];

    // ── 거래처별 미수금 현황 (거래명세서 + POS 외상 합산) ──
    $pos_bal_join = $has_pos
        ? "LEFT JOIN (SELECT source_id AS customer_id, SUM(amount) AS pos_sales FROM sales_pos_wholesale_pick WHERE source_type='credit' AND source_id > 0 AND sale_date BETWEEN '{$month_start}' AND '{$month_end}' GROUP BY source_id) ps ON ps.customer_id = cc.id"
        : "";
    $pos_sales_expr = $has_pos ? " + COALESCE(ps.pos_sales,0)" : "";
    $bal_sql = "
        SELECT cc.id, cc.name, cc.phone,
               (COALESCE(s.sales,0){$pos_sales_expr}) AS sales,
               COALESCE(p.paid,0) AS paid,
               ((COALESCE(s.sales,0){$pos_sales_expr}) - COALESCE(p.paid,0)) AS balance
        FROM credit_customers cc
        LEFT JOIN (SELECT customer_id, SUM(final_amount) AS sales FROM credit_transactions WHERE status='confirmed' AND transaction_date BETWEEN '{$month_start}' AND '{$month_end}' GROUP BY customer_id) s ON s.customer_id = cc.id
        LEFT JOIN (SELECT customer_id, SUM(amount) AS paid FROM credit_payments WHERE payment_date BETWEEN '{$month_start}' AND '{$month_end}' GROUP BY customer_id) p ON p.customer_id = cc.id
        {$pos_bal_join}
        WHERE cc.is_active = 1 {$store_cond_cc}
        HAVING (sales > 0 OR paid > 0)
        ORDER BY balance DESC, cc.name ASC
        LIMIT 100
    ";
    $customer_balances = $pdo->query($bal_sql)->fetchAll(PDO::FETCH_ASSOC);

    // 선택한 업체 이름 확인 (필터 배너용)
    if ($filter_customer_id > 0) {
        foreach ($customer_balances as $cb) {
            if ((int)$cb['id'] === $filter_customer_id) { $filter_customer_name = $cb['name']; break; }
        }
        if ($filter_customer_name === '') {
            $nst = $pdo->prepare("SELECT name FROM credit_customers WHERE id = ?");
            $nst->execute([$filter_customer_id]);
            $filter_customer_name = (string)$nst->fetchColumn();
        }
    }

    // ── 외상거래 목록 (거래명세서 + POS 외상 통합, PHP 병합·FIFO·페이징) ──
    $rows = [];
    // 1) 거래명세서
    foreach ($pdo->query("
        SELECT ct.id, ct.transaction_date AS tdate, ct.final_amount AS amount, ct.customer_id, ct.created_at,
               cc.name AS customer_name, cc.phone AS customer_phone, u.full_name AS user_name,
               (SELECT COUNT(*) FROM credit_transaction_items cti WHERE cti.transaction_id = ct.id) AS item_count
        FROM credit_transactions ct
        LEFT JOIN credit_customers cc ON ct.customer_id = cc.id
        LEFT JOIN users u ON ct.user_id = u.id
        WHERE ct.status != 'cancelled'{$date_cond_tx}{$store_cond_tx}
    ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'source' => 'doc', 'id' => (int)$r['id'], 'tdate' => $r['tdate'], 'created_at' => $r['created_at'],
            'amount' => (float)$r['amount'],
            'customer_id' => ($r['customer_id'] !== null ? (int)$r['customer_id'] : null),
            'customer_name' => $r['customer_name'], 'customer_phone' => $r['customer_phone'],
            'user_name' => $r['user_name'], 'item_count' => (int)$r['item_count'],
        ];
    }
    // 2) POS 외상
    if ($has_pos) {
        foreach ($pdo->query("
            SELECT wp.id, wp.sale_date AS tdate, wp.amount, wp.source_id, wp.client, wp.remark, wp.shift, wp.pos_no, wp.created_at,
                   cc.name AS cc_name, cc.phone AS cc_phone
            FROM sales_pos_wholesale_pick wp
            LEFT JOIN credit_customers cc ON cc.id = wp.source_id AND wp.source_id > 0
            WHERE wp.source_type='credit'{$date_cond_pos}{$store_cond_pos}
        ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nm = $r['cc_name'] ?: ($r['client'] ?: ($r['remark'] ?: '—'));
            $rows[] = [
                'source' => 'pos', 'id' => (int)$r['id'], 'tdate' => $r['tdate'], 'created_at' => $r['created_at'],
                'amount' => (float)$r['amount'],
                'customer_id' => ((int)$r['source_id'] > 0 ? (int)$r['source_id'] : null),
                'customer_name' => $nm, 'customer_phone' => $r['cc_phone'] ?? '',
                'user_name' => 'POS ' . strtoupper((string)$r['shift']) . '·' . (int)$r['pos_no'], 'item_count' => null,
            ];
        }
    }

    // 거래처별 월 수금액 (FIFO 충당용)
    $cust_paid_map = [];
    foreach ($pdo->query("SELECT customer_id, SUM(amount) AS paid FROM credit_payments WHERE payment_date BETWEEN '{$month_start}' AND '{$month_end}' GROUP BY customer_id")->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $cust_paid_map[(int)$pr['customer_id']] = (float)$pr['paid'];
    }
    // 오래된→최신 순으로 FIFO 충당(applied) 계산 (거래명세서+POS 통합 시간순)
    $asc = $rows;
    usort($asc, function($a, $b) {
        if ($a['tdate'] !== $b['tdate']) return strcmp($a['tdate'], $b['tdate']);
        return strcmp((string)$a['created_at'], (string)$b['created_at']);
    });
    $older_accum = [];
    $applied_map = [];
    foreach ($asc as $row) {
        $cid = $row['customer_id'];
        if ($cid === null) { $applied_map[$row['source'].':'.$row['id']] = 0.0; continue; }
        $prev = $older_accum[$cid] ?? 0.0;
        $paid = $cust_paid_map[$cid] ?? 0.0;
        $applied_map[$row['source'].':'.$row['id']] = max(0.0, min($row['amount'], $paid - $prev));
        $older_accum[$cid] = $prev + $row['amount'];
    }

    // 최신순 정렬 후 applied 매핑
    usort($rows, function($a, $b) {
        if ($a['tdate'] !== $b['tdate']) return strcmp($b['tdate'], $a['tdate']);
        return strcmp((string)$b['created_at'], (string)$a['created_at']);
    });
    foreach ($rows as &$row) { $row['applied'] = $applied_map[$row['source'].':'.$row['id']] ?? 0.0; }
    unset($row);

    // 선택한 업체만 필터 (카드 클릭 시)
    if ($filter_customer_id > 0) {
        $rows = array_values(array_filter($rows, function($r) use ($filter_customer_id) {
            return (int)($r['customer_id'] ?? 0) === $filter_customer_id;
        }));
    }

    // 선택한 날짜만 필터 (일자 보기)
    if ($filter_date !== '') {
        $rows = array_values(array_filter($rows, function($r) use ($filter_date) {
            return date('Y-m-d', strtotime($r['tdate'])) === $filter_date;
        }));
    }

    // 페이징 (PHP)
    $total_tx    = count($rows);
    $total_pages = max(1, ceil($total_tx / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset      = max(0, ($page - 1) * $per_page);
    $transactions = array_slice($rows, $offset, $per_page);

} catch (PDOException $e) {
    $errors[] = t('credit_transactions.db_error') . $e->getMessage();
    error_log("credit_transactions error: " . $e->getMessage());
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-6">

    <?php if (isset($flash)): ?>
        <div class="mb-4 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-700'; ?>">
            <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?> mr-1"></i>
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
            <?php foreach ($errors as $error): ?><div><i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($error); ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- 헤더 + 액션 -->
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold text-gray-800">
            <i class="fas fa-file-invoice-dollar text-yellow-600 mr-2"></i><?php echo htmlspecialchars(t('credit_transactions.title')); ?>
        </h1>
        <div class="flex gap-2">
            <button type="button" id="tx-bulk-pay-btn" disabled
               class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                <i class="fas fa-money-check-alt mr-2"></i>선택 결제하기 (<span id="tx-bulk-pay-count">0</span>)
            </button>
            <button type="button" id="open-payment-modal" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                <i class="fas fa-hand-holding-usd mr-2"></i><?php echo htmlspecialchars(t('credit_transactions.btn_payment')); ?>
            </button>
            <a href="add_credit_transaction.php" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-primary-600 rounded-md hover:bg-primary-700">
                <i class="fas fa-plus mr-2"></i><?php echo htmlspecialchars(t('credit_transactions.btn_new')); ?>
            </a>
        </div>
    </div>

    <!-- 월 네비게이션 (날짜 필터 해제됨) -->
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div class="inline-flex items-center gap-2">
            <a href="?<?php echo http_build_query(array_merge(array_diff_key($_GET, ['date'=>1]), ['month' => $prev_month, 'page' => 1])); ?>"
               class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                <i class="fas fa-chevron-left mr-1"></i>이전달
            </a>
            <input type="month" value="<?php echo htmlspecialchars($sel_month); ?>" max="<?php echo htmlspecialchars($current_month); ?>"
                   onchange="location.href='?<?php echo http_build_query(array_diff_key($_GET, ['month'=>1,'page'=>1,'date'=>1])); ?><?php echo empty(array_diff_key($_GET, ['month'=>1,'page'=>1,'date'=>1])) ? '' : '&'; ?>month=' + this.value"
                   class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
            <?php if ($next_disabled): ?>
                <span class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-300 bg-white border border-gray-200 rounded-md cursor-not-allowed">다음달<i class="fas fa-chevron-right ml-1"></i></span>
            <?php else: ?>
                <a href="?<?php echo http_build_query(array_merge(array_diff_key($_GET, ['date'=>1]), ['month' => $next_month, 'page' => 1])); ?>"
                   class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    다음달<i class="fas fa-chevron-right ml-1"></i>
                </a>
            <?php endif; ?>
            <?php if (!$is_current_month): ?>
                <a href="?<?php echo http_build_query(array_merge(array_diff_key($_GET, ['date'=>1]), ['month' => $current_month, 'page' => 1])); ?>"
                   class="inline-flex items-center px-3 py-2 text-sm font-medium text-white bg-primary-600 rounded-md hover:bg-primary-700">이번달</a>
            <?php endif; ?>
        </div>
        <div class="text-sm font-semibold text-gray-700"><i class="fas fa-calendar-alt mr-1 text-primary-600"></i><?php echo htmlspecialchars($month_label); ?> 기준</div>
    </div>

    <!-- 일자 네비게이션 -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
        <div class="inline-flex items-center gap-2">
            <a href="?<?php echo http_build_query(array_merge(array_diff_key($_GET, ['month'=>1]), ['date' => $prev_day, 'page' => 1])); ?>"
               class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                <i class="fas fa-chevron-left mr-1"></i>이전날
            </a>
            <input type="date" value="<?php echo htmlspecialchars($filter_date); ?>" max="<?php echo htmlspecialchars($day_today); ?>"
                   onchange="if(this.value){location.href='?<?php echo http_build_query(array_diff_key($_GET, ['date'=>1,'month'=>1,'page'=>1])); ?><?php echo empty(array_diff_key($_GET, ['date'=>1,'month'=>1,'page'=>1])) ? '' : '&'; ?>date=' + this.value;}"
                   class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
            <?php if ($next_day_disabled): ?>
                <span class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-300 bg-white border border-gray-200 rounded-md cursor-not-allowed">다음날<i class="fas fa-chevron-right ml-1"></i></span>
            <?php else: ?>
                <a href="?<?php echo http_build_query(array_merge(array_diff_key($_GET, ['month'=>1]), ['date' => $next_day, 'page' => 1])); ?>"
                   class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    다음날<i class="fas fa-chevron-right ml-1"></i>
                </a>
            <?php endif; ?>
            <a href="?<?php echo http_build_query(array_merge(array_diff_key($_GET, ['month'=>1]), ['date' => $day_today, 'page' => 1])); ?>"
               class="inline-flex items-center px-3 py-2 text-sm font-medium text-white bg-primary-600 rounded-md hover:bg-primary-700">오늘</a>
            <?php if ($filter_date !== ''): ?>
                <a href="?<?php echo http_build_query(array_diff_key($_GET, ['date'=>1,'page'=>1])); ?>"
                   class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    <i class="fas fa-times mr-1"></i>전체 날짜
                </a>
            <?php endif; ?>
        </div>
        <div class="text-sm font-semibold text-gray-700">
            <?php if ($filter_date !== ''): ?>
                <i class="fas fa-calendar-day mr-1 text-primary-600"></i><?php echo htmlspecialchars($filter_date); ?> 하루만 표시
            <?php else: ?>
                <i class="fas fa-calendar-alt mr-1 text-gray-400"></i>월 전체 표시
            <?php endif; ?>
        </div>
    </div>

    <!-- 거래처별 미수금 현황 (카드) -->
    <div class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-3"><?php echo htmlspecialchars(t('credit_transactions.balance_section_title')); ?></h3>

        <style>
        .credit-grid{display:grid;gap:1rem;grid-template-columns:repeat(1,minmax(0,1fr));}
        @media (min-width:640px){.credit-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
        @media (min-width:768px){.credit-grid{grid-template-columns:repeat(3,minmax(0,1fr));}}
        @media (min-width:1024px){.credit-grid{grid-template-columns:repeat(4,minmax(0,1fr));}}
        @media (min-width:1280px){.credit-grid{grid-template-columns:repeat(5,minmax(0,1fr));}}
        </style>
        <div class="credit-grid">
            <!-- 이 달 요약 (거래처 카드 맨 앞) -->
            <div class="rounded-lg shadow p-4 flex flex-col justify-center" style="background:#eff6ff;border:1px solid #bfdbfe">
                <div class="text-xs text-gray-500 mb-0.5">이 달 미수 (외상매출−수금)</div>
                <div class="text-2xl font-bold <?php echo $summary['outstanding'] > 0 ? 'text-red-600' : 'text-gray-900'; ?> mb-2"><?php echo number_format($summary['outstanding'], 2); ?></div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">이 달 외상매출</span>
                    <span class="font-semibold text-gray-900"><?php echo number_format($summary['month_sales'], 2); ?></span>
                </div>
                <div class="flex items-center justify-between text-sm mt-1">
                    <span class="text-gray-500">이 달 수금</span>
                    <span class="font-semibold text-blue-700"><?php echo number_format($summary['month_paid'], 2); ?></span>
                </div>
            </div>
            <?php if (empty($customer_balances)): ?>
                <div class="bg-white shadow rounded-lg ring-1 ring-gray-200 px-6 py-8 text-center text-gray-500 text-sm" style="grid-column:1/-1"><?php echo htmlspecialchars(t('credit_transactions.no_balance')); ?></div>
            <?php else: ?>
                <?php foreach ($customer_balances as $cb): ?>
                    <?php $has_balance = ((float)$cb['balance']) > 0.005; ?>
                    <?php $is_selected = ($filter_customer_id === (int)$cb['id']); ?>
                    <div class="bg-white rounded-lg shadow ring-1 <?php echo $is_selected ? 'ring-2 ring-primary-500' : ($has_balance ? 'ring-red-200' : 'ring-gray-200'); ?> p-4 flex flex-col cursor-pointer hover:shadow-md transition-shadow"
                         onclick="location.href='?<?php echo http_build_query(array_merge($_GET, ['customer_id' => $cb['id'], 'page' => 1])); ?>'"
                         title="클릭하여 이 업체 거래만 보기">
                        <div class="flex items-start justify-between mb-3">
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-gray-900 truncate" title="<?php echo htmlspecialchars($cb['name'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($cb['name']); ?></div>
                                <?php if (!empty($cb['phone'])): ?>
                                    <div class="text-xs text-gray-500 mt-0.5"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($cb['phone']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($has_balance): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800 flex-shrink-0"><?php echo htmlspecialchars(t('credit_transactions.badge_unpaid')); ?></span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 flex-shrink-0"><?php echo htmlspecialchars(t('credit_transactions.badge_paid')); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="space-y-1 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500"><?php echo htmlspecialchars(t('credit_transactions.label_sales_total')); ?></span>
                                <span class="text-gray-900"><?php echo number_format($cb['sales'], 2); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500"><?php echo htmlspecialchars(t('credit_transactions.label_paid_total')); ?></span>
                                <span class="text-blue-700"><?php echo number_format($cb['paid'], 2); ?></span>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-t border-gray-100 flex items-end justify-between gap-2">
                            <div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars(t('credit_transactions.label_balance')); ?></div>
                                <div class="text-xl font-bold <?php echo $has_balance ? 'text-red-600' : 'text-gray-700'; ?>"><?php echo number_format($cb['balance'], 2); ?></div>
                            </div>
                            <div class="flex gap-1.5 flex-shrink-0">
                                <a href="add_credit_transaction.php?customer_id=<?php echo $cb['id']; ?>"
                                   onclick="event.stopPropagation()"
                                   class="inline-flex items-center justify-center px-3 py-1.5 text-xs font-medium text-white bg-primary-600 rounded-md hover:bg-primary-700">
                                    <i class="fas fa-cart-plus mr-1"></i><?php echo htmlspecialchars(t('credit_transactions.btn_sale_register')); ?>
                                </a>
                                <button type="button"
                                        class="pay-btn inline-flex items-center justify-center px-3 py-1.5 text-xs font-medium text-white bg-blue-500 rounded-md hover:bg-blue-600"
                                        data-id="<?php echo $cb['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($cb['name'], ENT_QUOTES); ?>"
                                        data-balance="<?php echo (float)$cb['balance']; ?>">
                                    <i class="fas fa-hand-holding-usd mr-1"></i><?php echo htmlspecialchars(t('credit_transactions.btn_collect')); ?>
                                </button>
                                <a href="credit_payment_history.php?customer_id=<?php echo $cb['id']; ?>"
                                   onclick="event.stopPropagation()"
                                   title="수금 내역 관리"
                                   class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-medium text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                    <i class="fas fa-list-ul"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 외상거래 목록 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-300">
        <div class="px-6 py-3 border-b border-gray-200 bg-white flex justify-between items-center gap-2 flex-wrap">
            <h3 class="text-base font-semibold text-gray-900">
                <?php echo htmlspecialchars(t('credit_transactions.list_title')); ?>
                <span class="text-sm font-normal text-gray-500">(<?php echo htmlspecialchars(str_replace('{count}', number_format($total_tx), t('credit_transactions.total_count_label'))); ?>)</span>
                <?php if ($filter_customer_id > 0): ?>
                    <span class="ml-2 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-primary-100 text-primary-800">
                        <i class="fas fa-filter mr-1"></i><?php echo htmlspecialchars($filter_customer_name !== '' ? $filter_customer_name : ('#' . $filter_customer_id)); ?> 업체만 표시
                    </span>
                <?php endif; ?>
            </h3>
            <?php if ($filter_customer_id > 0): ?>
                <a href="?<?php echo http_build_query(array_diff_key($_GET, ['customer_id' => 1, 'page' => 1])); ?>"
                   class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    <i class="fas fa-times mr-1"></i>전체 보기
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($transactions)): ?>
            <div class="text-center py-12">
                <i class="fas fa-receipt text-gray-300 text-5xl mb-4"></i>
                <p class="text-gray-500 mb-6"><?php echo htmlspecialchars(t('credit_transactions.no_transactions')); ?></p>
                <a href="add_credit_transaction.php" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-primary-600 rounded-md hover:bg-primary-700">
                    <i class="fas fa-plus mr-2"></i><?php echo htmlspecialchars(t('credit_transactions.btn_new')); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase">
                                <input type="checkbox" id="tx-select-all-checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-700 uppercase"><?php echo htmlspecialchars(t('credit_transactions.th_date')); ?></th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-700 uppercase"><?php echo htmlspecialchars(t('credit_transactions.th_customer')); ?></th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-700 uppercase"><?php echo htmlspecialchars(t('credit_transactions.th_item_count')); ?></th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-700 uppercase"><?php echo htmlspecialchars(t('credit_transactions.th_amount')); ?></th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-700 uppercase"><?php echo htmlspecialchars(t('credit_transactions.th_payment')); ?></th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-700 uppercase"><?php echo htmlspecialchars(t('credit_transactions.th_user')); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <?php foreach ($transactions as $t): $isPos = ($t['source'] === 'pos'); ?>
                            <?php
                                $row_amount = (float)$t['amount'];
                                $row_applied = (float)($t['applied'] ?? 0);
                                $row_remaining = round($row_amount - $row_applied, 2);
                                $row_payable = ($t['customer_id'] !== null && $row_remaining > 0.005);
                            ?>
                            <tr class="border-b border-gray-100 <?php echo $isPos ? '' : 'hover:bg-gray-50 cursor-pointer'; ?>" <?php echo $isPos ? '' : "onclick=\"window.location.href='credit_transaction_preview.php?id=" . (int)$t['id'] . "'\""; ?>>
                                <td class="px-3 py-4 whitespace-nowrap text-center" onclick="event.stopPropagation()">
                                    <?php if ($row_payable): ?>
                                        <input type="checkbox" class="tx-select-checkbox rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                                               data-customer-id="<?php echo (int)$t['customer_id']; ?>"
                                               data-customer-name="<?php echo htmlspecialchars($t['customer_name'] ?? '', ENT_QUOTES); ?>"
                                               data-remaining="<?php echo $row_remaining; ?>">
                                    <?php else: ?>
                                        <input type="checkbox" class="rounded border-gray-300" disabled>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900 font-medium"><?php echo date('Y-m-d', strtotime($t['tdate'])); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="mb-1">
                                        <?php if ($isPos): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold" style="background:#f3e8ff;color:#7c3aed"><i class="fas fa-cash-register mr-1"></i>POS</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold" style="background:#dcfce7;color:#166534"><i class="fas fa-file-invoice mr-1"></i>거래명세서</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($t['customer_name'] ?? '—'); ?></div>
                                    <?php if (!empty($t['customer_phone'])): ?><div class="text-xs text-gray-500"><?php echo htmlspecialchars($t['customer_phone']); ?></div><?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php if ($t['item_count'] === null): ?>
                                        <span class="text-xs text-gray-400">—</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800"><?php echo number_format($t['item_count']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900"><?php echo number_format($t['amount'], 2); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php
                                    $fa = (float)$t['amount'];
                                    $applied = (float)($t['applied'] ?? 0);
                                    if ($applied >= $fa - 0.005 && $fa > 0) {
                                        echo '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800"><i class="fas fa-check-circle mr-1"></i>' . htmlspecialchars(t('credit_transactions.badge_paid')) . '</span>';
                                    } elseif ($applied > 0) {
                                        $partial_title = str_replace(['{applied}', '{total}'], [number_format($applied, 2), number_format($fa, 2)], t('credit_transactions.partial_tooltip'));
                                        echo '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800" title="' . htmlspecialchars($partial_title) . '"><i class="fas fa-adjust mr-1"></i>' . htmlspecialchars(t('credit_transactions.badge_partial')) . '</span>';
                                    } else {
                                        echo '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800"><i class="fas fa-exclamation-circle mr-1"></i>' . htmlspecialchars(t('credit_transactions.badge_unpaid')) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900"><?php echo htmlspecialchars($t['user_name'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                <div class="text-sm text-gray-500"><?php echo htmlspecialchars(str_replace(
                    ['{total}', '{start}', '{end}'],
                    [number_format($total_tx), number_format(($page - 1) * $per_page + 1), number_format(min($page * $per_page, $total_tx))],
                    t('credit_transactions.pagination_showing')
                )); ?></div>
                <div class="flex items-center space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"><?php echo htmlspecialchars(t('common.previous')); ?></a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="px-3 py-2 text-sm font-medium text-white bg-primary-600 border border-primary-600 rounded-md"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"><?php echo htmlspecialchars(t('common.next')); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- 수금 입력 모달 -->
<div id="payment-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900"><i class="fas fa-hand-holding-usd mr-2 text-blue-500"></i><?php echo htmlspecialchars(t('credit_transactions.modal_title')); ?></h3>
                <button type="button" id="close-payment-modal" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="p-4">
                <div id="payment-error" class="hidden mb-3 p-3 bg-red-50 border border-red-200 rounded-md text-sm text-red-700"></div>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('credit_transactions.modal_customer')); ?> <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="text" id="pay_customer_search" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="<?php echo htmlspecialchars(t('credit_transactions.modal_customer_search')); ?>" autocomplete="off">
                            <div id="pay_customer_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-48 overflow-y-auto hidden"></div>
                        </div>
                        <input type="hidden" id="pay_customer_id" value="">
                        <div id="pay_selected_balance" class="hidden text-xs text-gray-600 mt-1"></div>
                    </div>

                    <!-- 미결제 내역 체크리스트 (선택한 건만 수금 처리) -->
                    <div id="pay_unpaid_loading" class="hidden text-xs text-gray-400 px-1"><i class="fas fa-spinner fa-spin mr-1"></i>불러오는 중...</div>
                    <div id="pay_unpaid_empty" class="hidden text-xs text-gray-400 px-1">미결제 내역이 없습니다.</div>
                    <div id="pay_unpaid_wrap" class="hidden border border-gray-200 rounded-md overflow-hidden">
                        <div class="flex items-center justify-between px-3 py-1.5 bg-gray-50 border-b border-gray-200">
                            <label class="flex items-center gap-1.5 text-xs font-medium text-gray-600 cursor-pointer select-none">
                                <input type="checkbox" id="pay_select_all" checked class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                                전체 선택
                            </label>
                            <span class="text-xs text-gray-400">미결제 내역 (체크한 건만 수금)</span>
                        </div>
                        <div id="pay_unpaid_list" class="max-h-40 overflow-y-auto divide-y divide-gray-100"></div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('credit_transactions.modal_date')); ?> <span class="text-red-500">*</span></label>
                            <input type="date" id="pay_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('credit_transactions.modal_amount')); ?> <span class="text-red-500">*</span></label>
                            <input type="number" id="pay_amount" min="0" step="0.01" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="0">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('credit_transactions.modal_method')); ?></label>
                        <select id="pay_method" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="현금"><?php echo htmlspecialchars(t('credit_transactions.method_cash')); ?></option>
                            <option value="계좌이체"><?php echo htmlspecialchars(t('credit_transactions.method_transfer')); ?></option>
                            <option value="카드"><?php echo htmlspecialchars(t('credit_transactions.method_card')); ?></option>
                            <option value="수표"><?php echo htmlspecialchars(t('credit_transactions.method_check')); ?></option>
                            <option value="기타"><?php echo htmlspecialchars(t('credit_transactions.method_other')); ?></option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('credit_transactions.modal_memo')); ?></label>
                        <input type="text" id="pay_notes" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="<?php echo htmlspecialchars(t('credit_transactions.modal_memo_placeholder')); ?>">
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" id="cancel-payment" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50"><?php echo htmlspecialchars(t('credit_transactions.modal_cancel')); ?></button>
                    <button type="button" id="save-payment" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700"><i class="fas fa-save mr-1"></i><?php echo htmlspecialchars(t('credit_transactions.modal_save')); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 선택 결제하기 확인 모달 -->
<div id="tx-bulk-pay-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:60;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden;">
    <div style="padding:18px 22px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;">
      <div style="font-size:15px;font-weight:700;color:#1f2937;"><i class="fas fa-money-check-alt mr-2" style="color:#059669"></i>선택 항목 수금 처리</div>
      <button type="button" onclick="closeTxBulkPay()" style="border:none;background:none;color:#9ca3af;cursor:pointer;font-size:16px;"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:20px 22px;">
      <div id="tx-bulk-pay-summary" style="font-size:13px;color:#374151;margin-bottom:14px;max-height:160px;overflow-y:auto;"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:4px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">수금 날짜</label>
          <input type="date" id="tx-bulk-pay-date" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">결제 수단</label>
          <select id="tx-bulk-pay-method" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;">
            <option value="현금">현금</option>
            <option value="계좌이체">계좌이체</option>
            <option value="카드">카드</option>
            <option value="수표">수표</option>
            <option value="기타">기타</option>
          </select>
        </div>
      </div>
      <div id="tx-bulk-pay-msg" style="display:none;margin-top:10px;font-size:13px;font-weight:500;"></div>
    </div>
    <div style="padding:14px 22px;background:#fafafa;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;">
      <button type="button" onclick="closeTxBulkPay()" style="padding:8px 16px;border-radius:8px;border:1px solid #d1d5db;background:#fff;font-size:13px;cursor:pointer;">취소</button>
      <button type="button" id="tx-bulk-pay-confirm-btn" onclick="confirmTxBulkPay()" style="padding:8px 18px;border-radius:8px;border:none;background:#059669;color:#fff;font-size:13px;font-weight:600;cursor:pointer;"><i class="fas fa-check mr-1"></i>수금 처리</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('payment-modal');
    const openBtn = document.getElementById('open-payment-modal');
    const closeBtn = document.getElementById('close-payment-modal');
    const cancelBtn = document.getElementById('cancel-payment');
    const saveBtn = document.getElementById('save-payment');
    const errBox = document.getElementById('payment-error');

    const custSearch = document.getElementById('pay_customer_search');
    const custResults = document.getElementById('pay_customer_results');
    const custId = document.getElementById('pay_customer_id');
    const balanceHint = document.getElementById('pay_selected_balance');
    const payAmount = document.getElementById('pay_amount');
    const unpaidLoading = document.getElementById('pay_unpaid_loading');
    const unpaidEmpty = document.getElementById('pay_unpaid_empty');
    const unpaidWrap = document.getElementById('pay_unpaid_wrap');
    const unpaidList = document.getElementById('pay_unpaid_list');
    const selectAllCb = document.getElementById('pay_select_all');
    const SEL_MONTH = '<?php echo addslashes($sel_month); ?>';
    let searchTimer;

    function fmtNum(v) {
        const n = Number(v) || 0;
        return Number.isInteger(n) ? n.toLocaleString() : (Math.round(n * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 });
    }

    function resetUnpaidSection() {
        unpaidLoading.classList.add('hidden');
        unpaidEmpty.classList.add('hidden');
        unpaidWrap.classList.add('hidden');
        unpaidList.innerHTML = '';
        selectAllCb.checked = true;
    }

    function recalcAmountFromSelection() {
        const boxes = unpaidList.querySelectorAll('.unpaid-item-cb');
        let sum = 0, checkedCount = 0;
        boxes.forEach(function(cb) { if (cb.checked) { sum += parseFloat(cb.dataset.remaining) || 0; checkedCount++; } });
        payAmount.value = sum > 0 ? (Math.round(sum * 100) / 100) : '';
        selectAllCb.checked = boxes.length > 0 && checkedCount === boxes.length;
        balanceHint.textContent = '<?php echo addslashes(t('credit_transactions.js_current_balance')); ?>' + fmtNum(sum);
        balanceHint.classList.remove('hidden');
    }

    function renderUnpaidList(items) {
        if (!items.length) {
            unpaidEmpty.classList.remove('hidden');
            unpaidWrap.classList.add('hidden');
            payAmount.value = '';
            return;
        }
        unpaidWrap.classList.remove('hidden');
        unpaidEmpty.classList.add('hidden');
        unpaidList.innerHTML = items.map(function(item) {
            const badge = item.source === 'pos'
                ? '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-700">POS</span>'
                : '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-700">거래명세서</span>';
            const label = item.label ? `<span class="text-gray-400 ml-1">${escapeHtml(item.label)}</span>` : '';
            return `<label class="flex items-center gap-2 px-3 py-2 text-xs hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" class="unpaid-item-cb rounded border-gray-300 text-primary-600 focus:ring-primary-500" data-remaining="${item.remaining}" checked>
                <span class="flex-1 min-w-0 truncate">${badge}<span class="text-gray-700 ml-1">${escapeHtml(item.tdate)}</span>${label}</span>
                <span class="text-right font-mono text-red-600 flex-shrink-0">${fmtNum(item.remaining)}</span>
            </label>`;
        }).join('');
        recalcAmountFromSelection();
    }

    function loadUnpaidList(customerId) {
        resetUnpaidSection();
        if (!customerId) return;
        unpaidLoading.classList.remove('hidden');
        fetch('ajax_credit_customer_unpaid.php?customer_id=' + encodeURIComponent(customerId) + '&month=' + encodeURIComponent(SEL_MONTH))
            .then(r => r.json())
            .then(data => {
                unpaidLoading.classList.add('hidden');
                if (!data.success) { unpaidEmpty.textContent = data.message || '미결제 내역을 불러오지 못했습니다.'; unpaidEmpty.classList.remove('hidden'); return; }
                renderUnpaidList(data.items || []);
            })
            .catch(() => { unpaidLoading.classList.add('hidden'); unpaidEmpty.textContent = '미결제 내역을 불러오지 못했습니다.'; unpaidEmpty.classList.remove('hidden'); });
    }

    unpaidList.addEventListener('change', function(e) {
        if (e.target.classList.contains('unpaid-item-cb')) recalcAmountFromSelection();
    });
    selectAllCb.addEventListener('change', function() {
        unpaidList.querySelectorAll('.unpaid-item-cb').forEach(function(cb) { cb.checked = selectAllCb.checked; });
        recalcAmountFromSelection();
    });

    function openModal(preId, preName, preBalance) {
        errBox.classList.add('hidden');
        custId.value = preId || '';
        custSearch.value = preName || '';
        custResults.classList.add('hidden');
        payAmount.value = '';
        document.getElementById('pay_notes').value = '';
        resetUnpaidSection();
        if (preId) {
            loadUnpaidList(preId);
        } else {
            balanceHint.classList.add('hidden');
        }
        modal.classList.remove('hidden');
    }
    function closeModal() { modal.classList.add('hidden'); }

    openBtn.addEventListener('click', function() { openModal(); custSearch.focus(); });
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });

    // 거래처별 행의 "수금" 버튼
    document.querySelectorAll('.pay-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            openModal(this.dataset.id, this.dataset.name, this.dataset.balance);
            document.getElementById('pay_amount').focus();
        });
    });

    custSearch.addEventListener('input', function() {
        custId.value = '';
        balanceHint.classList.add('hidden');
        resetUnpaidSection();
        const q = this.value.trim();
        clearTimeout(searchTimer);
        if (q.length < 1) { custResults.classList.add('hidden'); return; }
        searchTimer = setTimeout(function() {
            fetch('ajax_search_credit_customers.php', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'q=' + encodeURIComponent(q) + '&limit=10'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.customers && data.customers.length > 0) {
                    let html = '';
                    data.customers.forEach(function(c) {
                        html += `<div class="pay-cust-item p-2 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0" data-id="${c.id}" data-name="${(c.name||'').replace(/"/g,'&quot;')}">
                            <div class="text-sm font-medium text-gray-900">${escapeHtml(c.name)}</div>
                            <div class="text-xs text-gray-500">${escapeHtml(c.phone || '')}</div></div>`;
                    });
                    custResults.innerHTML = html;
                    custResults.classList.remove('hidden');
                    custResults.querySelectorAll('.pay-cust-item').forEach(function(it) {
                        it.addEventListener('click', function() {
                            custId.value = this.dataset.id;
                            custSearch.value = this.dataset.name;
                            custResults.classList.add('hidden');
                            loadUnpaidList(this.dataset.id);
                        });
                    });
                } else {
                    custResults.innerHTML = '<div class="p-2 text-sm text-gray-500"><?php echo addslashes(t('credit_transactions.js_no_results')); ?></div>';
                    custResults.classList.remove('hidden');
                }
            });
        }, 250);
    });

    document.addEventListener('click', function(e) {
        if (!custSearch.contains(e.target) && !custResults.contains(e.target)) custResults.classList.add('hidden');
    });

    saveBtn.addEventListener('click', function() {
        const customer_id = custId.value;
        const amount = parseFloat(document.getElementById('pay_amount').value) || 0;
        const payment_date = document.getElementById('pay_date').value;
        const method = document.getElementById('pay_method').value;
        const notes = document.getElementById('pay_notes').value.trim();

        if (!customer_id) { showErr('<?php echo addslashes(t('credit_transactions.js_select_customer')); ?>'); return; }
        if (amount <= 0) { showErr('<?php echo addslashes(t('credit_transactions.js_enter_amount')); ?>'); return; }
        errBox.classList.add('hidden');

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i><?php echo addslashes(t('credit_transactions.js_saving')); ?>';
        fetch('ajax_save_credit_payment.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `customer_id=${encodeURIComponent(customer_id)}&amount=${encodeURIComponent(amount)}&payment_date=${encodeURIComponent(payment_date)}&method=${encodeURIComponent(method)}&notes=${encodeURIComponent(notes)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) { location.reload(); }
            else { showErr(data.message || '<?php echo addslashes(t('credit_transactions.js_save_error')); ?>'); saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save mr-1"></i><?php echo addslashes(t('credit_transactions.modal_save')); ?>'; }
        })
        .catch(() => { showErr('<?php echo addslashes(t('credit_transactions.js_server_error')); ?>'); saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-save mr-1"></i><?php echo addslashes(t('credit_transactions.modal_save')); ?>'; });
    });

    function showErr(msg) { errBox.textContent = msg; errBox.classList.remove('hidden'); }
    function escapeHtml(str) { return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
});

// 선택해서 결제하기 (외상거래 목록 - 체크박스로 여러 건을 골라 수금 처리)
document.addEventListener('DOMContentLoaded', function() {
    const txSelectAll = document.getElementById('tx-select-all-checkbox');
    const txBulkBtn = document.getElementById('tx-bulk-pay-btn');
    const txBulkCount = document.getElementById('tx-bulk-pay-count');
    if (!txBulkBtn) return;

    function updateTxBulkButton() {
        const checked = document.querySelectorAll('.tx-select-checkbox:checked');
        txBulkCount.textContent = checked.length;
        txBulkBtn.disabled = checked.length === 0;
    }

    if (txSelectAll) {
        txSelectAll.addEventListener('change', function() {
            document.querySelectorAll('.tx-select-checkbox').forEach(function(cb) { cb.checked = txSelectAll.checked; });
            updateTxBulkButton();
        });
    }
    document.querySelectorAll('.tx-select-checkbox').forEach(function(cb) {
        cb.addEventListener('change', updateTxBulkButton);
    });
    updateTxBulkButton();

    txBulkBtn.addEventListener('click', function() {
        const checked = document.querySelectorAll('.tx-select-checkbox:checked');
        if (!checked.length) return;

        // 거래처별로 선택한 항목의 잔액을 합산 (수금은 거래처 단위로 한 건씩 등록됨)
        const groups = {};
        checked.forEach(function(cb) {
            const cid = cb.dataset.customerId;
            if (!groups[cid]) groups[cid] = { name: cb.dataset.customerName, total: 0 };
            groups[cid].total += parseFloat(cb.dataset.remaining) || 0;
        });

        const esc = function(str) { return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
        let html = '';
        Object.keys(groups).forEach(function(cid) {
            const g = groups[cid];
            const total = Math.round(g.total * 100) / 100;
            html += '<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f3f4f6;">' +
                '<span>' + esc(g.name || ('#' + cid)) + '</span>' +
                '<span style="font-weight:600;">' + total.toLocaleString() + '</span></div>';
        });
        document.getElementById('tx-bulk-pay-summary').innerHTML = html;
        document.getElementById('tx-bulk-pay-date').value = new Date().toISOString().slice(0, 10);
        document.getElementById('tx-bulk-pay-msg').style.display = 'none';
        document.getElementById('tx-bulk-pay-modal').style.display = 'flex';
    });
});

function closeTxBulkPay() {
    document.getElementById('tx-bulk-pay-modal').style.display = 'none';
}

function confirmTxBulkPay() {
    const checked = document.querySelectorAll('.tx-select-checkbox:checked');
    if (!checked.length) { closeTxBulkPay(); return; }

    const groups = {};
    checked.forEach(function(cb) {
        const cid = cb.dataset.customerId;
        groups[cid] = (groups[cid] || 0) + (parseFloat(cb.dataset.remaining) || 0);
    });

    const date = document.getElementById('tx-bulk-pay-date').value;
    const method = document.getElementById('tx-bulk-pay-method').value;
    const msg = document.getElementById('tx-bulk-pay-msg');
    const confirmBtn = document.getElementById('tx-bulk-pay-confirm-btn');
    msg.style.display = 'none';
    confirmBtn.disabled = true;

    const requests = Object.keys(groups).map(function(cid) {
        const amount = Math.round(groups[cid] * 100) / 100;
        const params = 'customer_id=' + encodeURIComponent(cid) +
            '&amount=' + encodeURIComponent(amount) +
            '&payment_date=' + encodeURIComponent(date) +
            '&method=' + encodeURIComponent(method) +
            '&notes=' + encodeURIComponent('선택 결제하기 일괄 처리');
        return fetch('ajax_save_credit_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params
        }).then(function(r) { return r.json(); });
    });

    Promise.all(requests).then(function(results) {
        const failed = results.filter(function(r) { return !r.success; });
        if (failed.length === 0) {
            location.reload();
        } else {
            confirmBtn.disabled = false;
            msg.style.display = 'block'; msg.style.color = '#dc2626';
            msg.textContent = failed.length + '건 처리 실패: ' + (failed[0].message || '오류');
        }
    })
    .catch(function() {
        confirmBtn.disabled = false;
        msg.style.display = 'block'; msg.style.color = '#dc2626'; msg.textContent = '네트워크 오류가 발생했습니다.';
    });
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
