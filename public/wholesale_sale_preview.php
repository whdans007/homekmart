<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '도매 판매 미리보기' . ' - ' . t('company.name');
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
                s.address as store_address,
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
            // 판매 항목 정보 가져오기
            $items_sql = "
                SELECT 
                    wsi.*,
                    p.sku,
                    p.name_ko,
                    p.name_en
                FROM wholesale_sale_items wsi
                LEFT JOIN products p ON wsi.product_id = p.id
                WHERE wsi.sale_id = ?
                ORDER BY p.name_en, p.name_ko
            ";
            
            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([$sale_id]);
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

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-4xl mx-auto">
        <!-- 헤더 영역 -->
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_sales.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-handshake mr-1"></i>
                            도매 판매
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600">거래명세서 미리보기</span>
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
                        <h3 class="text-sm font-medium text-red-800">다음 오류를 해결해주세요:</h3>
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
                    도매 판매로 돌아가기
                </a>
            </div>
        <?php else: ?>
            <!-- 인쇄 버튼 -->
            <div class="mb-6 text-right">
                <button id="print-btn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-print mr-2"></i>
                    인쇄하기
                </button>
                <a href="wholesale_sales.php" class="ml-3 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i class="fas fa-arrow-left mr-2"></i>
                    판매 목록으로
                </a>
            </div>

            <!-- 거래명세서 -->
            <div id="invoice-content" class="bg-white shadow-sm rounded-lg border p-8 print:shadow-none print:border-none">
                <!-- 제목 -->
                <div class="text-center mb-8">
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">도매 판매 거래명세서</h1>
                    <div class="text-sm text-gray-600">
                        <div><?php echo htmlspecialchars($sale['store_name'] ?? '본점'); ?></div>
                        <?php if (!empty($sale['store_address'])): ?>
                            <div><?php echo htmlspecialchars($sale['store_address']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 거래처 및 날짜 정보 -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-3">TO :</h3>
                        <div class="space-y-1">
                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($sale['customer_name']); ?></div>
                            <?php if (!empty($sale['customer_phone'])): ?>
                                <div class="text-gray-700"><?php echo htmlspecialchars($sale['customer_phone']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($sale['customer_address'])): ?>
                                <div class="text-gray-700"><?php echo htmlspecialchars($sale['customer_address']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="font-medium">거래 날짜:</span>
                                <span><?php echo date('Y년 m월 d일', strtotime($sale['sale_date'])); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="font-medium">판매자:</span>
                                <span><?php echo htmlspecialchars($sale['user_name']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="font-medium">판매번호:</span>
                                <span>#<?php echo str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 상품 목록 테이블 -->
                <div class="mb-8">
                    <table class="min-w-full border border-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">SKU</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">상품명 (영문)</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">상품명 (한글)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">수량</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">판매가</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">합계금액</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b border-gray-200">비고</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (!empty($items)): ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($item['sku']); ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($item['name_en'] ?: '-'); ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($item['name_ko'] ?: '-'); ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo number_format($item['quantity']); ?></td>
                                        <td class="px-4 py-3 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo number_format($item['unit_price']); ?>원</td>
                                        <td class="px-4 py-3 text-sm text-gray-900 text-right font-medium border-b border-gray-200"><?php echo number_format($item['total_price']); ?>원</td>
                                        <td class="px-4 py-3 text-sm text-gray-600 border-b border-gray-200"><?php echo htmlspecialchars($item['notes'] ?: '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="5" class="px-4 py-3 text-right text-sm font-medium text-gray-900 border-t border-gray-200">총 합계:</td>
                                <td class="px-4 py-3 text-right text-lg font-bold text-gray-900 border-t border-gray-200"><?php echo number_format($sale['final_amount']); ?>원</td>
                                <td class="px-4 py-3 border-t border-gray-200"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (!empty($sale['notes'])): ?>
                    <!-- 비고 -->
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">비고</h3>
                        <div class="bg-gray-50 p-4 rounded-lg text-gray-700">
                            <?php echo nl2br(htmlspecialchars($sale['notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 푸터 -->
                <div class="text-center text-xs text-gray-500 mt-8 border-t border-gray-200 pt-4">
                    <div>발행일: <?php echo date('Y년 m월 d일 H시 i분'); ?></div>
                    <div class="mt-1"><?php echo htmlspecialchars(t('company.name')); ?> - 도매 판매 거래명세서</div>
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
            window.print();
        });
    }
});
</script>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    
    #invoice-content, #invoice-content * {
        visibility: visible;
    }
    
    #invoice-content {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 20px;
        box-shadow: none !important;
        border: none !important;
    }
    
    .print\\:shadow-none {
        box-shadow: none !important;
    }
    
    .print\\:border-none {
        border: none !important;
    }
    
    /* 페이지 나눔 방지 */
    table {
        page-break-inside: avoid;
    }
    
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>