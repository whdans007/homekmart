<?php
ob_start();
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();
if (!has_permission('wholesale_management')) {
    ob_end_clean();
    http_response_code(403);
    die('권한이 없습니다.');
}

$current_store_id = $_SESSION['store_id'] ?? null;
if (($_SESSION['role'] ?? '') === 'super_admin' && empty($current_store_id)) {
    $current_store_id = 1;
}

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to = date('Y-m-d'); }
$filter_customer_id = (int)($_GET['customer_id'] ?? 0);

$vendor_rows = [];
$product_rows = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $has_returned_amount = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'returned_amount'")->rowCount() > 0;
    $has_cost_amount     = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'cost_amount'")->rowCount() > 0;
    $has_returned_qty    = $pdo->query("SHOW COLUMNS FROM wholesale_sale_items LIKE 'returned_quantity'")->rowCount() > 0;

    $returned_amount_expr = $has_returned_amount ? "COALESCE(ws.returned_amount,0)" : "0";
    $cost_amount_expr     = $has_cost_amount ? "ws.cost_amount" : "NULL";
    $returned_qty_expr    = $has_returned_qty ? "COALESCE(wsi.returned_quantity,0)" : "0";

    $where = ["ws.status != 'cancelled'", "ws.sale_date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];

    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        $where[] = "ws.store_id = ?";
        $params[] = $current_store_id;
    }
    if ($filter_customer_id > 0) {
        $where[] = "ws.customer_id = ?";
        $params[] = $filter_customer_id;
    }
    $where_clause = implode(' AND ', $where);

    $vendor_sql = "
        SELECT
            wc.name AS customer_name,
            COUNT(DISTINCT ws.id) AS sale_count,
            SUM(ws.final_amount - {$returned_amount_expr}) AS revenue,
            SUM(
                CASE WHEN it.sale_id IS NOT NULL THEN COALESCE(it.item_cost, 0)
                     ELSE COALESCE({$cost_amount_expr}, 0)
                END
            ) AS cost
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

    $product_sql = "
        SELECT
            COALESCE(p.sku, '') AS sku,
            COALESCE(p.name_ko, wsi.custom_product_name) AS name_ko,
            SUM(wsi.quantity - {$returned_qty_expr}) AS net_qty,
            SUM((wsi.quantity - {$returned_qty_expr}) * wsi.unit_price) AS revenue,
            SUM((wsi.quantity - {$returned_qty_expr}) * COALESCE(wsi.custom_cost_price,0)) AS cost
        FROM wholesale_sale_items wsi
        JOIN wholesale_sales ws ON wsi.sale_id = ws.id
        LEFT JOIN products p ON wsi.product_id = p.id
        WHERE {$where_clause}
        GROUP BY wsi.product_id, wsi.custom_product_name, COALESCE(p.name_ko, wsi.custom_product_name), p.sku
        ORDER BY revenue DESC
    ";
    $product_stmt = $pdo->prepare($product_sql);
    $product_stmt->execute($params);
    $product_rows = $product_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    die('데이터 조회 중 오류가 발생했습니다: ' . $e->getMessage());
}

// XML helpers
function xesc(string $v): string {
    return htmlspecialchars($v, ENT_XML1, 'UTF-8');
}
function xrow(int $h, string $cells): string {
    return '<Row ss:Height="' . $h . '">' . $cells . "</Row>\n";
}
function xcell(string $val, string $style, int $mergeAcross = 0, string $type = 'String'): string {
    $ma  = $mergeAcross > 0 ? ' ss:MergeAcross="' . $mergeAcross . '"' : '';
    $sid = ' ss:StyleID="' . $style . '"';
    if ($val === '') return '<Cell' . $sid . $ma . '/>';
    return '<Cell' . $sid . $ma . '><Data ss:Type="' . $type . '">' . xesc($val) . '</Data></Cell>';
}
function xnum_cell(float $n, string $style): string {
    return xcell(number_format($n, 2, '.', ''), $style, 0, 'Number');
}

ob_end_clean();

$filename = 'WholesaleProfitReport_' . $date_from . '_' . $date_to . '.xls';
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
$xml .= '  xmlns:o="urn:schemas-microsoft-com:office:office">' . "\n";

$xml .= '<Styles>' . "\n";
$xml .= '<Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#c2410c" ss:Pattern="Solid"/></Style>' . "\n";
$xml .= '<Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#f3f4f6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_str"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_num"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_profit"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1" ss:Color="#15803d"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_grand"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_grand_str"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '</Styles>' . "\n";

