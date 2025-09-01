<?php
// 레이아웃 저장 테스트
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

ensure_logged_in();
require_permission('admin_access');

$conn = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_test') {
        $conn->autocommit(false);
        
        try {
            // 간단한 업데이트 테스트
            $update_stmt = $conn->prepare("UPDATE layout_rows SET row_name = ? WHERE id = ?");
            $test_name = '테스트 행 이름 ' . date('Y-m-d H:i:s');
            $row_id = 27; // 실제 존재하는 ID
            
            $update_stmt->bind_param("si", $test_name, $row_id);
            $result = $update_stmt->execute();
            
            if (!$result) {
                throw new Exception("업데이트 실패: " . $update_stmt->error);
            }
            
            $affected = $update_stmt->affected_rows;
            $update_stmt->close();
            
            $conn->commit();
            $conn->autocommit(true);
            
            $message = "✅ 업데이트 성공! 영향받은 행: $affected";
        } catch (Exception $e) {
            $conn->rollback();
            $conn->autocommit(true);
            $message = "❌ 오류: " . $e->getMessage();
        }
    }
}

// 현재 데이터 조회
$rows_result = $conn->query("SELECT * FROM layout_rows ORDER BY row_order ASC");
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>레이아웃 저장 테스트</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>레이아웃 저장 테스트</h1>
        
        <?php if (isset($message)): ?>
            <div class="alert alert-info"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <h2>현재 layout_rows 데이터</h2>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>이름</th>
                    <th>순서</th>
                    <th>설명</th>
                    <th>활성</th>
                    <th>업데이트 시각</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $rows_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['row_name'] ?? ''); ?></td>
                    <td><?php echo $row['row_order']; ?></td>
                    <td><?php echo htmlspecialchars($row['row_description'] ?? ''); ?></td>
                    <td><?php echo $row['is_active'] ? 'Y' : 'N'; ?></td>
                    <td><?php echo $row['updated_at']; ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
        <h2>저장 테스트</h2>
        <form method="post">
            <input type="hidden" name="action" value="save_test">
            <button type="submit" class="btn btn-primary">행 이름 업데이트 테스트</button>
        </form>
        
        <hr>
        
        <h2>AJAX 저장 테스트</h2>
        <button onclick="testAjaxSave()" class="btn btn-warning">AJAX로 저장 테스트</button>
        <div id="ajax-result" class="mt-3"></div>
        
        <script>
        function testAjaxSave() {
            const testData = {
                rows: [
                    {
                        id: 27,
                        row_name: 'AJAX 테스트 행 ' + new Date().toLocaleTimeString(),
                        row_description: 'AJAX로 업데이트된 설명',
                        columns: []
                    }
                ]
            };
            
            const formData = new FormData();
            formData.append('action', 'save_layout');
            formData.append('layout_data', JSON.stringify(testData));
            
            fetch('ajax_layout_manager.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(text => {
                console.log('Raw response:', text);
                return JSON.parse(text);
            })
            .then(data => {
                document.getElementById('ajax-result').innerHTML = 
                    '<div class="alert alert-' + (data.success ? 'success' : 'danger') + '">' +
                    JSON.stringify(data, null, 2) +
                    '</div>';
                
                if (data.success) {
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(error => {
                document.getElementById('ajax-result').innerHTML = 
                    '<div class="alert alert-danger">오류: ' + error + '</div>';
                console.error('Error:', error);
            });
        }
        </script>
    </div>
</body>
</html>
<?php
$conn->close();
?>