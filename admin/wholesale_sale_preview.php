<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('wholesale_sale_preview.page_title') . ' - ' . t('company.name');
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

$sale_id = (int)($_GET['id'] ?? 0);
$sale = null;
$customer = null;
$store = null;
$items = [];
$return_history = [];
$return_items_by_return = [];
$errors = [];

// 인쇄 상단 로고 (절대 URL — iframe 인쇄에서도 로드되도록)
$_scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$logo_url = $_scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/logo/homekmart_logo.png';

// 판매취소(삭제) 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $delete_sale_id = (int)($_POST['sale_id'] ?? 0);
    
    if ($delete_sale_id > 0) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 권한 확인 - super_admin이 아닌 경우 본인 점포 데이터만 삭제 가능
            $check_sql = "SELECT id, store_id FROM wholesale_sales WHERE id = ?";
            if ($_SESSION['role'] !== 'super_admin') {
                $check_sql .= " AND store_id = " . (int)$current_store_id;
            }
            
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$delete_sale_id]);
            $sale_to_delete = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sale_to_delete) {
                $_SESSION['flash'] = [
                    'type' => 'error',
                    'message' => t('wholesale_sale_preview.delete_no_permission')
                ];
            } else {
                // 트랜잭션 시작
                $pdo->beginTransaction();
                
                // 1. 판매 항목들 삭제
                $delete_items_stmt = $pdo->prepare("DELETE FROM wholesale_sale_items WHERE sale_id = ?");
                $delete_items_stmt->execute([$delete_sale_id]);
                
                // 2. 판매 정보 삭제
                $delete_sale_stmt = $pdo->prepare("DELETE FROM wholesale_sales WHERE id = ?");
                $delete_sale_stmt->execute([$delete_sale_id]);
                
                // 트랜잭션 커밋
                $pdo->commit();
                
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => t('wholesale_sale_preview.delete_success')
                ];
                
                // 판매 목록으로 리다이렉트
                header('Location: wholesale_sales_list.php');
                exit;
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollback();
            }
            
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => t('wholesale_sale_preview.delete_error') . ': ' . $e->getMessage()
            ];
            
            error_log("Wholesale sale delete error: " . $e->getMessage());
        }
        
        // 오류가 있었다면 현재 페이지로 리다이렉트
        header("Location: wholesale_sale_preview.php?id=$delete_sale_id");
        exit;
    }
}

// 결제 상태 처리 (완납/미납 토글)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['mark_paid', 'mark_unpaid'], true)) {
    $pay_sale_id = (int)($_POST['sale_id'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');

    if ($pay_sale_id > 0) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 권한 확인 - super_admin이 아닌 경우 본인 점포만
            $check_sql = "SELECT id FROM wholesale_sales WHERE id = ?";
            if ($_SESSION['role'] !== 'super_admin') {
                $check_sql .= " AND store_id = " . (int)$current_store_id;
            }
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$pay_sale_id]);

            if (!$check_stmt->fetch()) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => t('wholesale_sale_preview.payment_permission_denied')];
            } else {
                if ($_POST['action'] === 'mark_paid') {
                    $stmt = $pdo->prepare("UPDATE wholesale_sales SET payment_status = 'paid', paid_at = NOW(), payment_method = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$payment_method ?: null, $pay_sale_id]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => t('wholesale_sale_preview.payment_marked_paid')];
                } else {
                    $stmt = $pdo->prepare("UPDATE wholesale_sales SET payment_status = 'unpaid', paid_at = NULL, payment_method = NULL, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$pay_sale_id]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => t('wholesale_sale_preview.payment_marked_unpaid')];
                }
            }
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => t('wholesale_sale_preview.payment_process_error') . $e->getMessage()];
            error_log("Wholesale payment toggle error: " . $e->getMessage());
        }
        header("Location: wholesale_sale_preview.php?id=$pay_sale_id");
        exit;
    }
}

// V.A.T / E.W.T 적용 상태 저장 (체크박스 → DB 저장, 최종금액 재계산)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tax') {
    $tax_sale_id = (int)($_POST['sale_id'] ?? 0);
    $vat_flag = isset($_POST['vat_applied']) ? 1 : 0;
    $ewt_flag = isset($_POST['ewt_applied']) ? 1 : 0;

    if ($tax_sale_id > 0) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 권한 확인 - super_admin이 아닌 경우 본인 점포만
            $check_sql = "SELECT total_amount, final_amount FROM wholesale_sales WHERE id = ?";
            if ($_SESSION['role'] !== 'super_admin') {
                $check_sql .= " AND store_id = " . (int)$current_store_id;
            }
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$tax_sale_id]);
            $row = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => t('wholesale_sale_preview.tax_permission_denied')];
            } else {
                // vat_applied / ewt_applied 컬럼이 없으면 자동 추가 (하위 호환)
                $col_check = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'vat_applied'");
                if ($col_check->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE wholesale_sales
                        ADD COLUMN vat_applied TINYINT(1) NOT NULL DEFAULT 0,
                        ADD COLUMN ewt_applied TINYINT(1) NOT NULL DEFAULT 0");
                }

                // TOTAL(기본 합계)은 total_amount, 최종금액 = TOTAL + VAT - EWT
                $base = (float)($row['total_amount'] ?? $row['final_amount'] ?? 0);
                $vat  = $vat_flag ? round($base * 0.12, 2) : 0;
                $ewt  = $ewt_flag ? round($base * 0.01, 2) : 0;
                $final = $base + $vat - $ewt;

                $upd = $pdo->prepare("UPDATE wholesale_sales SET vat_applied = ?, ewt_applied = ?, final_amount = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$vat_flag, $ewt_flag, $final, $tax_sale_id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => t('wholesale_sale_preview.tax_saved_success')];
            }
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => t('wholesale_sale_preview.tax_save_error') . $e->getMessage()];
            error_log("Wholesale tax save error: " . $e->getMessage());
        }
        header("Location: wholesale_sale_preview.php?id=$tax_sale_id");
        exit;
    }
}

