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
    $products_stmt = $pdo->query("
        SELECT DISTINCT p.id, p.sku, p.name_ko, p.name_en
        FROM products p
        INNER JOIN purchase_items pi ON p.id = pi.product_id
        INNER JOIN purchases pu ON pi.purchase_id = pu.purchase_id
        WHERE (pu.deleted_at IS NULL OR pu.deleted_at = '0000-00-00 00:00:00')
        ORDER BY p.id
        LIMIT 10
    ");
    $test_products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    <title>매입가 선택 기능 테스트</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .test-section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        button { padding: 10px 20px; background: #007cba; color: white; border: none; border-radius: 3px; cursor: pointer; margin: 5px; }
        .test-button { background: #16a085; }
        .test-button:hover { background: #138d75; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-8">매입가 선택 기능 테스트</h1>
        
        <div class="test-section bg-white rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">1. 매입 이력이 있는 테스트 상품들</h2>
            <p class="info mb-4">다음 상품들은 매입 이력이 있어 매입가 선택 기능을 테스트할 수 있습니다:</p>
            
            <?php if (!empty($test_products)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($test_products as $product): ?>
                        <div class="border border-gray-200 rounded p-4 bg-gray-50">
                            <div class="font-medium text-gray-900">
                                <?php echo htmlspecialchars($product['name_ko'] ?: $product['name_en']); ?>
                            </div>
                            <div class="text-sm text-gray-600">
                                SKU: <?php echo htmlspecialchars($product['sku']); ?> | ID: <?php echo $product['id']; ?>
                            </div>
                            <button type="button" 
                                    onclick="testPurchaseHistory(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name_ko'] ?: $product['name_en']); ?>', '<?php echo $product['sku']; ?>')"
                                    class="mt-2 test-button text-white px-3 py-1 rounded text-sm">
                                <i class="fas fa-history mr-1"></i>매입가 조회 테스트
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="error">매입 이력이 있는 상품이 없습니다.</p>
                <p class="text-gray-600">매입 관리에서 상품을 먼저 등록해주세요.</p>
            <?php endif; ?>
        </div>
        
        <div class="test-section bg-white rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">2. 점포별 매입 이력 조회 테스트</h2>
            
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
            <h2 class="text-xl font-semibold mb-4">3. 실제 기능 테스트 방법</h2>
            <ol class="list-decimal list-inside space-y-2">
                <li><a href="store_transfers.php" target="_blank" class="text-blue-600 hover:underline">점간이동 등록 화면</a>을 엽니다.</li>
                <li>출발 점포를 선택합니다.</li>
                <li>상품을 검색하여 장바구니에 추가합니다.</li>
                <li>장바구니에서 "매입가 선택" 버튼을 클릭합니다.</li>
                <li>매입 이력 모달에서 원하는 매입가를 선택하거나 수동으로 입력합니다.</li>
            </ol>
            
            <h3 class="text-lg font-medium mt-6 mb-3">예상 결과:</h3>
            <ul class="list-disc list-inside space-y-1">
                <li>✅ 상품 추가 시 자동으로 최근 매입가가 설정됨</li>
                <li>✅ 매입가 선택 버튼 클릭 시 모달이 표시됨</li>
                <li>✅ 최근 3건의 매입 이력이 표시됨</li>
                <li>✅ 선택한 매입가가 장바구니에 반영됨</li>
                <li>✅ 수동 입력도 정상 작동</li>
            </ul>
        </div>
    </div>

    <script>
    function testPurchaseHistory(productId, productName, productSku) {
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
                html += '<div class="mb-2">';
                html += '<strong>상품:</strong> ' + productName + ' (SKU: ' + productSku + ')<br>';
                html += '<strong>점포 ID:</strong> ' + storeId + '<br>';
                html += '<strong>응답 상태:</strong> ' + (data.success ? '<span class="success">성공</span>' : '<span class="error">실패</span>');
                html += '</div>';
                
                if (data.success && data.data && data.data.length > 0) {
                    html += '<h4 class="font-medium mt-4 mb-2">매입 이력 (' + data.data.length + '건):</h4>';
                    html += '<div class="space-y-2">';
                    
                    // 최근 3건만 표시
                    const recentHistory = data.data.slice(0, 3);
                    recentHistory.forEach((item, index) => {
                        html += '<div class="border border-gray-200 rounded p-3 bg-white">';
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
                        html += '<p class="text-sm text-gray-600 mt-2">* 최근 3건만 표시됨 (전체 ' + data.data.length + '건)</p>';
                    }
                } else {
                    html += '<p class="error mt-3">매입 이력이 없거나 조회할 수 없습니다.</p>';
                    if (data.message) {
                        html += '<p class="text-sm text-gray-600">메시지: ' + data.message + '</p>';
                    }
                }
                
                resultDiv.innerHTML = html;
            })
            .catch(error => {
                console.error('API Error:', error);
                resultDiv.innerHTML = '<p class="error">API 호출 중 오류가 발생했습니다: ' + error.message + '</p>';
            });
    }
    </script>
</body>
</html>