<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '매입 통계 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();

// 조회 방식: calendar(이번 달 1일~말일, 기본값) / cycle26(전월 26일~이번달 25일 정산주기)
$view_mode = (($_GET['view'] ?? '') === 'cycle26') ? 'cycle26' : 'calendar';

$today_year = (int)date('Y');
$today_month = (int)date('n');
$today_day = (int)date('j');

// 최근 3개 구간 계산 (조회 방식에 따라 달력월 또는 26일 정산주기)
$periods = [];
if ($view_mode === 'cycle26') {
    // 오늘이 26일 이상이면 이번 달 26일이 현재 주기의 시작, 26일 미만이면 지난 달 26일이 현재 주기의 시작.
    $cycle_start_year = $today_year;
    $cycle_start_month = $today_month;
    if ($today_day < 26) {
        $cycle_start_month--;
        if ($cycle_start_month <= 0) {
            $cycle_start_month += 12;
            $cycle_start_year--;
        }
    }
    for ($i = 0; $i < 3; $i++) {
        $sy = $cycle_start_year;
        $sm = $cycle_start_month - $i;
        while ($sm <= 0) {
            $sm += 12;
            $sy--;
        }
        $ey = $sy;
        $em = $sm + 1;
        if ($em > 12) {
            $em -= 12;
            $ey++;
        }
        $periods[] = [
            'start' => sprintf('%04d-%02d-26', $sy, $sm),
            'end' => sprintf('%04d-%02d-25', $ey, $em),
            'label' => "{$sy}년 {$sm}월26일~{$em}월25일",
            'short_label' => "{$sm}/26~{$em}/25",
        ];
    }
    $period_captions = ['이번 주기', '지난 주기', '2주기 전'];
    $total_label = '3주기 총 합계';
    $table_total_label = '3주기 합계';
} else {
    for ($i = 0; $i < 3; $i++) {
        $m = $today_month - $i;
        $y = $today_year;
        while ($m <= 0) {
            $m += 12;
            $y--;
        }
        $start = sprintf('%04d-%02d-01', $y, $m);
        $periods[] = [
            'start' => $start,
            'end' => date('Y-m-t', strtotime($start)),
            'label' => "{$y}년 {$m}월",
            'short_label' => "{$m}월",
        ];
    }
    $period_captions = ['이번 달', '지난 달', '2달 전'];
    $total_label = '3개월 총 합계';
    $table_total_label = '3개월 합계';
}

// 점포 필터 조건 생성
$store_filter = '';
$store_params = [];
$store_param_types = '';
if ($_SESSION['role'] !== 'super_admin' && !empty($current_store_id)) {
    $store_filter = ' AND p.store_id = ?';
    $store_params[] = $current_store_id;
    $store_param_types = 'i';
}

// deleted_at 컬럼 확인
$check_deleted_at = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
$has_deleted_at = $check_deleted_at->num_rows > 0;

// soft delete 필터 조건
$soft_delete_filter = '';
if ($has_deleted_at) {
    $soft_delete_filter = ' AND p.deleted_at IS NULL';
}

// 업체별 구간별(달력월 또는 26일 정산주기) 매입 금액 쿼리
// 날짜 범위(BETWEEN)로 비교하므로 calendar/cycle26 두 조회 방식 모두 동일한 쿼리 구조를 쓴다.
$monthly_stats_sql = "SELECT
    s.id AS supplier_id,
    s.name AS supplier_name,
    SUM(CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.total_amount ELSE 0 END) AS month1_amount,
    SUM(CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.total_amount ELSE 0 END) AS month2_amount,
    SUM(CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.total_amount ELSE 0 END) AS month3_amount,
    COUNT(DISTINCT CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.purchase_id END) AS month1_count,
    COUNT(DISTINCT CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.purchase_id END) AS month2_count,
    COUNT(DISTINCT CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.purchase_id END) AS month3_count
FROM suppliers s
LEFT JOIN purchases p ON s.id = p.supplier_id {$store_filter} {$soft_delete_filter}
GROUP BY s.id, s.name
HAVING SUM(CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.total_amount ELSE 0 END) > 0
    OR SUM(CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.total_amount ELSE 0 END) > 0
    OR SUM(CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.total_amount ELSE 0 END) > 0
ORDER BY SUM(CASE WHEN p.purchase_date BETWEEN ? AND ? THEN p.total_amount ELSE 0 END) DESC";

$monthly_stats = [];
$monthly_totals = [
    'month1' => 0, 'month2' => 0, 'month3' => 0,
    'count1' => 0, 'count2' => 0, 'count3' => 0
];