if ($sale_id > 0) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 판매 정보와 고객, 점포 정보 조인해서 가져오기
        $sql = "
            SELECT 
                ws.*,
                wc.name as customer_name,
                wc.phone as customer_phone,
                wc.address as customer_address,
                s.name as store_name,
                s.phone as store_phone,
                s.address as store_address,
                s.bank_account as store_bank_account,
                u.full_name as user_name
            FROM wholesale_sales ws
            LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
            LEFT JOIN stores s ON ws.store_id = s.id
            LEFT JOIN users u ON ws.user_id = u.id
            WHERE ws.id = ?
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sale) {
            $errors[] = t('wholesale_sale_preview.sale_not_found');
        } else {
            // 스키마 호환성 확인 후 적절한 쿼리 선택
            try {
                $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
                $has_new_columns = $check_columns->rowCount() > 0;
            } catch (PDOException $e) {
                $has_new_columns = false;
            }

            // 반품 기능 마이그레이션 적용 여부 확인 (하위 호환, Design Ref: wholesale-sales-return.design.md)
            try {
                $has_returned_qty = $pdo->query("SHOW COLUMNS FROM wholesale_sale_items LIKE 'returned_quantity'")->rowCount() > 0;
            } catch (PDOException $e) {
                $has_returned_qty = false;
            }
            $returned_qty_expr = $has_returned_qty ? "wsi.returned_quantity" : "0 as returned_quantity";

            if ($has_new_columns) {
                // 새로운 스키마 사용 - 도매 상품명 필드가 있는 경우
                $items_sql = "
                    SELECT
                        wsi.id,
                        wsi.product_id,
                        wsi.quantity,
                        {$returned_qty_expr},
                        wsi.unit_price,
                        wsi.total_price,
                        wsi.sale_unit,
                        wsi.remarks,
                        wsi.custom_product_name,
                        wsi.custom_cost_price,
                        COALESCE(p.sku, '') as sku,
                        COALESCE(wp.wholesale_name_ko, p.name_ko, wsi.custom_product_name) as name_ko,
                        COALESCE(wp.wholesale_name_en, p.name_en, wsi.custom_product_name) as name_en,
                        COALESCE(p.pieces_per_box, wp.min_quantity, 1) as pieces_per_box
                    FROM wholesale_sale_items wsi
                    LEFT JOIN products p ON wsi.product_id = p.id
                    LEFT JOIN wholesale_products wp ON wp.product_id = p.id AND wp.store_id = ?
                    WHERE wsi.sale_id = ?
                    ORDER BY wsi.sort_order ASC, wsi.id ASC
                ";
                $items_stmt = $pdo->prepare($items_sql);
                $items_stmt->execute([$sale['store_id'], $sale_id]);
            } else {
                // 기존 스키마 사용 - 도매 상품명 필드가 없는 경우
                $items_sql = "
                    SELECT
                        wsi.id,
                        wsi.product_id,
                        wsi.quantity,
                        {$returned_qty_expr},
                        wsi.unit_price,
                        wsi.total_price,
                        wsi.sale_unit,
                        wsi.remarks,
                        NULL as custom_product_name,
                        wsi.custom_cost_price,
                        COALESCE(p.sku, '') as sku,
                        p.name_ko,
                        p.name_en,
                        COALESCE(p.pieces_per_box, wp.min_quantity, 1) as pieces_per_box
                    FROM wholesale_sale_items wsi
                    LEFT JOIN products p ON wsi.product_id = p.id
                    LEFT JOIN wholesale_products wp ON wp.product_id = p.id AND wp.store_id = ?
                    WHERE wsi.sale_id = ?
                    ORDER BY wsi.sort_order ASC, wsi.id ASC
                ";
                $items_stmt = $pdo->prepare($items_sql);
                $items_stmt->execute([$sale['store_id'], $sale_id]);
            }
            
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 반품 이력 조회 (Design Ref: wholesale-sales-return.design.md §5.1)
            try {
                $returns_stmt = $pdo->prepare("
                    SELECT wsr.id, wsr.reason, wsr.total_amount, wsr.created_at, u2.full_name as processed_by_name
                    FROM wholesale_sale_returns wsr
                    LEFT JOIN users u2 ON wsr.processed_by = u2.id
                    WHERE wsr.sale_id = ?
                    ORDER BY wsr.created_at DESC
                ");
                $returns_stmt->execute([$sale_id]);
                $return_history = $returns_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($return_history)) {
                    $return_ids = array_column($return_history, 'id');
                    $placeholders = implode(',', array_fill(0, count($return_ids), '?'));
                    $ri_stmt = $pdo->prepare("
                        SELECT wsri.return_id, wsri.quantity, wsri.unit_price, wsri.amount,
                               COALESCE(wsi.custom_product_name, p.name_ko, p.name_en) as product_name
                        FROM wholesale_sale_return_items wsri
                        LEFT JOIN wholesale_sale_items wsi ON wsri.sale_item_id = wsi.id
                        LEFT JOIN products p ON wsi.product_id = p.id
                        WHERE wsri.return_id IN ($placeholders)
                        ORDER BY wsri.id ASC
                    ");
                    $ri_stmt->execute($return_ids);
                    foreach ($ri_stmt->fetchAll(PDO::FETCH_ASSOC) as $ri) {
                        $return_items_by_return[$ri['return_id']][] = $ri;
                    }
                }
            } catch (PDOException $e) {
                // 반품 기능 마이그레이션 미실행 시 조용히 건너뜀 (하위 호환)
                $return_history = [];
            }
        }

    } catch (PDOException $e) {
        $errors[] = t('wholesale_sale_preview.database_error') . $e->getMessage();
        error_log("Wholesale sale preview error: " . $e->getMessage());
    }
} else {
    $errors[] = t('wholesale_sale_preview.invalid_sale_id');
}

// 상품 소계 — 이 전표에 새로 담긴 상품(wholesale_sale_items)의 합계만 (반품 차감 반영 전)
$items_subtotal = array_sum(array_column($items, 'total_price'));

// 원가 합계 (화면 전용 — 원가가 저장되지 않은 품목은 0으로 집계)
$items_cost_subtotal = 0;
foreach ($items as $__ci) {
    if ($__ci['custom_cost_price'] !== null) {
        $items_cost_subtotal += (float)$__ci['quantity'] * (float)$__ci['custom_cost_price'];
    }
}
unset($__ci);

// 반품 차감액 — 이 전표에 등록된 반품의 합계 (원본 전표는 영향 없음, wholesale_sales.returned_amount에 저장됨)
$return_deduction = (float)($sale['returned_amount'] ?? 0);

