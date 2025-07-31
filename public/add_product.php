<?php
$page_title = "새 상품 추가 - HOME K MART";
require_once __DIR__ . '/partials/header.php';

// 접근 권한 확인
if (!in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$errors = [];
$product = [
    'sku' => '', 'name_ko' => '', 'name_en' => '', 'description' => '',
    'category_id' => null, 'brand_id' => null, 'cost_price' => '',
    'selling_price' => '', 'image_url' => '', 'is_active' => 1,
    'pieces_per_box' => 1, 'barcode' => ''
];
$categories = [];
$brands = [];
$margin_rules = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $brands = $pdo->query("SELECT id, name_ko FROM brands ORDER BY name_ko ASC")->fetchAll(PDO::FETCH_ASSOC);
    $margin_rules_data = $pdo->query("SELECT category_id, margin_percentage FROM margin_rules")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($margin_rules_data as $rule) {
        $margin_rules[$rule['category_id']] = $rule['margin_percentage'];
    }

} catch (PDOException $e) {
    $errors[] = "데이터베이스 연결에 실패했습니다: " . $e->getMessage();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 데이터 받아오기
    foreach ($product as $key => $value) {
        if (isset($_POST[$key])) {
            $product[$key] = trim($_POST[$key]);
        }
    }

    // 유효성 검사
    if (empty($product['sku'])) $errors[] = "SKU를 입력해주세요.";
    if (empty($product['name_en'])) $errors[] = "상품명(영문)을 입력해주세요.";
    if (empty($product['selling_price']) || !is_numeric($product['selling_price'])) $errors[] = "판매가는 숫자여야 합니다.";
    if (empty($product['cost_price']) || !is_numeric($product['cost_price'])) $errors[] = "원가는 숫자여야 합니다.";
    if (!empty($product['pieces_per_box']) && (!is_numeric($product['pieces_per_box']) || $product['pieces_per_box'] < 1)) $errors[] = "박스포장 수량은 1 이상의 숫자여야 합니다.";

    // SKU 중복 확인
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->execute([$product['sku']]);
        if ($stmt->fetch()) {
            $errors[] = "이미 사용 중인 SKU입니다.";
        }
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO products (sku, name_ko, name_en, description, category_id, brand_id, cost_price, selling_price, image_url, is_active, pieces_per_box, barcode, last_modified_by_user_id) VALUES (:sku, :name_ko, :name_en, :description, :category_id, :brand_id, :cost_price, :selling_price, :image_url, :is_active, :pieces_per_box, :barcode, :user_id)";
            $stmt = $pdo->prepare($sql);

            $params = [
                ':sku' => $product['sku'],
                ':name_ko' => $product['name_ko'],
                ':name_en' => $product['name_en'] ?: null,
                ':description' => $product['description'] ?: null,
                ':category_id' => $product['category_id'] ?: null,
                ':brand_id' => $product['brand_id'] ?: null,
                ':cost_price' => $product['cost_price'] ?: 0,
                ':selling_price' => $product['selling_price'],
                ':image_url' => $product['image_url'] ?: null,
                ':is_active' => $product['is_active'],
                ':pieces_per_box' => $product['pieces_per_box'] ?: 1,
                ':barcode' => $product['barcode'] ?: null,
                ':user_id' => $_SESSION['user_id']
            ];

            $stmt->execute($params);

            $_SESSION['flash'] = ['type' => 'success', 'message' => '상품이 성공적으로 추가되었습니다.'];
            header("Location: product_management.php");
            exit;

        } catch (PDOException $e) {
            $errors[] = "상품 추가 중 오류가 발생했습니다: " . $e->getMessage();
        }
    }
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-3xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <a href="product_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-2"></i>
                상품 목록으로 돌아가기
            </a>
        </div>

        <div class="bg-white shadow-xl rounded-2xl">
            <div class="py-8 px-4 sm:px-10">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold tracking-tight text-gray-900">새 상품 추가</h1>
                    <p class="mt-2 text-sm text-gray-600">새로운 상품의 정보를 입력해주세요.</p>
                </div>

                <?php if (!empty($errors)) : ?>
                    <div class="mb-6 rounded-lg bg-red-50 p-4 border border-red-200">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-times-circle text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">다음 오류를 해결해주세요.</h3>
                                <div class="mt-2 text-sm text-red-700">
                                    <ul role="list" class="list-disc pl-5 space-y-1">
                                        <?php foreach ($errors as $error) : ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form action="add_product.php" method="post" class="space-y-6">
                    <!-- 바코드 검색 -->
                    <div class="border-b border-gray-200 pb-6">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">바코드 검색</h3>
                        <p class="mt-1 text-sm text-gray-500">바코드를 스캔하거나 입력하여 기존 상품 정보를 불러올 수 있습니다.</p>
                        <div class="mt-4 flex items-stretch gap-x-3">
                            <div class="flex-grow">
                                <label for="barcode_search" class="sr-only">바코드</label>
                                <input type="text" name="barcode_search" id="barcode_search" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" placeholder="바코드를 입력하세요...">
                            </div>
                            <button type="button" id="barcode-search-db-btn" class="relative inline-flex items-center gap-x-1.5 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                                <i class="fas fa-database text-gray-400"></i>
                                내 DB 검색
                            </button>
                            <button type="button" id="barcode-search-web-btn" class="relative inline-flex items-center gap-x-1.5 rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700">
                                <i class="fas fa-globe"></i>
                                웹 검색
                            </button>
                        </div>
                        <div id="barcode-result" class="mt-4 hidden">
                            <div id="barcode-error" class="hidden rounded-md bg-red-50 p-4">
                                <p class="text-sm font-medium text-red-800"></p>
                            </div>
                            <div id="barcode-success" class="hidden rounded-md bg-green-50 p-4 flex items-start gap-x-4">
                                <img id="product-image-preview" src="" alt="상품 이미지" class="h-20 w-20 rounded-md object-cover">
                                <div>
                                    <p class="font-semibold text-gray-900">상품 정보를 불러왔습니다.</p>
                                    <p class="text-sm text-gray-600">아래 폼의 내용이 자동으로 채워졌습니다. 수정 후 저장하세요.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 기본 정보 -->
                    <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                            <label for="sku" class="block text-sm font-medium text-gray-700">SKU</label>
                            <input type="text" name="sku" id="sku" value="<?php echo htmlspecialchars($product['sku']); ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                        </div>
                        <div class="sm:col-span-4">
                            <label for="name_ko" class="block text-sm font-medium text-gray-700">상품명 (한글)</label>
                            <div class="mt-1 flex rounded-md shadow-sm">
                                <input type="text" name="name_ko" id="name_ko" value="<?php echo htmlspecialchars($product['name_ko']); ?>" class="block w-full flex-1 rounded-none rounded-l-md border-gray-300 focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                <button type="button" id="translate-btn" class="relative -ml-px inline-flex items-center space-x-2 rounded-r-md border border-gray-300 bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                                    <i class="fas fa-language h-5 w-5 text-gray-400"></i>
                                    <span>번역</span>
                                </button>
                            </div>
                        </div>
                        <div class="sm:col-span-6">
                            <label for="name_en" class="block text-sm font-medium text-gray-700">상품명 (영문)</label>
                            <input type="text" name="name_en" id="name_en" value="<?php echo htmlspecialchars($product['name_en']); ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                        </div>
                    </div>

                    <!-- 분류 -->
                    <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-2">
                        <div>
                            <label for="category_search" class="block text-sm font-medium text-gray-700">카테고리</label>
                            <div class="relative">
                                <input type="text" id="category_search" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" placeholder="카테고리 이름 검색...">
                                <input type="hidden" name="category_id" id="category_id" value="<?php echo htmlspecialchars($product['category_id']); ?>">
                                <div id="category_results" class="absolute z-20 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden"></div>
                            </div>
                        </div>
                        <div>
                            <label for="brand_search" class="block text-sm font-medium text-gray-700">브랜드</label>
                            <div class="relative">
                                <input type="text" id="brand_search" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" placeholder="브랜드 이름 검색...">
                                <input type="hidden" name="brand_id" id="brand_id" value="<?php echo htmlspecialchars($product['brand_id']); ?>">
                                <div id="brand_results" class="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm hidden"></div>
                            </div>
                        </div>
                    </div>

                    <!-- 가격 정보 -->
                    <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-3">
                        <div>
                            <label for="cost_price" class="block text-sm font-medium text-gray-700">원가</label>
                            <div class="relative mt-1 rounded-md shadow-sm">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <span class="text-gray-500 sm:text-sm">₩</span>
                                </div>
                                <input type="number" name="cost_price" id="cost_price" value="<?php echo htmlspecialchars($product['cost_price']); ?>" required class="block w-full rounded-md border-gray-300 pl-7 pr-12 focus:border-primary-500 focus:ring-primary-500 sm:text-sm" placeholder="0" step="1">
                            </div>
                        </div>
                        <div>
                            <label for="margin_percent" class="block text-sm font-medium text-gray-700">마진</label>
                            <div class="relative mt-1 rounded-md shadow-sm">
                                <input type="number" name="margin_percent" id="margin_percent" class="block w-full rounded-md border-gray-300 focus:border-primary-500 focus:ring-primary-500 sm:text-sm" placeholder="0">
                            </div>
                        </div>
                        <div>
                            <label for="selling_price" class="block text-sm font-medium text-gray-700">판매가</label>
                            <div class="relative mt-1 rounded-md shadow-sm">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <span class="text-gray-500 sm:text-sm">₩</span>
                                </div>
                                <input type="number" name="selling_price" id="selling_price" value="<?php echo htmlspecialchars($product['selling_price']); ?>" required class="block w-full rounded-md border-gray-300 pl-7 pr-12 focus:border-primary-500 focus:ring-primary-500 sm:text-sm" placeholder="0" step="1">
                            </div>
                        </div>
                    </div>

                    <!-- 박스포장 정보 및 바코드 -->
                    <div class="border-t border-gray-200 pt-6">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">박스포장 정보</h3>
                        <p class="mt-1 text-sm text-gray-500">박스 단위로 매입할 때 필요한 정보를 입력하세요.</p>
                        <div class="mt-4 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-2">
                            <div>
                                <label for="pieces_per_box" class="block text-sm font-medium text-gray-700">박스당 수량</label>
                                <div class="relative mt-1 rounded-md shadow-sm">
                                    <input type="number" name="pieces_per_box" id="pieces_per_box" value="<?php echo htmlspecialchars($product['pieces_per_box']); ?>" min="1" class="block w-full rounded-md border-gray-300 focus:border-primary-500 focus:ring-primary-500 sm:text-sm" placeholder="1">
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                        <span class="text-gray-500 sm:text-sm">개</span>
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">한 박스에 들어있는 낱개 수량 (기본값: 1개)</p>
                            </div>
                            <div>
                                <label for="barcode" class="block text-sm font-medium text-gray-700">바코드 <span class="text-gray-500">(선택)</span></label>
                                <input type="text" name="barcode" id="barcode" value="<?php echo htmlspecialchars($product['barcode']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" placeholder="바코드 입력">
                                <p class="mt-1 text-xs text-gray-500">상품 바코드 (매입 시 검색용)</p>
                            </div>
                        </div>
                    </div>

                    <!-- 추가 정보 -->
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700">상품 설명 <span class="text-gray-500">(선택)</span></label>
                        <textarea id="description" name="description" rows="4" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"><?php echo htmlspecialchars($product['description']); ?></textarea>
                    </div>
                    <div>
                        <label for="image_url" class="block text-sm font-medium text-gray-700">이미지 URL <span class="text-gray-500">(선택)</span></label>
                        <input type="url" name="image_url" id="image_url" value="<?php echo htmlspecialchars($product['image_url']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" placeholder="https://example.com/image.png">
                    </div>

                    <!-- 상태 -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">상태</label>
                        <div class="mt-2 flex items-center">
                            <input id="is_active_true" name="is_active" type="radio" value="1" <?php echo ($product['is_active'] == 1) ? 'checked' : ''; ?> class="h-4 w-4 border-gray-300 text-primary-600 focus:ring-primary-500">
                            <label for="is_active_true" class="ml-3 block text-sm font-medium text-gray-700">활성</label>
                            <input id="is_active_false" name="is_active" type="radio" value="0" <?php echo ($product['is_active'] == 0) ? 'checked' : ''; ?> class="ml-6 h-4 w-4 border-gray-300 text-primary-600 focus:ring-primary-500">
                            <label for="is_active_false" class="ml-3 block text-sm font-medium text-gray-700">비활성</label>
                        </div>
                    </div>

                    <div class="pt-5 border-t border-gray-200">
                        <div class="flex justify-end gap-x-3">
                            <a href="product_management.php" class="rounded-md bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">취소</a>
                            <button type="submit" class="inline-flex justify-center rounded-md bg-primary-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                                <i class="fas fa-plus mr-2"></i>
                                상품 추가
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- 카테고리 추가 Modal -->
<div id="add-category-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-30">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-primary-100">
                <i class="fas fa-sitemap text-primary-600 text-xl"></i>
            </div>
            <h3 class="text-lg leading-6 font-medium text-gray-900 mt-4">새 카테고리 추가</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">'<span id="new-category-name-modal" class="font-semibold"></span>' 카테고리를 새로 추가하시겠습니까?</p>
            </div>
            <div class="items-center px-4 py-3">
                <button id="cancel-add-category" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 mr-2">취소</button>
                <button id="confirm-add-category" class="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700">추가</button>
            </div>
        </div>
    </div>
