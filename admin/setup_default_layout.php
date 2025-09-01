<?php
// 간단한 기본 레이아웃 설정 페이지
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

ensure_logged_in();
require_permission('admin_access');

$message = '';
$error = '';
$layout_exists = false;

try {
    $conn = get_db_connection();
    
    // 현재 레이아웃 상태 확인
    $check_result = $conn->query("SELECT COUNT(*) as count FROM layout_rows");
    $layout_exists = ($check_result->fetch_assoc()['count'] > 0);
    
    if ($_POST['action'] === 'create_layout') {
        if ($layout_exists) {
            $error = "이미 레이아웃 데이터가 존재합니다.";
        } else {
            // 트랜잭션 시작
            $conn->autocommit(false);
            
            try {
                // 1. 기본 행 생성
                $conn->query("INSERT INTO `layout_rows` (`row_name`, `row_order`, `row_description`, `is_active`, `margin_bottom`) VALUES
                    ('첫 번째 행', 1, '추천상품과 신상품 영역', 1, 20),
                    ('두 번째 행', 2, '카테고리별 상품 영역', 1, 20)");
                
                // 2. 컬럼 생성
                $conn->query("INSERT INTO `layout_columns` (`row_id`, `column_order`, `column_width`, `section_id`, `is_active`) VALUES
                    (1, 1, 6, 1, 1),
                    (1, 2, 6, 2, 1),
                    (2, 1, 6, 3, 1),
                    (2, 2, 6, 4, 1)");
                
                // 3. 프리셋 생성
                $preset_data = json_encode(['rows' => [['columns' => 2, 'widths' => [6, 6]], ['columns' => 2, 'widths' => [6, 6]]]]);
                $stmt = $conn->prepare("INSERT INTO `layout_presets` (`name`, `description`, `preset_data`) VALUES (?, ?, ?)");
                $name = '기본 2x2 레이아웃';
                $desc = '2개 행, 각 행당 2개 컬럼으로 구성된 기본 레이아웃';
                $stmt->bind_param("sss", $name, $desc, $preset_data);
                $stmt->execute();
                $stmt->close();
                
                $conn->commit();
                $conn->autocommit(true);
                
                $message = "✅ 기본 레이아웃이 성공적으로 생성되었습니다!";
                $layout_exists = true;
                
            } catch (Exception $e) {
                $conn->rollback();
                $conn->autocommit(true);
                throw $e;
            }
        }
    }
    
} catch (Exception $e) {
    $error = "오류가 발생했습니다: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>레이아웃 빠른 설정 - HOME K MART 관리</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-magic me-2"></i>레이아웃 빠른 설정
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
                            <div class="col-md-6">
                                <div class="card border-info">
                                    <div class="card-body text-center">
                                        <i class="fas fa-info-circle fa-2x text-info mb-2"></i>
                                        <h5>현재 상태</h5>
                                        <?php if ($layout_exists): ?>
                                            <span class="badge bg-success fs-6">레이아웃 설정됨</span>
                                            <p class="mt-2 text-muted">메인 페이지에서 동적 레이아웃을 사용할 수 있습니다.</p>
                                        <?php else: ?>
                                            <span class="badge bg-warning fs-6">레이아웃 미설정</span>
                                            <p class="mt-2 text-muted">기본 레이아웃을 생성해야 합니다.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-body text-center">
                                        <i class="fas fa-th-large fa-2x text-success mb-2"></i>
                                        <h5>생성할 레이아웃</h5>
                                        <p class="mb-1"><strong>2x2 기본 구조</strong></p>
                                        <small class="text-muted">
                                            2개 행, 각 행에 2개 컬럼<br>
                                            진열 섹션 자동 할당
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!$layout_exists): ?>
                            <form method="post" class="text-center">
                                <input type="hidden" name="action" value="create_layout">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-magic me-2"></i>기본 레이아웃 생성하기
                                </button>
                                <p class="mt-2 text-muted">
                                    클릭 한 번으로 2x2 기본 레이아웃이 자동 생성됩니다.
                                </p>
                            </form>
                        <?php else: ?>
                            <div class="text-center">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    레이아웃이 이미 설정되어 있습니다. 아래 링크로 이동하세요.
                                </div>
                                <a href="../shop/index_hmart.php" class="btn btn-success me-2" target="_blank">
                                    <i class="fas fa-external-link-alt me-2"></i>메인 페이지 확인
                                </a>
                                <a href="layout_builder.php" class="btn btn-primary">
                                    <i class="fas fa-edit me-2"></i>레이아웃 수정
                                </a>
                            </div>
                        <?php endif; ?>

                        <hr class="my-4">

                        <div class="row text-center">
                            <div class="col-md-4">
                                <a href="index.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-home me-2"></i>대시보드
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="display_sections.php" class="btn btn-outline-info w-100">
                                    <i class="fas fa-th-list me-2"></i>진열 섹션 관리
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="product_display.php" class="btn btn-outline-warning w-100">
                                    <i class="fas fa-cubes me-2"></i>상품 진열 관리
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>