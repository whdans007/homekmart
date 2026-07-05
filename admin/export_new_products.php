<?php
// 매월 신상품 리스트 엑셀(.xls) 다운로드 — 바코드 / 상품명 / 원가 / 판매가
// SpreadsheetML(XML) 방식: 한글 인코딩 안전, PhpSpreadsheet Writer 불필요 (export_monthly.php 동일 방식)
ob_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

if (!is_logged_in()) { header('Location: login.php'); exit; }
if (!has_permission('product_management')) { http_response_code(403); exit('권한이 없습니다.'); }

// ── 대상 월 (YYYY-MM, 기본: 이번 달) ──────────────────────────
$month_param = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month_param)) { $month_param = date('Y-m'); }
$year  = (int)substr($month_param, 0, 4);
$month = (int)substr($month_param, 5, 2);

// ── 가격 기준 점포 (현재 사용자 점포) ─────────────────────────
$store_id = $_SESSION['store_id'] ?? null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!$store_id && !empty($_SESSION['user_id'])) {
        $ust = $pdo->prepare("SELECT store_id FROM users WHERE id = ?");
        $ust->execute([$_SESSION['user_id']]);
        $store_id = $ust->fetchColumn() ?: null;
    }

    // 해당 월 등록 신상품 + 현재 점포 재고가격
    $sql = "
        SELECT p.sku, p.name_ko, p.name_en, i.cost_price, i.selling_price
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
        WHERE YEAR(p.created_at) = ? AND MONTH(p.created_at) = ?
        ORDER BY p.created_at DESC, p.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $store_id, $store_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(2, $year, PDO::PARAM_INT);
    $stmt->bindValue(3, $month, PDO::PARAM_INT);
    $stmt->execute();
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

$month_label = date('Y년 n월', mktime(0, 0, 0, $month, 1, $year));

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
$xml .= '<Style ss:ID="s_num"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/><NumberFormat ss:Format="#,##0.00"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '</Styles>' . "\n";

$xml .= '<Worksheet ss:Name="New Products">' . "\n";
$xml .= '<Table ss:DefaultRowHeight="18">' . "\n";
$xml .= '<Column ss:Width="140"/>' . "\n";  // 바코드
$xml .= '<Column ss:Width="300"/>' . "\n";  // 상품명
$xml .= '<Column ss:Width="90"/>' . "\n";   // 원가
$xml .= '<Column ss:Width="90"/>' . "\n";   // 판매가

// 타이틀 (4칸 병합)
$xml .= '<Row ss:Height="26"><Cell ss:StyleID="s_title" ss:MergeAcross="3"><Data ss:Type="String">' . xesc($month_label . ' 신상품 리스트') . '</Data></Cell></Row>' . "\n";

// 헤더
$xml .= xrow(
    xcell_text('바코드', 's_hdr') .
    xcell_text('상품명', 's_hdr') .
    xcell_text('원가',   's_hdr') .
    xcell_text('판매가', 's_hdr')
);

// 데이터
foreach ($rows as $r) {
    $name = $r['name_ko'] ?: ($r['name_en'] ?: '-');
    $xml .= xrow(
        xcell_text($r['sku'], 's_code') .
        xcell_text($name, 's_txt') .
        xcell_num((float)($r['cost_price'] ?? 0), 's_num') .
        xcell_num((float)($r['selling_price'] ?? 0), 's_num')
    );
}

if (empty($rows)) {
    $xml .= '<Row><Cell ss:StyleID="s_txt" ss:MergeAcross="3"><Data ss:Type="String">해당 월에 등록된 신상품이 없습니다.</Data></Cell></Row>' . "\n";
}

$xml .= '</Table>' . "\n";
$xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
$xml .= '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>2</SplitHorizontal><TopRowBottomPane>2</TopRowBottomPane><ActivePane>2</ActivePane>' . "\n";
$xml .= '</WorksheetOptions>' . "\n";
$xml .= '</Worksheet>' . "\n";
$xml .= '</Workbook>';

// ── 다운로드 헤더 ────────────────────────────────────────────
ob_end_clean();
$filename = 'NewProducts_' . sprintf('%04d_%02d', $year, $month) . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
echo $xml;
