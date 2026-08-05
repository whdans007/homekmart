<?php
// 상품정보 엑셀(.xls) 다운로드 — 사용자가 선택한 컬럼만 출력
// SpreadsheetML(XML) 방식: 한글 인코딩 안전, PhpSpreadsheet 불필요 (export_new_products.php 동일 방식)
ob_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

if (!is_logged_in()) { header('Location: login.php'); exit; }
if (!has_permission('product_management')) { http_response_code(403); exit('권한이 없습니다.'); }

// ── 선택 가능 컬럼 화이트리스트 ──────────────────────────────
// key => [라벨, SELECT 식, 스타일, 컬럼 너비, 타입(string|number|date)]
$COLUMNS = [
    'sku'            => ['SKU',            'p.sku',              's_code', 130, 'string'],
    'name_ko'        => ['상품명(한글)',   'p.name_ko',          's_txt',  240, 'string'],
    'name_en'        => ['상품명(영어)',   'p.name_en',          's_txt',  240, 'string'],
    'brand_ko'       => ['브랜드(한글)',   'b.name_ko',          's_txt',  140, 'string'],
    'brand_en'       => ['브랜드(영어)',   'b.name_en',          's_txt',  140, 'string'],
    'category'       => ['카테고리',       'c.name',             's_txt',  120, 'string'],
    'pieces_per_box' => ['박스당 개수',    'p.pieces_per_box',   's_int',  90,  'number'],
    'vat'            => ['VAT 적용',       'p.is_vat_applicable', 's_txt', 80,  'vat'],
    'status'         => ['상태',           'p.is_active',        's_txt',  80,  'status'],
    'created_at'     => ['등록일',         'p.created_at',       's_txt',  150, 'date'],
    'updated_at'     => ['수정일',         'p.updated_at',       's_txt',  150, 'date'],
];

// ── 요청 컬럼 검증 (화이트리스트에 있는 키만, 정의 순서 유지) ─
$requested = (array)($_GET['cols'] ?? []);
$selected  = [];
foreach ($COLUMNS as $key => $def) {
    if (in_array($key, $requested, true)) $selected[$key] = $def;
}
// 선택이 없으면 전체 컬럼
if (empty($selected)) $selected = $COLUMNS;

// ── 검색 조건 (product_management.php 목록과 동일) ────────────
$search_term = trim($_GET['search'] ?? '');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $select_sql = [];
    foreach ($selected as $key => $def) {
        $select_sql[] = $def[1] . ' AS `' . $key . '`';
    }

    $where_clause = '';
    $params = [];
    if ($search_term !== '') {
        $where_clause = " WHERE p.name_ko LIKE ? OR p.sku LIKE ? OR b.name_ko LIKE ?";
        $params = ["%$search_term%", "%$search_term%", "%$search_term%"];
    }

    $sql = "SELECT " . implode(', ', $select_sql) . "
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            " . $where_clause . "
            ORDER BY p.created_at DESC, p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    exit('데이터 조회 오류: ' . htmlspecialchars($e->getMessage()));
}

// ── SpreadsheetML 헬퍼 ───────────────────────────────────────
function xesc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function xcell_text($v, $style) {
    return '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . xesc($v) . '</Data></Cell>';
}
function xcell_num($v, $style) {
    return '<Cell ss:StyleID="' . $style . '"><Data ss:Type="Number">' . (0 + $v) . '</Data></Cell>';
}
function xrow($cells) { return '<Row>' . $cells . '</Row>' . "\n"; }

// ── 값 포맷 ──────────────────────────────────────────────────
function fmt_value($type, $val) {
    switch ($type) {
        case 'vat':    return ((int)$val === 1) ? '적용' : '비적용';
        case 'status': return ((int)$val === 1) ? '판매중' : '판매중지';
        case 'date':   return $val ? date('Y-m-d H:i', strtotime($val)) : '';
        default:       return ($val === null || $val === '') ? '' : $val;
    }
}

$col_count = count($selected);

$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
$xml .= '  xmlns:o="urn:schemas-microsoft-com:office:office">' . "\n";

$xml .= '<Styles>' . "\n";
$xml .= '<Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1a5276" ss:Pattern="Solid"/></Style>' . "\n";
$xml .= '<Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2e86c1" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_txt"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_code"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Consolas" ss:Size="10"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_int"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/><NumberFormat ss:Format="#,##0"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '</Styles>' . "\n";

$xml .= '<Worksheet ss:Name="Products">' . "\n";
$xml .= '<Table ss:DefaultRowHeight="18">' . "\n";
foreach ($selected as $def) {
    $xml .= '<Column ss:Width="' . (int)$def[3] . '"/>' . "\n";
}

// 타이틀 (전체 칸 병합)
$title = '상품 리스트' . ($search_term !== '' ? ' — 검색: ' . $search_term : '');
$xml .= '<Row ss:Height="26"><Cell ss:StyleID="s_title" ss:MergeAcross="' . ($col_count - 1) . '"><Data ss:Type="String">' . xesc($title) . '</Data></Cell></Row>' . "\n";

// 헤더
$hdr_cells = '';
foreach ($selected as $def) {
    $hdr_cells .= xcell_text($def[0], 's_hdr');
}
$xml .= xrow($hdr_cells);

// 데이터
foreach ($rows as $r) {
    $cells = '';
    foreach ($selected as $key => $def) {
        $type  = $def[4];
        $style = $def[2];
        if ($type === 'number') {
            $cells .= xcell_num((float)($r[$key] ?? 0), $style);
        } else {
            $cells .= xcell_text(fmt_value($type, $r[$key] ?? ''), $style);
        }
    }
    $xml .= xrow($cells);
}

if (empty($rows)) {
    $xml .= '<Row><Cell ss:StyleID="s_txt" ss:MergeAcross="' . ($col_count - 1) . '"><Data ss:Type="String">조건에 해당하는 상품이 없습니다.</Data></Cell></Row>' . "\n";
}

$xml .= '</Table>' . "\n";
$xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
$xml .= '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>2</SplitHorizontal><TopRowBottomPane>2</TopRowBottomPane><ActivePane>2</ActivePane>' . "\n";
$xml .= '</WorksheetOptions>' . "\n";
$xml .= '</Worksheet>' . "\n";
$xml .= '</Workbook>';

// ── 다운로드 헤더 ────────────────────────────────────────────
ob_end_clean();
$filename = 'Products_' . date('Ymd_His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
echo $xml;