</div>

<!-- 브랜드 추가 Modal -->
<div id="add-brand-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-30">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-primary-100">
                <i class="fas fa-tags text-primary-600 text-xl"></i>
            </div>
            <h3 class="text-lg leading-6 font-medium text-gray-900 mt-4">새 브랜드 추가</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">'<span id="new-brand-name-modal" class="font-semibold"></span>' 브랜드를 새로 추가하시겠습니까?</p>
            </div>
            <div class="items-center px-4 py-3">
                <button id="cancel-add-brand" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 mr-2">취소</button>
                <button id="confirm-add-brand" class="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700">추가</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Element Declarations ---
    const translateBtn = document.getElementById('translate-btn');
    const nameKoInput = document.getElementById('name_ko');
    const nameEnInput = document.getElementById('name_en');
    const costPriceInput = document.getElementById('cost_price');
    const marginInput = document.getElementById('margin_percent');
    const sellingPriceInput = document.getElementById('selling_price');
    const barcodeDbSearchBtn = document.getElementById('barcode-search-db-btn');
    const barcodeWebSearchBtn = document.getElementById('barcode-search-web-btn');
    const barcodeInput = document.getElementById('barcode_search');
    const barcodeResultDiv = document.getElementById('barcode-result');
    const barcodeErrorDiv = document.getElementById('barcode-error');
    const barcodeSuccessDiv = document.getElementById('barcode-success');
    const imagePreview = document.getElementById('product-image-preview');

    // --- 번역 기능 ---
    translateBtn.addEventListener('click', function() {
        const textToTranslate = nameKoInput.value;
        if (!textToTranslate.trim()) {
            alert('번역할 상품명(한글)을 입력해주세요.');
            return;
        }
        translateBtn.disabled = true;
        translateBtn.innerHTML = '<i class="fas fa-spinner fa-spin h-5 w-5 text-gray-400"></i><span>번역 중...</span>';
        fetch('ajax_translate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `text=${encodeURIComponent(textToTranslate)}&target_lang=EN`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                nameEnInput.value = data.translated_text;
            } else {
                alert('번역 실패: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('번역 중 오류가 발생했습니다.');
        })
        .finally(() => {
            translateBtn.disabled = false;
            translateBtn.innerHTML = '<i class="fas fa-language h-5 w-5 text-gray-400"></i><span>번역</span>';
        });
    });

    // --- 검색 가능한 드롭다운 공통 함수 ---
    function setupSearchableDropdown(options) {
        const searchInput = document.getElementById(options.searchInputId);
        const idInput = document.getElementById(options.idInputId);
        const resultsContainer = document.getElementById(options.resultsContainerId);
        const allItems = options.items;
        const modal = document.getElementById(options.addModalId);
        const newNameSpan = document.getElementById(options.newNameSpanId);
        const confirmBtn = document.getElementById(options.confirmBtnId);
        const cancelBtn = document.getElementById(options.cancelBtnId);

        // 초기 값 설정
        if (idInput.value) {
            const initialItem = allItems.find(item => item.id == idInput.value);
            if (initialItem) {
                searchInput.value = initialItem.name_ko || initialItem.name;
            }
        }

        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            if (!searchTerm) {
                resultsContainer.innerHTML = '';
                resultsContainer.classList.add('hidden');
                idInput.value = '';
                return;
            }

            const filteredItems = allItems.filter(item => (item.name_ko || item.name).toLowerCase().includes(searchTerm));
            
            resultsContainer.innerHTML = '';
            if (filteredItems.length > 0) {
                filteredItems.forEach(item => {
                    const div = document.createElement('div');
                    div.textContent = item.name_ko || item.name;
                    div.className = 'cursor-pointer hover:bg-primary-100 p-2';
                    div.addEventListener('click', function() {
                        searchInput.value = item.name_ko || item.name;
                        idInput.value = item.id;
                        resultsContainer.classList.add('hidden');
                    });
                    resultsContainer.appendChild(div);
                });
            } else {
                const div = document.createElement('div');
                div.innerHTML = `<span class="italic text-gray-500 p-2">결과 없음.</span> <button type="button" class="text-primary-600 hover:underline ml-2 add-new-btn">새로 추가</button>`;
                div.className = 'p-2';
                resultsContainer.appendChild(div);

                div.querySelector('.add-new-btn').addEventListener('click', function() {
                    newNameSpan.textContent = searchInput.value;
                    modal.classList.remove('hidden');
                });
            }
            resultsContainer.classList.remove('hidden');
        });

        cancelBtn.addEventListener('click', () => modal.classList.add('hidden'));

        confirmBtn.addEventListener('click', () => {
            const newName = searchInput.value;
            fetch(options.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `name=${encodeURIComponent(newName)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    idInput.value = data.data.id;
                    searchInput.value = data.data.name_ko || data.data.name;
                    allItems.push(data.data);
                    modal.classList.add('hidden');
                    resultsContainer.classList.add('hidden');
                    alert(`새 ${options.itemType}이(가) 추가되고 선택되었습니다.`);
                } else {
                    alert('오류: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert(`${options.itemType} 추가 중 오류가 발생했습니다.`);
            });
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
                resultsContainer.classList.add('hidden');
            }
        });
    }

    // --- 카테고리 검색 설정 ---
    setupSearchableDropdown({
        searchInputId: 'category_search',
        idInputId: 'category_id',
        resultsContainerId: 'category_results',
        items: <?php echo json_encode($categories); ?>,
        addModalId: 'add-category-modal',
        newNameSpanId: 'new-category-name-modal',
        confirmBtnId: 'confirm-add-category',
        cancelBtnId: 'cancel-add-category',
        ajaxUrl: 'ajax_add_category.php',
        itemType: '카테고리'
    });

    // --- 마진 자동 적용 ---
    const marginRules = <?php echo json_encode($margin_rules); ?>;
    const categoryIdInput = document.getElementById('category_id');
    
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
                const categoryId = categoryIdInput.value;
                if (marginRules[categoryId]) {
                    marginInput.value = marginRules[categoryId];
                    updateCalculations('margin');
                }
            }
        });
    });

    observer.observe(categoryIdInput, {
        attributes: true 
    });

    // --- 브랜드 검색 설정 ---
    setupSearchableDropdown({
        searchInputId: 'brand_search',
        idInputId: 'brand_id',
        resultsContainerId: 'brand_results',
        items: <?php echo json_encode($brands); ?>,
        addModalId: 'add-brand-modal',
        newNameSpanId: 'new-brand-name-modal',
        confirmBtnId: 'confirm-add-brand',
        cancelBtnId: 'cancel-add-brand',
        ajaxUrl: 'ajax_add_brand.php',
        itemType: '브랜드'
    });

    // --- 판매가/마진 자동 계산 ---
    let isCalculating = false; 

    function updateCalculations(source) {
        if (isCalculating) return;
        isCalculating = true;

        const cost = parseFloat(costPriceInput.value);
        const margin = parseFloat(marginInput.value);
        const selling = parseFloat(sellingPriceInput.value);

        if (source === 'margin' || source === 'cost_from_margin') {
            if (!isNaN(cost) && cost > 0 && !isNaN(margin)) {
                const newSelling = cost * (1 + margin / 100);
                sellingPriceInput.value = newSelling.toFixed(2);
            }
        } else if (source === 'selling' || source === 'cost_from_selling') {
            if (!isNaN(cost) && cost > 0 && !isNaN(selling) && selling > cost) {
                const newMargin = ((selling - cost) / cost) * 100;
                marginInput.value = newMargin.toFixed(2);
            } else if (!isNaN(cost) && !isNaN(selling) && selling <= cost) {
                marginInput.value = '0';
            }
        }
        
        setTimeout(() => { isCalculating = false; }, 100);
    }

    costPriceInput.addEventListener('input', () => {
        updateCalculations('cost_from_selling');
        updateCalculations('cost_from_margin');
    });
    marginInput.addEventListener('input', () => updateCalculations('margin'));
    sellingPriceInput.addEventListener('input', () => updateCalculations('selling'));

    // --- 바코드 검색 기능 ---
    // 바코드 입력 필드의 내용이 변경되고 포커스가 벗어날 때 검색 실행
    barcodeInput.addEventListener('change', function() {
        if (barcodeInput.value.trim() !== '') {
            barcodeDbSearchBtn.click();
        }
    });

    function fillFormWithProductData(product) {
        document.getElementById('sku').value = product.sku || '';
        document.getElementById('name_ko').value = product.name_ko || '';
        document.getElementById('name_en').value = product.name_en || '';
        document.getElementById('description').value = product.description || '';
        document.getElementById('cost_price').value = product.cost_price || '';
        document.getElementById('selling_price').value = product.selling_price || '';
        document.getElementById('image_url').value = product.image_url || '';
        document.getElementById('category_id').value = product.category_id || '';
        document.getElementById('category_search').value = product.category_name || '';
        document.getElementById('brand_id').value = product.brand_id || '';
        document.getElementById('brand_search').value = product.brand_name_ko || '';
        
        if (product.image_url) {
            imagePreview.src = product.image_url;
            barcodeSuccessDiv.classList.remove('hidden');
            barcodeErrorDiv.classList.add('hidden');
        } else {
            barcodeSuccessDiv.classList.add('hidden');
        }
        barcodeResultDiv.classList.remove('hidden');
    }

    function handleBarcodeSearch(url, button) {
        const barcode = barcodeInput.value.trim();
        if (!barcode) {
            alert('바코드를 입력해주세요.');
            return;
        }

        const originalButtonHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        fetch(`${url}?barcode=${encodeURIComponent(barcode)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(result => {
                if (result.success) {
                    fillFormWithProductData(result.data);
                } else {
                    barcodeErrorDiv.querySelector('p').textContent = result.message;
                    barcodeErrorDiv.classList.remove('hidden');
                    barcodeSuccessDiv.classList.add('hidden');
                    barcodeResultDiv.classList.remove('hidden');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                barcodeErrorDiv.querySelector('p').textContent = '검색 중 오류가 발생했습니다: ' + error.message;
                barcodeErrorDiv.classList.remove('hidden');
                barcodeSuccessDiv.classList.add('hidden');
                barcodeResultDiv.classList.remove('hidden');
            })
            .finally(() => {
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            });
    }

    barcodeDbSearchBtn.addEventListener('click', () => {
        handleBarcodeSearch('ajax_get_product_by_barcode.php', barcodeDbSearchBtn);
    });

    barcodeWebSearchBtn.addEventListener('click', () => {
        handleBarcodeSearch('ajax_search_barcode_online.php', barcodeWebSearchBtn);
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>