// V.A.T / E.W.T 계산용 값 (TOTAL = total_amount = 상품소계 - 반품차감액, 최종 = TOTAL + VAT - EWT)
$base_total  = 0;
$vat_applied = false;
$ewt_applied = false;
if ($sale) {
    $base_total  = (float)($sale['total_amount'] ?? $sale['final_amount'] ?? 0);
    $vat_applied = !empty($sale['vat_applied']);
    $ewt_applied = !empty($sale['ewt_applied']);
}
$vat_amount = $vat_applied ? round($base_total * 0.12, 2) : 0;
$ewt_amount = $ewt_applied ? round($base_total * 0.01, 2) : 0;
$computed_final = $base_total + $vat_amount - $ewt_amount;

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="px-2 sm:px-3 md:px-4 py-4">
    <div class="w-full max-w-none">
        <?php if (isset($flash)): ?>
            <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                            <?php echo htmlspecialchars($flash['message']); ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800"><?php echo t('wholesale_sale_preview.solve_errors'); ?></h3>
                        <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="text-center">
                <a href="wholesale_sales.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700">
                    <i class="fas fa-arrow-left mr-2"></i>
                    <?php echo t('wholesale_sale_preview.back_to_wholesale'); ?>
                </a>
            </div>
        <?php else: ?>
            <!-- 거래명세서 -->
            <div id="invoice-content" class="bg-white shadow-sm rounded-lg border p-4 print:shadow-none print:border-none">
                <!-- 상단 가운데 로고 -->
                <div class="invoice-logo text-center mb-4">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Home K Mart" style="height:60px;display:inline-block;">
                </div>
                <!-- 제목 + 액션 버튼 -->
                <?php $payment_status = $sale['payment_status'] ?? 'unpaid'; $is_paid = ($payment_status === 'paid'); ?>
                <?php $return_status = $sale['return_status'] ?? 'none'; ?>
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 mb-1">
                            <span id="invoice-title-text"><?php echo t('wholesale_sale_preview.transaction_title'); ?></span>
                            <?php if ($is_paid): ?>
                                <span class="ml-2 inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800 align-middle"><i class="fas fa-check-circle mr-1"></i><?php echo t('wholesale_sale_preview.status_paid'); ?></span>
                            <?php else: ?>
                                <span id="unpaid-status-badge" class="ml-2 inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-800 align-middle"><i class="fas fa-exclamation-circle mr-1"></i><?php echo t('wholesale_sale_preview.status_unpaid'); ?></span>
                            <?php endif; ?>
                            <?php if ($return_status === 'partial'): ?>
                                <span class="ml-2 inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-orange-100 text-orange-800 align-middle"><i class="fas fa-undo mr-1"></i><?php echo t('wholesale_sale_preview.status_return_partial'); ?></span>
                            <?php elseif ($return_status === 'full'): ?>
                                <span class="ml-2 inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-gray-200 text-gray-700 align-middle"><i class="fas fa-undo mr-1"></i><?php echo t('wholesale_sale_preview.status_return_full'); ?></span>
                            <?php endif; ?>
                        </h1>
                        <div class="text-sm text-gray-600">
                            <?php if ($is_paid): ?>
                                <div class="text-green-700 mt-1">
                                    <i class="fas fa-money-bill-wave mr-1"></i>
                                    <?php echo $sale['paid_at'] ? date('Y-m-d', strtotime($sale['paid_at'])) : ''; ?><?php echo t('wholesale_sale_preview.paid_date_suffix'); ?>
                                    <?php if (!empty($sale['payment_method'])): ?>(<?php echo htmlspecialchars($sale['payment_method']); ?>)<?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div id="preview-action-buttons" class="flex gap-2 flex-shrink-0 print:hidden">
                        <?php if ($is_paid): ?>
                            <button id="unpay-btn" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-amber-500 hover:bg-amber-600">
                                <i class="fas fa-undo mr-1.5"></i><?php echo t('wholesale_sale_preview.unpay_button'); ?>
                            </button>
                        <?php else: ?>
                            <button id="pay-btn" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700">
                                <i class="fas fa-money-bill-wave mr-1.5"></i><?php echo t('wholesale_sale_preview.mark_paid_button'); ?>
                            </button>
                        <?php endif; ?>
                        <a href="wholesale_sales.php?edit=<?php echo $sale_id; ?>" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                            <i class="fas fa-edit mr-1.5"></i><?php echo t('common.edit'); ?>
                        </a>
                        <button type="button" id="pdf-btn" style="background-color:#059669 !important;color:#fff !important;" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-file-pdf mr-1.5"></i><?php echo t('wholesale_sale_preview.pdf_download_btn'); ?>
                        </button>
                        <button type="button" id="image-btn" style="background-color:#7c3aed !important;color:#fff !important;" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-violet-600 hover:bg-violet-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-image mr-1.5"></i><?php echo t('wholesale_sale_preview.image_download_btn'); ?>
                        </button>
                        <button id="print-btn" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i class="fas fa-print mr-1.5"></i><?php echo t('common.print'); ?>
                        </button>
                        <button id="delete-btn" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                            <i class="fas fa-trash mr-1.5"></i><?php echo t('wholesale_sale_preview.cancel_sale_btn'); ?>
                        </button>
                        <a href="wholesale_sales_list.php" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i class="fas fa-arrow-left mr-1.5"></i><?php echo t('wholesale_sale_preview.back_to_list'); ?>
                        </a>
                    </div>
                </div>

                <!-- 거래처 및 날짜 정보 테이블 -->
                <div class="mb-6">
                    <table id="customer-info-table" class="info-table w-full border border-gray-200 mb-4">
                        <tbody>
                            <tr>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" style="width:45%;">
                                    <span class="text-gray-500"><?php echo t('wholesale_sale_preview.customer_label'); ?></span>
                                    <span class="cust-name font-bold text-base ml-2"><?php echo htmlspecialchars($sale['customer_name']); ?></span>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" style="width:27.5%;">
                                    <span class="text-gray-500"><?php echo t('wholesale_sale_preview.phone_label'); ?></span>
                                    <span class="ml-2"><?php echo htmlspecialchars($sale['customer_phone'] ?: '-'); ?></span>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-gray-200" style="width:27.5%;">
                                    <span class="text-gray-500"><?php echo t('wholesale_sale_preview.date_label'); ?></span>
                                    <span class="ml-2"><?php echo date('Y-m-d', strtotime($sale['sale_date'])); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 text-sm text-gray-900" colspan="3">
                                    <span class="text-gray-500"><?php echo t('wholesale_sale_preview.address_label'); ?></span>
                                    <span class="ml-2"><?php echo htmlspecialchars($sale['customer_address'] ?: '-'); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 합계금액 요약 (배달정보와 상품 리스트 사이) - 새로 담긴 상품의 소계 (반품 차감 전) -->
                <div class="mb-6">
                    <table class="summary-total-table w-full border border-gray-200">
                        <tbody>
                            <tr>
                                <th class="bg-gray-50 px-3 py-2 text-left text-sm font-medium text-gray-700 border-r border-gray-200 summary-total-label" style="width: 50%;"><?php echo t('wholesale_sale_preview.grand_total'); ?>:</th>
                                <td class="px-3 py-2 text-right text-lg font-bold text-gray-900 summary-total-value"><?php echo fmt_num($items_subtotal); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 상품 목록 테이블 -->
                <div class="mb-6">
                    <table class="product-table min-w-full border border-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200"><?php echo t('wholesale_sale_preview.sku_label'); ?></th>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200"><?php echo t('wholesale_sale_preview.product_name_label'); ?></th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200"><?php echo t('wholesale_sale_preview.pcs_per_box_label'); ?></th>
                                <?php $unit_header = t('wholesale_sale_preview.unit_header'); if ($unit_header === 'wholesale_sale_preview.unit_header') { $unit_header = '단위'; } ?>
                                <th class="unit-cell px-2 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200"><?php echo htmlspecialchars($unit_header); ?></th>
                                <th class="qty-cell px-2 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200"><?php echo t('wholesale_sale_preview.qty_label'); ?></th>
                                <th class="cost-col print:hidden px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Cost</th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200"><?php echo t('wholesale_sale_preview.unit_price_label'); ?></th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200"><?php echo t('wholesale_sale_preview.total_label'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (!empty($items)): ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="px-2 py-2 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($item['sku'] !== '' ? $item['sku'] : t('wholesale_sale_preview.manual_entry_label')); ?></td>
                                        <td class="px-2 py-2 text-sm text-gray-900 border-b border-gray-200">
                                            <?php
                                            // 디버깅용 - 실제 데이터 확인
                                            echo "<!-- DEBUG: name_en=[".htmlspecialchars($item['name_en'])."] name_ko=[".htmlspecialchars($item['name_ko'])."] -->";
                                            ?>
                                            <?php if ($item['name_en']): ?>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['name_en']); ?></div>
                                            <?php endif; ?>
                                            <?php // 수기 입력 등 영문/한글명이 동일하면 한 줄만 표시 ?>
                                            <?php if ($item['name_ko'] && $item['name_ko'] !== $item['name_en']): ?>
                                                <div class="text-sm text-gray-600"><?php echo htmlspecialchars($item['name_ko']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!$item['name_en'] && !$item['name_ko']): ?>
                                                <div class="text-sm text-gray-500">-</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 py-2 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo number_format($item['pieces_per_box']); ?></td>
                                        <?php
                                        // 판매단위 라벨 — 영문(BOX/PCS), 별도 컬럼으로 표시
                                        $is_piece = ((($item['sale_unit'] ?? 'box')) === 'piece');
                                        $unit_label = $is_piece ? 'PCS' : 'BOX';
                                        ?>
                                        <td class="unit-cell px-2 py-2 text-sm text-gray-900 text-center border-b border-gray-200"><?php echo $unit_label; ?></td>
                                        <td class="qty-cell px-2 py-2 text-sm text-gray-900 text-center border-b border-gray-200"><?php echo fmt_num($item['quantity']); ?></td>
                                        <td class="cost-col print:hidden px-2 py-2 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo $item['custom_cost_price'] !== null ? fmt_num($item['custom_cost_price']) : '-'; ?></td>
                                        <td class="px-2 py-2 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo fmt_num($item['unit_price']); ?></td>
                                        <td class="px-2 py-2 text-sm text-gray-900 text-right font-medium border-b border-gray-200"><?php echo fmt_num($item['total_price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="bg-gray-50 grand-total-row">
                            <tr>
                                <td colspan="5" class="px-2 py-2 text-right text-sm font-medium text-gray-900 border-t border-gray-200 grand-total-label"><?php echo t('wholesale_sale_preview.grand_total'); ?>:</td>
                                <td class="cost-col print:hidden px-2 py-2 text-right text-sm font-semibold text-gray-900 border-t border-gray-200"><?php echo fmt_num($items_cost_subtotal); ?></td>
                                <td class="px-2 py-2 border-t border-gray-200"></td>
                                <td class="px-2 py-2 text-right text-lg font-bold text-gray-900 border-t border-gray-200 grand-total-value"><?php echo fmt_num($items_subtotal); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (!empty($sale['scan_image_path'])): ?>
                <!-- 메뉴얼 DR(빠른등록) 첨부 스캔 이미지 -->
                <div class="mb-6">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2"><i class="fas fa-camera mr-1 text-primary-600"></i><?php echo t('wholesale_sale_preview.scan_image_title'); ?></h3>
                    <img src="ajax_wholesale_sale_scan.php?id=<?php echo (int)$sale_id; ?>" alt="<?php echo htmlspecialchars(t('wholesale_sale_preview.scan_image_title')); ?>" class="border border-gray-200 rounded-lg shadow-sm" style="max-width:100%;max-height:600px;">
                </div>
                <?php endif; ?>

                <?php if (!empty($return_history)): ?>
                <!-- 반품 이력 -->
                <div id="return-history-box" class="mb-6 print:hidden">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2"><i class="fas fa-undo mr-1 text-orange-500"></i><?php echo t('wholesale_sale_preview.return_history_title'); ?></h3>
                    <div class="space-y-2">
                        <?php foreach ($return_history as $ret): ?>
                            <div class="border border-gray-200 rounded-md p-3 bg-orange-50">
                                <div class="flex items-center justify-between text-sm">
                                    <div class="text-gray-700">
                                        <span class="font-medium"><?php echo date('Y-m-d H:i', strtotime($ret['created_at'])); ?></span>
                                        <span class="text-gray-500 ml-2"><?php echo htmlspecialchars($ret['processed_by_name'] ?? ''); ?></span>
                                        <?php if (!empty($ret['reason'])): ?>
                                            <span class="text-gray-500 ml-2"><?php echo t('wholesale_sale_preview.return_reason_prefix'); ?><?php echo htmlspecialchars($ret['reason']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="font-semibold text-orange-700"><?php echo fmt_num($ret['total_amount']); ?></div>
                                </div>
                                <?php if (!empty($return_items_by_return[$ret['id']])): ?>
                                    <ul class="mt-2 text-xs text-gray-600 list-disc list-inside">
                                        <?php foreach ($return_items_by_return[$ret['id']] as $ri): ?>
                                            <li><?php echo htmlspecialchars($ri['product_name'] ?? '-'); ?> — <?php echo fmt_num($ri['quantity']); ?> × <?php echo fmt_num($ri['unit_price']); ?> = <?php echo fmt_num($ri['amount']); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <div class="text-sm font-semibold text-orange-700"><?php echo t('wholesale_sale_preview.return_total_prefix'); ?>-<?php echo fmt_num($return_deduction); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 상품소계 - 반품차감액 = 최종 합계 (VAT/EWT 적용 전) -->
                <?php if ($return_deduction > 0): ?>
                <div class="mb-6">
                    <table class="summary-total-table w-full border border-gray-200">
                        <tbody>
                            <tr>
                                <th class="bg-gray-50 px-3 py-2 text-left text-sm font-bold text-gray-900 border-r border-gray-200" style="width: 50%;"><?php echo t('wholesale_sale_preview.final_total_after_return_label'); ?></th>
                                <td class="px-3 py-2 text-right text-lg font-bold text-gray-900"><?php echo fmt_num($base_total); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- V.A.T / E.W.T 계산 (체크박스 선택 시 반영) -->
                <div id="tax-summary-box" class="mb-6">
                    <!-- 체크박스 컨트롤 (인쇄/미리보기 제외) -->
                    <form method="POST" class="tax-controls flex flex-wrap items-center gap-4 mb-2 print:hidden">
                        <input type="hidden" name="action" value="save_tax">
                        <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
                        <label class="inline-flex items-center text-sm font-medium text-gray-700 cursor-pointer">
                            <input type="checkbox" id="vat-checkbox" name="vat_applied" value="1" <?php echo $vat_applied ? 'checked' : ''; ?> class="mr-1.5 h-4 w-4">
                            V.A.T + 12% <?php echo t('common.apply'); ?>
                        </label>
                        <label class="inline-flex items-center text-sm font-medium text-gray-700 cursor-pointer">
                            <input type="checkbox" id="ewt-checkbox" name="ewt_applied" value="1" <?php echo $ewt_applied ? 'checked' : ''; ?> class="mr-1.5 h-4 w-4">
                            E.W.T - 1% <?php echo t('common.apply'); ?>
                        </label>
                        <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700">
                            <i class="fas fa-save mr-1.5"></i><?php echo t('common.save'); ?>
                        </button>
                    </form>
                    <table class="tax-summary-table w-full border border-gray-200">
                        <tbody>
                            <tr class="vat-row" style="<?php echo $vat_applied ? '' : 'display: none;'; ?>">
                                <th class="bg-gray-50 px-3 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200 tax-summary-label" style="width: 50%;">V.A.T + 12%:</th>
                                <td class="px-3 py-2 text-right text-base text-gray-900 border-b border-gray-200 tax-summary-value vat-value">+<?php echo fmt_num($vat_amount); ?></td>
                            </tr>
                            <tr class="ewt-row" style="<?php echo $ewt_applied ? '' : 'display: none;'; ?>">
                                <th class="bg-gray-50 px-3 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200 tax-summary-label">E.W.T - 1%:</th>
                                <td class="px-3 py-2 text-right text-base text-gray-900 border-b border-gray-200 tax-summary-value ewt-value">-<?php echo fmt_num($ewt_amount); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-gray-50 px-3 py-2 text-left text-sm font-bold text-gray-900 border-r border-gray-200 tax-summary-grand-label">TOTAL + VAT - EWT:</th>
                                <td class="px-3 py-2 text-right text-lg font-bold text-gray-900 tax-summary-grand-value"><?php echo fmt_num($computed_final); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($sale['notes'])): ?>
                    <!-- 비고 -->
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo t('wholesale_sale_preview.notes_label'); ?></h3>
                        <div class="bg-gray-50 p-4 rounded-lg text-gray-700">
                            <?php echo nl2br(htmlspecialchars($sale['notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 작성자 / 인수자 서명란 -->
                <div id="signOffBox" class="mb-6">
                    <table class="payment-table w-full border border-gray-300" style="table-layout: fixed;">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-r border-gray-300" style="width: 33.33%;"><?php echo t('wholesale_sale_preview.prepared_by_label'); ?></th>
                                <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-r border-gray-300" style="width: 33.33%;"><?php echo t('wholesale_sale_preview.received_by_label'); ?></th>
                                <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-gray-300" style="width: 33.34%;"><?php echo t('wholesale_sale_preview.cashier_label'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="px-3 py-2 text-sm text-gray-900 border-r border-gray-300" style="vertical-align: top;">
                                    <div><?php echo t('wholesale_sale_preview.name_label'); ?>: <strong><?php echo htmlspecialchars($sale['user_name']); ?></strong></div>
                                    <div class="sign-space" style="height: 50px;"></div>
                                    <div style="text-align: right;"><?php echo t('wholesale_sale_preview.signature_label'); ?>: _____________________</div>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-900 border-r border-gray-300" style="vertical-align: top;">
                                    <div><?php echo t('wholesale_sale_preview.name_label'); ?>: <?php echo htmlspecialchars($sale['customer_name']); ?></div>
                                    <div class="sign-space" style="height: 50px;"></div>
                                    <div style="text-align: right;"><?php echo t('wholesale_sale_preview.signature_label'); ?>: _____________________</div>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-900" style="vertical-align: top;">
                                    <div><?php echo t('wholesale_sale_preview.name_label'); ?>: _____________________</div>
                                    <div class="sign-space" style="height: 50px;"></div>
                                    <div style="text-align: right;"><?php echo t('wholesale_sale_preview.signature_label'); ?>: _____________________</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 점포 정보 -->
                <div class="mb-6">
                    <table class="info-table w-full border border-gray-200">
                        <tbody>
                            <tr>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200" style="width:12%;"><?php echo t('wholesale_sale_preview.store_name_label'); ?></th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" style="width:23%;"><?php echo htmlspecialchars($sale['store_name'] ?: '-'); ?></td>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200" style="width:12%;"><?php echo t('wholesale_sale_preview.store_phone_label'); ?></th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" style="width:18%;"><?php echo htmlspecialchars($sale['store_phone'] ?: '-'); ?></td>
                                <td class="px-3 py-2 text-xs text-gray-500 border-b border-gray-200" style="width:35%; vertical-align: middle;"><?php echo t('wholesale_sale_preview.price_notice_1'); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200"><?php echo t('wholesale_sale_preview.address_label'); ?></th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" colspan="3"><?php echo htmlspecialchars($sale['store_address'] ?: '-'); ?></td>
                                <td class="px-3 py-2 text-xs text-gray-500 border-b border-gray-200" style="vertical-align: middle;"><?php echo t('wholesale_sale_preview.price_notice_2'); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-r border-gray-200"><?php echo t('wholesale_sale_preview.store_account_label'); ?></th>
                                <td class="px-3 py-2 text-sm text-gray-900" colspan="4"><?php echo htmlspecialchars($sale['store_bank_account'] ?: '-'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 삭제 확인 모달 -->
<div id="delete-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                </div>
                <div class="text-center">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 mb-2"><?php echo t('wholesale_sale_preview.delete_modal_title'); ?></h3>
                    <div class="text-sm text-gray-500 mb-4">
                        <p><?php echo t('wholesale_sale_preview.delete_confirm'); ?></p>
                        <p class="font-semibold text-red-600 mt-2"><?php echo t('wholesale_sale_preview.delete_warning'); ?></p>
                    </div>
                </div>
                <div class="flex space-x-3 justify-center">
                    <button id="cancel-delete" type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        <?php echo t('common.cancel'); ?>
                    </button>
                    <button id="confirm-delete" type="button" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                        <?php echo t('common.delete'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 삭제 폼 (숨김) -->
<form id="delete-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
</form>

<!-- 결제완료 처리 모달 -->
<div id="payment-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-sm w-full">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900"><i class="fas fa-money-bill-wave mr-2 text-emerald-600"></i><?php echo t('wholesale_sale_preview.mark_paid_button'); ?></h3>
                <button type="button" id="close-payment-modal" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <form method="POST" class="p-4">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('wholesale_sale_preview.payment_method_label'); ?></label>
                <select name="payment_method" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 mb-4">
                    <option value="현금"><?php echo t('wholesale_sale_preview.payment_method_cash'); ?></option>
                    <option value="계좌이체"><?php echo t('wholesale_sale_preview.payment_method_transfer'); ?></option>
                    <option value="카드"><?php echo t('wholesale_sale_preview.payment_method_card'); ?></option>
                    <option value="수표"><?php echo t('wholesale_sale_preview.payment_method_check'); ?></option>
                    <option value="기타"><?php echo t('wholesale_sale_preview.payment_method_other'); ?></option>
                </select>
                <div class="flex justify-end gap-2">
                    <button type="button" id="cancel-payment" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50"><?php echo t('common.cancel'); ?></button>
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700"><i class="fas fa-check mr-1"></i><?php echo t('wholesale_sale_preview.status_paid'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 미결제 전환 폼 (숨김) -->
<form id="unpay-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="mark_unpaid">
    <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
</form>

<!-- 인쇄 미리보기 모달 -->
<div id="print-preview-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 hidden z-50 overflow-y-auto">
    <div class="min-h-screen px-4 py-6">
        <!-- 모달 헤더 (고정) -->
        <div class="sticky top-0 z-10 bg-white rounded-t-lg mx-auto px-4 py-3 flex items-center justify-between border-b" style="max-width: 810px;">
            <h3 class="text-lg font-semibold text-gray-900"><?php echo t('wholesale_sale_preview.print_preview_title'); ?></h3>
            <div class="flex items-center gap-3">
                <button id="modal-print-btn" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                    <i class="fas fa-print mr-2"></i><?php echo t('wholesale_sale_preview.print_btn'); ?>
                </button>
                <button id="modal-close-btn" class="inline-flex items-center px-4 py-2 bg-gray-500 text-white text-sm font-medium rounded-md hover:bg-gray-600">
                    <i class="fas fa-times mr-2"></i><?php echo t('common.close'); ?>
                </button>
            </div>
        </div>
        <!-- 모달 콘텐츠 -->
        <div class="bg-white mx-auto rounded-b-lg shadow-xl" style="max-width: 810px;">
            <div id="print-preview-content" class="print-preview-wrapper p-4">
                <!-- 여기에 invoice-content가 복사됨 -->
            </div>
        </div>
    </div>
</div>

<style>
/* 수량 열 배경색 분홍색 + 볼드 (화면 + 미리보기 공통) */
.product-table th.qty-cell,
.product-table td.qty-cell {
    background-color: #fbcfe8 !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}
/* 수량 셀 폰트 - 화면 기본 (text-sm 14px → +2 = 16px) */
.product-table td.qty-cell { font-size: 16px !important; }
/* 미리보기 모달 - 기존 9px → +2 = 11px */
.print-preview-wrapper .product-table th.qty-cell,
.print-preview-wrapper .product-table td.qty-cell { font-size: 11px !important; }

/* 거래명세서 글자색 검정 통일 (PDF 버튼 제외) */
#invoice-content,
#invoice-content * { color: #000 !important; }
#invoice-content #pdf-btn,
#invoice-content #pdf-btn *,
#invoice-content #image-btn,
#invoice-content #image-btn * { color: #fff !important; }

/* 인쇄 미리보기 모달 스타일 - 폰트 9px 통일 */
.print-preview-wrapper {
    font-size: 9px !important;
    line-height: 1.3;
}
.print-preview-wrapper #invoice-content {
    box-shadow: none;
    border: none;
    padding: 10px;
}
/* 미리보기 표 테두리 검정 통일 (회색 → 검정) */
.print-preview-wrapper table { border-collapse: collapse !important; }
.print-preview-wrapper .info-table,
.print-preview-wrapper .info-table th,
.print-preview-wrapper .info-table td,
.print-preview-wrapper .product-table,
.print-preview-wrapper .product-table th,
.print-preview-wrapper .product-table td,
.print-preview-wrapper .payment-table,
.print-preview-wrapper .payment-table th,
.print-preview-wrapper .payment-table td,
.print-preview-wrapper .summary-total-table,
.print-preview-wrapper .summary-total-table th,
.print-preview-wrapper .summary-total-table td,
.print-preview-wrapper .tax-summary-table,
.print-preview-wrapper .tax-summary-table th,
.print-preview-wrapper .tax-summary-table td {
    border: 1px solid #000 !important;
}
/* 배달정보 하단 합계금액 요약 폰트 크기 */
.print-preview-wrapper .summary-total-table .summary-total-value { font-size: 14px !important; font-weight: 700 !important; }
.print-preview-wrapper .summary-total-table .summary-total-label { font-size: 11px !important; }
/* V.A.T / E.W.T 요약 폰트 크기 */
.print-preview-wrapper .tax-summary-table .tax-summary-value { font-size: 11px !important; }
.print-preview-wrapper .tax-summary-table .tax-summary-label { font-size: 11px !important; }
.print-preview-wrapper .tax-summary-table .tax-summary-grand-value { font-size: 14px !important; font-weight: 700 !important; }
.print-preview-wrapper .tax-summary-table .tax-summary-grand-label { font-size: 12px !important; font-weight: 700 !important; }
.print-preview-wrapper h1 {
    font-size: 14px !important;
    margin-bottom: 8px !important;
}
.print-preview-wrapper h3 {
    font-size: 11px !important;
}
.print-preview-wrapper table {
    font-size: 9px !important;
}
.print-preview-wrapper table th {
    font-size: 9px !important;
    padding: 3px 5px !important;
}
.print-preview-wrapper table td {
    font-size: 9px !important;
    padding: 3px 5px !important;
}
.print-preview-wrapper .info-table th,
.print-preview-wrapper .info-table td {
    font-size: 9px !important;
    padding: 3px 5px !important;
}
/* 거래처명 강조 (다른 셀 9px 대비 +2px, 볼드) */
.print-preview-wrapper .info-table .cust-name {
    font-size: 11px !important;
    font-weight: 700 !important;
}
/* 상단 로고 */
.print-preview-wrapper .invoice-logo { text-align: center !important; }
.print-preview-wrapper .invoice-logo img { height: 50px !important; display: inline-block !important; }
.print-preview-wrapper .payment-table {
    width: 100% !important;
    table-layout: fixed !important;
}
.print-preview-wrapper .payment-table th,
.print-preview-wrapper .payment-table td {
    font-size: 9px !important;
    padding: 3px 5px !important;
}
.print-preview-wrapper .payment-table .sign-space {
    height: 36px !important;
}
.print-preview-wrapper .text-center.text-xs {
    font-size: 9px !important;
}
/* 상품 테이블 컬럼 너비 */
.print-preview-wrapper .product-table {
    table-layout: fixed;
    width: 100%;
}
/* 7컬럼: 1 SKU · 2 상품명 · 3 박스당수량 · 4 단위 · 5 수량 · 6 단가 · 7 합계 */
.print-preview-wrapper .product-table th:nth-child(1),
.print-preview-wrapper .product-table td:nth-child(1) { width: 11%; }
.print-preview-wrapper .product-table th:nth-child(2),
.print-preview-wrapper .product-table td:nth-child(2) { width: 40%; font-size: 6px !important; }
/* 상품명 셀 내부 div(text-sm 등)가 폰트 크기를 키우는 것 방지 */
.print-preview-wrapper .product-table td:nth-child(2) div { font-size: 6px !important; line-height: 1.25 !important; }
.print-preview-wrapper .product-table th:nth-child(3),
.print-preview-wrapper .product-table td:nth-child(3) { width: 10%; }
.print-preview-wrapper .product-table th:nth-child(3) { white-space: nowrap; }
.print-preview-wrapper .product-table th:nth-child(4),
.print-preview-wrapper .product-table td:nth-child(4) { width: 7%; }
.print-preview-wrapper .product-table th:nth-child(5),
.print-preview-wrapper .product-table td:nth-child(5) { width: 8%; }
.print-preview-wrapper .product-table th:nth-child(6),
.print-preview-wrapper .product-table td:nth-child(6) { width: 10%; }
.print-preview-wrapper .product-table th:nth-child(7),
.print-preview-wrapper .product-table td:nth-child(7) { width: 14%; }
/* Grand Total 폰트 사이즈 증가 */
.print-preview-wrapper .product-table tfoot .grand-total-value {
    font-size: 18px !important;
}
.print-preview-wrapper .product-table tfoot .grand-total-label {
    font-size: 14px !important;
}
</style>

<script id="html2pdf-script" src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.3/html2pdf.bundle.min.js"
        onerror="this.onerror=null;this.src='../public/js/lib/html2pdf.bundle.min.js';"></script>
<script id="html2canvas-script" src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"
        onerror="this.onerror=null;this.src='../public/js/lib/html2canvas.min.js';"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const printBtn = document.getElementById('print-btn');
    const deleteBtn = document.getElementById('delete-btn');
    const deleteModal = document.getElementById('delete-modal');
    const cancelDelete = document.getElementById('cancel-delete');
    const confirmDelete = document.getElementById('confirm-delete');
    const deleteForm = document.getElementById('delete-form');

    function loadHtml2Pdf() {
        if (typeof window.html2pdf === 'function') return Promise.resolve(window.html2pdf);
        return new Promise(function(resolve, reject) {
            const script = document.getElementById('html2pdf-script');
            if (!script) return reject(new Error('PDF library script is missing'));
            const finish = function() {
                if (typeof window.html2pdf === 'function') resolve(window.html2pdf);
                else reject(new Error('PDF library did not initialize'));
            };
            script.addEventListener('load', finish, { once: true });
            script.addEventListener('error', function() { reject(new Error('PDF library failed to load')); }, { once: true });
            // Handles a script that already failed before this handler was attached.
            setTimeout(finish, 0);
        });
    }

    function loadHtml2Canvas() {
        if (typeof window.html2canvas === 'function') return Promise.resolve(window.html2canvas);
        return new Promise(function(resolve, reject) {
            const script = document.getElementById('html2canvas-script');
            if (!script) return reject(new Error('Image library script is missing'));
            const finish = function() {
                if (typeof window.html2canvas === 'function') resolve(window.html2canvas);
                else reject(new Error('Image library did not initialize'));
            };
            script.addEventListener('load', finish, { once: true });
            script.addEventListener('error', function() { reject(new Error('Image library failed to load')); }, { once: true });
            setTimeout(finish, 0);
        });
    }
    
    // 인쇄 미리보기와 PDF가 동일한 명세서 내용을 사용한다.
    function renderPrintContent() {
        const invoiceContent = document.getElementById('invoice-content');
        const printPreviewContent = document.getElementById('print-preview-content');
        if (!invoiceContent || !printPreviewContent) return null;

        const clone = invoiceContent.cloneNode(true);
        const actionBtns = clone.querySelector('#preview-action-buttons');
        if (actionBtns) actionBtns.remove();
        const taxControls = clone.querySelector('.tax-controls');
        if (taxControls) taxControls.remove();
        const unpaidBadge = clone.querySelector('#unpaid-status-badge');
        if (unpaidBadge) unpaidBadge.remove();
        const titleText = clone.querySelector('#invoice-title-text');
        if (titleText) titleText.remove();
        clone.querySelectorAll('.cost-col').forEach(function(el) { el.remove(); });
        printPreviewContent.innerHTML = clone.outerHTML;
        return printPreviewContent;
    }

    // 인쇄 버튼 - 모달 팝업으로 미리보기
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            if (!renderPrintContent()) return;
            const printModal = document.getElementById('print-preview-modal');
            if (!printModal) return;
            printModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });
    }
    
    // 삭제 버튼
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            deleteModal.classList.remove('hidden');
        });
    }

    // 결제완료 처리 버튼 → 모달
    const payBtn = document.getElementById('pay-btn');
    const paymentModal = document.getElementById('payment-modal');
    const closePaymentModal = document.getElementById('close-payment-modal');
    const cancelPayment = document.getElementById('cancel-payment');
    if (payBtn && paymentModal) {
        payBtn.addEventListener('click', function() { paymentModal.classList.remove('hidden'); });
        if (closePaymentModal) closePaymentModal.addEventListener('click', function() { paymentModal.classList.add('hidden'); });
        if (cancelPayment) cancelPayment.addEventListener('click', function() { paymentModal.classList.add('hidden'); });
        paymentModal.addEventListener('click', function(e) { if (e.target === paymentModal) paymentModal.classList.add('hidden'); });
    }

    // V.A.T / E.W.T 계산 (체크박스 선택 시 반영) - TOTAL(기본 합계) 기준
    const taxBase = <?php echo (float)$base_total; ?>;
    const vatCheckbox = document.getElementById('vat-checkbox');
    const ewtCheckbox = document.getElementById('ewt-checkbox');

    // PHP fmt_num 과 동일한 형식 (정수는 콤마만, 소수는 둘째자리까지 후행 0 제거)
    function fmtNum(v) {
        v = Number(v);
        if (v === Math.floor(v)) {
            return v.toLocaleString('en-US');
        }
        let s = v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return s.replace(/0+$/, '').replace(/\.$/, '');
    }

    function recalcTax() {
        if (!vatCheckbox || !ewtCheckbox) return;
        const vatRow = document.querySelector('.vat-row');
        const ewtRow = document.querySelector('.ewt-row');
        const vatAmt = Math.round(taxBase * 0.12 * 100) / 100;
        const ewtAmt = Math.round(taxBase * 0.01 * 100) / 100;
        let total = taxBase;

        if (vatCheckbox.checked) {
            total += vatAmt;
            if (vatRow) vatRow.style.display = '';
            const vc = document.querySelector('.vat-value');
            if (vc) vc.textContent = '+' + fmtNum(vatAmt);
        } else if (vatRow) {
            vatRow.style.display = 'none';
        }

        if (ewtCheckbox.checked) {
            total -= ewtAmt;
            if (ewtRow) ewtRow.style.display = '';
            const ec = document.querySelector('.ewt-value');
            if (ec) ec.textContent = '-' + fmtNum(ewtAmt);
        } else if (ewtRow) {
            ewtRow.style.display = 'none';
        }

        const gv = document.querySelector('.tax-summary-grand-value');
        if (gv) gv.textContent = fmtNum(total);

        // 상단 합계금액 요약도 TOTAL + VAT - EWT 값으로 동기화
        const stv = document.querySelector('.summary-total-value');
        if (stv) stv.textContent = fmtNum(total);
    }

    if (vatCheckbox) vatCheckbox.addEventListener('change', recalcTax);
    if (ewtCheckbox) ewtCheckbox.addEventListener('change', recalcTax);
    recalcTax();

    // 미결제로 변경 버튼
    const unpayBtn = document.getElementById('unpay-btn');
    const unpayForm = document.getElementById('unpay-form');
    if (unpayBtn && unpayForm) {
        unpayBtn.addEventListener('click', function() {
            if (confirm('<?php echo addslashes(t('wholesale_sale_preview.js_confirm_mark_unpaid')); ?>')) unpayForm.submit();
        });
    }
    
    // 삭제 취소
    if (cancelDelete) {
        cancelDelete.addEventListener('click', function() {
            deleteModal.classList.add('hidden');
        });
    }
    
    // 삭제 확인
    if (confirmDelete) {
        confirmDelete.addEventListener('click', function() {
            deleteForm.submit();
        });
    }
    
    // 모달 외부 클릭시 닫기
    if (deleteModal) {
        deleteModal.addEventListener('click', function(e) {
            if (e.target === deleteModal) {
                deleteModal.classList.add('hidden');
            }
        });
    }
    
    // ESC 키로 모달 닫기
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (deleteModal && !deleteModal.classList.contains('hidden')) {
                deleteModal.classList.add('hidden');
            }
            const printModal = document.getElementById('print-preview-modal');
            if (printModal && !printModal.classList.contains('hidden')) {
                printModal.classList.add('hidden');
                document.body.style.overflow = '';
            }
        }
    });

    // 인쇄 미리보기 모달 - 닫기 버튼
    const modalCloseBtn = document.getElementById('modal-close-btn');
    const printPreviewModal = document.getElementById('print-preview-modal');

    if (modalCloseBtn) {
        modalCloseBtn.addEventListener('click', function() {
            printPreviewModal.classList.add('hidden');
            document.body.style.overflow = '';
        });
    }

    // 거래명세서 화면에서 인쇄용 내용을 바로 PDF로 저장한다.
    const pdfBtn = document.getElementById('pdf-btn');
    if (pdfBtn) {
        pdfBtn.addEventListener('click', async function() {
            const content = renderPrintContent();
            if (!content) return;
            let pdfFactory;
            try {
                pdfFactory = await loadHtml2Pdf();
            } catch (error) {
                alert(<?php echo json_encode(t('wholesale_sale_preview.pdf_load_error'), JSON_UNESCAPED_UNICODE); ?>);
                return;
            }

            pdfBtn.disabled = true;
            try {
                await pdfFactory().set({
                    margin: 8,
                    filename: 'wholesale-sale-<?php echo (int)$sale_id; ?>.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.invoice-logo'] }
                }).from(content).save();
            } catch (error) {
                console.error('PDF download failed:', error);
                alert(<?php echo json_encode(t('wholesale_sale_preview.pdf_save_error'), JSON_UNESCAPED_UNICODE); ?>);
            } finally {
                pdfBtn.disabled = false;
            }
        });
    }

    // 거래명세서 화면에서 인쇄용 내용을 PNG 이미지로 저장한다.
    const imageBtn = document.getElementById('image-btn');
    if (imageBtn) {
        imageBtn.addEventListener('click', async function() {
            const content = renderPrintContent();
            if (!content) return;
            try {
                await loadHtml2Canvas();
            } catch (error) {
                alert(<?php echo json_encode(t('wholesale_sale_preview.image_load_error'), JSON_UNESCAPED_UNICODE); ?>);
                return;
            }

            imageBtn.disabled = true;
            let captureHost;
            try {
                // 인쇄 모달은 숨겨져 있으므로, 캡처할 때만 화면 밖의 표시 영역에 복제한다.
                captureHost = document.createElement('div');
                captureHost.style.cssText = 'position:fixed;left:-100000px;top:0;display:block;width:794px;background:#fff;z-index:9999;';
                const captureClone = content.cloneNode(true);
                captureClone.style.display = 'block';
                captureClone.style.visibility = 'visible';
                captureClone.style.width = '100%';
                captureHost.appendChild(captureClone);
                document.body.appendChild(captureHost);
                captureClone.querySelectorAll('th, td').forEach(function(cell) {
                    const originalHeight = cell.clientHeight;
                    const styles = window.getComputedStyle(cell);
                    cell.style.setProperty('vertical-align', 'middle', 'important');
                    cell.style.setProperty('line-height', '1.3', 'important');
                    const cellContent = document.createElement('div');
                    cellContent.style.cssText = 'display:flex;align-items:center;width:100%;box-sizing:border-box;height:' + Math.max(0, originalHeight - parseFloat(styles.paddingTop) - parseFloat(styles.paddingBottom)) + 'px;';
                    while (cell.firstChild) cellContent.appendChild(cell.firstChild);
                    cell.appendChild(cellContent);
                });

                const canvas = await window.html2canvas(captureHost, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    logging: false,
                    windowWidth: 794,
                    onclone: function(clonedDocument) {
                        const clonedHost = clonedDocument.body.lastElementChild;
                        if (clonedHost) clonedHost.style.display = 'block';
                    }
                });
                const blob = await new Promise(function(resolve, reject) {
                    canvas.toBlob(function(result) {
                        if (result) resolve(result);
                        else reject(new Error('PNG blob creation failed'));
                    }, 'image/png');
                });
                const objectUrl = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.download = 'wholesale-sale-<?php echo (int)$sale_id; ?>.png';
                link.href = objectUrl;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(function() { URL.revokeObjectURL(objectUrl); }, 1000);
            } catch (error) {
                console.error('Image download failed:', error);
                alert(<?php echo json_encode(t('wholesale_sale_preview.image_save_error'), JSON_UNESCAPED_UNICODE); ?>);
            } finally {
                if (captureHost) captureHost.remove();
                imageBtn.disabled = false;
            }
        });
    }

    // 인쇄 미리보기 모달 - 인쇄 버튼
    const modalPrintBtn = document.getElementById('modal-print-btn');
    if (modalPrintBtn) {
        modalPrintBtn.addEventListener('click', function() {
            const singleContent = document.getElementById('print-preview-content').innerHTML;
            // 프린터 출력 시 동일 명세서 1부(1페이지)로 출력
            const printContent = '<div class="print-copy">' + singleContent + '</div>';

            // 인쇄용 iframe 생성
            const printFrame = document.createElement('iframe');
            printFrame.style.position = 'absolute';
            printFrame.style.top = '-10000px';
            printFrame.style.left = '-10000px';
            document.body.appendChild(printFrame);

            const printDoc = printFrame.contentDocument || printFrame.contentWindow.document;
            printDoc.open();
            printDoc.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="UTF-8">
                    <title><?php echo addslashes(t('wholesale_sale_preview.print_title')); ?></title>
                    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                    <style>
                        body {
                            font-family: 'Malgun Gothic', sans-serif;
                            font-size: 9px;
                            line-height: 1.3;
                            padding: 5mm;
                        }
                        /* 출력 글자색 검정 통일 */
                        body, body * { color: #000 !important; }
                        /* 출력 표 테두리 검정 통일 (회색 → 검정) */
                        table { border-collapse: collapse !important; }
                        .info-table, .info-table th, .info-table td,
                        .product-table, .product-table th, .product-table td,
                        .payment-table, .payment-table th, .payment-table td,
                        .summary-total-table, .summary-total-table th, .summary-total-table td,
                        .tax-summary-table, .tax-summary-table th, .tax-summary-table td {
                            border: 1px solid #000 !important;
                        }
                        .summary-total-table .summary-total-value { font-size: 14px !important; font-weight: 700 !important; }
                        .summary-total-table .summary-total-label { font-size: 11px !important; }
                        .tax-summary-table .tax-summary-value { font-size: 11px !important; }
                        .tax-summary-table .tax-summary-label { font-size: 11px !important; }
                        .tax-summary-table .tax-summary-grand-value { font-size: 14px !important; font-weight: 700 !important; }
                        .tax-summary-table .tax-summary-grand-label { font-size: 12px !important; font-weight: 700 !important; }
                        #invoice-content {
                            box-shadow: none;
                            border: none;
                        }
                        h1 { font-size: 14px !important; margin-bottom: 8px !important; }
                        h3 { font-size: 11px !important; }
                        table { font-size: 9px !important; }
                        table th { font-size: 9px !important; padding: 3px 5px !important; }
                        table td { font-size: 9px !important; padding: 3px 5px !important; }
                        .info-table th, .info-table td { font-size: 9px !important; padding: 3px 5px !important; }
                        .info-table .cust-name { font-size: 11px !important; font-weight: 700 !important; }
                        .invoice-logo { text-align: center !important; margin-bottom: 8px !important; }
                        .invoice-logo img { height: 55px !important; display: inline-block !important; }
                        .payment-table { width: 100% !important; table-layout: fixed !important; }
                        .payment-table th, .payment-table td { font-size: 9px !important; padding: 3px 5px !important; }
                        .payment-table .sign-space { height: 40px !important; }
                        .text-center.text-xs { font-size: 9px !important; }
                        .product-table { table-layout: fixed; width: 100%; }
                        /* 7컬럼: 1 SKU · 2 상품명 · 3 박스당수량 · 4 단위 · 5 수량 · 6 단가 · 7 합계 */
                        .product-table th:nth-child(1), .product-table td:nth-child(1) { width: 11%; }
                        .product-table th:nth-child(2), .product-table td:nth-child(2) { width: 40%; font-size: 6px !important; }
                        .product-table td:nth-child(2) div { font-size: 9px !important; line-height: 1.25 !important; }
                        .product-table th:nth-child(3), .product-table td:nth-child(3) { width: 10%; }
                        .product-table th:nth-child(3) { white-space: nowrap; }
                        .product-table th:nth-child(4), .product-table td:nth-child(4) { width: 7%; }
                        .product-table th:nth-child(5), .product-table td:nth-child(5) { width: 8%; }
                        .product-table th:nth-child(6), .product-table td:nth-child(6) { width: 10%; }
                        .product-table th:nth-child(7), .product-table td:nth-child(7) { width: 14%; }
                        /* 수량 열 배경색 분홍색 + 볼드 + 폰트 +2 (9→11px) */
                        .product-table th.qty-cell,
                        .product-table td.qty-cell {
                            background-color: #fbcfe8 !important;
                            font-weight: 700 !important;
                            -webkit-print-color-adjust: exact !important;
                            print-color-adjust: exact !important;
                        }
                        .product-table td.qty-cell { font-size: 11px !important; }
                        /* Grand Total 폰트 사이즈 증가 */
                        .product-table tfoot .grand-total-value { font-size: 18px !important; }
                        .product-table tfoot .grand-total-label { font-size: 14px !important; }
                        @page { size: A4; margin: 8mm; }
                        /* 3부 출력 — 각 부를 새 페이지로 */
                        .print-copy { page-break-after: always; }
                        .print-copy:last-child { page-break-after: auto; }
                        .product-table { page-break-inside: auto !important; }
                        .product-table thead { display: table-header-group; }
                        .product-table tbody tr { page-break-inside: avoid; }
                        /* 합계금액(tfoot)은 매 페이지 반복하지 않고 마지막 페이지에만 표시 */
                        .product-table tfoot { display: table-row-group; }
                    </style>
                </head>
                <body>${printContent}</body>
                </html>
            `);
            printDoc.close();

            // CSS 로딩 대기 후 인쇄
            printFrame.onload = function() {
                setTimeout(function() {
                    printFrame.contentWindow.print();
                    setTimeout(function() {
                        document.body.removeChild(printFrame);
                    }, 1000);
                }, 500);
            };
        });
    }

    // 인쇄 미리보기 모달 - 외부 클릭시 닫기
    if (printPreviewModal) {
        printPreviewModal.addEventListener('click', function(e) {
            if (e.target === printPreviewModal) {
                printPreviewModal.classList.add('hidden');
                document.body.style.overflow = '';
            }
        });
    }

});
</script>

<style>
@media print {
    /* A4 페이지 설정 */
    @page {
        size: A4;
        margin: 10mm 5mm;
    }

    /* 기본 설정 */
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* 인쇄에서 숨길 요소들 */
    header,
    nav,
    footer,
    .no-print,
    #pdf-btn,
    #image-btn,
    #print-btn,
    #delete-btn,
    #delete-modal,
    #delete-form,
    .mb-6.text-right,
    nav[aria-label="Breadcrumb"],
    .px-2.sm\\:px-3.md\\:px-4.py-4 > .w-full > .mb-6:first-child {
        display: none !important;
        visibility: hidden !important;
    }

    /* 컨테이너 초기화 */
    .px-2, .sm\\:px-3, .md\\:px-4, .py-4,
    .w-full, .max-w-none {
        padding: 0 !important;
        margin: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
    }

    #invoice-content {
        display: block !important;
        visibility: visible !important;
        position: relative !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 5mm !important;
        box-shadow: none !important;
        border: none !important;
        font-size: 11px !important;
        line-height: 1.3 !important;
        color: #000000 !important;
        background: white !important;
    }

    #invoice-content * {
        visibility: visible !important;
    }

    /* 모든 텍스트 요소를 검정색으로 설정 */
    #invoice-content * {
        color: #000000 !important;
    }

    /* 표 테두리 검정 통일 (회색 → 검정) */
    #invoice-content table { border-collapse: collapse !important; }
    #invoice-content .info-table,
    #invoice-content .info-table th,
    #invoice-content .info-table td,
    #invoice-content .product-table,
    #invoice-content .product-table th,
    #invoice-content .product-table td,
    #invoice-content .payment-table,
    #invoice-content .payment-table th,
    #invoice-content .payment-table td,
    #invoice-content .summary-total-table,
    #invoice-content .summary-total-table th,
    #invoice-content .summary-total-table td,
    #invoice-content .tax-summary-table,
    #invoice-content .tax-summary-table th,
    #invoice-content .tax-summary-table td {
        border: 1px solid #000 !important;
    }
    #invoice-content .summary-total-table .summary-total-value { font-size: 15px !important; font-weight: 700 !important; }
    #invoice-content .summary-total-table .summary-total-label { font-size: 12px !important; }
    #invoice-content .tax-summary-table .tax-summary-value { font-size: 11px !important; }
    #invoice-content .tax-summary-table .tax-summary-label { font-size: 11px !important; }
    #invoice-content .tax-summary-table .tax-summary-grand-value { font-size: 15px !important; font-weight: 700 !important; }
    #invoice-content .tax-summary-table .tax-summary-grand-label { font-size: 12px !important; font-weight: 700 !important; }

    /* 제목 크기 조정 */
    #invoice-content h1 {
        font-size: 17px !important;
        margin-bottom: 8px !important;
    }
    
    #invoice-content h3 {
        font-size: 13px !important;
        margin-bottom: 6px !important;
    }
    
    /* 테이블 최적화 */
    #invoice-content table {
        font-size: 10px !important;
        margin-bottom: 10px !important;
    }
    
    #invoice-content table th {
        font-size: 9px !important;
        padding: 3px 4px !important;
        font-weight: 600 !important;
    }
    
    #invoice-content table td {
        font-size: 10px !important;
        padding: 3px 4px !important;
    }
    
    /* 총 합계 강조 */
    #invoice-content tfoot td {
        font-size: 11px !important;
        font-weight: bold !important;
    }
    
    /* 정보 테이블 최적화 - 클래스 기반 선택자 사용 */
    .info-table {
        margin-bottom: 8px !important;
        font-size: 9px !important;
        width: 100% !important;
        table-layout: fixed !important;
    }
    
    .info-table th {
        background: #f8f9fa !important;
        font-size: 8px !important;
        padding: 2px 3px !important;
        font-weight: 600 !important;
        text-align: left !important;
    }
    
    .info-table td {
        font-size: 9px !important;
        padding: 2px 3px !important;
    }

    /* 거래처명 강조 (+2px, 볼드) */
    .info-table .cust-name {
        font-size: 11px !important;
        font-weight: 700 !important;
    }

    /* 상단 가운데 로고 */
    #invoice-content .invoice-logo {
        text-align: center !important;
        margin-bottom: 8px !important;
    }
    #invoice-content .invoice-logo img {
        height: 55px !important;
        display: inline-block !important;
    }
    
    /* 푸터 */
    #invoice-content .text-center.text-xs {
        font-size: 9px !important;
        margin-top: 12px !important;
        padding-top: 8px !important;
    }
    
    /* 그리드 레이아웃 최적화 */
    #invoice-content .grid {
        gap: 10px !important;
        margin-bottom: 12px !important;
    }
    
    
    
    /* 페이지 나눔 설정 - 테이블은 자연스럽게 넘어가도록 허용 */
    .product-table {
        page-break-inside: auto !important;
    }

    .product-table thead {
        display: table-header-group; /* 각 페이지에 헤더 반복 */
    }

    .product-table tbody tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }

    .product-table tfoot {
        display: table-footer-group;
    }

    /* 정보 테이블과 서명란은 나눔 방지 */
    .info-table,
    .payment-table {
        page-break-inside: avoid;
    }
    
    /* 작성자 / 인수자 서명란 테이블 최적화 */
    .payment-table {
        font-size: 9px !important;
        margin-bottom: 8px !important;
        width: 100% !important;
        table-layout: fixed !important;
    }

    .payment-table th {
        background: #f8f9fa !important;
        font-size: 8px !important;
        padding: 2px 3px !important;
        font-weight: 600 !important;
        text-align: center !important;
        width: 50% !important;
    }

    .payment-table td {
        font-size: 8px !important;
        padding: 4px 6px !important;
        width: 50% !important;
    }

    .payment-table .sign-space {
        height: 40px !important;
    }

    /* 상품 테이블 컬럼 너비 최적화 - 클래스 기반 선택자 사용 */
    .product-table {
        table-layout: fixed !important;
        width: 100% !important;
    }

    /* 7컬럼: 1 SKU · 2 상품명 · 3 박스당수량 · 4 단위 · 5 수량 · 6 판매가 · 7 합계 */
    .product-table th:nth-child(1),
    .product-table td:nth-child(1) {
        width: 10% !important;
        max-width: 10% !important;
        min-width: 10% !important;
    } /* SKU */

    #invoice-content .product-table th:nth-child(2),
    #invoice-content .product-table td:nth-child(2) {
        width: 32% !important;
        max-width: 32% !important;
        min-width: 32% !important;
        font-size: 7px !important;
    } /* 상품명 */
    /* 상품명 셀 내부 div(text-sm 등)가 폰트 크기를 키우는 것 방지 */
    #invoice-content .product-table td:nth-child(2) div {
        font-size: 10px !important;
        line-height: 1.2 !important;
    }

    .product-table th:nth-child(3),
    .product-table td:nth-child(3) {
        width: 10% !important;
        max-width: 10% !important;
        min-width: 10% !important;
    } /* 박스포장수량 */
    .product-table th:nth-child(3) { white-space: nowrap !important; }

    .product-table th:nth-child(4),
    .product-table td:nth-child(4) {
        width: 7% !important;
        max-width: 7% !important;
        min-width: 7% !important;
    } /* 단위 */

    .product-table th:nth-child(5),
    .product-table td:nth-child(5) {
        width: 8% !important;
        max-width: 8% !important;
        min-width: 8% !important;
    } /* 수량 */

    .product-table th:nth-child(6),
    .product-table td:nth-child(6) {
        width: 10% !important;
        max-width: 10% !important;
        min-width: 10% !important;
    } /* 판매가 */

    .product-table th:nth-child(7),
    .product-table td:nth-child(7) {
        width: 13% !important;
        max-width: 13% !important;
        min-width: 13% !important;
    } /* 합계금액 */

    /* 수량 열 배경색 분홍색 + 볼드 + 폰트 +2 (10→12px) */
    #invoice-content .product-table th.qty-cell,
    #invoice-content .product-table td.qty-cell {
        background-color: #fbcfe8 !important;
        font-weight: 700 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    #invoice-content .product-table td.qty-cell { font-size: 12px !important; }

    /* Grand Total 폰트 사이즈 증가 */
    #invoice-content .product-table tfoot .grand-total-value {
        font-size: 20px !important;
    }
    #invoice-content .product-table tfoot .grand-total-label {
        font-size: 14px !important;
    }
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
