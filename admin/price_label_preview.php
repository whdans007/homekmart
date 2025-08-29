<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '가격표 미리보기 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// Check product management permission
if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: index.php');
    exit;
}

// 세션에서 가격표 데이터 확인
if (!isset($_SESSION['price_label_data']) || empty($_SESSION['price_label_data']['items'])) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => '가격표 데이터가 없습니다. 다시 선택해주세요.'
    ];
    header('Location: price_label_print.php');
    exit;
}

$label_data = $_SESSION['price_label_data'];
$items = $label_data['items'];

// A4 용지 3x10 레이아웃 고정 사용
$layout = ['cols' => 3, 'rows' => 10, 'width' => '32%', 'height' => '85px'];

// 상품별 라벨 생성 (수량만큼 반복)
$labels = [];
foreach ($items as $item) {
    for ($i = 0; $i < $item['quantity']; $i++) {
        $labels[] = $item;
    }
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

?>

<style>
/* 프린트용 스타일 - Rongta TSC 프린터 최적화 */
@media print {
    @page {
        size: 72mm 30mm;
        margin: 0;
    }
    
    body * {
        visibility: hidden;
    }
    .print-area, .print-area * {
        visibility: visible;
    }
    .print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 72mm;
        height: 30mm;
        overflow: hidden;
    }
    .no-print {
        display: none !important;
    }
    
    .price-card {
        margin-top: 0 !important;
        height: 28mm !important;
        padding: 1mm !important;
    }
}

/* 미리보기용 스타일 */
.print-area {
    display: flex;
    flex-wrap: wrap;
    gap: 5mm;
    justify-content: center;
    margin: 20px auto;
    max-width: 800px;
}

.price-card {
    width: 70mm;
    height: 28mm;
    border: 1px solid #000;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    border-collapse: collapse;
    page-break-inside: avoid;
    margin: 5mm;
}

.name-row {
    height: 11mm;
}

.name-cell {
    text-align: center;
    padding: 0.3mm;
    vertical-align: middle;
}

.product-name-en-line1 {
    font-size: 9pt;
    font-weight: bold;
    line-height: 1.0;
    margin-bottom: 0.2mm;
}

.product-name-en-line2 {
    font-size: 9pt;
    font-weight: bold;
    line-height: 1.0;
    margin-bottom: 0.2mm;
}

.product-name-ko {
    font-size: 8pt;
    font-weight: normal;
    line-height: 1.0;
}

.divider-row {
    height: 1mm;
}

.divider-line {
    height: 1mm;
    border-bottom: 1px solid #000;
    padding: 0;
}

.content-row {
    height: 16mm;
}

.barcode-cell {
    width: 42mm;
    text-align: center;
    padding: 1mm;
    vertical-align: middle;
}

.price-cell {
    width: 28mm;
    text-align: center;
    border-left: 1px solid #000;
    font-size: 14pt;
    font-weight: bold;
    padding: 1mm;
    vertical-align: middle;
}

.price-card-barcode {
    width: 38mm !important;
    height: 14mm !important;
    display: block;
    margin: 0 auto;
}

.price-card td {
    border: none;
    padding: 0.5mm;
}
</style>

<div class="container mx-auto px-2 sm:px-3 md:px-4 py-8 no-print">
    <div class="w-full mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="price_label_print.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-tags mr-1"></i>
                            가격표출력
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

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-semibold text-gray-900">
                            <i class="fas fa-eye mr-2 text-primary-500"></i>
                            가격표 미리보기
                        </h1>
                        <p class="mt-1 text-sm text-gray-600">
                            총 <?php echo count($labels); ?>개의 프라이스카드가 출력됩니다. 
                            (72mm x 30mm 프라이스카드)
                        </p>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="window.print()" 
                                class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                            <i class="fas fa-print mr-1"></i>
                            출력하기
                        </button>
                        <a href="price_label_print.php" 
                           class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                            <i class="fas fa-edit mr-1"></i>
                            수정하기
                        </a>
                    </div>
                </div>
            </div>

            <?php if (isset($flash)): ?>
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
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
                </div>
            <?php endif; ?>
        </div>

        <!-- 미리보기 안내 -->
        <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-md">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-blue-700">
                        <strong>출력 안내:</strong> 
                        '출력하기' 버튼을 클릭하면 브라우저의 인쇄 대화상자가 열립니다. 
                        Rongta TSC 프라이스카드 프린터용으로 최적화되어 있으며, 72mm x 30mm 용지 크기로 설정하세요.
                        일반 프린터 사용 시에는 여백을 최소로 설정하세요.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 프라이스카드 출력 영역 -->
