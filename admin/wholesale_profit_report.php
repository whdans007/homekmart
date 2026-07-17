<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '도매 이익 리포트 - ' . t('company.name');
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

// 기간 필터 (기본: 이번 달 1일 ~ 오늘)
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to = date('Y-m-d'); }

$filter_customer_id = (int)($_GET['customer_id'] ?? 0);

$vendor_rows = [];
$product_rows = [];
$vendor_totals = ['revenue' => 0, 'cost' => 0, 'profit' => 0];
$product_totals = ['revenue' => 0, 'cost' => 0, 'profit' => 0];
$customers = [];
$errors = [];
$unknown_cost_sale_count = 0;
$unknown_cost_item_count = 0;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 반품/원가 관련 컬럼 마이그레이션 적용 여부 확인 (하위 호환)
    $has_returned_amount = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'returned_amount'")->rowCount() > 0;
    $has_cost_amount     = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'cost_amount'")->rowCount() > 0;
    $has_returned_qty    = $pdo->query("SHOW COLUMNS FROM wholesale_sale_items LIKE 'returned_quantity'")->rowCount() > 0;

    $returned_amount_expr = $has_returned_amount ? "COALESCE(ws.returned_amount,0)" : "0";
    $cost_amount_expr     = $has_cost_amount ? "ws.cost_amount" : "NULL";
    $returned_qty_expr    = $has_returned_qty ? "COALESCE(wsi.returned_quantity,0)" : "0";

    // 공통 WHERE 조건
    $where = ["ws.status != 'cancelled'", "ws.sale_date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];

    if ($_SESSION['role'] !== 'super_admin') {
        $where[] = "ws.store_id = ?";
        $params[] = $current_store_id;
    }
    if ($filter_customer_id > 0) {
        $where[] = "ws.customer_id = ?";
        $params[] = $filter_customer_id;
    }
    $where_clause = implode(' AND ', $where);

    // 거래처 드롭다운 목록 (기간과 무관하게 점포 스코프의 활성 거래처)
    $cust_sql = "SELECT id, name FROM wholesale_customers WHERE is_active = 1";
    $cust_params = [];
    if ($_SESSION['role'] !== 'super_admin') {
        $cust_sql .= " AND store_id = ?";
        $cust_params[] = $current_store_id;
    }
    $cust_sql .= " ORDER BY name ASC";
    $cust_stmt = $pdo->prepare($cust_sql);
    $cust_stmt->execute($cust_params);
    $customers = $cust_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 업체별 이익: 품목이 있는 판매는 품목 원가 합계, 빠른등록(품목 없음) 판매는 cost_amount 사용
    $vendor_sql = "
        SELECT
            wc.id AS customer_id,
            wc.name AS customer_name,
            COUNT(DISTINCT ws.id) AS sale_count,
            SUM(ws.final_amount - {$returned_amount_expr}) AS revenue,
            SUM(
                CASE WHEN it.sale_id IS NOT NULL THEN COALESCE(it.item_cost, 0)
                     ELSE COALESCE({$cost_amount_expr}, 0)
                END
            ) AS cost,
            SUM(CASE WHEN it.sale_id IS NULL AND {$cost_amount_expr} IS NULL THEN 1 ELSE 0 END) AS unknown_cost_count
        FROM wholesale_sales ws
        JOIN wholesale_customers wc ON ws.customer_id = wc.id
        LEFT JOIN (
            SELECT sale_id, SUM((quantity - {$returned_qty_expr}) * COALESCE(custom_cost_price,0)) AS item_cost
            FROM wholesale_sale_items wsi
            GROUP BY sale_id
        ) it ON it.sale_id = ws.id
        WHERE {$where_clause}
        GROUP BY wc.id, wc.name
        ORDER BY revenue DESC
    ";
    $vendor_stmt = $pdo->prepare($vendor_sql);
    $vendor_stmt->execute($params);
    $vendor_rows = $vendor_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($vendor_rows as &$vr) {
        $vr['revenue'] = (float)$vr['revenue'];
        $vr['cost'] = (float)$vr['cost'];
        $vr['profit'] = $vr['revenue'] - $vr['cost'];
        $vr['margin'] = $vr['revenue'] > 0 ? ($vr['profit'] / $vr['revenue'] * 100) : 0;
        $vendor_totals['revenue'] += $vr['revenue'];
        $vendor_totals['cost'] += $vr['cost'];
        $unknown_cost_sale_count += (int)$vr['unknown_cost_count'];
    }
    unset($vr);
    $vendor_totals['profit'] = $vendor_totals['revenue'] - $vendor_totals['cost'];

    // 상품별 이익: wholesale_sale_items가 있는 판매만 대상 (빠른등록 판매는 품목이 없어 제외)
    $product_sql = "
        SELECT
            wsi.product_id,
            wsi.custom_product_name,
            COALESCE(p.sku, '') AS sku,
            COALESCE(p.name_ko, wsi.custom_product_name) AS name_ko,
            COALESCE(p.name_en, wsi.custom_product_name) AS name_en,
            SUM(wsi.quantity - {$returned_qty_expr}) AS net_qty,
            SUM((wsi.quantity - {$returned_qty_expr}) * wsi.unit_price) AS revenue,
            SUM((wsi.quantity - {$returned_qty_expr}) * COALESCE(wsi.custom_cost_price,0)) AS cost,
            SUM(CASE WHEN wsi.custom_cost_price IS NULL THEN 1 ELSE 0 END) AS unknown_cost_items
        FROM wholesale_sale_items wsi
        JOIN wholesale_sales ws ON wsi.sale_id = ws.id
        LEFT JOIN products p ON wsi.product_id = p.id
        WHERE {$where_clause}
        GROUP BY wsi.product_id, wsi.custom_product_name, COALESCE(p.name_ko, wsi.custom_product_name), COALESCE(p.name_en, wsi.custom_product_name), p.sku
        ORDER BY revenue DESC
    ";
    $product_stmt = $pdo->prepare($product_sql);
    $product_stmt->execute($params);
    $product_rows = $product_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($product_rows as &$pr) {
        $pr['revenue'] = (float)$pr['revenue'];
        $pr['cost'] = (float)$pr['cost'];
        $pr['profit'] = $pr['revenue'] - $pr['cost'];
        $pr['margin'] = $pr['revenue'] > 0 ? ($pr['profit'] / $pr['revenue'] * 100) : 0;
        $product_totals['revenue'] += $pr['revenue'];
        $product_totals['cost'] += $pr['cost'];
        $unknown_cost_item_count += (int)$pr['unknown_cost_items'];
    }
    unset($pr);
    $product_totals['profit'] = $product_totals['revenue'] - $product_totals['cost'];

} catch (PDOException $e) {
    $errors[] = '데이터 조회 중 오류가 발생했습니다: ' . $e->getMessage();
    error_log("Wholesale profit report error: " . $e->getMessage());
}

