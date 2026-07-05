<?php
ob_start();
set_time_limit(120);
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$store_id = get_office_store_id();
$conn     = get_db_connection();

$search   = trim($_GET['q']  ?? '');
$supplier = trim($_GET['s']  ?? '');

$where = "d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})";
if ($search !== '') {
    $like   = '%' . $conn->real_escape_string($search) . '%';
    $where .= " AND (d.sale_date LIKE '{$like}' OR d.item_name LIKE '{$like}' OR d.item_code LIKE '{$like}' OR d.supplier LIKE '{$like}' OR d.department LIKE '{$like}' OR d.si_no LIKE '{$like}' OR d.cashier LIKE '{$like}')";
}
if ($supplier !== '') {
    $slike  = '%' . $conn->real_escape_string($supplier) . '%';
    $where .= " AND d.supplier LIKE '{$slike}'";
}

$result = $conn->query(
    "SELECT d.sale_date, d.sale_time, d.pos_store_id, d.si_no, d.item_code, d.item_name,
            d.supplier, d.department, d.box, d.pcs, d.unit_cost, d.total_cost,
            d.selling_price, d.discount, d.total_sales, d.gross_profit,
            d.sc_discount, d.pwd_discount, d.less_vat, d.net_sales, d.cashier, d.payment_form
     FROM pos_sales_data d
     JOIN pos_sales_uploads u ON u.id = d.upload_id
     WHERE {$where}
     ORDER BY u.uploaded_at DESC, d.row_no ASC"
);
$conn->close();

$headers = ['DATE','TIME','STORE ID','SI NO.','ITEMCODE','ITEMNAME','SUPPLIER','DEPARTMENT',
            'BOX','PCS','UNIT COST','TOTAL COST','SELLING PRICE','DISCOUNT','TOTAL SALES',
            'GROSS PROFIT','SC DISCOUNT','PWD DISCOUNT','LESS VAT','NET SALES','CASHIER','PAYMENT FORM'];

$suffix = '';
if ($search)   $suffix .= '_' . preg_replace('/[^a-zA-Z0-9]/', '', $search);
if ($supplier) $suffix .= '_' . preg_replace('/[^a-zA-Z0-9]/', '', $supplier);
$filename = 'POS_Sales' . $suffix . '_' . date('Ymd_His') . '.csv';

ob_end_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');

// UTF-8 BOM (Excel 한글 깨짐 방지)
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, $headers);

while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row['sale_date'],
        $row['sale_time'],
        $row['pos_store_id'],
        $row['si_no'],
        $row['item_code'],
        $row['item_name'],
        $row['supplier'],
        $row['department'],
        $row['box'],
        $row['pcs'],
        $row['unit_cost'],
        $row['total_cost'],
        $row['selling_price'],
        $row['discount'],
        $row['total_sales'],
        $row['gross_profit'],
        $row['sc_discount'],
        $row['pwd_discount'],
        $row['less_vat'],
        $row['net_sales'],
        $row['cashier'],
        $row['payment_form'],
    ]);
}

fclose($out);
exit;