// ── Worksheet 1: 업체별 이익 ──
$xml .= '<Worksheet ss:Name="Vendor Profit">' . "\n";
$xml .= '<Table ss:DefaultRowHeight="16">' . "\n";
foreach ([180, 90, 100, 100, 100, 80] as $w) { $xml .= '<Column ss:Width="' . $w . '"/>' . "\n"; }
$xml .= xrow(24, xcell('도매 이익 리포트 - 업체별 (' . $date_from . ' ~ ' . $date_to . ')', 's_title', 5));
$xml .= xrow(20,
    xcell('거래처', 's_hdr') . xcell('판매건수', 's_hdr') . xcell('매출', 's_hdr') .
    xcell('원가', 's_hdr') . xcell('이익', 's_hdr') . xcell('마진율(%)', 's_hdr')
);
$v_total_revenue = 0; $v_total_cost = 0;
foreach ($vendor_rows as $vr) {
    $revenue = (float)$vr['revenue'];
    $cost = (float)$vr['cost'];
    $profit = $revenue - $cost;
    $margin = $revenue > 0 ? ($profit / $revenue * 100) : 0;
    $v_total_revenue += $revenue;
    $v_total_cost += $cost;
    $xml .= xrow(16,
        xcell($vr['customer_name'], 's_str') .
        xcell((string)$vr['sale_count'], 's_num', 0, 'Number') .
        xnum_cell($revenue, 's_num') .
        xnum_cell($cost, 's_num') .
        xnum_cell($profit, 's_profit') .
        xcell(number_format($margin, 1), 's_num', 0, 'Number')
    );
}
$v_total_profit = $v_total_revenue - $v_total_cost;
$v_total_margin = $v_total_revenue > 0 ? ($v_total_profit / $v_total_revenue * 100) : 0;
$xml .= xrow(18,
    xcell('총 합계', 's_grand_str', 1) .
    xnum_cell($v_total_revenue, 's_grand') .
    xnum_cell($v_total_cost, 's_grand') .
    xnum_cell($v_total_profit, 's_grand') .
    xcell(number_format($v_total_margin, 1), 's_grand', 0, 'Number')
);
$xml .= '</Table>' . "\n";
$xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><PageSetup><Layout x:Orientation="Landscape"/></PageSetup></WorksheetOptions>' . "\n";
$xml .= '</Worksheet>' . "\n";

// ── Worksheet 2: 상품별 이익 ──
$xml .= '<Worksheet ss:Name="Product Profit">' . "\n";
$xml .= '<Table ss:DefaultRowHeight="16">' . "\n";
foreach ([90, 220, 90, 100, 100, 100, 80] as $w) { $xml .= '<Column ss:Width="' . $w . '"/>' . "\n"; }
$xml .= xrow(24, xcell('도매 이익 리포트 - 상품별 (' . $date_from . ' ~ ' . $date_to . ')', 's_title', 6));
$xml .= xrow(20,
    xcell('SKU', 's_hdr') . xcell('상품명', 's_hdr') . xcell('판매수량', 's_hdr') .
    xcell('매출', 's_hdr') . xcell('원가', 's_hdr') . xcell('이익', 's_hdr') . xcell('마진율(%)', 's_hdr')
);
$p_total_revenue = 0; $p_total_cost = 0;
foreach ($product_rows as $pr) {
    $revenue = (float)$pr['revenue'];
    $cost = (float)$pr['cost'];
    $profit = $revenue - $cost;
    $margin = $revenue > 0 ? ($profit / $revenue * 100) : 0;
    $p_total_revenue += $revenue;
    $p_total_cost += $cost;
    $xml .= xrow(16,
        xcell($pr['sku'] ?: '-', 's_str') .
        xcell($pr['name_ko'] ?: '-', 's_str') .
        xcell(number_format((float)$pr['net_qty'], 2, '.', ''), 's_num', 0, 'Number') .
        xnum_cell($revenue, 's_num') .
        xnum_cell($cost, 's_num') .
        xnum_cell($profit, 's_profit') .
        xcell(number_format($margin, 1), 's_num', 0, 'Number')
    );
}
$p_total_profit = $p_total_revenue - $p_total_cost;
$p_total_margin = $p_total_revenue > 0 ? ($p_total_profit / $p_total_revenue * 100) : 0;
$xml .= xrow(18,
    xcell('총 합계', 's_grand_str', 2) .
    xnum_cell($p_total_revenue, 's_grand') .
    xnum_cell($p_total_cost, 's_grand') .
    xnum_cell($p_total_profit, 's_grand') .
    xcell(number_format($p_total_margin, 1), 's_grand', 0, 'Number')
);
$xml .= '</Table>' . "\n";
$xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><PageSetup><Layout x:Orientation="Landscape"/></PageSetup></WorksheetOptions>' . "\n";
$xml .= '</Worksheet>' . "\n";

$xml .= '</Workbook>';

echo $xml;
