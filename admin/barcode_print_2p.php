<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('barcode_print_2p.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// Check barcode_management permission
if (!has_permission('barcode_management')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}
?>

<div class="flex-1 overflow-y-auto">
    <div class="max-w-4xl mx-auto p-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold mb-4"><?php echo t('barcode_print_2p.page_title'); ?></h2>

            <!-- 바코드 입력 섹션 -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo t('barcode_print_2p.barcode_input_label'); ?></label>
                    <input
                        id="barcodeInput"
                        type="text"
                        placeholder="<?php echo t('barcode_print_2p.barcode_input_placeholder'); ?>"
                        class="w-full border rounded px-3 py-2"
                        autocomplete="off"
                    >
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1">&nbsp;</label>
                    <button id="btnSearch" class="btn btn-primary w-full"><?php echo t('barcode_print_2p.search_button'); ?></button>
                </div>
            </div>

            <div id="searchStatus" class="mt-2 text-sm mb-4"></div>

            <!-- 컨트롤 버튼 -->
            <div class="flex items-center gap-2 mb-4">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" id="selectAllCheckbox" class="w-4 h-4">
                    <span class="text-sm text-gray-700"><?php echo t('barcode_print_2p.select_all_button'); ?></span>
                </label>
                <button id="btnClearCart" class="btn btn-outline-secondary btn-sm"><?php echo t('barcode_print_2p.clear_all_button'); ?></button>
                <button id="btnPrint" class="btn btn-primary btn-sm"><?php echo t('barcode_print_2p.print_button'); ?></button>
            </div>

            <div id="msg" class="mt-4 text-sm"></div>
        </div>

        <!-- 장바구니 테이블 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mt-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold"><?php echo t('barcode_print_2p.page_title'); ?></h3>
                <div class="text-sm text-gray-600">
                    <span><?php echo t('barcode_print_2p.total_items_label'); ?></span>
                    <span id="totalItems" class="font-semibold ml-2">0</span>
                    <span class="mx-3">|</span>
                    <span><?php echo t('barcode_print_2p.selected_items_label'); ?></span>
                    <span id="selectedItems" class="font-semibold ml-2">0</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th style="width:50px"></th>
                            <th style="width:150px"><?php echo t('barcode_print_2p.sku_column'); ?></th>
                            <th><?php echo t('barcode_print_2p.product_name_column'); ?></th>
                            <th style="width:120px" class="text-right"><?php echo t('barcode_print_2p.price_column'); ?></th>
                            <th style="width:50px" class="text-center"></th>
                        </tr>
                    </thead>
                    <tbody id="cartTable">
                        <tr id="emptyRow">
                            <td colspan="5" class="text-center text-muted"><?php echo t('barcode_print_2p.empty_cart_message'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 출력 미리보기 Modal -->
<div id="printModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50">
    <div class="bg-white w-full h-full flex flex-col">
        <div class="flex items-center justify-between p-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold"><?php echo t('barcode_print_2p.print_preview_title'); ?></h3>
            <button id="btnClosePrintModal" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div id="printIframeContainer" class="flex-1 overflow-auto">
            <!-- iframe이 여기에 로드됨 -->
        </div>
        <div class="flex items-center justify-end gap-2 p-4 border-t border-gray-200">
            <button id="btnPrintConfirm" class="btn btn-primary"><?php echo t('barcode_print_2p.print_confirm_button'); ?></button>
            <button id="btnCancelPrint" class="btn btn-outline-secondary"><?php echo t('barcode_print_2p.cancel_button'); ?></button>
        </div>
    </div>
</div>

<script>
// 번역 데이터
const translations = {
    searching: '검색 중...',
    search_error: '<?php echo addslashes(t("barcode_print_2p.search_error")); ?>',
    product_not_found: '<?php echo addslashes(t("barcode_print_2p.product_not_found")); ?>',
    product_added_success: '<?php echo addslashes(t("barcode_print_2p.product_added_success")); ?>',
    product_already_exists: '<?php echo addslashes(t("barcode_print_2p.product_already_exists")); ?>',
    no_items_selected: '<?php echo addslashes(t("barcode_print_2p.no_items_selected")); ?>',
    please_enter_barcode: '<?php echo addslashes(t("barcode_print_2p.please_enter_barcode")); ?>'
};

// 장바구니 상태 관리
let cart = [];

// sessionStorage에서 장바구니 복원
function loadCart() {
    const savedCart = sessionStorage.getItem('barcode_print_2p_cart');
    if (savedCart) {
        try {
            cart = JSON.parse(savedCart);
        } catch (e) {
            cart = [];
        }
    }
    updateCartUI();
}

// sessionStorage에 장바구니 저장
function saveCart() {
    sessionStorage.setItem('barcode_print_2p_cart', JSON.stringify(cart));
}

// 장바구니 UI 업데이트
function updateCartUI() {
    const tableBody = document.getElementById('cartTable');
    const emptyRow = document.getElementById('emptyRow');
    const totalItemsEl = document.getElementById('totalItems');
    const selectedItemsEl = document.getElementById('selectedItems');

    if (cart.length === 0) {
        tableBody.innerHTML = emptyRow.cloneNode(true).innerHTML;
        totalItemsEl.textContent = '0';
        selectedItemsEl.textContent = '0';
        return;
    }

    const rowsHtml = cart.map((item, index) => `
        <tr>
            <td><input type="checkbox" class="cart-item-checkbox" data-index="${index}"></td>
            <td><code>${escapeHtml(item.sku)}</code></td>
            <td>${escapeHtml(item.name_en)}</td>
            <td class="text-right">${formatPrice(item.selling_price_raw)}</td>
            <td class="text-center"><button class="btn-delete-item text-danger" data-index="${index}" style="border:none; background:none; cursor:pointer;"><i class="fas fa-trash text-sm"></i></button></td>
        </tr>
    `).join('');

    tableBody.innerHTML = rowsHtml;

    // 체크박스 이벤트 리스너
    document.querySelectorAll('.cart-item-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });

    // 행 클릭 시 체크박스 선택
    document.querySelectorAll('#cartTable tr').forEach(row => {
        row.addEventListener('click', function(e) {
            // 삭제 버튼이나 체크박스를 직접 클릭한 경우는 제외
            if (e.target.closest('.btn-delete-item') || e.target.closest('.cart-item-checkbox')) {
                return;
            }
            const checkbox = this.querySelector('.cart-item-checkbox');
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                updateSelectedCount();
            }
        });
        // 행에 hover 효과 추가
        row.style.cursor = 'pointer';
    });

    totalItemsEl.textContent = cart.length;
    updateSelectedCount();
}

// 선택된 항목 수 업데이트
function updateSelectedCount() {
    const selectedCount = document.querySelectorAll('.cart-item-checkbox:checked').length;
    document.getElementById('selectedItems').textContent = selectedCount;

    // "전체선택" 체크박스 상태 업데이트
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const totalCheckboxes = document.querySelectorAll('.cart-item-checkbox').length;
    selectAllCheckbox.checked = selectedCount === totalCheckboxes && totalCheckboxes > 0;
    selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes;
}

// 바코드로 상품 검색
async function searchProductByBarcode(barcode) {
    const statusEl = document.getElementById('searchStatus');
    statusEl.innerHTML = '<span class="text-blue-600"><i class="fas fa-spinner fa-spin mr-2"></i>' + translations.searching + '</span>';

    try {
        const response = await fetch('ajax_get_product_by_barcode.php?barcode=' + encodeURIComponent(barcode));
        const data = await response.json();

        if (!data.success) {
            statusEl.innerHTML = '<span class="text-red-600"><i class="fas fa-exclamation-circle mr-2"></i>' + data.message + '</span>';
            return;
        }

        // 이미 장바구니에 있는지 확인
        if (cart.some(item => item.sku === data.product.sku)) {
            statusEl.innerHTML = '<span class="text-orange-600"><i class="fas fa-info-circle mr-2"></i>' + translations.product_already_exists + '</span>';
            return;
        }

        // 장바구니에 추가
        addToCart({
            product_id: data.product.product_id,
            sku: data.product.sku,
            name_en: data.product.name_en,
            name_ko: data.product.name_ko,
            selling_price_raw: data.product.selling_price_raw
        });

        statusEl.innerHTML = '<span class="text-green-600"><i class="fas fa-check-circle mr-2"></i>' + translations.product_added_success + '</span>';
        document.getElementById('barcodeInput').value = '';
        document.getElementById('barcodeInput').focus();
    } catch (error) {
        console.error('Error:', error);
        statusEl.innerHTML = '<span class="text-red-600"><i class="fas fa-exclamation-circle mr-2"></i>' + translations.search_error + '</span>';
    }
}

// 장바구니에 상품 추가
function addToCart(product) {
    cart.push(product);
    saveCart();
    updateCartUI();
}

// 장바구니에서 상품 제거
function removeFromCart(index) {
    cart.splice(index, 1);
    saveCart();
    updateCartUI();
}

// 장바구니 비우기
function clearCart() {
    if (cart.length === 0) return;
    if (!confirm('<?php echo t("barcode_print_2p.confirm_clear_cart"); ?>')) return;

    cart = [];
    sessionStorage.removeItem('barcode_print_2p_cart');
    updateCartUI();
}

// 선택한 상품들의 바코드 출력
function printSelectedBarcodes() {
    const selectedItems = document.querySelectorAll('.cart-item-checkbox:checked');

    if (selectedItems.length === 0) {
        alert(translations.no_items_selected);
        return;
    }

    // 선택된 SKU들 수집 (각각 2번씩)
    let skuList = [];
    selectedItems.forEach(checkbox => {
        const index = parseInt(checkbox.dataset.index);
        const item = cart[index];
        // 각 상품 2장씩
        skuList.push(item.sku);
        skuList.push(item.sku);
    });

    const skuParam = skuList.join(',');

    // Modal에서 iframe으로 미리보기 표시
    const printModal = document.getElementById('printModal');
    const iframeContainer = document.getElementById('printIframeContainer');

    iframeContainer.innerHTML = `<iframe src="barcode_print.php?skus=${encodeURIComponent(skuParam)}" style="width:100%; height:100%; border:none;"></iframe>`;
    printModal.classList.remove('hidden');
}

// HTML 이스케이프
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

// 가격 포맷팅
function formatPrice(price) {
    return Math.floor(price).toLocaleString();
}

// 전체선택 체크박스
document.getElementById('selectAllCheckbox').addEventListener('change', function() {
    document.querySelectorAll('.cart-item-checkbox').forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    updateSelectedCount();
});

// 바코드 입력 엔터 키
document.getElementById('barcodeInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        const barcode = this.value.trim();
        if (barcode) {
            searchProductByBarcode(barcode);
        } else {
            alert(translations.please_enter_barcode);
        }
    }
});

