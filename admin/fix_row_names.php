<?php
// 비어있는 row_name과 row_description 수정
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

ensure_logged_in();
require_permission('admin_access');

$conn = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->autocommit(false);
        
        // ID 27 업데이트
        $stmt1 = $conn->prepare("UPDATE layout_rows SET row_name = ?, row_description = ? WHERE id = ?");
        $name1 = "첫 번째 행";
        $desc1 = "추천상품과 신상품 영역";
        $id1 = 27;
        $stmt1->bind_param("ssi", $name1, $desc1, $id1);
        $stmt1->execute();
        $stmt1->close();
        
        // ID 28 업데이트
        $stmt2 = $conn->prepare("UPDATE layout_rows SET row_name = ?, row_description = ? WHERE id = ?");
        $name2 = "두 번째 행";
        $desc2 = "카테고리별 상품 영역";
        $id2 = 28;
        $stmt2->bind_param("ssi", $name2, $desc2, $id2);
        $stmt2->execute();
        $stmt2->close();
        
        $conn->commit();
        $conn->autocommit(true);
        
        $message = "✅ 행 이름과 설명이 업데이트되었습니다!";
    } catch (Exception $e) {
        $conn->rollback();
        $conn->autocommit(true);
        $message = "❌ 오류: " . $e->getMessage();
    }
}

// 현재 상태 확인
$rows_result = $conn->query("SELECT * FROM layout_rows ORDER BY row_order ASC");
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>행 이름 수정</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>레이아웃 행 이름 수정</h1>
        
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
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $rows_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['row_name'] ?? '(비어있음)'); ?></td>
                    <td><?php echo $row['row_order']; ?></td>
                    <td><?php echo htmlspecialchars($row['row_description'] ?? '(비어있음)'); ?></td>
                    <td><?php echo $row['is_active'] ? 'Y' : 'N'; ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
        <?php if ($rows_result->num_rows > 0): ?>
        <form method="post">
            <button type="submit" class="btn btn-primary">비어있는 행 이름 수정</button>
        </form>
        <?php endif; ?>
        
        <hr>
        
        <div class="mt-3">
            <a href="layout_builder.php" class="btn btn-success">레이아웃 빌더로 이동</a>
            <a href="../shop/index_hmart.php" class="btn btn-info" target="_blank">메인 페이지 확인</a>
        </div>
    </div>
</body>
</html>
<?php
$conn->close();
?>