$stats_stmt = $conn->prepare($monthly_stats_sql);
if ($stats_stmt) {
    // mysqli bind_param은 SQL 텍스트에 등장하는 ? 순서대로 위치 기반 바인딩된다.
    // {$store_filter}(점포 필터의 ?)는 SELECT 절 12개 바로 다음, JOIN ON 절에 위치하므로
    // HAVING/ORDER BY보다 먼저 값을 넣어야 한다. (예전엔 store 파라미터를 배열 맨 뒤에
    // 붙여서 super_admin이 아닌 사용자는 결과가 거의 항상 비어버리는 버그가 있었다.)
    $stats_params = [
        // SELECT 절 (3개 구간 x 2(시작/끝) x 2(금액/건수) = 12개)
        $periods[0]['start'], $periods[0]['end'],
        $periods[1]['start'], $periods[1]['end'],
        $periods[2]['start'], $periods[2]['end'],
        $periods[0]['start'], $periods[0]['end'],
        $periods[1]['start'], $periods[1]['end'],
        $periods[2]['start'], $periods[2]['end'],
    ];
    $stats_types = 'ssssssssssss'; // 12개 (날짜 문자열)

    // store_filter의 ?는 SELECT 절 바로 다음(JOIN ON 절)에 나오므로 여기서 이어붙인다.
    if (!empty($store_params)) {
        $stats_params = array_merge($stats_params, $store_params);
        $stats_types .= $store_param_types;
    }

    // HAVING 절 (3개 구간 x 2 = 6개) + ORDER BY 절 (1개 구간 x 2 = 2개 - 첫번째 구간만)
    $stats_params = array_merge($stats_params, [
        $periods[0]['start'], $periods[0]['end'],
        $periods[1]['start'], $periods[1]['end'],
        $periods[2]['start'], $periods[2]['end'],
        $periods[0]['start'], $periods[0]['end'],
    ]);
    $stats_types .= 'ssssssss'; // 8개

    $stats_stmt->bind_param($stats_types, ...$stats_params);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();

    while ($row = $stats_result->fetch_assoc()) {
        $monthly_stats[] = $row;
        $monthly_totals['month1'] += $row['month1_amount'];
        $monthly_totals['month2'] += $row['month2_amount'];
        $monthly_totals['month3'] += $row['month3_amount'];
        $monthly_totals['count1'] += $row['month1_count'];
        $monthly_totals['count2'] += $row['month2_count'];
        $monthly_totals['count3'] += $row['month3_count'];
    }
    $stats_stmt->close();
}

// 전체 합계
$grand_total = $monthly_totals['month1'] + $monthly_totals['month2'] + $monthly_totals['month3'];
$grand_count = $monthly_totals['count1'] + $monthly_totals['count2'] + $monthly_totals['count3'];
?>

<style>
.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 1rem;
    padding: 1.5rem;
    color: white;
    box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
}

.stat-card.month-current {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    box-shadow: 0 10px 40px rgba(17, 153, 142, 0.3);
}

.stat-card.month-prev1 {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    box-shadow: 0 10px 40px rgba(79, 172, 254, 0.3);
}

.stat-card.month-prev2 {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    box-shadow: 0 10px 40px rgba(250, 112, 154, 0.3);
}