<div class="print-area">
    <?php foreach ($labels as $label): ?>
    <table class="price-card">
        <tr class="name-row">
            <td colspan="2" class="name-cell">
                <?php 
                $productNameEn = $label['name_en'] ?: '';
                $productNameKo = $label['name_ko'] ?: '';
                
                // 영문 상품명 2줄 처리
                $productNameEnLine1 = '';
                $productNameEnLine2 = '';
                
                if (strlen($productNameEn) > 20) {
                    // 단어 단위로 분할하되, 공백이 없으면 강제 분할
                    $words = explode(' ', $productNameEn);
                    $line1 = '';
                    $line2 = '';
                    
                    foreach ($words as $word) {
                        if (strlen(trim($line1 . ' ' . $word)) <= 20) {
                            $line1 = trim($line1 . ' ' . $word);
                        } else {
                            $line2 = trim($line2 . ' ' . $word);
                        }
                    }
                    
                    // 첫 번째 줄이 너무 길면 강제 분할
                    if (strlen($line1) > 20) {
                        $productNameEnLine1 = substr($line1, 0, 20);
                        $productNameEnLine2 = substr($line1, 20) . ' ' . $line2;
                    } else {
                        $productNameEnLine1 = $line1;
                        $productNameEnLine2 = $line2;
                    }
                    
                    // 두 번째 줄도 길면 자르기
                    if (strlen($productNameEnLine2) > 20) {
                        $productNameEnLine2 = substr($productNameEnLine2, 0, 17) . '...';
                    }
                } else {
                    $productNameEnLine1 = $productNameEn;
                    $productNameEnLine2 = '';
                }
                
                // 한글 길이 제한
                if (strlen($productNameKo) > 20) {
                    $productNameKo = substr($productNameKo, 0, 17) . '...';
                }
                ?>
                
                <div class="product-name-en-line1"><?php echo htmlspecialchars($productNameEnLine1); ?></div>
                <?php if ($productNameEnLine2): ?>
                <div class="product-name-en-line2"><?php echo htmlspecialchars($productNameEnLine2); ?></div>
                <?php endif; ?>
                <div class="product-name-ko"><?php echo htmlspecialchars($productNameKo); ?></div>
            </td>
        </tr>
        <tr class="divider-row">
            <td colspan="2" class="divider-line"></td>
        </tr>
        <tr class="content-row">
            <td class="barcode-cell">
                <svg class="price-card-barcode" data-sku="<?php echo htmlspecialchars($label['sku']); ?>"></svg>
            </td>
            <td class="price-cell">
                <?php echo number_format($label['selling_price']); ?>
            </td>
        </tr>
    </table>
    <?php endforeach; ?>
</div>

<!-- JsBarcode 라이브러리 -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<script>
// 바코드 생성 함수 (Rongta TSC 최적화)
function generatePriceCardBarcodes() {
    const barcodeElements = document.querySelectorAll('.price-card-barcode');
    barcodeElements.forEach(element => {
        const sku = element.getAttribute('data-sku');
        if (sku && sku !== 'N/A') {
            try {
                // EAN-13 형식 확인 (13자리 숫자)
                if (/^\d{13}$/.test(sku)) {
                    // EAN-13 바코드 생성
                    JsBarcode(element, sku, {
                        format: "EAN13",
                        width: 1.0,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } else if (/^\d{12}$/.test(sku)) {
                    // UPC-A (12자리) - EAN-13으로 변환하여 생성
                    const ean13 = '0' + sku; // 앞에 0 추가
                    JsBarcode(element, ean13, {
                        format: "EAN13",
                        width: 1.0,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } else if (/^\d{8}$/.test(sku)) {
                    // EAN-8 바코드 생성
                    JsBarcode(element, sku, {
                        format: "EAN8",
                        width: 1.2,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } else {
                    // 기타 형식은 CODE128로 처리
                    JsBarcode(element, sku, {
                        format: "CODE128",
                        width: 1.2,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                }
            } catch (e) {
                console.error('바코드 생성 실패:', sku, e);
                // 실패 시 CODE128로 재시도
                try {
                    JsBarcode(element, sku, {
                        format: "CODE128",
                        width: 1.2,
                        height: 28,
                        displayValue: true,
                        fontSize: 8,
                        fontOptions: "bold",
                        textMargin: 1,
                        margin: 1,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } catch (e2) {
                    element.innerHTML = '<text style="font-size: 10px; font-weight: bold;">바코드 오류</text>';
                }
            }
        } else {
            element.innerHTML = '<text style="font-size: 10px; color: #666;">SKU 없음</text>';
        }
    });
}

// 페이지 로드 시 바코드 생성
document.addEventListener('DOMContentLoaded', function() {
    generatePriceCardBarcodes();
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>