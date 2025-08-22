<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 테스트용 세션 설정
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'super_admin';

// 테스트용 상품 및 점포 정보 로드
$test_products = [];
$stores = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 점포 목록 조회
    $stores_stmt = $pdo->query("SELECT id, name FROM stores ORDER BY name");
    $stores = $stores_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 매입 이력이 있는 상품들 조회
    $products_with_history_stmt = $pdo->query("
        SELECT DISTINCT p.id, p.sku, p.name_ko, p.name_en, 'has_history' as type
        FROM products p
        INNER JOIN purchase_items pi ON p.id = pi.product_id
        INNER JOIN purchases pu ON pi.purchase_id = pu.purchase_id
        WHERE (pu.deleted_at IS NULL OR pu.deleted_at = '0000-00-00 00:00:00')
        ORDER BY p.id
        LIMIT 5
    ");
    $products_with_history = $products_with_history_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 매입 이력이 없는 상품들 조회
    $products_without_history_stmt = $pdo->query("
        SELECT p.id, p.sku, p.name_ko, p.name_en, 'no_history' as type
        FROM products p
        LEFT JOIN purchase_items pi ON p.id = pi.product_id
        WHERE pi.product_id IS NULL
        ORDER BY p.id
        LIMIT 5
    ");
    $products_without_history = $products_without_history_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $test_products = array_merge($products_with_history, $products_without_history);
    
} catch (Exception $e) {
    echo "데이터베이스 오류: " . $e->getMessage();
}

// 현재 점포 정보 (테스트용)
$current_store_id = 1;
$current_store_name = '본점';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>업데이트된 상품 추가 플로우 테스트</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .test-section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        .with-history { background-color: #e8f5e8; }
        .without-history { background-color: #fff3cd; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-8">업데이트된 상품 추가 플로우 테스트</h1>
        
        <div class="test-section bg-white rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">🔄 변경된 기능 설명</h2>
            <div class="bg-blue-50 p-4 rounded-lg mb-4">
                <h3 class="font-semibold text-blue-800 mb-2">새로운 플로우:</h3>
                <ol class="list-decimal list-inside text-blue-700 space-y-1">
                    <li><strong>상품 검색</strong> → 검색 결과에서 상품 클릭</li>
                    <li><strong>매입 이력 모달 자동 표시</strong> (기존: 즉시 장바구니 추가)</li>
                    <li><strong>매입가 선택</strong> → 매입 이력에서 선택 또는 수동 입력</li>
                    <li><strong>선택된 가격으로 장바구니 추가</strong></li>
                </ol>
            </div>
            
            <div class="bg-gray-50 p-4 rounded-lg">
                <h3 class="font-semibold text-gray-800 mb-2">지원하는 시나리오:</h3>
                <ul class="list-disc list-inside text-gray-700 space-y-1">
                    <li>✅ 매입 이력이 있는 상품: 최근 3건 표시 → 선택</li>
                    <li>✅ 매입 이력이 없는 상품: "기본 원가로 추가" 버튼 표시</li>
                    <li>✅ 수동 입력: "수동으로 가격 입력" 항상 제공</li>
                    <li>✅ 이미 장바구니에 있는 상품: 수량만 증가 (모달 없음)</li>
                </ul>
            </div>
        </div>
        
        <div class="test-section bg-white rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">📋 테스트용 상품 목록</h2>
            
            <?php if (!empty($test_products)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($test_products as $product): ?>
                        <div class="border border-gray-200 rounded p-4 <?php echo $product['type'] === 'has_history' ? 'with-history' : 'without-history'; ?>">
                            <div class="flex items-center justify-between mb-2">
                                <div class="font-medium text-gray-900">
                                    <?php echo htmlspecialchars($product['name_ko'] ?: $product['name_en']); ?>
                                </div>
                                <span class="text-xs px-2 py-1 rounded <?php echo $product['type'] === 'has_history' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                    <?php echo $product['type'] === 'has_history' ? '매입이력 있음' : '매입이력 없음'; ?>
                                </span>
                            </div>
                            <div class="text-sm text-gray-600 mb-3">
                                SKU: <?php echo htmlspecialchars($product['sku']); ?> | ID: <?php echo $product['id']; ?>
                            </div>
                            <div class="text-xs text-gray-500 mb-3">
                                <?php if ($product['type'] === 'has_history'): ?>
                                    <i class="fas fa-info-circle"></i> 이 상품을 선택하면 매입 이력 모달이 나타나서 최근 3건의 매입가를 보여줍니다.
                                <?php else: ?>
                                    <i class="fas fa-exclamation-triangle"></i> 이 상품을 선택하면 매입 이력이 없어서 "기본 원가로 추가" 버튼이 나타납니다.
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex space-x-2">
                                <button type="button" 
                                        onclick="testSingleProduct(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name_ko'] ?: $product['name_en']); ?>', '<?php echo $product['sku']; ?>', '<?php echo $product['type']; ?>')"
                                        class="px-3 py-1 bg-blue-500 text-white text-sm rounded hover:bg-blue-600">
                                    <i class="fas fa-play mr-1"></i>단독 테스트
                                </button>
                                <a href="store_transfers.php" target="_blank" 
                                   class="px-3 py-1 bg-green-500 text-white text-sm rounded hover:bg-green-600 inline-block">
                                    <i class="fas fa-external-link-alt mr-1"></i>실제 화면
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="error">테스트용 상품이 없습니다.</p>
                <p class="text-gray-600">상품 및 매입 관리에서 데이터를 먼저 등록해주세요.</p>
            <?php endif; ?>
        </div>
        
        <div class="test-section bg-white rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">🧪 점포별 매입 이력 API 테스트</h2>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">테스트할 점포 선택:</label>
                <select id="test-store-select" class="block w-full max-w-xs px-3 py-2 border border-gray-300 rounded-md">
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo ($store['id'] == $current_store_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($store['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div id="test-result" class="mt-4 p-4 bg-gray-50 rounded hidden">
                <!-- 테스트 결과가 여기에 표시됩니다 -->
            </div>
        </div>
        
        <div class="test-section bg-white rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">📝 테스트 시나리오</h2>
            
            <div class="space-y-4">
                <div class="border-l-4 border-green-500 pl-4">
                    <h3 class="font-semibold text-green-800">시나리오 1: 매입 이력이 있는 상품</h3>
                    <ol class="list-decimal list-inside text-sm text-gray-700 mt-2 space-y-1">
                        <li>점간이동 등록 화면에서 출발 점포 선택</li>
                        <li>매입 이력이 있는 상품 검색 후 클릭</li>
                        <li><strong>매입 이력 모달 자동 표시</strong></li>
                        <li>최근 3건의 매입 기록 확인</li>
                        <li>원하는 매입가 "선택" 버튼 클릭</li>
                        <li>선택된 가격으로 장바구니에 상품 추가됨</li>
                    </ol>
                </div>
                
                <div class="border-l-4 border-yellow-500 pl-4">
                    <h3 class="font-semibold text-yellow-800">시나리오 2: 매입 이력이 없는 상품</h3>
                    <ol class="list-decimal list-inside text-sm text-gray-700 mt-2 space-y-1">
                        <li>점간이동 등록 화면에서 출발 점포 선택</li>
                        <li>매입 이력이 없는 상품 검색 후 클릭</li>
                        <li><strong>매입 이력 모달 표시</strong> (이력 없음 메시지)</li>
                        <li>"기본 원가로 추가" 버튼 클릭</li>
                        <li>inventory의 cost_price로 장바구니에 상품 추가됨</li>
                    </ol>
                </div>
                
                <div class="border-l-4 border-blue-500 pl-4">
                    <h3 class="font-semibold text-blue-800">시나리오 3: 수동 가격 입력</h3>
                    <ol class="list-decimal list-inside text-sm text-gray-700 mt-2 space-y-1">
                        <li>상품 검색 후 클릭 → 매입 이력 모달 표시</li>
                        <li>"수동으로 가격 입력" 버튼 클릭</li>
                        <li>원하는 가격 입력</li>
                        <li>입력된 가격으로 장바구니에 상품 추가됨</li>
                    </ol>
                </div>
                
                <div class="border-l-4 border-purple-500 pl-4">
                    <h3 class="font-semibold text-purple-800">시나리오 4: 기존 상품 재추가</h3>
                    <ol class="list-decimal list-inside text-sm text-gray-700 mt-2 space-y-1">
                        <li>이미 장바구니에 있는 상품을 다시 클릭</li>
                        <li><strong>모달 없이 수량만 증가</strong></li>
                        <li>기존 가격 유지</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <script>
    function testSingleProduct(productId, productName, productSku, hasHistory) {
        const storeId = document.getElementById('test-store-select').value;
        const resultDiv = document.getElementById('test-result');
        
        resultDiv.innerHTML = '<p><i class="fas fa-spinner fa-spin"></i> 매입 이력 조회 중...</p>';
        resultDiv.classList.remove('hidden');
        
        const params = new URLSearchParams({
            product_id: productId,
            store_id: storeId
        });
        
        fetch('ajax_get_purchase_history.php?' + params.toString())
            .then(response => response.json())
            .then(data => {
                console.log('API Response:', data);
                
                let html = '<h3 class="font-semibold mb-3">매입 이력 조회 결과:</h3>';
                html += '<div class="mb-4 p-3 bg-blue-50 rounded">';
                html += '<strong>상품:</strong> ' + productName + ' (SKU: ' + productSku + ')<br>';
                html += '<strong>점포 ID:</strong> ' + storeId + '<br>';
                html += '<strong>예상 매입 이력:</strong> ' + (hasHistory === 'has_history' ? '<span class="text-green-600">있음</span>' : '<span class="text-orange-600">없음</span>') + '<br>';
                html += '<strong>API 응답:</strong> ' + (data.success ? '<span class="success">성공</span>' : '<span class="error">실패</span>');
                html += '</div>';
                
                if (data.success && data.data && data.data.length > 0) {
                    html += '<h4 class="font-medium mt-4 mb-2 text-green-700">✅ 매입 이력 (' + data.data.length + '건) - 모달에서 선택 가능:</h4>';
                    html += '<div class="space-y-2">';
                    
                    // 최근 3건만 표시
                    const recentHistory = data.data.slice(0, 3);
                    recentHistory.forEach((item, index) => {
                        html += '<div class="border border-green-200 rounded p-3 bg-green-50">';
                        html += '<div class="text-sm">';
                        html += '<strong>매입일:</strong> ' + item.purchase_date_formatted + '<br>';
                        html += '<strong>공급업체:</strong> ' + (item.supplier_name || 'N/A') + '<br>';
                        html += '<strong>박스원가:</strong> <span class="text-green-600 font-semibold">' + item.box_cost_formatted + '</span><br>';
                        html += '<strong>수량:</strong> ' + item.quantity + '개<br>';
                        html += '<strong>유형:</strong> ' + (item.purchase_type === 'box' ? '박스' : '개별');
                        html += '</div>';
                        html += '</div>';
                    });
                    
                    html += '</div>';
                    
                    if (data.data.length > 3) {
                        html += '<p class="text-sm text-gray-600 mt-2">* 실제 모달에서는 최근 3건만 표시됨 (전체 ' + data.data.length + '건)</p>';
                    }
                    
                    html += '<div class="mt-4 p-3 bg-blue-50 rounded">';
                    html += '<strong>예상 동작:</strong> 사용자가 상품 클릭 → 위 매입 이력들이 모달에 표시 → 선택 가능';
                    html += '</div>';
                    
                } else {
                    html += '<div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded">';
                    html += '<h4 class="font-medium text-yellow-800">⚠️ 매입 이력 없음</h4>';
                    html += '<p class="text-yellow-700 mt-2"><strong>예상 동작:</strong> 사용자가 상품 클릭 → "매입 이력이 없습니다" 메시지와 함께 "기본 원가로 추가" 버튼 표시</p>';
                    html += '</div>';
                    
                    if (data.message) {
                        html += '<p class="text-sm text-gray-600 mt-2">API 메시지: ' + data.message + '</p>';
                    }
                }
                
                resultDiv.innerHTML = html;
            })
            .catch(error => {
                console.error('API Error:', error);
                resultDiv.innerHTML = '<div class="p-3 bg-red-50 border border-red-200 rounded"><p class="error">API 호출 중 오류가 발생했습니다: ' + error.message + '</p></div>';
            });
    }
    
    // 페이지 로드 시 안내 메시지
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== 업데이트된 상품 추가 플로우 테스트 페이지 ===');
        console.log('1. 매입 이력이 있는 상품: 모달에서 최근 3건 표시');
        console.log('2. 매입 이력이 없는 상품: "기본 원가로 추가" 버튼 표시');
        console.log('3. 수동 입력 옵션 항상 제공');
        console.log('4. 이미 장바구니에 있는 상품: 수량만 증가');
    });
    </script>
</body>
</html>