<?php
/**
 * Foodpang 외부 도매 XLSX 업로드 화면.
 * Barcode / PMS_스냅샷 시트를 파싱하여 fpwx_raw_* 테이블에 불변 스냅샷으로 저장하고,
 * 정규화 테이블을 갱신한 뒤 결정론적 매칭을 실행한다.
 * PMS 상태가 사용중인 상품은 Barcode 시트의 바코드를 SKU로 products에 등록한다.
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
ensure_logged_in();
require_permission('foodpang_wholesale_management', '/index.php');
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../mall/lib/csrf.php';
require_once __DIR__ . '/../../lib/fpwx_helper.php';

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}
$has_phpspreadsheet = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');

$csrf_token = mall_csrf_token();
$result = null; // 처리 결과 요약
$error = null;

const FPWX_MAX_UPLOAD_BYTES = 20 * 1024 * 1024; // 20MB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = t('foodpang_wholesale.csrf_error');
    } elseif (!$has_phpspreadsheet) {
        $error = t('foodpang_wholesale.phpspreadsheet_missing');
    } elseif (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        $error = t('foodpang_wholesale.upload_error');
    } else {
        $file = $_FILES['xlsx_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext !== 'xlsx') {
            $error = t('foodpang_wholesale.invalid_extension');
        } elseif ($file['size'] > FPWX_MAX_UPLOAD_BYTES) {
            $error = t('foodpang_wholesale.file_too_large');
        } else {
            try {
                $hash = fpwx_hash_file($file['tmp_name']);
                $pdo = fpwx_pdo();

                $existing_batch = fpwx_find_batch_by_hash($pdo, $hash);
                if ($existing_batch) {
                    // 원본 배치는 중복 저장하지 않지만 상품/카테고리 가져오기는 멱등하게 다시 실행한다.
                    $sheets = fpwx_load_workbook_sheets($file['tmp_name']);
                    $barcode_rows = fpwx_extract_rows($sheets['barcode_sheet'], fpwx_field_specs('barcode'));
                    $pms_rows = fpwx_extract_rows($sheets['pms_sheet'], fpwx_field_specs('pms'));
                    $product_import = fpwx_import_active_pms_products(
                        $pdo,
                        $barcode_rows,
                        $pms_rows,
                        $_SESSION['user_id']
                    );
                    $result = [
                        'duplicate' => true,
                        'batch_id' => $existing_batch['id'],
                        'filename' => $existing_batch['original_filename'],
                        'barcode_row_count' => $existing_batch['barcode_row_count'],
                        'pms_row_count' => $existing_batch['pms_row_count'],
                        'auto_matched' => $existing_batch['auto_matched_count'],
                        'exceptions' => $existing_batch['exception_count'],
                        'product_import' => $product_import,
                    ];
                } else {
                    $sheets = fpwx_load_workbook_sheets($file['tmp_name']);
                    $barcode_rows = fpwx_extract_rows($sheets['barcode_sheet'], fpwx_field_specs('barcode'));
                    $pms_rows = fpwx_extract_rows($sheets['pms_sheet'], fpwx_field_specs('pms'));

                    if (empty($barcode_rows) && empty($pms_rows)) {
                        $error = t('foodpang_wholesale.empty_sheets');
                    } else {
                        $batch_id = fpwx_store_batch($pdo, [
                            'hash' => $hash,
                            'filename' => $file['name'],
                            'barcode_sheet_name' => $sheets['barcode_sheet_name'],
                            'pms_sheet_name' => $sheets['pms_sheet_name'],
                            'barcode_rows' => $barcode_rows,
                            'pms_rows' => $pms_rows,
                            'uploaded_by' => $_SESSION['user_id'],
                        ]);

                        $product_import = fpwx_import_active_pms_products(
                            $pdo,
                            $barcode_rows,
                            $pms_rows,
                            $_SESSION['user_id']
                        );
                        $sales_codes = fpwx_rebuild_normalized_products($pdo, $batch_id);
                        $match_result = fpwx_run_matching($pdo, $batch_id, $sales_codes);
                        fpwx_finalize_batch($pdo, $batch_id, $match_result['auto_matched'], $match_result['exceptions']);

                        $result = [
                            'duplicate' => false,
                            'batch_id' => $batch_id,
                            'filename' => $file['name'],
                            'barcode_row_count' => count($barcode_rows),
                            'pms_row_count' => count($pms_rows),
                            'auto_matched' => $match_result['auto_matched'],
                            'exceptions' => $match_result['exceptions'],
                            'product_import' => $product_import,
                        ];
                    }
                }
            } catch (Throwable $e) {
                error_log('fpwx_upload error: ' . $e->getMessage());
                $error = t('foodpang_wholesale.processing_error') . ' (' . $e->getMessage() . ')';
            }
        }
    }
}

$current_page = 'wholesale_upload.php';
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Foodpang - <?php echo t('foodpang_wholesale.nav_upload'); ?></title>
<link rel="icon" href="data:,">
<link href="../../admin/css/style.css" rel="stylesheet">
<link href="../../admin/css/design-system.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6 max-w-3xl">
<h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-file-arrow-up mr-2"></i><?php echo t('foodpang_wholesale.nav_upload'); ?></h1>

<?php if ($error): ?>
<div class="mb-4 rounded-md p-3 text-sm bg-red-50 text-red-800 border border-red-200"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($result): ?>
<div class="mb-4 rounded-md p-4 text-sm <?php echo $result['duplicate'] ? 'bg-amber-50 text-amber-800 border border-amber-200' : 'bg-green-50 text-green-800 border border-green-200'; ?>">
    <?php if ($result['duplicate']): ?>
        <p class="font-bold mb-1"><i class="fas fa-clock-rotate-left mr-1"></i><?php echo t('foodpang_wholesale.duplicate_batch'); ?></p>
    <?php else: ?>
        <p class="font-bold mb-1"><i class="fas fa-circle-check mr-1"></i><?php echo t('foodpang_wholesale.upload_success'); ?></p>
    <?php endif; ?>
    <ul class="list-disc list-inside">
        <li><?php echo t('foodpang_wholesale.col_filename'); ?>: <?php echo htmlspecialchars($result['filename']); ?></li>
        <li><?php echo t('foodpang_wholesale.col_barcode_rows'); ?>: <?php echo (int)$result['barcode_row_count']; ?></li>
        <li><?php echo t('foodpang_wholesale.col_pms_rows'); ?>: <?php echo (int)$result['pms_row_count']; ?></li>
        <li><?php echo t('foodpang_wholesale.col_auto_matched'); ?>: <?php echo (int)$result['auto_matched']; ?></li>
        <li><?php echo t('foodpang_wholesale.col_exceptions'); ?>: <?php echo (int)$result['exceptions']; ?></li>
        <?php if (isset($result['product_import'])): ?>
        <li>사용중 상품 신규 등록: <?php echo (int)$result['product_import']['created']; ?></li>
        <li>기존 상품 확인: <?php echo (int)$result['product_import']['existing']; ?></li>
        <li>바코드/상품명 누락으로 제외: <?php echo (int)$result['product_import']['skipped']; ?></li>
        <li>신규 카테고리 등록: <?php echo (int)$result['product_import']['categories_created']; ?></li>
        <?php endif; ?>
    </ul>
    <?php if ((int)$result['exceptions'] > 0): ?>
    <a href="wholesale_review.php?batch_id=<?php echo (int)$result['batch_id']; ?>" class="inline-block mt-2 font-semibold underline"><?php echo t('foodpang_wholesale.go_review'); ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$has_phpspreadsheet): ?>
<div class="mb-4 rounded-md p-3 text-sm bg-red-50 text-red-800 border border-red-200"><?php echo t('foodpang_wholesale.phpspreadsheet_missing'); ?></div>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="bg-white rounded-lg border border-gray-200 p-5">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <p class="text-sm text-gray-600 mb-3"><?php echo t('foodpang_wholesale.upload_desc'); ?></p>
    <input type="file" name="xlsx_file" accept=".xlsx" required class="block w-full text-sm border border-gray-300 rounded-md p-2 mb-4">
    <button type="submit" class="px-4 py-2 bg-pink-600 hover:bg-pink-700 text-white rounded-md text-sm font-semibold"><i class="fas fa-upload mr-2"></i><?php echo t('foodpang_wholesale.upload_button'); ?></button>
</form>
<?php endif; ?>

</main>
</body>
</html>
