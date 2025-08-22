<?php
session_start();
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 테스트용 세션 설정
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'super_admin';

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>점간이동 삭제 기능 테스트</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .test-section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        button { padding: 10px 20px; margin: 5px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>점간이동 삭제 기능 테스트</h1>
    
    <div class="test-section">
        <h2>1. 기존 점간이동 목록</h2>
        <?php
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 점간이동 목록 조회
            $sql = "
                SELECT 
                    st.id,
                    st.from_store_id,
                    st.to_store_id,
                    st.transfer_date,
                    st.total_amount,
                    st.status,
                    st.created_at,
                    fs.name as from_store_name,
                    ts.name as to_store_name,
                    (SELECT COUNT(*) FROM store_transfer_items WHERE transfer_id = st.id) as item_count
                FROM store_transfers st
                LEFT JOIN stores fs ON st.from_store_id = fs.id
                LEFT JOIN stores ts ON st.to_store_id = ts.id
                ORDER BY st.created_at DESC
                LIMIT 10
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($transfers)) {
                echo "<p class='success'>✓ " . count($transfers) . "개의 점간이동 기록 발견</p>";
                echo "<table>";
                echo "<tr><th>ID</th><th>출발→목적지</th><th>이동날짜</th><th>총금액</th><th>상품수</th><th>상태</th><th>액션</th></tr>";
                
                foreach ($transfers as $transfer) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($transfer['id']) . "</td>";
                    echo "<td>" . htmlspecialchars($transfer['from_store_name']) . " → " . htmlspecialchars($transfer['to_store_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($transfer['transfer_date']) . "</td>";
                    echo "<td>" . number_format($transfer['total_amount'], 2) . "</td>";
                    echo "<td>" . htmlspecialchars($transfer['item_count']) . "개</td>";
                    echo "<td>" . htmlspecialchars($transfer['status']) . "</td>";
                    echo "<td>";
                    echo "<a href='store_transfers.php?edit=" . $transfer['id'] . "' style='margin-right: 10px;'>수정/삭제</a>";
                    echo "<a href='store_transfer_preview.php?id=" . $transfer['id'] . "'>미리보기</a>";
                    echo "</td>";
                    echo "</tr>";
                }
                
                echo "</table>";
            } else {
                echo "<p class='info'>점간이동 기록이 없습니다.</p>";
            }
            
        } catch (Exception $e) {
            echo "<p class='error'>✗ 오류: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>
    
    <div class="test-section">
        <h2>2. 삭제 기능 테스트 방법</h2>
        <ol>
            <li>위의 테이블에서 "수정/삭제" 링크를 클릭합니다.</li>
            <li>점간이동 수정 화면이 열리면 우측 하단의 빨간색 "삭제" 버튼을 찾습니다.</li>
            <li><strong>브라우저의 개발자 도구(F12)를 열고 Console 탭을 확인합니다.</strong></li>
            <li>"삭제" 버튼을 클릭하면 확인 대화상자가 나타납니다.</li>
            <li>"확인"을 클릭하면 점간이동이 삭제되고 목록 페이지로 이동합니다.</li>
            <li>삭제 후 다시 이 페이지를 새로고침하여 해당 기록이 삭제되었는지 확인합니다.</li>
        </ol>
        
        <h3>삭제 기능 특징:</h3>
        <ul>
            <li>확정된(confirmed) 상태의 점간이동 삭제 시 재고가 복원됩니다</li>
            <li>임시(draft) 상태의 점간이동은 재고 변동 없이 삭제됩니다</li>
            <li>삭제는 되돌릴 수 없으므로 확인 대화상자가 표시됩니다</li>
            <li>삭제 중에는 버튼이 비활성화되어 중복 클릭을 방지합니다</li>
        </ul>
        
        <h3>⚠️ 트러블슈팅:</h3>
        <ul>
            <li><strong>브라우저 콘솔 확인</strong>: F12를 누르고 Console 탭에서 JavaScript 에러나 로그를 확인하세요</li>
            <li><strong>서버 로그 확인</strong>: PHP 에러 로그에서 삭제 과정의 상세 로그를 확인할 수 있습니다</li>
            <li><strong>권한 문제</strong>: super_admin이 아닌 경우 자신의 점포에서 출발하는 이동만 삭제할 수 있습니다</li>
        </ul>
    </div>
    
    <div class="test-section">
        <h2>3. 네비게이션</h2>
        <p>
            <a href="store_transfers_list.php">점간이동 목록으로 이동</a> |
            <a href="store_transfers.php">새 점간이동 등록</a>
        </p>
    </div>
</body>
</html>