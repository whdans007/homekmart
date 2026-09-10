<?php
/**
 * Foodpang 외부 도매 매핑 - 매핑 목록 CSV 다운로드.
 * 읽기 전용 내보내기이며, 내보내기 이력만 fpwx_export_logs에 기록한다 (기존 데이터는 변경하지 않음).
 */
ob_start();
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/fpwx_helper.php';

if (!is_logged_in()) { header('Location: /admin/login.php'); exit; }
if (!has_permission('foodpang_wholesale_management')) { http_response_code(403); exit('권한이 없습니다.'); }

$status = in_array($_GET['status'] ?? 'active', ['active', 'rejected', 'all'], true) ? $_GET['status'] : 'active';
$search = trim($_GET['search'] ?? '');

try {
    $pdo = fpwx_pdo();
    $rows = fpwx_get_mappings_for_export($pdo, ['status' => $status]);

    if ($search !== '') {
        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($rows, function ($r) use ($needle) {
            return strpos(mb_strtolower($r['sales_code'] ?? ''), $needle) !== false
                || strpos(mb_strtolower($r['hkm_sku'] ?? ''), $needle) !== false
                || strpos(mb_strtolower($r['hkm_name_ko'] ?? ''), $needle) !== false;
        }));
    }

    fpwx_log_export($pdo, $_SESSION['user_id'], count($rows), ['status' => $status, 'search' => $search]);
} catch (Throwable $e) {
    error_log('wholesale_export.php error: ' . $e->getMessage());
    ob_clean();
    http_response_code(500);
    exit('내보내기 중 오류가 발생했습니다.');
}

ob_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="fpwx_mappings_' . date('Ymd_His') . '.csv"');

echo "\xEF\xBB\xBF"; // UTF-8 BOM (엑셀 한글 깨짐 방지)
$out = fopen('php://output', 'w');
fputcsv($out, ['sales_code', 'base_code', 'hkm_product_id', 'hkm_sku', 'hkm_name_ko', 'match_type', 'package_type', 'units_per_sale', 'status', 'mapped_at', 'updated_at']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['sales_code'],
        $r['base_code'],
        $r['hkm_product_id'],
        $r['hkm_sku'],
        $r['hkm_name_ko'],
        $r['match_type'],
        $r['package_type'],
        $r['units_per_sale'],
        $r['status'],
        $r['mapped_at'],
        $r['updated_at'],
    ]);
}
fclose($out);
exit;
