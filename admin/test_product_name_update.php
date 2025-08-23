<?php
// 간단한 테스트 파일
require_once __DIR__ . '/partials/header.php';

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    echo "권한 없음";
    exit;
}
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-4">상품명 수정 테스트</h1>
    
    <div class="bg-white shadow rounded-lg p-6">
        <form id="test-form">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">상품 ID</label>
                <input type="number" id="product_id" class="w-full px-3 py-2 border border-gray-300 rounded-md" value="1">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">언어</label>
                <select id="language" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="en">영어</option>
                    <option value="ko">한글</option>
                </select>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">새 상품명</label>
                <input type="text" id="product_name" class="w-full px-3 py-2 border border-gray-300 rounded-md" value="테스트 상품명">
            </div>
            
            <button type="button" id="test-btn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                테스트 실행
            </button>
        </form>
        
        <div id="result" class="mt-6 p-4 border rounded-md hidden">
            <h3 class="font-semibold mb-2">결과:</h3>
            <pre id="result-text" class="bg-gray-100 p-3 rounded text-sm"></pre>
        </div>
    </div>
</div>

<script>
document.getElementById('test-btn').addEventListener('click', function() {
    const productId = document.getElementById('product_id').value;
    const language = document.getElementById('language').value;
    const productName = document.getElementById('product_name').value;
    
    const formData = new FormData();
    formData.append('product_id', productId);
    formData.append('language', language);
    formData.append('product_name', productName);
    
    const resultDiv = document.getElementById('result');
    const resultText = document.getElementById('result-text');
    
    resultDiv.classList.remove('hidden');
    resultText.textContent = '처리 중...';
    
    fetch('ajax_update_product_name.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Response text:', text);
        resultText.textContent = text;
        
        try {
            const result = JSON.parse(text);
            resultText.textContent = JSON.stringify(result, null, 2);
            
            if (result.success) {
                resultDiv.className = 'mt-6 p-4 border border-green-300 bg-green-50 rounded-md';
            } else {
                resultDiv.className = 'mt-6 p-4 border border-red-300 bg-red-50 rounded-md';
            }
        } catch (e) {
            resultDiv.className = 'mt-6 p-4 border border-yellow-300 bg-yellow-50 rounded-md';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        resultText.textContent = '오류: ' + error.message;
        resultDiv.className = 'mt-6 p-4 border border-red-300 bg-red-50 rounded-md';
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>