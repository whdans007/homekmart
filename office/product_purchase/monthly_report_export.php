<?php
ob_start();
set_time_limit(120);
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$store_id = get_office_store_id();
$conn     = get_db_connection();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$all_items = [];

// 1) Cash product purchases
$stmt = $conn->prepare(
    "SELECT supplier_name, delivery_content AS details, amount, payment_date AS ref_date
     FROM office_product_purchases
     WHERE store_id=? AND payment_type='cash'
       AND YEAR(payment_date)=? AND MONTH(payment_date)=?
     ORDER BY payment_date, id"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $all_items[] = ['date'=>$r['ref_date'],'supplier'=>trim($r['supplier_name']),'details'=>$r['details'],'amount'=>(float)$r['amount']];
}
$stmt->close();

// 2) Check product purchases
$stmt = $conn->prepare(
    "SELECT supplier_name, delivery_content AS details, amount, check_issued_date AS ref_date
     FROM office_product_purchases
     WHERE store_id=? AND payment_type='check'
       AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=?
     ORDER BY check_issued_date, id"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $all_items[] = ['date'=>$r['ref_date'],'supplier'=>trim($r['supplier_name']),'details'=>$r['details'],'amount'=>(float)$r['amount']];
}
$stmt->close();

// 3) ER 저장 상태의 r_ 영수증 아이템
$er_tbl = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if ($er_tbl && $er_tbl->num_rows > 0) {
    $first    = sprintf('%04d-%02d-01', $year, $month);
    $last_day = date('Y-m-t', strtotime($first));
    $er_q = $conn->prepare(
        "SELECT save_date, state_json FROM er_saved_state
         WHERE store_id=? AND save_date BETWEEN ? AND ?"
    );
    if ($er_q) {
        $er_q->bind_param('iss', $store_id, $first, $last_day);
        $er_q->execute();
        foreach ($er_q->get_result()->fetch_all(MYSQLI_ASSOC) as $er_row) {
            $state = json_decode($er_row['state_json'], true);
            if (!$state || !isset($state['sections'])) continue;
            foreach (['selling', 'check_sup'] as $sec) {
                foreach ($state['sections'][$sec] ?? [] as $row) {
                    $bid = (string)($row['item_id'] ?? '');
                    if (strncmp($bid, 'r_', 2) !== 0) continue;
                    $amt = (float)($row['amount'] ?? 0);
                    if ($amt <= 0) continue;
                    $all_items[] = ['date'=>$er_row['save_date'],'supplier'=>trim($row['supplier']??''),'details'=>$row['details']??'','amount'=>$amt];
                }
            }
        }
        $er_q->close();
    }
}
$conn->close();

usort($all_items, fn($a, $b) => strcmp($a['date'], $b['date']));

$groups      = [];
$grand_total = 0.0;
foreach ($all_items as $item) {
    if ($item['amount'] <= 0) continue;
    $key = mb_strtolower($item['supplier']);
    if (!isset($groups[$key])) $groups[$key] = ['label' => $item['supplier'], 'items' => []];
    $groups[$key]['items'][] = $item;
    $grand_total += $item['amount'];
}
ksort($groups);

$filename = sprintf('Purchase_Report_%d_%d.csv', $year, $month);
ob_end_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');

// 제목
fputcsv($out, [sprintf('%d년 %d월 업체별 입고 보고서', $year, $month)]);
fputcsv($out, ['전체 합계', '', '', '', number_format($grand_total, 2)]);
fputcsv($out, []);

// 헤더
fputcsv($out, ['업체명', '날짜', '내용', '금액', '합계금액']);

foreach ($groups as $group) {
    $supplier = $group['label'];
    $items    = $group['items'];
    $subtotal = array_sum(array_column($items, 'amount'));

    foreach ($items as $idx => $item) {
        fputcsv($out, [
            $idx === 0 ? $supplier : '',
            date('m/d (D)', strtotime($item['date'])),
            $item['details'],
            number_format($item['amount'], 2),
            $idx === 0 ? number_format($subtotal, 2) : '',
        ]);
    }
}

fputcsv($out, [sprintf('총 합계 (%d건)', count($all_items)), '', '', '', number_format($grand_total, 2)]);
fclose($out);
exit;
