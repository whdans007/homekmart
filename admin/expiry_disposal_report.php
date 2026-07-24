<?php
// Design Ref: docs/02-design/features/expiry-management.design.md §5.4 폐기통계
require_once __DIR__ . '/partials/header.php';

if (!has_permission('product_management')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();

// 조회 가능한 연도 목록
$years = [];
$year_stmt = $conn->prepare("SELECT DISTINCT YEAR(disposed_at) AS y FROM product_disposals WHERE store_id = ? ORDER BY y DESC");
$year_stmt->bind_param("i", $current_store_id);
$year_stmt->execute();
$year_result = $year_stmt->get_result();
while ($row = $year_result->fetch_assoc()) {
    $years[] = (int)$row['y'];
}
$year_stmt->close();

$current_year = (int)date('Y');
if (!in_array($current_year, $years, true)) {
    $years[] = $current_year;
    rsort($years);
}

$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : $current_year;

// 월별 집계
$monthly = array_fill(1, 12, ['count' => 0, 'quantity' => 0, 'loss' => 0.0]);
$stmt = $conn->prepare("
    SELECT MONTH(disposed_at) AS m, COUNT(*) AS cnt, SUM(quantity) AS qty_sum, SUM(quantity * unit_cost) AS loss_sum
    FROM product_disposals
    WHERE store_id = ? AND YEAR(disposed_at) = ?
    GROUP BY MONTH(disposed_at)
    ORDER BY m ASC
");
$stmt->bind_param("ii", $current_store_id, $selected_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $monthly[(int)$row['m']] = [
        'count' => (int)$row['cnt'],
        'quantity' => (int)$row['qty_sum'],
        'loss' => (float)$row['loss_sum'],
    ];
}
$stmt->close();

$total_count = array_sum(array_column($monthly, 'count'));
$total_quantity = array_sum(array_column($monthly, 'quantity'));
$total_loss = array_sum(array_column($monthly, 'loss'));
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">폐기통계</h1>
            <p class="mt-1 text-sm text-gray-500"><?php echo htmlspecialchars($current_store_name); ?></p>
        </div>
        <?php require __DIR__ . '/partials/expiry_nav.php'; ?>
    </div>

    <form method="get" class="mb-6 flex items-center gap-2">
        <select name="year" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            <?php foreach ($years as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo $y === $selected_year ? 'selected' : ''; ?>><?php echo $y; ?>년</option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- 요약 카드 -->
    <div class="flex flex-wrap gap-3 mb-6">
        <div class="bg-white rounded-lg shadow-sm ring-1 ring-gray-200 p-4" style="width:220px;">
            <p class="text-xs font-medium text-gray-500">총 폐기 건수</p>
            <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo number_format($total_count); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-sm ring-1 ring-gray-200 p-4" style="width:220px;">
            <p class="text-xs font-medium text-gray-500">총 폐기 수량</p>
            <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo number_format($total_quantity); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-sm ring-1 ring-gray-200 p-4" style="width:220px;">
            <p class="text-xs font-medium text-gray-500">총 추정 손실 금액</p>
            <p class="text-2xl font-bold text-red-600 mt-1"><?php echo number_format($total_loss, 2); ?></p>
        </div>
    </div>

    <?php if ($total_count === 0): ?>
        <div class="bg-white rounded-lg shadow-sm ring-1 ring-gray-200 p-8 text-center text-gray-500">
            해당 연도의 폐기 이력이 없습니다.
        </div>
    <?php else: ?>
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-200">
        <table class="min-w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">월</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase">폐기 건수</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase">폐기 수량</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase">추정 손실 금액</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <tr class="<?php echo $monthly[$m]['count'] === 0 ? 'text-gray-400' : ''; ?>">
                    <td class="px-4 py-3 text-sm"><?php echo $m; ?>월</td>
                    <td class="px-4 py-3 text-right text-sm"><?php echo number_format($monthly[$m]['count']); ?></td>
                    <td class="px-4 py-3 text-right text-sm"><?php echo number_format($monthly[$m]['quantity']); ?></td>
                    <td class="px-4 py-3 text-right text-sm"><?php echo number_format($monthly[$m]['loss'], 2); ?></td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
