<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = 'Wholesale Sale Preview' . ' - ' . t('company.name');
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
$errors = [];

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
                    'message' => '삭제 권한이 없거나 해당 판매 내역을 찾을 수 없습니다.'
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
                    'message' => '판매 내역이 성공적으로 삭제되었습니다.'
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
                'message' => '삭제 중 오류가 발생했습니다: ' . $e->getMessage()
            ];
            
            error_log("Wholesale sale delete error: " . $e->getMessage());
        }
        
        // 오류가 있었다면 현재 페이지로 리다이렉트
        header("Location: wholesale_sale_preview.php?id=$delete_sale_id");
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
            $errors[] = '해당 판매 내역을 찾을 수 없습니다.';
        } else {
            // 스키마 호환성 확인 후 적절한 쿼리 선택
            try {
                $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
                $has_new_columns = $check_columns->rowCount() > 0;
            } catch (PDOException $e) {
                $has_new_columns = false;
            }
            
            if ($has_new_columns) {
                // 새로운 스키마 사용 - 도매 상품명 필드가 있는 경우
                $items_sql = "
                    SELECT 
                        wsi.product_id,
                        wsi.quantity,
                        wsi.unit_price,
                        wsi.total_price,
                        wsi.sale_unit,
                        wsi.remarks,
                        p.sku,
                        COALESCE(wp.wholesale_name_ko, p.name_ko) as name_ko,
                        COALESCE(wp.wholesale_name_en, p.name_en) as name_en,
                        COALESCE(p.pieces_per_box, wp.min_quantity, 1) as pieces_per_box
                    FROM wholesale_sale_items wsi
                    LEFT JOIN products p ON wsi.product_id = p.id
                    LEFT JOIN wholesale_products wp ON wp.product_id = p.id AND wp.store_id = ?
                    WHERE wsi.sale_id = ?
                    ORDER BY wsi.id ASC
                ";
                $items_stmt = $pdo->prepare($items_sql);
                $items_stmt->execute([$sale['store_id'], $sale_id]);
            } else {
                // 기존 스키마 사용 - 도매 상품명 필드가 없는 경우
                $items_sql = "
                    SELECT 
                        wsi.product_id,
                        wsi.quantity,
                        wsi.unit_price,
                        wsi.total_price,
                        wsi.sale_unit,
                        wsi.remarks,
                        p.sku,
                        p.name_ko,
                        p.name_en,
                        COALESCE(p.pieces_per_box, wp.min_quantity, 1) as pieces_per_box
                    FROM wholesale_sale_items wsi
                    LEFT JOIN products p ON wsi.product_id = p.id
                    LEFT JOIN wholesale_products wp ON wp.product_id = p.id AND wp.store_id = ?
                    WHERE wsi.sale_id = ?
                    ORDER BY wsi.id ASC
                ";
                $items_stmt = $pdo->prepare($items_sql);
                $items_stmt->execute([$sale['store_id'], $sale_id]);
            }
            
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        $errors[] = '데이터베이스 오류: ' . $e->getMessage();
        error_log("Wholesale sale preview error: " . $e->getMessage());
    }
} else {
    $errors[] = '잘못된 판매 ID입니다.';
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="px-2 sm:px-3 md:px-4 py-4">
    <div class="w-full max-w-none">
        <!-- 헤더 영역 -->
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_sales.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-handshake mr-1"></i>
                            Wholesale Sales
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600">Transaction Statement Preview</span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

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
                        <h3 class="text-sm font-medium text-red-800">Please resolve the following errors:</h3>
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
                    Back to Wholesale Sales
                </a>
            </div>
        <?php else: ?>
            <!-- 버튼들 -->
            <div class="mb-6 text-right">
                <a href="wholesale_sales.php?edit=<?php echo $sale_id; ?>" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <i class="fas fa-edit mr-2"></i>
                    수정
                </a>
                <button id="print-btn" class="ml-3 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-print mr-2"></i>
                    인쇄
                </button>
                <button id="delete-btn" class="ml-3 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                    <i class="fas fa-trash mr-2"></i>
                    판매취소
                </button>
                <a href="wholesale_sales_list.php" class="ml-3 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i class="fas fa-arrow-left mr-2"></i>
                    목록으로
                </a>
            </div>

            <!-- 거래명세서 -->
            <div id="invoice-content" class="bg-white shadow-sm rounded-lg border p-4 print:shadow-none print:border-none">
                <!-- 제목 -->
                <div class="text-left mb-6">
                    <h1 class="text-xl font-bold text-gray-900 mb-1">Wholesale Sales Transaction Statement</h1>
                    <div class="text-sm text-gray-600">
                        <div><?php echo htmlspecialchars($sale['store_name'] ?? 'Main Store'); ?></div>
                    </div>
                </div>

                <!-- 거래처 및 날짜 정보 테이블 -->
                <div class="mb-6">
                    <table class="info-table w-full border border-gray-200 mb-4">
                        <tbody>
                            <tr>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200">Customer</th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200 font-medium"><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200">Date</th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-gray-200"><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200">Phone</th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200"><?php echo htmlspecialchars($sale['customer_phone'] ?: '-'); ?></td>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200">Salesperson</th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($sale['user_name']); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-r border-gray-200">Address</th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-r border-gray-200"><?php echo htmlspecialchars($sale['customer_address'] ?: '-'); ?></td>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-r border-gray-200">Sale No.</th>
                                <td class="px-3 py-2 text-sm text-gray-900">#<?php echo str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 상품 목록 테이블 -->
                <div class="mb-6">
                    <table class="product-table min-w-full border border-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">SKU</th>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Product Name</th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Pcs/Box</th>
                                <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Sale Unit</th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Qty</th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Unit Price</th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">Total</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (!empty($items)): ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="px-2 py-2 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($item['sku']); ?></td>
                                        <td class="px-2 py-2 text-sm text-gray-900 border-b border-gray-200">
                                            <?php 
                                            // 디버깅용 - 실제 데이터 확인
                                            echo "<!-- DEBUG: name_en=[".htmlspecialchars($item['name_en'])."] name_ko=[".htmlspecialchars($item['name_ko'])."] -->";
                                            ?>
                                            <?php if ($item['name_en']): ?>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['name_en']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($item['name_ko']): ?>
                                                <div class="text-sm text-gray-600"><?php echo htmlspecialchars($item['name_ko']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!$item['name_en'] && !$item['name_ko']): ?>
                                                <div class="text-sm text-gray-500">-</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 py-2 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo number_format($item['pieces_per_box']); ?></td>
                                        <td class="px-2 py-2 text-sm text-center border-b border-gray-200">
                                            <?php if (isset($item['sale_unit']) && $item['sale_unit'] === 'piece'): ?>
                                                <span class="inline-flex items-center text-orange-600">
                                                    <i class="fas fa-cube mr-1"></i>
                                                    <span class="text-xs font-medium">Piece</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center text-blue-600">
                                                    <i class="fas fa-box mr-1"></i>
                                                    <span class="text-xs font-medium">Box</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2 py-2 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo number_format($item['quantity']); ?></td>
                                        <td class="px-2 py-2 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo number_format($item['unit_price']); ?></td>
                                        <td class="px-2 py-2 text-sm text-gray-900 text-right font-medium border-b border-gray-200"><?php echo number_format($item['total_price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="6" class="px-2 py-2 text-right text-sm font-medium text-gray-900 border-t border-gray-200">Grand Total:</td>
                                <td class="px-2 py-2 text-right text-lg font-bold text-gray-900 border-t border-gray-200"><?php echo number_format($sale['final_amount']); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (!empty($sale['notes'])): ?>
                    <!-- 비고 -->
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Notes</h3>
                        <div class="bg-gray-50 p-4 rounded-lg text-gray-700">
                            <?php echo nl2br(htmlspecialchars($sale['notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 담당자 및 서명란 -->
                <div class="mb-6 flex justify-end">
                    <div class="w-full max-w-lg">
                        <table class="payment-table w-full border border-gray-300">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-r border-gray-300">Representative</th>
                                    <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-gray-300">Signature</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="px-3 py-4 text-sm text-gray-900 text-center border-r border-gray-300"><?php echo htmlspecialchars($sale['user_name']); ?></td>
                                    <td class="px-3 py-4 text-sm text-gray-900 text-center" style="height: 60px;"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 푸터 -->
                <div class="text-center text-xs text-gray-500 mt-8 border-t border-gray-200 pt-4">
                    <div>Issued: <?php echo date('M d, Y H:i'); ?></div>
                    <div class="mt-1"><?php echo htmlspecialchars(t('company.name')); ?> - Wholesale Sales Transaction Statement</div>
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
                    <h3 class="text-lg leading-6 font-medium text-gray-900 mb-2">판매 취소</h3>
                    <div class="text-sm text-gray-500 mb-4">
                        <p>이 판매 내역을 완전히 삭제하시겠습니까?</p>
                        <p class="font-semibold text-red-600 mt-2">삭제된 데이터는 복구할 수 없습니다.</p>
                    </div>
                </div>
                <div class="flex space-x-3 justify-center">
                    <button id="cancel-delete" type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        취소
                    </button>
                    <button id="confirm-delete" type="button" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                        삭제
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

<!-- 인쇄 미리보기 모달 -->
<div id="print-preview-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 hidden z-50 overflow-y-auto">
    <div class="min-h-screen px-4 py-6">
        <!-- 모달 헤더 (고정) -->
        <div class="sticky top-0 z-10 bg-white rounded-t-lg max-w-4xl mx-auto px-4 py-3 flex items-center justify-between border-b">
            <h3 class="text-lg font-semibold text-gray-900">인쇄 미리보기</h3>
            <div class="flex items-center gap-3">
                <button id="modal-print-btn" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                    <i class="fas fa-print mr-2"></i>인쇄하기
                </button>
                <button id="modal-close-btn" class="inline-flex items-center px-4 py-2 bg-gray-500 text-white text-sm font-medium rounded-md hover:bg-gray-600">
                    <i class="fas fa-times mr-2"></i>닫기
                </button>
            </div>
        </div>
        <!-- 모달 콘텐츠 -->
        <div class="bg-white max-w-4xl mx-auto rounded-b-lg shadow-xl">
            <div id="print-preview-content" class="print-preview-wrapper p-4">
                <!-- 여기에 invoice-content가 복사됨 -->
            </div>
        </div>
    </div>
</div>

<style>
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
.print-preview-wrapper .payment-table {
    max-width: 220px !important;
}
.print-preview-wrapper .payment-table th,
.print-preview-wrapper .payment-table td {
    font-size: 9px !important;
    padding: 3px 5px !important;
}
.print-preview-wrapper .text-center.text-xs {
    font-size: 9px !important;
}
/* 상품 테이블 컬럼 너비 */
.print-preview-wrapper .product-table {
    table-layout: fixed;
    width: 100%;
}
.print-preview-wrapper .product-table th:nth-child(1),
.print-preview-wrapper .product-table td:nth-child(1) { width: 12%; }
.print-preview-wrapper .product-table th:nth-child(2),
.print-preview-wrapper .product-table td:nth-child(2) { width: 46%; font-size: 8px !important; }
.print-preview-wrapper .product-table th:nth-child(3),
.print-preview-wrapper .product-table td:nth-child(3) { width: 7%; }
.print-preview-wrapper .product-table th:nth-child(4),
.print-preview-wrapper .product-table td:nth-child(4) { width: 7%; }
.print-preview-wrapper .product-table th:nth-child(5),
.print-preview-wrapper .product-table td:nth-child(5) { width: 6%; }
.print-preview-wrapper .product-table th:nth-child(6),
.print-preview-wrapper .product-table td:nth-child(6) { width: 10%; }
.print-preview-wrapper .product-table th:nth-child(7),
.print-preview-wrapper .product-table td:nth-child(7) { width: 12%; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const printBtn = document.getElementById('print-btn');
    const deleteBtn = document.getElementById('delete-btn');
    const deleteModal = document.getElementById('delete-modal');
    const cancelDelete = document.getElementById('cancel-delete');
    const confirmDelete = document.getElementById('confirm-delete');
    const deleteForm = document.getElementById('delete-form');
    
    // 인쇄 버튼 - 모달 팝업으로 미리보기
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            const invoiceContent = document.getElementById('invoice-content');
            if (!invoiceContent) return;

            // 모달 열기
            const printModal = document.getElementById('print-preview-modal');
            const printPreviewContent = document.getElementById('print-preview-content');

            if (printModal && printPreviewContent) {
                printPreviewContent.innerHTML = invoiceContent.outerHTML;
                printModal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        });
    }
    
    // 삭제 버튼
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            deleteModal.classList.remove('hidden');
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

    // 인쇄 미리보기 모달 - 인쇄 버튼
    const modalPrintBtn = document.getElementById('modal-print-btn');
    if (modalPrintBtn) {
        modalPrintBtn.addEventListener('click', function() {
            const printContent = document.getElementById('print-preview-content').innerHTML;

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
                    <title>거래명세서 인쇄</title>
                    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                    <style>
                        body {
                            font-family: 'Malgun Gothic', sans-serif;
                            font-size: 9px;
                            line-height: 1.3;
                            padding: 5mm;
                        }
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
                        .payment-table { max-width: 220px !important; }
                        .payment-table th, .payment-table td { font-size: 9px !important; padding: 3px 5px !important; }
                        .text-center.text-xs { font-size: 9px !important; }
                        .product-table { table-layout: fixed; width: 100%; }
                        .product-table th:nth-child(1), .product-table td:nth-child(1) { width: 12%; }
                        .product-table th:nth-child(2), .product-table td:nth-child(2) { width: 46%; font-size: 8px !important; }
                        .product-table th:nth-child(3), .product-table td:nth-child(3) { width: 7%; }
                        .product-table th:nth-child(4), .product-table td:nth-child(4) { width: 7%; }
                        .product-table th:nth-child(5), .product-table td:nth-child(5) { width: 6%; }
                        .product-table th:nth-child(6), .product-table td:nth-child(6) { width: 10%; }
                        .product-table th:nth-child(7), .product-table td:nth-child(7) { width: 12%; }
                        @page { size: A4; margin: 8mm; }
                        .product-table { page-break-inside: auto !important; }
                        .product-table thead { display: table-header-group; }
                        .product-table tbody tr { page-break-inside: avoid; }
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
    
    /* 정보 테이블 컬럼 너비 고정 */
    .info-table th:nth-child(1),
    .info-table td:nth-child(1) { width: 10% !important; } /* 거래처 */
    .info-table th:nth-child(2),
    .info-table td:nth-child(2) { width: 40% !important; } /* 거래처명 */
    .info-table th:nth-child(3),
    .info-table td:nth-child(3) { width: 10% !important; } /* 거래일자 */
    .info-table th:nth-child(4),
    .info-table td:nth-child(4) { width: 40% !important; } /* 날짜 */
    
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
    
    /* 담당자 및 서명란 테이블 최적화 */
    .payment-table {
        font-size: 9px !important;
        margin-bottom: 8px !important;
        width: 100% !important;
        max-width: 300px !important;
        float: right !important;
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
        padding: 2px 3px !important;
        text-align: center !important;
        height: 35px !important;
        width: 50% !important;
    }

    /* 상품 테이블 컬럼 너비 최적화 - 클래스 기반 선택자 사용 */
    .product-table {
        table-layout: fixed !important;
        width: 100% !important;
    }
    
    .product-table th:nth-child(1), 
    .product-table td:nth-child(1) { 
        width: 10% !important; 
        max-width: 10% !important;
        min-width: 10% !important;
    } /* SKU */
    
    #invoice-content .product-table th:nth-child(2), 
    #invoice-content .product-table td:nth-child(2) { 
        width: 35% !important; 
        max-width: 35% !important;
        min-width: 35% !important;
        font-size: 9px !important;
    } /* 상품명 */
    
    .product-table th:nth-child(3), 
    .product-table td:nth-child(3) { 
        width: 7% !important; 
        max-width: 7% !important;
        min-width: 7% !important;
    } /* 박스포장수량 */
    
    .product-table th:nth-child(4), 
    .product-table td:nth-child(4) { 
        width: 8% !important; 
        max-width: 8% !important;
        min-width: 8% !important;
        font-size: 8px !important;
    } /* 판매단위 */
    
    .product-table th:nth-child(5), 
    .product-table td:nth-child(5) { 
        width: 6% !important; 
        max-width: 6% !important;
        min-width: 6% !important;
    } /* 수량 */
    
    .product-table th:nth-child(6), 
    .product-table td:nth-child(6) { 
        width: 10% !important; 
        max-width: 10% !important;
        min-width: 10% !important;
    } /* 판매가 */
    
    .product-table th:nth-child(7), 
    .product-table td:nth-child(7) { 
        width: 12% !important; 
        max-width: 12% !important;
        min-width: 12% !important;
    } /* 합계금액 */
    
    .product-table th:nth-child(8), 
    .product-table td:nth-child(8) { 
        width: 12% !important; 
        max-width: 12% !important;
        min-width: 12% !important;
    } /* 비고 */
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>