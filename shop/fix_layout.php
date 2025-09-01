<?php
// 레이아웃 데이터 수정
require_once __DIR__ . '/../config/db_config.php';

$message = '';
$error = '';

if (isset($_POST['action']) && $_POST['action'] === 'fix_layout') {
    try {
        $conn = get_db_connection();
        
        // 트랜잭션 시작
        $conn->autocommit(false);
        
        // 기존 데이터 삭제 (외래 키 순서 고려)
        $conn->query("DELETE FROM layout_columns");
        $conn->query("DELETE FROM layout_presets");
        $conn->query("DELETE FROM layout_rows");
        
        // 올바른 레이아웃 데이터 생성
        // 1. 행 생성 (ID 1, 2로 생성됨)
        $conn->query("INSERT INTO `layout_rows` (`row_name`, `row_order`, `row_description`, `is_active`, `margin_bottom`) VALUES
            ('첫 번째 행', 1, '추천상품과 신상품 영역', 1, 20),
            ('두 번째 행', 2, '카테고리별 상품 영역', 1, 20)");
        
        // 방금 생성된 행의 ID를 확인
        $row1_result = $conn->query("SELECT id FROM layout_rows WHERE row_order = 1");
        $row1_id = $row1_result->fetch_assoc()['id'];
        
        $row2_result = $conn->query("SELECT id FROM layout_rows WHERE row_order = 2");
        $row2_id = $row2_result->fetch_assoc()['id'];
        
        // 2. 컬럼 생성 (실제 생성된 row ID 사용)
        $columns_sql = "INSERT INTO `layout_columns` (`row_id`, `column_order`, `column_width`, `section_id`, `is_active`) VALUES
            ($row1_id, 1, 6, 1, 1),
            ($row1_id, 2, 6, 2, 1),
            ($row2_id, 1, 6, 3, 1),
            ($row2_id, 2, 6, 4, 1)";
        $conn->query($columns_sql);
        
        // 3. 프리셋 생성
        $preset_data = json_encode(['rows' => [['columns' => [['width' => 6], ['width' => 6]]], ['columns' => [['width' => 6], ['width' => 6]]]]]);
        $stmt = $conn->prepare("INSERT INTO `layout_presets` (`preset_name`, `preset_description`, `pattern_code`, `layout_config`) VALUES (?, ?, ?, ?)");
        $name = '기본 2x2 레이아웃';
        $desc = '2개 행, 각 행당 2개 컬럼으로 구성된 기본 레이아웃';
        $pattern = '2-2-2-2';
        $stmt->bind_param("ssss", $name, $desc, $pattern, $preset_data);
        $stmt->execute();
        $stmt->close();
        
        $conn->commit();
        $conn->autocommit(true);
        
        $message = "✅ 레이아웃 데이터가 올바르게 수정되었습니다!";
        
        $conn->close();
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
            $conn->autocommit(true);
            $conn->close();
        }
        $error = "오류가 발생했습니다: " . $e->getMessage();
    }
}

// 현재 상태 재확인
try {
    $conn = get_db_connection();
    
    $rows_result = $conn->query("SELECT COUNT(*) as count FROM layout_rows");
    $rows_count = $rows_result->fetch_assoc()['count'];
    
    $columns_result = $conn->query("SELECT COUNT(*) as count FROM layout_columns");
    $columns_count = $columns_result->fetch_assoc()['count'];
    
    $sections_result = $conn->query("SELECT COUNT(*) as count FROM display_sections WHERE is_active = 1");
    $sections_count = $sections_result->fetch_assoc()['count'];
    
    $conn->close();
} catch (Exception $e) {
    $error = "상태 확인 중 오류: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>레이아웃 수정 - HOME K MART</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header bg-warning text-dark">
                        <h4 class="mb-0">
                            <i class="fas fa-wrench me-2"></i>레이아웃 데이터 수정
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="text-center p-3 border rounded">
                                    <h3 class="text-primary"><?php echo $rows_count ?? 0; ?></h3>
                                    <small class="text-muted">레이아웃 행</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-3 border rounded">
                                    <h3 class="text-success"><?php echo $columns_count ?? 0; ?></h3>
                                    <small class="text-muted">레이아웃 컬럼</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-3 border rounded">
                                    <h3 class="text-info"><?php echo $sections_count ?? 0; ?></h3>
                                    <small class="text-muted">진열 섹션</small>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <h5><i class="fas fa-info-circle me-2"></i>문제 상황</h5>
                            <ul class="mb-0">
                                <li>레이아웃 행 이름이 비어있음</li>
                                <li>컬럼 수가 부족함 (4개 섹션에 3개 컬럼)</li>
                                <li>첫 번째 행에 컬럼이 1개만 있음</li>
                                <li>올바른 2x2 구조가 생성되지 않음</li>
                            </ul>
                        </div>

                        <div class="alert alert-success">
                            <h5><i class="fas fa-tools me-2"></i>수정 내용</h5>
                            <ul class="mb-0">
                                <li>2개 행 생성 (각각 명확한 이름)</li>
                                <li>4개 컬럼 생성 (각 행에 2개씩)</li>
                                <li>4개 진열 섹션에 1:1 할당</li>
                                <li>올바른 2x2 레이아웃 구조</li>
                            </ul>
                        </div>

                        <?php if ($columns_count != 4 || $message): ?>
                            <div class="text-center">
                                <form method="post">
                                    <input type="hidden" name="action" value="fix_layout">
                                    <button type="submit" class="btn btn-warning btn-lg" onclick="return confirm('기존 레이아웃 데이터를 삭제하고 새로 생성합니다. 계속하시겠습니까?')">
                                        <i class="fas fa-wrench me-2"></i>레이아웃 데이터 수정
                                    </button>
                                </form>
                                <p class="mt-2 text-muted">
                                    기존 데이터를 삭제하고 올바른 2x2 레이아웃을 생성합니다.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="text-center">
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    레이아웃 데이터가 올바르게 설정되었습니다!
                                </div>
                                <a href="index_hmart.php" class="btn btn-success me-2">
                                    <i class="fas fa-external-link-alt me-2"></i>메인 페이지 확인
                                </a>
                                <a href="check_layout.php" class="btn btn-info">
                                    <i class="fas fa-search me-2"></i>데이터 재확인
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>