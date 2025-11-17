<?php
/**
 * 모바일 전용 - SKU 상품명 수정 페이지
 * SKU를 입력하여 상품의 한글명/영문명을 빠르게 수정
 */

// AJAX 요청 처리 - 상품 검색
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_product') {
    session_start();
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';

    header('Content-Type: application/json; charset=utf-8');

    // 권한 체크
    if (!has_permission('product_management')) {
        echo json_encode(['success' => false, 'error' => '상품 관리 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sku = trim($_POST['sku'] ?? '');

    if (empty($sku)) {
        echo json_encode(['success' => false, 'error' => 'SKU를 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $conn = get_db_connection();

        // SKU로 상품 검색
        $stmt = $conn->prepare("
            SELECT id, name_ko, name_en, sku
            FROM products
            WHERE sku = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'SKU에 해당하는 상품을 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        } else {
            $product = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'product' => [
                    'id' => (int)$product['id'],
                    'name_ko' => $product['name_ko'] ?? '',
                    'name_en' => $product['name_en'] ?? '',
                    'sku' => $product['sku'] ?? ''
                ]
            ], JSON_UNESCAPED_UNICODE);
        }

        $stmt->close();
        $conn->close();

    } catch (Exception $e) {
        error_log("Product search error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => '상품 검색 중 오류가 발생했습니다.',
            'debug' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// AJAX 요청 처리 - 상품명 업데이트
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_product_names') {
    session_start();
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';

    header('Content-Type: application/json; charset=utf-8');

    // 권한 체크
    if (!has_permission('product_management')) {
        echo json_encode(['success' => false, 'error' => '상품 관리 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $product_id = (int)($_POST['product_id'] ?? 0);
    $name_ko = trim($_POST['name_ko'] ?? '');
    $name_en = trim($_POST['name_en'] ?? '');

    // 입력 검증
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'error' => '잘못된 상품 ID입니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($name_ko)) {
        echo json_encode(['success' => false, 'error' => '한글 상품명을 입력해주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strlen($name_ko) > 255 || strlen($name_en) > 255) {
        echo json_encode(['success' => false, 'error' => '상품명이 너무 깁니다. (최대 255자)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $conn = get_db_connection();
        $conn->begin_transaction();

        // 상품명 업데이트
        $stmt = $conn->prepare("
            UPDATE products
            SET name_ko = ?, name_en = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("ssi", $name_ko, $name_en, $product_id);

        if ($stmt->execute()) {
            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => '상품명이 성공적으로 수정되었습니다.',
                'data' => [
                    'name_ko' => $name_ko,
                    'name_en' => $name_en
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('상품명 업데이트에 실패했습니다.');
        }

        $stmt->close();
        $conn->close();

    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
            $conn->close();
        }
        error_log("Product update error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'debug' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 페이지 렌더링
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 모바일/태블릿 감지 함수
function is_mobile_device() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    // 모바일, 태블릿 모두 허용
    return preg_match('/(android|iphone|ipad|tablet|mobile|webos|blackberry)/i', $user_agent);
}

$page_title = '모바일 상품명 수정';
require_once __DIR__ . '/partials/header.php';

// 권한 체크
if (!has_permission('product_management')) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>
            <strong class='font-bold'>접근 거부:</strong>
            <span class='block sm:inline'>상품 관리 권한이 필요합니다.</span>
          </div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$is_mobile = is_mobile_device();
?>

<style>
/* 모바일/태블릿 전용 스타일 */
@media (max-width: 1024px) {
    body {
        background-color: #f7fafc;
    }

    .mobile-container {
        min-height: calc(100vh - 120px);
        padding: 1rem;
    }

    .mobile-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        padding: 1.5rem;
        margin-bottom: 1rem;
    }

    .mobile-input {
        width: 100%;
        padding: 1rem;
        font-size: 1.125rem;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .mobile-input:focus {
        outline: none;
        border-color: #4299e1;
        box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
    }

    .mobile-btn {
        width: 100%;
        padding: 1rem;
        font-size: 1.125rem;
        font-weight: 600;
        border-radius: 8px;
        transition: all 0.2s;
        cursor: pointer;
    }

    .mobile-btn-primary {
        background: #4299e1;
        color: white;
        border: none;
    }

    .mobile-btn-primary:active {
        background: #3182ce;
        transform: scale(0.98);
    }

    .mobile-btn-success {
        background: #48bb78;
        color: white;
        border: none;
    }

    .mobile-btn-success:active {
        background: #38a169;
        transform: scale(0.98);
    }

    .mobile-label {
        display: block;
        font-size: 0.875rem;
        font-weight: 600;
        color: #4a5568;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .product-info {
        display: none;
        animation: fadeIn 0.3s ease-in;
    }

    .product-info.show {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
}

.desktop-warning {
    display: none;
}

@media (min-width: 1025px) {
    .mobile-only {
        display: none !important;
    }

    .desktop-warning {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 400px;
        text-align: center;
    }
}

/* 태블릿 최적화 (768px ~ 1024px) */
@media (min-width: 768px) and (max-width: 1024px) {
    .mobile-container {
        max-width: 600px;
        margin: 2rem auto;
        padding: 2rem;
    }

    .mobile-card {
        padding: 2rem;
    }

    .mobile-input {
        font-size: 1.25rem;
        padding: 1.25rem;
    }

    .mobile-btn {
        font-size: 1.25rem;
        padding: 1.25rem;
    }

    .mobile-label {
        font-size: 1rem;
    }
}

.alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    font-size: 0.95rem;
    animation: slideDown 0.3s ease-out;
}

.alert-success {
    background-color: #c6f6d5;
    border: 1px solid #9ae6b4;
    color: #22543d;
}

.alert-error {
    background-color: #fed7d7;
    border: 1px solid #fc8181;
    color: #742a2a;
}

.alert-info {
    background-color: #bee3f8;
    border: 1px solid #90cdf4;
    color: #2c5282;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<!-- 데스크톱 경고 메시지 -->
<div class="desktop-warning">
    <div class="text-center px-4">
        <i class="fas fa-mobile-alt text-6xl text-gray-400 mb-4"></i>
        <h2 class="text-2xl font-bold text-gray-700 mb-2">모바일/태블릿 전용 기능</h2>
        <p class="text-gray-600">이 페이지는 모바일 또는 태블릿에서 사용할 수 있습니다.</p>
        <p class="text-sm text-gray-500 mt-2">모바일 기기 또는 태블릿으로 접속해주세요.</p>
    </div>
</div>

<!-- 모바일 전용 컨텐츠 -->
<div class="mobile-only mobile-container">
    <!-- 피드백 메시지 영역 -->
    <div id="messageArea"></div>

    <!-- SKU 검색 카드 -->
    <div class="mobile-card">
        <label for="skuInput" class="mobile-label">
            <i class="fas fa-barcode mr-1"></i> SKU / 바코드
        </label>
        <input
            type="text"
            id="skuInput"
            class="mobile-input mb-3"
            placeholder="SKU 또는 바코드 입력"
            autocomplete="off"
        >
        <button id="searchBtn" class="mobile-btn mobile-btn-primary">
            <i class="fas fa-search mr-2"></i> 상품 검색
        </button>
    </div>

    <!-- 상품 정보 카드 -->
    <div id="productInfo" class="mobile-card product-info">
        <input type="hidden" id="productId">

        <div class="mb-4">
            <label for="nameKoInput" class="mobile-label">
                <i class="fas fa-font mr-1"></i> 한글 상품명
            </label>
            <input
                type="text"
                id="nameKoInput"
                class="mobile-input"
                placeholder="한글 상품명 입력"
                maxlength="255"
            >
        </div>

        <div class="mb-4">
            <label for="nameEnInput" class="mobile-label">
                <i class="fas fa-globe mr-1"></i> 영문 상품명
            </label>
            <input
                type="text"
                id="nameEnInput"
                class="mobile-input"
                placeholder="English Product Name"
                maxlength="255"
            >
        </div>

        <button id="saveBtn" class="mobile-btn mobile-btn-success">
            <i class="fas fa-save mr-2"></i> 저장
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const skuInput = document.getElementById('skuInput');
    const searchBtn = document.getElementById('searchBtn');
    const productInfo = document.getElementById('productInfo');
    const productId = document.getElementById('productId');
    const nameKoInput = document.getElementById('nameKoInput');
    const nameEnInput = document.getElementById('nameEnInput');
    const saveBtn = document.getElementById('saveBtn');
    const messageArea = document.getElementById('messageArea');

    // 메시지 표시 함수
    function showMessage(message, type = 'info') {
        messageArea.innerHTML = `
            <div class="alert alert-${type}">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'} mr-2"></i>
                ${message}
            </div>
        `;

        // 3초 후 자동 숨김
        setTimeout(() => {
            messageArea.innerHTML = '';
        }, 3000);
    }

    // SKU 입력 시 엔터키로 검색
    skuInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchProduct();
        }
    });

    // 검색 버튼 클릭
    searchBtn.addEventListener('click', searchProduct);

    // 상품 검색 함수
    function searchProduct() {
        const sku = skuInput.value.trim();

        if (!sku) {
            showMessage('SKU를 입력해주세요.', 'error');
            skuInput.focus();
            return;
        }

        // 버튼 로딩 상태
        searchBtn.disabled = true;
        searchBtn.innerHTML = '<span class="spinner"></span> 검색 중...';

        // AJAX 요청
        fetch('mobile_product_edit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=search_product&sku=${encodeURIComponent(sku)}`
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            console.log('Server response:', text);
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    // 상품 정보 표시
                    productId.value = data.product.id;
                    nameKoInput.value = data.product.name_ko || '';
                    nameEnInput.value = data.product.name_en || '';
                    productInfo.classList.add('show');

                    showMessage('상품을 찾았습니다.', 'success');
                    nameKoInput.focus();
                } else {
                    const errorMsg = data.error || '알 수 없는 오류가 발생했습니다.';
                    if (data.debug) {
                        console.error('Debug info:', data.debug);
                    }
                    showMessage(errorMsg, 'error');
                    productInfo.classList.remove('show');
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                showMessage('서버 응답을 처리할 수 없습니다.', 'error');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            showMessage('검색 중 네트워크 오류가 발생했습니다.', 'error');
        })
        .finally(() => {
            // 버튼 원래 상태로
            searchBtn.disabled = false;
            searchBtn.innerHTML = '<i class="fas fa-search mr-2"></i> 상품 검색';
        });
    }

    // 저장 버튼 클릭
    saveBtn.addEventListener('click', saveProductNames);

    // 상품명 저장 함수
    function saveProductNames() {
        const id = productId.value;
        const nameKo = nameKoInput.value.trim();
        const nameEn = nameEnInput.value.trim();

        if (!nameKo) {
            showMessage('한글 상품명을 입력해주세요.', 'error');
            nameKoInput.focus();
            return;
        }

        // 버튼 로딩 상태
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner"></span> 저장 중...';

        // AJAX 요청
        fetch('mobile_product_edit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=update_product_names&product_id=${id}&name_ko=${encodeURIComponent(nameKo)}&name_en=${encodeURIComponent(nameEn)}`
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            console.log('Server response:', text);
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    showMessage(data.message, 'success');

                    // 입력 필드 초기화 및 다음 검색 준비
                    setTimeout(() => {
                        skuInput.value = '';
                        productInfo.classList.remove('show');
                        skuInput.focus();
                    }, 1500);
                } else {
                    const errorMsg = data.error || '알 수 없는 오류가 발생했습니다.';
                    if (data.debug) {
                        console.error('Debug info:', data.debug);
                    }
                    showMessage(errorMsg, 'error');
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                showMessage('서버 응답을 처리할 수 없습니다.', 'error');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            showMessage('저장 중 네트워크 오류가 발생했습니다.', 'error');
        })
        .finally(() => {
            // 버튼 원래 상태로
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save mr-2"></i> 저장';
        });
    }

    // SKU 입력 필드 클릭/포커스 시 전체 선택
    skuInput.addEventListener('focus', function() {
        this.select();
    });

    skuInput.addEventListener('click', function() {
        this.select();
    });

    // 한글 상품명 입력 필드 클릭/포커스 시 전체 선택
    nameKoInput.addEventListener('focus', function() {
        this.select();
    });

    nameKoInput.addEventListener('click', function() {
        this.select();
    });

    // 영문 상품명 입력 필드 클릭/포커스 시 전체 선택
    nameEnInput.addEventListener('focus', function() {
        this.select();
    });

    nameEnInput.addEventListener('click', function() {
        this.select();
    });

    // 페이지 로드 시 SKU 입력 필드에 포커스
    skuInput.focus();
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