// 검색 버튼
document.getElementById('btnSearch').addEventListener('click', function() {
    const barcode = document.getElementById('barcodeInput').value.trim();
    if (barcode) {
        searchProductByBarcode(barcode);
    } else {
        alert(translations.please_enter_barcode);
    }
});

// 전체 삭제 버튼
document.getElementById('btnClearCart').addEventListener('click', clearCart);

// 출력 버튼
document.getElementById('btnPrint').addEventListener('click', printSelectedBarcodes);

// Modal 닫기
document.getElementById('btnClosePrintModal').addEventListener('click', function() {
    document.getElementById('printModal').classList.add('hidden');
    document.getElementById('printIframeContainer').innerHTML = '';
});

document.getElementById('btnCancelPrint').addEventListener('click', function() {
    document.getElementById('printModal').classList.add('hidden');
    document.getElementById('printIframeContainer').innerHTML = '';
});

// 출력 확인
document.getElementById('btnPrintConfirm').addEventListener('click', function() {
    const iframe = document.querySelector('#printIframeContainer iframe');
    if (iframe) {
        iframe.contentWindow.print();
    }
});

// ESC 키로 모달 닫기
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const printModal = document.getElementById('printModal');
        if (!printModal.classList.contains('hidden')) {
            printModal.classList.add('hidden');
            document.getElementById('printIframeContainer').innerHTML = '';
        }
    }
});

// 페이지 로드 시 장바구니 복원
loadCart();
</script>