$export_query = http_build_query([
    'date_from' => $date_from,
    'date_to' => $date_to,
    'customer_id' => $filter_customer_id,
]);
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-6">
    <div class="mb-6">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    <i class="fas fa-chart-pie text-orange-600 mr-2"></i>
                    도매 이익 리포트
                </h1>
                <p class="text-sm text-gray-600 mt-1">업체별 / 상품별 도매판매 이익을 확인하고 엑셀로 다운로드합니다</p>
            </div>
            <a href="wholesale_sales_list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-2"></i>도매 판매 목록
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
            <?php foreach ($errors as $error): ?>
                <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- 필터 -->
    <form method="get" class="bg-white shadow rounded-lg ring-1 ring-gray-300 p-4 mb-6 flex items-end gap-3 flex-wrap">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">시작일</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">종료일</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">거래처</label>
            <select name="customer_id" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm min-w-[160px]">
                <option value="0">전체 거래처</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $filter_customer_id === (int)$c['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="px-4 py-1.5 bg-primary-600 text-white text-sm font-medium rounded-md hover:bg-primary-700">
            <i class="fas fa-search mr-1"></i>조회
        </button>
        <a href="export_wholesale_profit_report.php?<?php echo $export_query; ?>" class="ml-auto px-4 py-1.5 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700 inline-flex items-center">
            <i class="fas fa-file-excel mr-1.5"></i>엑셀 다운로드
        </a>
    </form>

    <!-- 요약 카드 (한 줄) -->
    <div class="bg-white shadow rounded-lg ring-1 ring-gray-300 p-4 mb-6 flex items-center flex-wrap gap-x-8 gap-y-2">
        <div class="flex items-baseline gap-2">
            <span class="text-xs font-semibold text-gray-500">총 매출</span>
            <span class="text-xl font-bold text-gray-900"><?php echo fmt_num($vendor_totals['revenue']); ?></span>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-xs font-semibold text-gray-500">총 원가</span>
            <span class="text-xl font-bold text-gray-900"><?php echo fmt_num($vendor_totals['cost']); ?></span>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-xs font-semibold text-gray-500">총 이익</span>
            <span class="text-xl font-bold <?php echo $vendor_totals['profit'] >= 0 ? 'text-green-700' : 'text-red-600'; ?>"><?php echo fmt_num($vendor_totals['profit']); ?></span>
        </div>
    </div>

    <?php if ($unknown_cost_sale_count > 0 || $unknown_cost_item_count > 0): ?>
    <div class="mb-6 p-3 bg-amber-50 border border-amber-200 rounded-md text-sm text-amber-800">
        <i class="fas fa-triangle-exclamation mr-1"></i>
        원가 정보가 없는 빠른등록 판매 <?php echo number_format($unknown_cost_sale_count); ?>건, 원가 정보가 없는 품목 <?php echo number_format($unknown_cost_item_count); ?>건이 있어 해당 항목은 원가 0으로 계산되었습니다 (이익이 실제보다 높게 표시될 수 있음).
        빠른등록 판매는 목록 화면에서 <strong>원가입력</strong> 버튼으로 원가를 입력할 수 있습니다.
    </div>
    <?php endif; ?>

    <!-- 업체별 이익 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-300 mb-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-white">
            <h3 class="text-lg font-semibold text-gray-900"><i class="fas fa-building text-gray-600 mr-2"></i>업체별 이익</h3>
        </div>
        <?php if (!empty($vendor_rows)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">거래처</th>
                        <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 uppercase">판매건수</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">매출</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">원가</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">이익</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">마진율</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    <?php foreach ($vendor_rows as $vr): ?>
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location.href='wholesale_sales_list.php?customer_id=<?php echo (int)$vr['customer_id']; ?>&date=<?php echo htmlspecialchars($date_to); ?>'">
                        <td class="px-4 py-2 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($vr['customer_name']); ?></td>
                        <td class="px-4 py-2 text-center text-sm text-gray-700"><?php echo number_format($vr['sale_count']); ?></td>
                        <td class="px-4 py-2 text-right text-sm text-gray-900"><?php echo fmt_num($vr['revenue']); ?></td>
                        <td class="px-4 py-2 text-right text-sm text-gray-700"><?php echo fmt_num($vr['cost']); ?></td>
                        <td class="px-4 py-2 text-right text-sm font-semibold <?php echo $vr['profit'] >= 0 ? 'text-green-700' : 'text-red-600'; ?>"><?php echo fmt_num($vr['profit']); ?></td>
                        <td class="px-4 py-2 text-right text-sm text-gray-600"><?php echo number_format($vr['margin'], 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-100">
                    <tr class="font-bold">
                        <td class="px-4 py-3 text-sm text-gray-900" colspan="2">총 합계</td>
                        <td class="px-4 py-3 text-right text-sm text-gray-900"><?php echo fmt_num($vendor_totals['revenue']); ?></td>
                        <td class="px-4 py-3 text-right text-sm text-gray-900"><?php echo fmt_num($vendor_totals['cost']); ?></td>
                        <td class="px-4 py-3 text-right text-sm <?php echo $vendor_totals['profit'] >= 0 ? 'text-green-700' : 'text-red-600'; ?>"><?php echo fmt_num($vendor_totals['profit']); ?></td>
                        <td class="px-4 py-3 text-right text-sm text-gray-600"><?php echo $vendor_totals['revenue'] > 0 ? number_format($vendor_totals['profit'] / $vendor_totals['revenue'] * 100, 1) : '0.0'; ?>%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-12 text-gray-500"><i class="fas fa-inbox text-4xl text-gray-300 mb-3"></i><p>해당 기간의 도매판매 데이터가 없습니다</p></div>
        <?php endif; ?>
    </div>

    <!-- 상품별 이익 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-300">
        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-white">
            <h3 class="text-lg font-semibold text-gray-900"><i class="fas fa-box-open text-gray-600 mr-2"></i>상품별 이익</h3>
            <p class="text-xs text-gray-500 mt-1">품목 없이 빠른등록한 판매는 상품 단위 집계에 포함되지 않습니다</p>
        </div>
        <?php if (!empty($product_rows)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">SKU</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">상품명</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">판매수량</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">매출</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">원가</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">이익</th>
                        <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">마진율</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    <?php foreach ($product_rows as $pr): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-xs font-mono text-gray-500"><?php echo htmlspecialchars($pr['sku'] ?: '-'); ?></td>
                        <td class="px-4 py-2 text-sm text-gray-900"><?php echo htmlspecialchars($pr['name_ko'] ?: $pr['name_en'] ?: '-'); ?></td>
                        <td class="px-4 py-2 text-right text-sm text-gray-700"><?php echo fmt_num($pr['net_qty']); ?></td>
                        <td class="px-4 py-2 text-right text-sm text-gray-900"><?php echo fmt_num($pr['revenue']); ?></td>
                        <td class="px-4 py-2 text-right text-sm text-gray-700"><?php echo fmt_num($pr['cost']); ?></td>
                        <td class="px-4 py-2 text-right text-sm font-semibold <?php echo $pr['profit'] >= 0 ? 'text-green-700' : 'text-red-600'; ?>"><?php echo fmt_num($pr['profit']); ?></td>
                        <td class="px-4 py-2 text-right text-sm text-gray-600"><?php echo number_format($pr['margin'], 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-100">
                    <tr class="font-bold">
                        <td class="px-4 py-3 text-sm text-gray-900" colspan="3">총 합계</td>
                        <td class="px-4 py-3 text-right text-sm text-gray-900"><?php echo fmt_num($product_totals['revenue']); ?></td>
                        <td class="px-4 py-3 text-right text-sm text-gray-900"><?php echo fmt_num($product_totals['cost']); ?></td>
                        <td class="px-4 py-3 text-right text-sm <?php echo $product_totals['profit'] >= 0 ? 'text-green-700' : 'text-red-600'; ?>"><?php echo fmt_num($product_totals['profit']); ?></td>
                        <td class="px-4 py-3 text-right text-sm text-gray-600"><?php echo $product_totals['revenue'] > 0 ? number_format($product_totals['profit'] / $product_totals['revenue'] * 100, 1) : '0.0'; ?>%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-12 text-gray-500"><i class="fas fa-inbox text-4xl text-gray-300 mb-3"></i><p>해당 기간의 품목 판매 데이터가 없습니다</p></div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
