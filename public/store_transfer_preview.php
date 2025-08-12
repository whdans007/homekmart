<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '점간이동 미리보기' . ' - ' . t('company.name');
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
            $errors[] = '해당 점간이동 내역을 찾을 수 없습니다.';
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
                    p.name_en
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
        $errors[] = '데이터베이스 오류: ' . $e->getMessage();
        error_log("Store transfer preview error: " . $e->getMessage());
    }
} else {
    $errors[] = '잘못된 이동 ID입니다.';
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-7xl mx-auto">
        <!-- 헤더 영역 -->
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="store_transfers.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-exchange-alt mr-1"></i>
                            점간이동
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600">미리보기</span>
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
                        <h3 class="text-sm font-medium text-red-800">오류가 발생했습니다:</h3>
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
                    <h1 class="text-2xl font-bold text-gray-900">점간이동 #<?php echo $transfer['id']; ?></h1>
                    <p class="text-sm text-gray-600 mt-1">
                        상태: 
                        <?php 
                        $status_classes = [
                            'draft' => 'bg-yellow-100 text-yellow-800',
                            'confirmed' => 'bg-green-100 text-green-800',
                            'cancelled' => 'bg-red-100 text-red-800'
                        ];
                        $status_names = [
                            'draft' => '임시',
                            'confirmed' => '확정',
                            'cancelled' => '취소'
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
                        수정
                    </a>
                    <button id="print-btn" 
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        <i class="fas fa-print mr-2"></i>
                        출력
                    </button>
                    <a href="store_transfers_list.php" 
                       class="inline-flex items-center px-4 py-2 bg-gray-600 text-white text-sm font-medium rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                        <i class="fas fa-list mr-2"></i>
                        목록
                    </a>
                </div>
            </div>

            <!-- 이동 전표 -->
            <div id="transfer-receipt" class="bg-white border border-gray-300 rounded-lg overflow-hidden">
                <!-- 헤더 -->
                <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                    <div class="text-center">
                        <h2 class="text-xl font-bold text-gray-900">점간이동 전표</h2>
                        <p class="text-sm text-gray-600 mt-1"><?php echo t('company.name'); ?></p>
                    </div>
                </div>

                <!-- 이동 정보 -->
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- 왼쪽 정보 -->
                        <div>
                            <div class="mb-3">
                                <span class="text-sm font-medium text-gray-600">이동 번호:</span>
                                <span class="ml-2 text-sm text-gray-900 font-mono">#<?php echo str_pad($transfer['id'], 6, '0', STR_PAD_LEFT); ?></span>
                            </div>
                            <div class="mb-3">
                                <span class="text-sm font-medium text-gray-600">이동 날짜:</span>
                                <span class="ml-2 text-sm text-gray-900"><?php echo date('Y년 m월 d일', strtotime($transfer['transfer_date'])); ?></span>
                            </div>
                            <div class="mb-3">
                                <span class="text-sm font-medium text-gray-600">처리자:</span>
                                <span class="ml-2 text-sm text-gray-900"><?php echo htmlspecialchars($transfer['user_name'] ?? '알 수 없음'); ?></span>
                            </div>
                        </div>

                        <!-- 오른쪽 정보 -->
                        <div>
                            <div class="mb-3">
                                <span class="text-sm font-medium text-gray-600">출발 점포:</span>
                                <span class="ml-2 text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($transfer['from_store_name']); ?></span>
                            </div>
                            <div class="mb-3">
                                <span class="text-sm font-medium text-gray-600">목적지 점포:</span>
                                <span class="ml-2 text-sm text-gray-900 font-medium"><?php echo htmlspecialchars($transfer['to_store_name']); ?></span>
                            </div>
                            <div class="mb-3">
                                <span class="text-sm font-medium text-gray-600">등록일시:</span>
                                <span class="ml-2 text-sm text-gray-900"><?php echo date('Y-m-d H:i', strtotime($transfer['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($transfer['notes'])): ?>
                        <div class="mt-4 pt-4 border-t border-gray-200">
                            <span class="text-sm font-medium text-gray-600">비고:</span>
                            <p class="ml-2 text-sm text-gray-900 mt-1"><?php echo htmlspecialchars($transfer['notes']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 이동 상품 목록 -->
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border-b">SKU</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border-b">상품명</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase border-b">단가</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase border-b">수량</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase border-b">금액</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase border-b">비고</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white">
                            <?php foreach ($items as $item): ?>
                                <tr class="border-b border-gray-200">
                                    <td class="px-3 py-2 text-xs font-mono text-gray-700">
                                        <?php echo htmlspecialchars($item['sku']); ?>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($item['name_en'] ?: $item['name_ko']); ?>
                                        </div>
                                        <?php if ($item['name_ko'] && $item['name_en'] && $item['name_ko'] !== $item['name_en']): ?>
                                            <div class="text-xs text-gray-600">
                                                <?php echo htmlspecialchars($item['name_ko']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-center text-sm text-gray-900">
                                        ₩<?php echo number_format($item['unit_cost_price'], 2); ?>
                                    </td>
                                    <td class="px-3 py-2 text-center text-sm font-medium text-gray-900">
                                        <?php echo number_format($item['quantity']); ?>
                                    </td>
                                    <td class="px-3 py-2 text-right text-sm font-medium text-primary-600">
                                        ₩<?php echo number_format($item['total_price'], 2); ?>
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
                            <div class="text-sm text-gray-600 mb-1">총 <?php echo count($items); ?>개 항목</div>
                            <div class="text-lg font-bold text-gray-900">
                                총 이동 금액: ₩<?php echo number_format($transfer['total_amount'], 2); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 푸터 -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="text-center text-xs text-gray-500">
                        이 전표는 점포간 상품 이동을 확인하는 문서입니다.
                    </div>
                    <div class="text-center text-xs text-gray-400 mt-2">
                        출력일시: <?php echo date('Y-m-d H:i:s'); ?>
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
                        margin: 0.5in;
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
                        font-size: 12px !important;
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
        font-size: 12px;
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
}

/* 화면용 추가 스타일 */
#transfer-receipt {
    max-width: 210mm;
    margin: 0 auto;
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