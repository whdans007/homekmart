<?php
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/mobile_detect.php';

// 모바일 기기에서 모바일 메인으로 리다이렉트
redirect_if_mobile('mobile_main.php', true);
$page_title = t('wholesale_sales_list.title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$sales = [];
$total_sales = 0;
$total_sales_amount = 0;
$total_pages = 1;
$errors = [];
$unpaid_list = [];        // 미결제 내역 (인쇄용)
$unpaid_list_total = 0;   // 미결제 합계 금액

// 좌측 거래처 사이드바 / 거래처 필터
$filter_customer_id = (int)($_GET['customer_id'] ?? 0);
$filter_customer_name = '';
$sale_customers = [];

// 기간 네비게이션 (전체 / 이번달 / 어제 / 오늘 / 특정일) — 판매 목록에만 적용
$period = $_GET['period'] ?? 'all';
if (!in_array($period, ['all', 'month', 'yesterday', 'today'], true)) { $period = 'all'; }
// 특정 날짜 직접 선택 (YYYY-MM-DD). 설정 시 period 버튼보다 우선.
$filter_date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) { $filter_date = ''; }
if ($filter_date !== '') { $period = ''; } // 특정일 선택 시 기간 버튼 비활성
// 거래처 링크에 현재 기간/날짜를 유지하기 위한 쿼리 조각
$time_param = $filter_date !== '' ? ('date=' . $filter_date) : ($period !== '' && $period !== 'all' ? ('period=' . $period) : '');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // WHERE 조건 구성
    $where_conditions = ["1=1"];
    $params = [];
    
    // 취소된 판매 제외
    $where_conditions[] = "ws.status != 'cancelled'";
    
    // 점포 필터 (super_admin이 아닌 경우)
    if ($_SESSION['role'] !== 'super_admin') {
        $where_conditions[] = "ws.store_id = ?";
        $params[] = $current_store_id;
    }

    // 거래처 필터 (좌측 사이드바 선택)
    if ($filter_customer_id > 0) {
        $where_conditions[] = "ws.customer_id = ?";
        $params[] = $filter_customer_id;
    }

    // 기간 필터 (특정일 우선 → 전체 / 이번달 / 어제 / 오늘)
    if ($filter_date !== '') {
        $where_conditions[] = "ws.sale_date = ?";
        $params[] = $filter_date;
    } elseif ($period === 'today') {
        $where_conditions[] = "ws.sale_date = CURDATE()";
    } elseif ($period === 'yesterday') {
        $where_conditions[] = "ws.sale_date = CURDATE() - INTERVAL 1 DAY";
    } elseif ($period === 'month') {
        $where_conditions[] = "YEAR(ws.sale_date) = YEAR(CURDATE()) AND MONTH(ws.sale_date) = MONTH(CURDATE())";
    }

    $where_clause = implode(" AND ", $where_conditions);
    
    // 총 개수 + 총 판매금액 조회
    $count_sql = "
        SELECT COUNT(*) as total, COALESCE(SUM(ws.final_amount), 0) as total_amount
        FROM wholesale_sales ws
        LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
        LEFT JOIN stores s ON ws.store_id = s.id
        WHERE {$where_clause}
    ";

    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $total_sales = (int)$count_row['total'];
    $total_sales_amount = (float)$count_row['total_amount'];
    $total_pages = max(1, ceil($total_sales / $per_page));
    
    // 현재 페이지가 전체 페이지를 벗어나면 조정
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    
    $offset = max(0, ($page - 1) * $per_page);
    
    // 판매 목록 조회
    $sql = "
        SELECT 
            ws.id,
            ws.sale_date,
            ws.total_amount,
            ws.final_amount,
            ws.status,
            ws.payment_status,
            ws.created_at,
            wc.name as customer_name,
            wc.phone as customer_phone,
            s.name as store_name,
            u.full_name as user_name,
            COUNT(wsi.id) as item_count
        FROM wholesale_sales ws
        LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
        LEFT JOIN stores s ON ws.store_id = s.id
        LEFT JOIN users u ON ws.user_id = u.id
        LEFT JOIN wholesale_sale_items wsi ON ws.id = wsi.sale_id
        WHERE {$where_clause}
        GROUP BY ws.id
        ORDER BY ws.id DESC
        LIMIT " . (int)$per_page . " OFFSET " . (int)$offset . "
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 미결제 내역 (인쇄용) - 현재 필터(거래처/기간) 기준, 미결제만, 페이지네이션 없음
    try {
        $unpaid_where = $where_conditions;
        $unpaid_where[] = "COALESCE(ws.payment_status,'unpaid') <> 'paid'";
        $unpaid_sql = "
            SELECT ws.id, ws.sale_date, ws.final_amount, wc.name AS customer_name
            FROM wholesale_sales ws
            LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
            WHERE " . implode(" AND ", $unpaid_where) . "
            ORDER BY ws.sale_date ASC, ws.id ASC
        ";
        $unpaid_stmt = $pdo->prepare($unpaid_sql);
        $unpaid_stmt->execute($params);
        $unpaid_list = $unpaid_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($unpaid_list as $u) {
            $unpaid_list_total += (float)$u['final_amount'];
        }
    } catch (PDOException $e) {
        // payment_status 컬럼 부재 등 - 미결제 인쇄 비활성화
        $unpaid_list = [];
        $unpaid_list_total = 0;
    }

    // 좌측 거래처 목록 — 도매판매(취소 제외)가 있는 거래처. 점포 스코프(거래처 필터는 미적용).
    $cust_where  = ["ws.status != 'cancelled'"];
    $cust_params = [];
    if ($_SESSION['role'] !== 'super_admin') {
        $cust_where[]  = "ws.store_id = ?";
        $cust_params[] = $current_store_id;
    }
    $cust_sql = "
        SELECT wc.id, wc.name,
               COUNT(ws.id)         AS sale_count,
               SUM(ws.final_amount) AS total_amount,
               SUM(CASE WHEN COALESCE(ws.payment_status,'unpaid') <> 'paid' THEN ws.final_amount ELSE 0 END) AS unpaid_amount,
               MAX(ws.sale_date)    AS last_date
        FROM wholesale_sales ws
        JOIN wholesale_customers wc ON ws.customer_id = wc.id
        WHERE " . implode(" AND ", $cust_where) . "
        GROUP BY wc.id, wc.name
        ORDER BY total_amount DESC, wc.name ASC
    ";
    $cust_stmt = $pdo->prepare($cust_sql);
    $cust_stmt->execute($cust_params);
    $sale_customers = $cust_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 선택된 거래처명
    if ($filter_customer_id > 0) {
        foreach ($sale_customers as $c) {
            if ((int)$c['id'] === $filter_customer_id) { $filter_customer_name = $c['name']; break; }
        }
    }

} catch (PDOException $e) {
    $errors[] = t('wholesale_sales_list.database_error') . $e->getMessage();
    error_log("Wholesale sales list error: " . $e->getMessage());
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="w-full mx-auto">

        <?php if (isset($flash)): ?>
            <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                            <?php echo htmlspecialchars($flash['message']); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800"><?php echo htmlspecialchars(t('wholesale_sales_list.solve_errors')); ?></h3>
                        <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="flex gap-4 items-start">
        <!-- 좌측: 거래처 사이드바 -->
        <aside class="w-72 flex-shrink-0">
            <div class="bg-white shadow rounded-lg ring-1 ring-gray-300 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 text-sm font-semibold text-gray-800">
                    <i class="fas fa-store mr-1 text-primary-600"></i><?php echo htmlspecialchars(t('wholesale_sales_list.sidebar_customers')); ?>
                    <span class="text-xs font-normal text-gray-400">(<?php echo number_format(count($sale_customers)); ?>)</span>
                </div>
                <div class="overflow-y-auto" style="max-height:72vh">
                    <?php
                    $all_total  = array_sum(array_column($sale_customers, 'total_amount'));
                    $all_unpaid = array_sum(array_column($sale_customers, 'unpaid_amount'));
                    $all_count  = array_sum(array_column($sale_customers, 'sale_count'));
                    ?>
                    <!-- 전체 -->
                    <a href="?<?php echo $time_param; ?>" class="block px-4 py-2.5 border-b border-gray-100 hover:bg-gray-50 <?php echo $filter_customer_id === 0 ? 'bg-primary-50' : ''; ?>">
                        <div class="flex items-center justify-between">
                            <span class="text-sm <?php echo $filter_customer_id === 0 ? 'text-primary-700 font-semibold' : 'text-gray-800 font-medium'; ?>"><i class="fas fa-list mr-1.5 text-gray-400"></i><?php echo htmlspecialchars(t('wholesale_sales_list.sidebar_all')); ?></span>
                            <span class="text-xs <?php echo $filter_customer_id === 0 ? 'text-primary-500' : 'text-gray-400'; ?>"><?php echo number_format($all_count) . htmlspecialchars(t('wholesale_sales_list.count_suffix')); ?></span>
                        </div>
                        <div class="flex items-center justify-between mt-1" style="font-size:11px">
                            <span class="text-gray-500"><?php echo htmlspecialchars(t('wholesale_sales_list.label_subtotal')); ?> <span class="font-semibold text-gray-700"><?php echo number_format($all_total); ?></span></span>
                            <span class="<?php echo $all_unpaid > 0 ? 'text-red-600' : 'text-gray-400'; ?>"><?php echo htmlspecialchars(t('wholesale_sales_list.label_unpaid')); ?> <span class="font-semibold"><?php echo number_format($all_unpaid); ?></span></span>
                        </div>
                    </a>
                    <?php foreach ($sale_customers as $c): ?>
                        <?php $is_sel = $filter_customer_id === (int)$c['id']; $unpaid = (float)$c['unpaid_amount']; ?>
                        <a href="?customer_id=<?php echo (int)$c['id']; ?><?php echo $time_param !== '' ? '&' . $time_param : ''; ?>"
                           class="block px-4 py-2.5 border-b border-gray-100 hover:bg-gray-50 <?php echo $is_sel ? 'bg-primary-50' : ''; ?>">
                            <div class="flex items-center justify-between">
                                <span class="text-sm truncate mr-2 <?php echo $is_sel ? 'text-primary-700 font-semibold' : 'text-gray-800 font-medium'; ?>" title="<?php echo htmlspecialchars($c['name']); ?>"><?php echo htmlspecialchars($c['name']); ?></span>
                                <span class="text-xs flex-shrink-0 <?php echo $is_sel ? 'text-primary-500' : 'text-gray-400'; ?>"><?php echo number_format($c['sale_count']) . htmlspecialchars(t('wholesale_sales_list.count_suffix')); ?></span>
                            </div>
                            <div class="flex items-center justify-between mt-1" style="font-size:11px">
                                <span class="text-gray-500"><?php echo htmlspecialchars(t('wholesale_sales_list.label_subtotal')); ?> <span class="font-semibold text-gray-700"><?php echo number_format($c['total_amount']); ?></span></span>
                                <span class="<?php echo $unpaid > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'; ?>"><?php echo htmlspecialchars(t('wholesale_sales_list.label_unpaid')); ?> <span class="font-semibold"><?php echo number_format($unpaid); ?></span></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($sale_customers)): ?>
                        <div class="px-4 py-6 text-center text-sm text-gray-400"><?php echo htmlspecialchars(t('wholesale_sales_list.sidebar_no_customers')); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <!-- 우측: 도매판매 목록 테이블 -->
        <div class="flex-1 min-w-0">
        <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
            <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center gap-3 flex-wrap">
                <div class="flex items-center gap-3 flex-wrap">
                <h3 class="text-lg leading-6 font-semibold text-gray-900 flex items-center gap-2 flex-wrap">
                    <?php echo htmlspecialchars(t('wholesale_sales_list.title')); ?>
                    <?php if ($filter_customer_id > 0 && $filter_customer_name !== ''): ?>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-sm font-medium bg-primary-100 text-primary-800">
                            <i class="fas fa-store mr-1"></i><?php echo htmlspecialchars($filter_customer_name); ?>
                        </span>
                        <a href="?<?php echo $time_param; ?>" class="text-xs font-normal text-gray-500 hover:text-gray-700"><i class="fas fa-times-circle mr-0.5"></i><?php echo htmlspecialchars(t('wholesale_sales_list.view_all')); ?></a>
                    <?php endif; ?>
                    <span class="text-sm font-normal text-gray-500">(<?php echo htmlspecialchars(str_replace('{count}', number_format($total_sales), t('wholesale_sales_list.total_count_label'))); ?>)</span>
                    <span class="text-sm font-semibold text-gray-700"><?php echo htmlspecialchars(str_replace('{amount}', number_format($total_sales_amount, 2), t('wholesale_sales_list.total_amount_label'))); ?></span>
                </h3>
                <!-- 기간 네비게이션 -->
                <div class="flex items-center gap-2 flex-wrap">
                    <!-- 범위 버튼 -->
                    <div class="inline-flex rounded-md border border-gray-300 overflow-hidden text-sm">
                        <?php
                        $periods = ['all' => t('wholesale_sales_list.period_all'), 'month' => t('wholesale_sales_list.period_month')];
                        $nav_base = $_GET; unset($nav_base['page'], $nav_base['date']); // 범위 버튼은 특정일 해제
                        $first = true;
                        foreach ($periods as $pk => $plabel):
                            $nav_base['period'] = $pk;
                            $href = '?' . http_build_query($nav_base);
                            $active = ($filter_date === '' && $period === $pk);
                        ?>
                            <a href="<?php echo htmlspecialchars($href); ?>"
                               class="px-3 py-1.5 <?php echo $first ? '' : 'border-l border-gray-300'; ?> <?php echo $active ? 'bg-primary-600 text-white font-medium' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">
                                <?php echo $plabel; ?>
                            </a>
                        <?php $first = false; endforeach; ?>
                    </div>

                    <!-- 일자 이동 (◀ 날짜 오늘 ▶) -->
                    <?php
                    $today_str   = date('Y-m-d');
                    $cur_day     = $filter_date !== '' ? $filter_date : $today_str;
                    $prev_day    = date('Y-m-d', strtotime($cur_day . ' -1 day'));
                    $next_day    = date('Y-m-d', strtotime($cur_day . ' +1 day'));
                    $is_today    = ($cur_day === $today_str);
                    $next_future = ($next_day > $today_str);
                    $day_base    = $_GET; unset($day_base['page'], $day_base['period'], $day_base['date']);
                    $day_qs      = http_build_query($day_base);
                    $day_pre     = '?' . ($day_qs ? $day_qs . '&' : '');
                    $day_active  = ($filter_date !== '');
                    ?>
                    <div class="inline-flex items-center rounded-md border <?php echo $day_active ? 'border-primary-400 ring-1 ring-primary-200' : 'border-gray-300'; ?> overflow-hidden text-sm">
                        <a href="<?php echo htmlspecialchars($day_pre . 'date=' . $prev_day); ?>" class="px-2.5 py-1.5 bg-white text-gray-600 hover:bg-gray-50" title="<?php echo htmlspecialchars(t('wholesale_sales_list.prev_day')); ?>"><i class="fas fa-chevron-left"></i></a>
                        <input type="date" max="<?php echo $today_str; ?>" value="<?php echo htmlspecialchars($cur_day); ?>"
                               onchange="location.href='<?php echo htmlspecialchars($day_pre); ?>date=' + this.value"
                               class="border-l border-r border-gray-300 px-2 py-1 text-sm focus:outline-none <?php echo $day_active ? 'text-primary-700 font-medium' : 'text-gray-600'; ?>" title="<?php echo htmlspecialchars(t('wholesale_sales_list.select_date')); ?>">
                        <a href="<?php echo htmlspecialchars($day_pre . 'date=' . $today_str); ?>" class="px-3 py-1.5 border-l border-gray-300 <?php echo ($day_active && $is_today) ? 'bg-primary-600 text-white font-medium' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>"><?php echo htmlspecialchars(t('wholesale_sales_list.today')); ?></a>
                        <a href="<?php echo $next_future ? 'javascript:void(0)' : htmlspecialchars($day_pre . 'date=' . $next_day); ?>"
                           class="px-2.5 py-1.5 border-l border-gray-300 bg-white text-gray-600 hover:bg-gray-50 <?php echo $next_future ? 'opacity-30 pointer-events-none' : ''; ?>" title="<?php echo htmlspecialchars(t('wholesale_sales_list.next_day')); ?>"><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
                </div>
                <div class="flex items-center gap-2">
                    <?php if (!empty($unpaid_list)): ?>
                    <button type="button" id="unpaid-print-btn"
                       class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 transition-colors duration-200">
                        <i class="fas fa-file-invoice-dollar mr-2"></i>
                        미결제 내역 인쇄 (<?php echo count($unpaid_list); ?>)
                    </button>
                    <?php endif; ?>
                    <button type="button" id="quick-register-btn"
                       class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200">
                        <i class="fas fa-bolt mr-2"></i>
                        빠른등록
                    </button>
                    <a href="wholesale_sales.php"
                       class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                        <i class="fas fa-plus mr-2"></i>
                        <?php echo htmlspecialchars(t('wholesale_sales_list.new_sale_button')); ?>
                    </a>
                </div>
            </div>



            
            <?php if (empty($sales)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-shopping-cart text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo htmlspecialchars(t('wholesale_sales_list.no_sales_message')); ?></h3>
                    <p class="text-gray-500 mb-6"><?php echo htmlspecialchars(t('wholesale_sales_list.no_sales_description')); ?></p>
                    <a href="wholesale_sales.php" 
                       class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                        <i class="fas fa-plus mr-2"></i>
                        <?php echo htmlspecialchars(t('wholesale_sales_list.new_sale_button')); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo htmlspecialchars(t('wholesale_sales_list.table_sale_date')); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo htmlspecialchars(t('wholesale_sales_list.table_customer')); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo htmlspecialchars(t('wholesale_sales_list.table_item_count')); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo htmlspecialchars(t('wholesale_sales_list.table_sale_amount')); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo htmlspecialchars(t('wholesale_sales_list.payment_header')); ?>
                                </th>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    <?php echo htmlspecialchars(t('wholesale_sales_list.table_seller')); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white">
                            <?php foreach ($sales as $sale): ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="window.location.href='wholesale_sale_preview.php?id=<?php echo $sale['id']; ?>'">
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900 font-medium">
                                        <?php echo date('Y-m-d', strtotime($sale['sale_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($sale['customer_name']); ?>
                                        </div>
                                        <?php if (!empty($sale['customer_phone'])): ?>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($sale['customer_phone']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?php echo number_format($sale['item_count']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                        <?php echo fmt_num($sale['final_amount']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <?php if (($sale['payment_status'] ?? 'unpaid') === 'paid'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800"><i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars(t('wholesale_sales_list.payment_done')); ?></span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800"><i class="fas fa-exclamation-circle mr-1"></i><?php echo htmlspecialchars(t('wholesale_sales_list.payment_unpaid')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        <?php echo htmlspecialchars($sale['user_name']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-500">
                        <?php echo str_replace(
                            ['{total}', '{start}', '{end}'],
                            [number_format($total_sales), number_format(($page - 1) * $per_page + 1), number_format(min($page * $per_page, $total_sales))],
                            t('wholesale_sales_list.pagination_showing')
                        ); ?>
                    </div>
                    
                    <div class="flex items-center space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                               class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                <?php echo htmlspecialchars(t('wholesale_sales_list.pagination_previous')); ?>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="px-3 py-2 text-sm font-medium text-white bg-primary-600 border border-primary-600 rounded-md">
                                    <?php echo $i; ?>
                                </span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                               class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                <?php echo htmlspecialchars(t('wholesale_sales_list.pagination_next')); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        </div><!-- /우측 flex-1 -->
        </div><!-- /flex 2단 레이아웃 -->
    </div>
</div>

<!-- 빠른등록 모달: 품목 없이 거래처+금액만으로 Whole Sale 등록 (daily_entry.php §4 POS 버튼과 동일한 취지) -->
<div id="quick-register-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:60;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden;">
    <div style="padding:18px 22px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;">
      <div style="font-size:15px;font-weight:700;color:#1f2937;"><i class="fas fa-bolt mr-2" style="color:#4f46e5"></i>빠른등록 <span style="font-size:12px;font-weight:400;color:#9ca3af">(품목 없이 거래처+금액)</span></div>
      <button type="button" onclick="closeQuickRegister()" style="border:none;background:none;color:#9ca3af;cursor:pointer;font-size:16px;"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:20px 22px;">
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">거래처</label>
        <div style="position:relative;">
          <input type="text" id="qr-customer-search" autocomplete="off" placeholder="거래처 검색 (등록된 거래처만)" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;">
          <div id="qr-customer-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 6px 16px rgba(0,0,0,.10);z-index:20;max-height:180px;overflow-y:auto;margin-top:3px;"></div>
        </div>
        <input type="hidden" id="qr-customer-id" value="">
      </div>
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">판매 날짜</label>
        <input type="date" id="qr-sale-date" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:4px;">
        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">금액</label>
        <input type="number" id="qr-amount" min="0" step="0.01" placeholder="0.00" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;text-align:right;box-sizing:border-box;">
      </div>
      <div id="qr-msg" style="display:none;margin-top:10px;font-size:13px;font-weight:500;"></div>
    </div>
    <div style="padding:14px 22px;background:#fafafa;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:10px;">
      <button type="button" onclick="closeQuickRegister()" style="padding:8px 16px;border-radius:8px;border:1px solid #d1d5db;background:#fff;font-size:13px;cursor:pointer;">취소</button>
      <button type="button" onclick="saveQuickRegister()" style="padding:8px 18px;border-radius:8px;border:none;background:#4f46e5;color:#fff;font-size:13px;font-weight:600;cursor:pointer;"><i class="fas fa-check mr-1"></i>등록</button>
    </div>
  </div>
</div>

<?php if (!empty($unpaid_list)): ?>
<!-- 미결제 내역서 (인쇄용 - 숨김) -->
<div id="unpaid-print-area" style="display:none;">
    <h1 style="text-align:center; font-size:18px; font-weight:bold; margin-bottom:8px;">미결제 내역서</h1>
    <div style="text-align:center; font-size:12px; margin-bottom:12px;">
        <?php if ($filter_customer_id > 0 && $filter_customer_name !== ''): ?>
            거래처: <strong><?php echo htmlspecialchars($filter_customer_name); ?></strong> &nbsp;|&nbsp;
        <?php endif; ?>
        발행일: <?php echo date('Y-m-d'); ?>
    </div>
    <table style="width:100%; border-collapse:collapse; border:1px solid #000;">
        <thead>
            <tr style="background:#f3f4f6;">
                <th style="border:1px solid #000; padding:5px 8px; text-align:center;">날짜</th>
                <?php if ($filter_customer_id === 0): ?>
                <th style="border:1px solid #000; padding:5px 8px; text-align:left;">거래처</th>
                <?php endif; ?>
                <th style="border:1px solid #000; padding:5px 8px; text-align:right;">금액</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($unpaid_list as $u): ?>
            <tr>
                <td style="border:1px solid #000; padding:5px 8px; text-align:center;"><?php echo date('Y-m-d', strtotime($u['sale_date'])); ?></td>
                <?php if ($filter_customer_id === 0): ?>
                <td style="border:1px solid #000; padding:5px 8px; text-align:left;"><?php echo htmlspecialchars($u['customer_name']); ?></td>
                <?php endif; ?>
                <td style="border:1px solid #000; padding:5px 8px; text-align:right;"><?php echo fmt_num($u['final_amount']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f3f4f6; font-weight:bold;">
                <td style="border:1px solid #000; padding:6px 8px; text-align:right;" colspan="<?php echo $filter_customer_id === 0 ? 2 : 1; ?>">합계:</td>
                <td style="border:1px solid #000; padding:6px 8px; text-align:right; font-size:15px;"><?php echo fmt_num($unpaid_list_total); ?></td>
            </tr>
        </tfoot>
    </table>
    <div style="text-align:right; font-size:11px; margin-top:8px;">발행일시: <?php echo date('Y-m-d H:i'); ?></div>
</div>
<?php endif; ?>

<script>
// 미결제 내역 인쇄
document.addEventListener('DOMContentLoaded', function() {
    const unpaidBtn = document.getElementById('unpaid-print-btn');
    if (!unpaidBtn) return;
    unpaidBtn.addEventListener('click', function() {
        const area = document.getElementById('unpaid-print-area');
        if (!area) return;
        const frame = document.createElement('iframe');
        frame.style.position = 'absolute';
        frame.style.top = '-10000px';
        frame.style.left = '-10000px';
        document.body.appendChild(frame);
        const doc = frame.contentDocument || frame.contentWindow.document;
        doc.open();
        doc.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>미결제 내역서</title>' +
            '<style>body{font-family:"Malgun Gothic",sans-serif;font-size:12px;padding:8mm;color:#000;}' +
            'h1{font-size:18px;} table{border-collapse:collapse;} @page{size:A4;margin:10mm;}</style>' +
            '</head><body>' + area.innerHTML + '</body></html>');
        doc.close();
        frame.onload = function() {
            setTimeout(function() {
                frame.contentWindow.focus();
                frame.contentWindow.print();
                setTimeout(function() { document.body.removeChild(frame); }, 1000);
            }, 300);
        };
    });
});

function cancelSale(saleId) {
    if (confirm('<?php echo addslashes(t('wholesale_sales_list.js_cancel_confirm')); ?>')) {
        fetch('ajax_cancel_wholesale_sale.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'sale_id=' + encodeURIComponent(saleId)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('<?php echo addslashes(t('wholesale_sales_list.js_cancel_success')); ?>');
                location.reload();
            } else {
                alert('<?php echo addslashes(t('wholesale_sales_list.js_cancel_error')); ?>' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('<?php echo addslashes(t('wholesale_sales_list.js_network_error')); ?>');
        });
    }
}
</script>

<script>
// 빠른등록 모달 (품목 없이 거래처+금액만으로 Whole Sale 등록)
function openQuickRegister(){
    document.getElementById('qr-customer-search').value = '';
    document.getElementById('qr-customer-id').value = '';
    document.getElementById('qr-sale-date').value = new Date().toISOString().slice(0,10);
    document.getElementById('qr-amount').value = '';
    document.getElementById('qr-msg').style.display = 'none';
    document.getElementById('quick-register-modal').style.display = 'flex';
}
function closeQuickRegister(){
    document.getElementById('quick-register-modal').style.display = 'none';
}
function saveQuickRegister(){
    const customerId = document.getElementById('qr-customer-id').value;
    const saleDate = document.getElementById('qr-sale-date').value;
    const amount = parseFloat(document.getElementById('qr-amount').value) || 0;
    const msg = document.getElementById('qr-msg');
    msg.style.display = 'none';

    if (!customerId) { msg.style.display = 'block'; msg.style.color = '#dc2626'; msg.textContent = '거래처를 검색해서 선택해주세요.'; return; }
    if (!saleDate) { msg.style.display = 'block'; msg.style.color = '#dc2626'; msg.textContent = '판매 날짜를 입력해주세요.'; return; }
    if (amount <= 0) { msg.style.display = 'block'; msg.style.color = '#dc2626'; msg.textContent = '금액을 입력해주세요.'; return; }

    fetch('ajax_quick_register_wholesale_sale.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'customer_id=' + encodeURIComponent(customerId) + '&sale_date=' + encodeURIComponent(saleDate) + '&amount=' + encodeURIComponent(amount)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            window.location.href = 'wholesale_sale_preview.php?id=' + d.sale_id;
        } else {
            msg.style.display = 'block'; msg.style.color = '#dc2626'; msg.textContent = d.message || '등록 실패';
        }
    })
    .catch(function(){ msg.style.display = 'block'; msg.style.color = '#dc2626'; msg.textContent = '네트워크 오류가 발생했습니다.'; });
}
document.addEventListener('DOMContentLoaded', function(){
    const btn = document.getElementById('quick-register-btn');
    if (btn) btn.addEventListener('click', openQuickRegister);

    let qrSearchTimer = null;
    const qrInput = document.getElementById('qr-customer-search');
    const qrResults = document.getElementById('qr-customer-results');
    if (qrInput) {
        qrInput.addEventListener('input', function(){
            document.getElementById('qr-customer-id').value = '';
            clearTimeout(qrSearchTimer);
            const q = this.value.trim();
            if (q.length < 1) { qrResults.style.display = 'none'; qrResults.innerHTML = ''; return; }
            qrSearchTimer = setTimeout(function(){
                fetch('ajax_search_wholesale_customers.php?q=' + encodeURIComponent(q) + '&limit=10')
                    .then(r => r.json())
                    .then(d => {
                        const items = (d.success && d.customers) ? d.customers : [];
                        if (!items.length) {
                            qrResults.innerHTML = '<div style="padding:8px 10px;font-size:12px;color:#9ca3af">검색 결과 없음</div>';
                        } else {
                            qrResults.innerHTML = items.map(function(c){
                                const safeName = String(c.name).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                const safePhone = c.phone ? String(c.phone).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : '';
                                return '<div class="qr-cust-item" data-id="' + c.id + '" data-name="' + safeName + '" style="padding:8px 10px;font-size:13px;cursor:pointer;border-bottom:1px solid #f3f4f6;">' +
                                    safeName + (safePhone ? ' <span style="color:#9ca3af;font-size:11px;">' + safePhone + '</span>' : '') +
                                '</div>';
                            }).join('');
                            qrResults.querySelectorAll('.qr-cust-item').forEach(function(el){
                                el.addEventListener('mousedown', function(){
                                    qrInput.value = el.dataset.name;
                                    document.getElementById('qr-customer-id').value = el.dataset.id;
                                    qrResults.style.display = 'none';
                                    qrResults.innerHTML = '';
                                });
                            });
                        }
                        qrResults.style.display = 'block';
                    })
                    .catch(function(){ qrResults.style.display = 'none'; });
            }, 250);
        });
        qrInput.addEventListener('blur', function(){
            setTimeout(function(){ qrResults.style.display = 'none'; }, 150);
        });
    }
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>