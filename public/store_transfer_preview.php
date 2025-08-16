<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('store_transfer.preview') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 점간이동 권한 확인
if (!has_permission('store_transfer_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$transfer_id = (int)($_GET['id'] ?? 0);
$transfer = null;
$from_store = null;
$to_store = null;
$items = [];
$errors = [];

if ($transfer_id > 0) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 이동 정보와 점포 정보 조인해서 가져오기
        $sql = "
            SELECT 
                st.*,
                fs.name as from_store_name,
                ts.name as to_store_name,
                u.full_name as user_name
            FROM store_transfers st
            LEFT JOIN stores fs ON st.from_store_id = fs.id
            LEFT JOIN stores ts ON st.to_store_id = ts.id
            LEFT JOIN users u ON st.user_id = u.id
            WHERE st.id = ?
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$transfer_id]);
        $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transfer) {
            $errors[] = t('store_transfer.transfer_not_found');
        } else {
            // 이동 항목 정보 가져오기
            $items_sql = "
                SELECT 
                    sti.product_id,
                    sti.quantity,
                    sti.unit_cost_price,
                    sti.total_price,
                    sti.remarks,
                    p.sku,
                    p.name_ko,
                    p.name_en,
                    p.pieces_per_box
                FROM store_transfer_items sti
                LEFT JOIN products p ON sti.product_id = p.id
                WHERE sti.transfer_id = ?
                ORDER BY p.name_en, p.name_ko
            ";
            
            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([$transfer_id]);
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (PDOException $e) {
        $errors[] = t('store_transfer.database_error') . $e->getMessage();
        error_log("Store transfer preview error: " . $e->getMessage());
    }
} else {
    $errors[] = t('store_transfer.invalid_transfer_id');
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="container px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-none">
        <!-- 헤더 영역 -->
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="store_transfers.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-exchange-alt mr-1"></i>
                            <?php echo t('store_transfer.breadcrumb_transfer'); ?>
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo t('store_transfer.preview'); ?></span>
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
                        <h3 class="text-sm font-medium text-red-800"><?php echo t('common.error_occurred'); ?>:</h3>
                        <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php elseif ($transfer): ?>
            <!-- 액션 버튼들 -->
            <div class="mb-6 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900"><?php echo t('store_transfer.management'); ?> #<?php echo $transfer['id']; ?></h1>
                    <p class="text-sm text-gray-600 mt-1">
                        <?php echo t('store_transfer.status'); ?>: 
                        <?php 
                        $status_classes = [
                            'draft' => 'bg-yellow-100 text-yellow-800',
                            'confirmed' => 'bg-green-100 text-green-800',
                            'cancelled' => 'bg-red-100 text-red-800'
                        ];
                        $status_names = [
                            'draft' => t('store_transfer.draft'),
                            'confirmed' => t('store_transfer.confirmed'),
                            'cancelled' => t('store_transfer.cancelled')
                        ];
                        $status_class = $status_classes[$transfer['status']] ?? 'bg-gray-100 text-gray-800';
                        $status_name = $status_names[$transfer['status']] ?? $transfer['status'];
                        ?>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>">
                            <?php echo $status_name; ?>
                        </span>
                    </p>
                </div>
                <div class="flex space-x-2">
                    <a href="store_transfers.php?edit=<?php echo $transfer['id']; ?>" 
                       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i class="fas fa-edit mr-2"></i>
                        <?php echo t('common.edit'); ?>
                    </a>
                    <button id="print-btn" 
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        <i class="fas fa-print mr-2"></i>
                        <?php echo t('store_transfer.print_button'); ?>
                    </button>
                    <a href="store_transfers_list.php" 
                       class="inline-flex items-center px-4 py-2 bg-gray-600 text-white text-sm font-medium rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                        <i class="fas fa-list mr-2"></i>
                        <?php echo t('store_transfer.list_button'); ?>
                    </a>
                </div>
            </div>

            <!-- 이동 전표 -->
            <div id="transfer-receipt" class="bg-white border border-gray-300 rounded-lg overflow-hidden">
                <!-- 헤더 -->
                <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                    <div class="text-center">
                        <h2 class="text-xl font-bold text-gray-900"><?php echo t('store_transfer.transfer_document'); ?></h2>
                        <p class="text-sm text-gray-600 mt-1"><?php echo t('company.name'); ?></p>
                    </div>
                </div>

                <!-- 이동 정보 -->
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center py-3">
                        <div class="text-left text-sm text-gray-900 font-medium">
                            점포 이동: <?php echo htmlspecialchars($transfer['from_store_name']); ?> → <?php echo htmlspecialchars($transfer['to_store_name']); ?>
                        </div>
                        <div class="text-center text-sm text-gray-900 font-medium">
                            이동 번호: #<?php echo str_pad($transfer['id'], 6, '0', STR_PAD_LEFT); ?>
                        </div>
                        <div class="text-right text-sm text-gray-900 font-medium">
                            이동 날짜: <?php echo date('Y년 m월 d일', strtotime($transfer['transfer_date'])); ?>
                        </div>
                    </div>

                    <?php if (!empty($transfer['notes'])): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200">
                            <span class="text-sm font-medium text-gray-600"><?php echo t('store_transfer.notes_label'); ?>:</span>
                            <p class="ml-2 text-sm text-gray-900 mt-1"><?php echo htmlspecialchars($transfer['notes']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 이동 상품 목록 -->
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase border-b w-20">SKU</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border-b w-64"><?php echo t('store_transfer.product_name_column'); ?></th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase border-b w-20">포장단위</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase border-b w-24"><?php echo t('store_transfer.unit_cost_column'); ?></th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase border-b w-20"><?php echo t('store_transfer.quantity_column'); ?></th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase border-b w-24"><?php echo t('store_transfer.total_price_column'); ?></th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border-b"><?php echo t('store_transfer.remarks_column'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white">
                            <?php foreach ($items as $item): ?>
                                <tr class="border-b border-gray-200">
                                    <td class="px-2 py-2 text-xs font-mono text-gray-700">
                                        <?php echo htmlspecialchars($item['sku']); ?>
                                    </td>
                                    <td class="px-3 py-2">
                                        <!-- 영문명 먼저 표시 -->
                                        <?php if (!empty($item['name_en'])): ?>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($item['name_en']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- 한글명 아래에 표시 -->
                                        <?php if (!empty($item['name_ko'])): ?>
                                            <div class="text-xs text-gray-600">
                                                <?php echo htmlspecialchars($item['name_ko']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- 영문명이 없을 경우 한글명만 큰 글씨로 표시 -->
                                        <?php if (empty($item['name_en']) && !empty($item['name_ko'])): ?>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($item['name_ko']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-center text-sm text-gray-900">
                                        <?php echo !empty($item['pieces_per_box']) ? number_format($item['pieces_per_box']) : '-'; ?>
                                    </td>
                                    <td class="px-3 py-2 text-center text-sm text-gray-900">
                                        <?php echo number_format($item['unit_cost_price'], 2); ?>
                                    </td>
                                    <td class="px-3 py-2 text-center text-sm font-medium text-gray-900">
                                        <?php echo number_format($item['quantity']); ?>
                                    </td>
                                    <td class="px-3 py-2 text-right text-sm font-medium text-primary-600">
                                        <?php echo number_format($item['total_price'], 2); ?>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-gray-600">
                                        <?php echo htmlspecialchars($item['remarks']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 합계 -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="flex justify-end">
                        <div class="text-right">
                            <div class="text-sm text-gray-600 mb-1"><?php echo str_replace('{count}', count($items), t('store_transfer.total_items_count')); ?></div>
                            <div class="text-lg font-bold text-gray-900">
                                <?php echo t('store_transfer.total_transfer_price'); ?>: <?php echo number_format($transfer['total_amount'], 2); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 담당자 및 서명란 -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="flex justify-end">
                        <div class="w-full max-w-lg">
                            <table class="signature-table w-full border border-gray-300">
                                <thead>
                                    <tr class="bg-gray-50">
                                        <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-r border-gray-300">담당자</th>
                                        <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-gray-300">싸인</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="px-3 py-4 text-sm text-gray-900 text-center border-r border-gray-300"><?php echo htmlspecialchars($transfer['user_name'] ?? '미확인'); ?></td>
                                        <td class="px-3 py-4 text-sm text-gray-900 text-center" style="height: 60px;"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 푸터 -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="text-center text-xs text-gray-500">
                        <?php echo t('store_transfer.document_description'); ?>
                    </div>
                    <div class="text-center text-xs text-gray-400 mt-2">
                        <?php echo t('store_transfer.print_time'); ?>: <?php echo date('Y-m-d H:i:s'); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const printBtn = document.getElementById('print-btn');
    
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            // 프린터 전용 CSS 적용
            const printStyles = `
                <style media="print">
                    @page {
                        margin: 0.3in;
                        size: A4;
                    }
                    
                    body * {
                        visibility: hidden;
                    }
                    
                    #transfer-receipt,
                    #transfer-receipt * {
                        visibility: visible;
                    }
                    
                    #transfer-receipt {
                        position: absolute;
                        left: 0;
                        top: 0;
                        width: 100% !important;
                        box-shadow: none !important;
                        border: none !important;
                    }
                    
                    .no-print {
                        display: none !important;
                    }
                    
                    table {
                        font-size: 13px !important;
                    }
                    
                    .text-xl {
                        font-size: 18px !important;
                    }
                    
                    .text-lg {
                        font-size: 16px !important;
                    }
                    
                    .text-sm {
                        font-size: 11px !important;
                    }
                    
                    .text-xs {
                        font-size: 10px !important;
                    }
                    
                    .px-6 {
                        padding-left: 0.5rem !important;
                        padding-right: 0.5rem !important;
                    }
                    
                    .py-4 {
                        padding-top: 0.25rem !important;
                        padding-bottom: 0.25rem !important;
                    }
                    
                    * {
                        color: #000 !important;
                    }
                    
                    .px-6 table.border-0 {
                        border: none !important;
                    }
                    
                    .px-6 table.border-0 td {
                        border: none !important;
                    }
                    
                    .px-6 table.border-0 tr {
                        border: none !important;
                    }
                    
                    /* 서명란 테이블 스타일 */
                    .signature-table {
                        font-size: 9px !important;
                        margin-bottom: 8px !important;
                        width: 100% !important;
                        max-width: 300px !important;
                        float: right !important;
                        table-layout: fixed !important;
                    }
                    
                    .signature-table th {
                        background: #f8f9fa !important;
                        font-size: 8px !important;
                        padding: 2px 3px !important;
                        font-weight: 600 !important;
                        text-align: center !important;
                        width: 50% !important;
                    }
                    
                    .signature-table td {
                        font-size: 8px !important;
                        padding: 2px 3px !important;
                        text-align: center !important;
                        height: 35px !important;
                        width: 50% !important;
                    }
                </style>
            `;
            
            // 스타일을 head에 추가
            document.head.insertAdjacentHTML('beforeend', printStyles);
            
            // 프린트 실행
            window.print();
            
            // 프린트 완료 후 스타일 제거 (선택사항)
            setTimeout(function() {
                const styleElement = document.head.querySelector('style[media="print"]');
                if (styleElement) {
                    styleElement.remove();
                }
            }, 1000);
        });
    }
});
</script>

<style>
/* 프린트용 스타일 */
@media print {
    .no-print {
        display: none !important;
    }
    
    body {
        font-size: 13px;
        line-height: 1.4;
    }
    
    #transfer-receipt {
        box-shadow: none;
        border: 1px solid #000;
    }
    
    table {
        border-collapse: collapse;
    }
    
    th, td {
        border: 1px solid #ddd;
        padding: 4px 8px;
    }
    
    .bg-gray-50 {
        background-color: #f9f9f9 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    * {
        color: #000 !important;
    }
    
    .px-6 table.border-0 {
        border: none !important;
    }
    
    .px-6 table.border-0 td {
        border: none !important;
    }
    
    .px-6 table.border-0 tr {
        border: none !important;
    }
    
    /* 서명란 테이블 스타일 */
    .signature-table {
        font-size: 9px !important;
        margin-bottom: 8px !important;
        width: 100% !important;
        max-width: 300px !important;
        float: right !important;
        table-layout: fixed !important;
    }
    
    .signature-table th {
        background: #f8f9fa !important;
        font-size: 8px !important;
        padding: 2px 3px !important;
        font-weight: 600 !important;
        text-align: center !important;
        width: 50% !important;
    }
    
    .signature-table td {
        font-size: 8px !important;
        padding: 2px 3px !important;
        text-align: center !important;
        height: 35px !important;
        width: 50% !important;
    }
}

/* 화면용 추가 스타일 */
#transfer-receipt {
    max-width: 280mm;
    margin: 0;
}

@media (max-width: 768px) {
    #transfer-receipt {
        margin: 0;
    }
    
    .grid-cols-1.md\\:grid-cols-2 {
        grid-template-columns: 1fr;
    }
    
    table {
        font-size: 12px;
    }
    
    .px-6 {
        padding-left: 1rem;
        padding-right: 1rem;
    }
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>