<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 테스트용 세션 설정
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'super_admin';

// 점포 정보 로드
$stores = [];
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stores_stmt = $pdo->query("SELECT id, name FROM stores ORDER BY name");
    $stores = $stores_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "데이터베이스 오류: " . $e->getMessage();
}

// 현재 점포 정보 (header.php에서 가져온 로직)
$current_store_name = '본점';
$current_store_id = 1; // 테스트용
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>점포 선택 기능 테스트</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        .test-section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        select, input { padding: 8px; margin: 5px; border: 1px solid #ccc; border-radius: 3px; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        button { padding: 10px 20px; background: #007cba; color: white; border: none; border-radius: 3px; cursor: pointer; }
        .current-store { background: #e8f4f8; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>점포 선택 기능 테스트</h1>
    
    <div class="test-section">
        <h2>1. 현재 점포 정보</h2>
        <div class="current-store">
            <strong>현재 점포:</strong> <?php echo htmlspecialchars($current_store_name); ?> (ID: <?php echo $current_store_id; ?>)
        </div>
        <p class="info">점간이동 등록 시 출발 점포로 자동 설정되어야 합니다.</p>
    </div>
    
    <div class="test-section">
        <h2>2. 점포 선택 테스트</h2>
        
        <div class="form-group">
            <label for="from_store_id">출발 점포:</label>
            <select id="from_store_id" onchange="updateToStoreOptions()">
                <option value="">출발 점포를 선택하세요</option>
                <?php foreach ($stores as $store): ?>
                    <option value="<?php echo $store['id']; ?>" 
                            <?php echo ($store['id'] == $current_store_id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($store['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="to_store_id">목적지 점포:</label>
            <select id="to_store_id">
                <option value="">목적지 점포를 선택하세요</option>
                <?php foreach ($stores as $store): ?>
                    <option value="<?php echo $store['id']; ?>" data-store-id="<?php echo $store['id']; ?>">
                        <?php echo htmlspecialchars($store['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button onclick="testStoreSelection()">선택된 점포 확인</button>
        
        <div id="test-result" style="margin-top: 15px;"></div>
    </div>
    
    <div class="test-section">
        <h2>3. 테스트 결과 해석</h2>
        <ul>
            <li><strong>✅ 정상:</strong> 출발 점포가 현재 점포로 자동 선택됨</li>
            <li><strong>✅ 정상:</strong> 목적지 점포에서 출발 점포와 같은 점포가 제외됨</li>
            <li><strong>✅ 정상:</strong> 출발 점포 변경 시 목적지 점포 목록이 동적으로 업데이트됨</li>
        </ul>
    </div>

    <script>
    // 목적지 점포 옵션 업데이트 함수 (실제 코드와 동일)
    function updateToStoreOptions() {
        const fromStoreSelect = document.getElementById('from_store_id');
        const toStoreSelect = document.getElementById('to_store_id');
        
        if (!fromStoreSelect || !toStoreSelect) {
            console.log('점포 선택 요소를 찾을 수 없습니다');
            return;
        }
        
        const selectedFromStoreId = fromStoreSelect.value;
        const currentToStoreValue = toStoreSelect.value;
        
        console.log('출발 점포 ID:', selectedFromStoreId);
        
        // 모든 목적지 점포 옵션을 순회하며 출발 점포와 같은 것은 숨김
        Array.from(toStoreSelect.options).forEach(function(option) {
            if (option.value === '') {
                // 기본 옵션은 항상 표시
                option.style.display = '';
                option.disabled = false;
            } else if (option.value === selectedFromStoreId) {
                // 출발 점포와 같은 점포는 숨김
                option.style.display = 'none';
                option.disabled = true;
                
                // 현재 선택된 목적지가 출발 점포와 같다면 선택 해제
                if (option.selected) {
                    toStoreSelect.value = '';
                }
            } else {
                // 다른 점포들은 표시
                option.style.display = '';
                option.disabled = false;
            }
        });
        
        // 현재 선택된 목적지 점포 값이 유효하다면 복원
        if (currentToStoreValue && currentToStoreValue !== selectedFromStoreId) {
            toStoreSelect.value = currentToStoreValue;
        }
        
        console.log('목적지 점포 옵션 업데이트 완료');
    }
    
    function testStoreSelection() {
        const fromStoreId = document.getElementById('from_store_id').value;
        const toStoreId = document.getElementById('to_store_id').value;
        const resultDiv = document.getElementById('test-result');
        
        let html = '<h4>선택된 점포:</h4>';
        html += '<p><strong>출발 점포 ID:</strong> ' + (fromStoreId || '선택되지 않음') + '</p>';
        html += '<p><strong>목적지 점포 ID:</strong> ' + (toStoreId || '선택되지 않음') + '</p>';
        
        // 검증
        const currentStoreId = '<?php echo $current_store_id; ?>';
        let validationResults = [];
        
        if (fromStoreId === currentStoreId) {
            validationResults.push('<span class="success">✅ 출발 점포가 현재 점포로 올바르게 설정됨</span>');
        } else if (fromStoreId) {
            validationResults.push('<span class="error">❌ 출발 점포가 현재 점포와 다름</span>');
        } else {
            validationResults.push('<span class="error">❌ 출발 점포가 선택되지 않음</span>');
        }
        
        if (toStoreId && toStoreId !== fromStoreId) {
            validationResults.push('<span class="success">✅ 목적지 점포가 출발 점포와 다르게 선택됨</span>');
        } else if (toStoreId === fromStoreId) {
            validationResults.push('<span class="error">❌ 목적지 점포가 출발 점포와 동일함 (이는 숨겨져야 함)</span>');
        } else {
            validationResults.push('<span class="info">ℹ️ 목적지 점포가 선택되지 않음</span>');
        }
        
        html += '<h4>검증 결과:</h4>';
        validationResults.forEach(result => {
            html += '<p>' + result + '</p>';
        });
        
        resultDiv.innerHTML = html;
    }
    
    // 페이지 로드 시 초기 필터링 실행
    document.addEventListener('DOMContentLoaded', function() {
        updateToStoreOptions();
        console.log('페이지 로드 완료 - 초기 점포 필터링 실행됨');
    });
    </script>
</body>
</html>