<?php
require_once 'partials/header.php';
require_permission('admin_access');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = get_db_connection();
        
        // 기존 데이터 확인
        $check_query = "SELECT COUNT(*) as count FROM layout_rows";
        $check_result = $conn->query($check_query);
        $existing_count = $check_result->fetch_assoc()['count'];
        
        if ($existing_count > 0) {
            $error = "이미 레이아웃 데이터가 존재합니다. 레이아웃 빌더에서 수정해주세요.";
        } else {
            // 트랜잭션 시작
            $conn->autocommit(false);
            
            // 기본 행 생성
            $rows_sql = "INSERT INTO `layout_rows` (`row_name`, `row_order`, `row_description`, `is_active`, `margin_bottom`) VALUES
                ('첫 번째 행', 1, '추천상품과 신상품 영역', 1, 20),
                ('두 번째 행', 2, '카테고리별 상품 영역', 1, 20)";
            $conn->query($rows_sql);
            
            // 컬럼 생성 (각 행에 2개씩)
            $columns_sql = "INSERT INTO `layout_columns` (`row_id`, `column_order`, `column_width`, `section_id`, `is_active`) VALUES
                (1, 1, 6, 1, 1),
                (1, 2, 6, 2, 1),
                (2, 1, 6, 3, 1),
                (2, 2, 6, 4, 1)";
            $conn->query($columns_sql);
            
            // 기본 프리셋 추가
            $preset_sql = "INSERT INTO `layout_presets` (`name`, `description`, `preset_data`) VALUES
                ('기본 2x2 레이아웃', '2개 행, 각 행당 2개 컬럼으로 구성된 기본 레이아웃', 
                '{\"rows\": [{\"columns\": 2, \"widths\": [6, 6]}, {\"columns\": 2, \"widths\": [6, 6]}]}')";
            $conn->query($preset_sql);
            
            // 커밋
            $conn->commit();
            $conn->autocommit(true);
            
            $message = "기본 레이아웃이 성공적으로 생성되었습니다!";
        }
        
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

// 현재 상태 확인
try {
    $conn = get_db_connection();
    
    // 레이아웃 행 수 확인
    $rows_result = $conn->query("SELECT COUNT(*) as count FROM layout_rows");
    $rows_count = $rows_result->fetch_assoc()['count'];
    
    // 컬럼 수 확인
    $columns_result = $conn->query("SELECT COUNT(*) as count FROM layout_columns");
    $columns_count = $columns_result->fetch_assoc()['count'];
    
    // 섹션 수 확인
    $sections_result = $conn->query("SELECT COUNT(*) as count FROM display_sections WHERE is_active = 1");
    $sections_count = $sections_result->fetch_assoc()['count'];
    
    $conn->close();
} catch (Exception $e) {
    $error = "시스템 상태를 확인할 수 없습니다: " . $e->getMessage();
}
?>

<div class="container-fluid px-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mt-4">빠른 레이아웃 설정</h1>
                <ol class="breadcrumb mb-4">
                    <li class="breadcrumb-item"><a href="index.php">대시보드</a></li>
                    <li class="breadcrumb-item"><a href="layout_builder.php">레이아웃 빌더</a></li>
                    <li class="breadcrumb-item active">빠른 설정</li>
                </ol>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- 현재 상태 -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>현재 시스템 상태</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="border rounded p-3">
                                <h3 class="text-primary"><?php echo $rows_count ?? 0; ?></h3>
                                <small class="text-muted">레이아웃 행</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-3">
                                <h3 class="text-success"><?php echo $columns_count ?? 0; ?></h3>
                                <small class="text-muted">레이아웃 컬럼</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-3">
                                <h3 class="text-info"><?php echo $sections_count ?? 0; ?></h3>
                                <small class="text-muted">진열 섹션</small>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <?php if ($rows_count > 0): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check me-2"></i>레이아웃이 설정되어 있습니다.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>레이아웃이 설정되지 않았습니다.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 빠른 설정 -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-magic me-2"></i>빠른 레이아웃 생성</h5>
                </div>
                <div class="card-body">
                    <?php if ($rows_count == 0): ?>
                        <p>기본 2x2 레이아웃을 자동으로 생성합니다:</p>
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check text-success me-2"></i>2개 행 생성</li>
                            <li><i class="fas fa-check text-success me-2"></i>각 행에 2개 컬럼 (50%씩)</li>
                            <li><i class="fas fa-check text-success me-2"></i>진열 섹션 자동 할당</li>
                            <li><i class="fas fa-check text-success me-2"></i>기본 프리셋 생성</li>
                        </ul>

                        <form method="post">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-magic me-2"></i>기본 레이아웃 생성
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            이미 레이아웃이 설정되어 있습니다. 레이아웃 빌더에서 수정하실 수 있습니다.
                        </div>
                        <a href="layout_builder.php" class="btn btn-primary">
                            <i class="fas fa-edit me-2"></i>레이아웃 빌더로 이동
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 도구 링크 -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-tools me-2"></i>관련 도구</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <a href="layout_builder.php" class="btn btn-outline-primary w-100 mb-2">
                        <i class="fas fa-th-large me-2"></i>레이아웃 빌더
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="display_sections.php" class="btn btn-outline-success w-100 mb-2">
                        <i class="fas fa-th-list me-2"></i>진열 섹션 관리
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="../shop/index_hmart.php" class="btn btn-outline-info w-100 mb-2" target="_blank">
                        <i class="fas fa-external-link-alt me-2"></i>메인 페이지 미리보기
                    </a>
                </div>
                <div class="col-md-3">
                    <a href="product_display.php" class="btn btn-outline-warning w-100 mb-2">
                        <i class="fas fa-cubes me-2"></i>상품 진열 관리
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'partials/footer.php'; ?>