.stat-card.total {
    background: linear-gradient(135deg, #434343 0%, #000000 100%);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.2;
}

.stat-label {
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.5rem;
}

.stat-count {
    font-size: 0.75rem;
    opacity: 0.8;
    margin-top: 0.5rem;
}

.supplier-row:hover {
    background-color: #f0f9ff !important;
}

.amount-cell {
    font-variant-numeric: tabular-nums;
}

.progress-bar {
    height: 4px;
    background-color: rgba(255, 255, 255, 0.3);
    border-radius: 2px;
    margin-top: 0.75rem;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background-color: white;
    border-radius: 2px;
    transition: width 0.5s ease;
}
</style>

<div class="w-full px-2 sm:px-3 md:px-4 py-6">
    <!-- 페이지 헤더 -->
    <div class="mb-6">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    <i class="fas fa-chart-line text-indigo-600 mr-2"></i>
                    매입 통계
                </h1>
                <p class="text-sm text-gray-600 mt-1">업체별 매입 금액을 확인합니다</p>
            </div>
            <a href="purchase_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-2"></i>
                매입 목록
            </a>
        </div>
        <!-- 조회 방식 전환: 이번 달(달력월) / 전월 26일부터(정산주기) -->
        <div class="mt-4 inline-flex rounded-md shadow-sm" role="group">
            <a href="?view=calendar"
               class="px-4 py-2 text-sm font-medium border rounded-l-md <?php echo $view_mode === 'calendar' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                <i class="fas fa-calendar-day mr-1.5"></i>이번 달
            </a>
            <a href="?view=cycle26"
               class="px-4 py-2 text-sm font-medium border-t border-b border-r rounded-r-md <?php echo $view_mode === 'cycle26' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                <i class="fas fa-calendar-week mr-1.5"></i>전월 26일부터
            </a>
        </div>
        <?php if ($view_mode === 'cycle26'): ?>
        <p class="mt-2 text-xs text-gray-500"><i class="fas fa-circle-info mr-1"></i>매월 26일~다음 달 25일을 한 정산주기로 계산합니다. (현재 주기: <?php echo htmlspecialchars($periods[0]['label']); ?>)</p>
        <?php endif; ?>
    </div>

    <!-- 월별 요약 카드 -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- 첫번째 구간 (이번 달 / 이번 주기) -->
        <div class="stat-card month-current">
            <div class="stat-label">
                <i class="fas fa-calendar-day mr-1"></i>
                <?php echo htmlspecialchars($periods[0]['label']); ?> (<?php echo $period_captions[0]; ?>)
            </div>
            <div class="stat-value"><?php echo number_format($monthly_totals['month1'], 2); ?></div>
            <div class="stat-count"><?php echo number_format($monthly_totals['count1']); ?>건 매입</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $grand_total > 0 ? round($monthly_totals['month1'] / $grand_total * 100) : 0; ?>%"></div>
            </div>
        </div>

        <!-- 두번째 구간 (지난 달 / 지난 주기) -->
        <div class="stat-card month-prev1">
            <div class="stat-label">
                <i class="fas fa-calendar-alt mr-1"></i>
                <?php echo htmlspecialchars($periods[1]['label']); ?>
            </div>
            <div class="stat-value"><?php echo number_format($monthly_totals['month2'], 2); ?></div>
            <div class="stat-count"><?php echo number_format($monthly_totals['count2']); ?>건 매입</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $grand_total > 0 ? round($monthly_totals['month2'] / $grand_total * 100) : 0; ?>%"></div>
            </div>
        </div>

        <!-- 세번째 구간 (2달 전 / 2주기 전) -->
        <div class="stat-card month-prev2">
            <div class="stat-label">
                <i class="fas fa-calendar mr-1"></i>
                <?php echo htmlspecialchars($periods[2]['label']); ?>
            </div>
            <div class="stat-value"><?php echo number_format($monthly_totals['month3'], 2); ?></div>
            <div class="stat-count"><?php echo number_format($monthly_totals['count3']); ?>건 매입</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $grand_total > 0 ? round($monthly_totals['month3'] / $grand_total * 100) : 0; ?>%"></div>
            </div>
        </div>

        <!-- 전체 합계 -->
        <div class="stat-card total">
            <div class="stat-label">
                <i class="fas fa-calculator mr-1"></i>
                <?php echo $total_label; ?>
            </div>
            <div class="stat-value"><?php echo number_format($grand_total, 2); ?></div>
            <div class="stat-count"><?php echo number_format($grand_count); ?>건 매입 / <?php echo count($monthly_stats); ?>개 업체</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: 100%"></div>
            </div>
        </div>
    </div>

    <!-- 업체별 상세 테이블 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-300">
        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-white">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-building text-gray-600 mr-2"></i>
                    업체별 매입 금액 상세
                </h3>
                <span class="text-sm text-gray-500">
                    총 <?php echo count($monthly_stats); ?>개 업체
                </span>
            </div>
        </div>

        <?php if (!empty($monthly_stats)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-12">No</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">거래처명</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-white uppercase tracking-wider bg-green-600">
                            <?php echo htmlspecialchars($periods[0]['short_label']); ?>
                            <span class="block text-xs font-normal opacity-75">(<?php echo $period_captions[0]; ?>)</span>
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider bg-blue-50">
                            <?php echo htmlspecialchars($periods[1]['short_label']); ?>
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider bg-orange-50">
                            <?php echo htmlspecialchars($periods[2]['short_label']); ?>
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider bg-gray-100">
                            <?php echo $table_total_label; ?>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">비율</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    <?php $no = 1; ?>
                    <?php foreach ($monthly_stats as $stat): ?>
                    <?php
                        $row_total = $stat['month1_amount'] + $stat['month2_amount'] + $stat['month3_amount'];
                        $percentage = $grand_total > 0 ? round($row_total / $grand_total * 100, 1) : 0;
                    ?>
                    <tr class="supplier-row hover:bg-blue-50 transition-colors cursor-pointer"
                        onclick="window.location.href='purchase_management.php?supplier=<?php echo urlencode($stat['supplier_name']); ?>'">
                        <td class="px-4 py-3 text-sm text-gray-500 text-center"><?php echo $no++; ?></td>
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($stat['supplier_name']); ?></div>
                            <div class="text-xs text-gray-500">
                                <?php echo $stat['month1_count'] + $stat['month2_count'] + $stat['month3_count']; ?>건 매입
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right bg-green-50 amount-cell">
                            <span class="text-sm font-semibold <?php echo $stat['month1_amount'] > 0 ? 'text-green-700' : 'text-gray-400'; ?>">
                                <?php echo number_format($stat['month1_amount'], 2); ?>
                            </span>
                            <?php if ($stat['month1_count'] > 0): ?>
                            <span class="block text-xs text-green-600"><?php echo $stat['month1_count']; ?>건</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right bg-blue-50/50 amount-cell">
                            <span class="text-sm <?php echo $stat['month2_amount'] > 0 ? 'text-blue-700' : 'text-gray-400'; ?>">
                                <?php echo number_format($stat['month2_amount'], 2); ?>
                            </span>
                            <?php if ($stat['month2_count'] > 0): ?>
                            <span class="block text-xs text-blue-600"><?php echo $stat['month2_count']; ?>건</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right bg-orange-50/50 amount-cell">
                            <span class="text-sm <?php echo $stat['month3_amount'] > 0 ? 'text-orange-700' : 'text-gray-400'; ?>">
                                <?php echo number_format($stat['month3_amount'], 2); ?>
                            </span>
                            <?php if ($stat['month3_count'] > 0): ?>
                            <span class="block text-xs text-orange-600"><?php echo $stat['month3_count']; ?>건</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right bg-gray-50 amount-cell">
                            <span class="text-sm font-bold text-gray-900">
                                <?php echo number_format($row_total, 2); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center">
                                <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                    <div class="bg-indigo-600 h-2 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                                <span class="text-xs font-medium text-gray-600"><?php echo $percentage; ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-100">
                    <tr class="font-bold">
                        <td class="px-4 py-4 text-sm text-gray-900" colspan="2">
                            <i class="fas fa-sigma mr-1"></i> 총 합계
                        </td>
                        <td class="px-4 py-4 text-right text-sm bg-green-100 text-green-800 amount-cell">
                            <?php echo number_format($monthly_totals['month1'], 2); ?>
                            <span class="block text-xs font-normal"><?php echo $monthly_totals['count1']; ?>건</span>
                        </td>
                        <td class="px-4 py-4 text-right text-sm bg-blue-100 text-blue-800 amount-cell">
                            <?php echo number_format($monthly_totals['month2'], 2); ?>
                            <span class="block text-xs font-normal"><?php echo $monthly_totals['count2']; ?>건</span>
                        </td>
                        <td class="px-4 py-4 text-right text-sm bg-orange-100 text-orange-800 amount-cell">
                            <?php echo number_format($monthly_totals['month3'], 2); ?>
                            <span class="block text-xs font-normal"><?php echo $monthly_totals['count3']; ?>건</span>
                        </td>
                        <td class="px-4 py-4 text-right text-sm bg-gray-200 text-gray-900 amount-cell">
                            <?php echo number_format($grand_total, 2); ?>
                            <span class="block text-xs font-normal"><?php echo $grand_count; ?>건</span>
                        </td>
                        <td class="px-4 py-4 text-center text-sm text-gray-600">100%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-12">
            <div class="text-gray-500">
                <i class="fas fa-chart-bar text-4xl text-gray-400 mb-4"></i>
                <p class="text-lg">매입 데이터가 없습니다</p>
                <p class="text-sm mt-2"><?php echo htmlspecialchars($periods[2]['label']); ?> ~ <?php echo htmlspecialchars($periods[0]['label']); ?> 기간의 매입 내역이 없습니다</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- 안내 문구 -->
    <div class="mt-4 text-sm text-gray-500">
        <i class="fas fa-info-circle mr-1"></i>
        업체명을 클릭하면 해당 업체의 매입 내역을 확인할 수 있습니다.
    </div>
</div>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>
