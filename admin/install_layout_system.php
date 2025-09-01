<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 관리자 권한 확인
ensure_logged_in();
require_permission('admin_access');

$conn = get_db_connection();

// 설치 과정 추적
$installation_log = [];
$success_count = 0;
$error_count = 0;

function log_message($message, $type = 'info') {
    global $installation_log;
    $installation_log[] = [
        'message' => $message,
        'type' => $type,
        'time' => date('H:i:s')
    ];
}

function execute_sql($conn, $sql, $description) {
    global $success_count, $error_count;
    
    try {
        if ($conn->query($sql)) {
            log_message("✅ {$description} - 성공", 'success');
            $success_count++;
            return true;
        } else {
            log_message("❌ {$description} - 실패: " . $conn->error, 'error');
            $error_count++;
            return false;
        }
    } catch (Exception $e) {
        log_message("❌ {$description} - 예외: " . $e->getMessage(), 'error');
        $error_count++;
        return false;
    }
}

// HTML 시작
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>동적 레이아웃 시스템 설치</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .install-container { max-width: 800px; margin: 2rem auto; }
        .log-entry { padding: 0.5rem; margin: 0.2rem 0; border-radius: 4px; }
        .log-success { background-color: #d4edda; color: #155724; }
        .log-error { background-color: #f8d7da; color: #721c24; }
        .log-info { background-color: #d1ecf1; color: #0c5460; }
        .progress-header { background: linear-gradient(135deg, #DE121C, #FF4444); color: white; }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="card border-0 shadow">
            <div class="card-header progress-header text-center py-4">
                <h2><i class="fas fa-cogs me-2"></i>동적 레이아웃 시스템 설치</h2>
                <p class="mb-0">메인 페이지 레이아웃을 자유롭게 구성할 수 있는 시스템을 설치합니다.</p>
            </div>
            <div class="card-body">

<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['install'])) {
    log_message("동적 레이아웃 시스템 설치 시작", 'info');
    
    // 1. layout_rows 테이블 생성
    $sql_layout_rows = "CREATE TABLE IF NOT EXISTS `layout_rows` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `row_name` varchar(100) DEFAULT NULL COMMENT '행 이름',
        `row_order` int(11) NOT NULL DEFAULT 0 COMMENT '행 표시 순서',
        `row_description` text DEFAULT NULL COMMENT '행 설명',
        `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
        `margin_top` int(11) DEFAULT 0 COMMENT '상단 여백 (px)',
        `margin_bottom` int(11) DEFAULT 20 COMMENT '하단 여백 (px)',
        `background_color` varchar(7) DEFAULT NULL COMMENT '배경색',
        `custom_css_class` varchar(255) DEFAULT NULL COMMENT '커스텀 CSS 클래스',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        INDEX `idx_layout_rows_order` (`row_order`),
        INDEX `idx_layout_rows_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='메인 페이지 레이아웃 행 관리'";
    
    execute_sql($conn, $sql_layout_rows, "layout_rows 테이블 생성");
    
    // 2. layout_columns 테이블 생성
    $sql_layout_columns = "CREATE TABLE IF NOT EXISTS `layout_columns` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `row_id` int(11) NOT NULL COMMENT '레이아웃 행 ID',
        `column_order` int(11) NOT NULL DEFAULT 0 COMMENT '컬럼 순서',
        `column_width` int(11) NOT NULL DEFAULT 12 COMMENT 'Bootstrap 그리드 너비 (1-12)',
        `section_id` int(11) DEFAULT NULL COMMENT '배치된 섹션 ID',
        `column_name` varchar(100) DEFAULT NULL COMMENT '컬럼 이름',
        `min_height` int(11) DEFAULT NULL COMMENT '최소 높이 (px)',
        `padding_x` int(11) DEFAULT 15 COMMENT '좌우 패딩 (px)',
        `padding_y` int(11) DEFAULT 10 COMMENT '상하 패딩 (px)',
        `background_color` varchar(7) DEFAULT NULL COMMENT '배경색',
        `border_radius` int(11) DEFAULT 0 COMMENT '모서리 둥글기 (px)',
        `custom_css_class` varchar(255) DEFAULT NULL COMMENT '커스텀 CSS 클래스',
        `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `row_id` (`row_id`),
        KEY `section_id` (`section_id`),
        INDEX `idx_layout_columns_order` (`row_id`, `column_order`),
        INDEX `idx_layout_columns_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='레이아웃 컬럼 및 섹션 매핑'";
    
    execute_sql($conn, $sql_layout_columns, "layout_columns 테이블 생성");
    
    // 3. layout_presets 테이블 생성
    $sql_layout_presets = "CREATE TABLE IF NOT EXISTS `layout_presets` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `preset_name` varchar(100) NOT NULL COMMENT '프리셋 이름',
        `preset_description` text DEFAULT NULL COMMENT '프리셋 설명',
        `pattern_code` varchar(50) NOT NULL COMMENT '패턴 코드',
        `layout_config` json NOT NULL COMMENT '레이아웃 구성 JSON',
        `thumbnail_url` varchar(500) DEFAULT NULL COMMENT '미리보기 이미지 URL',
        `usage_count` int(11) DEFAULT 0 COMMENT '사용 횟수',
        `is_system_preset` tinyint(1) DEFAULT 0 COMMENT '시스템 기본 프리셋 여부',
        `created_by` int(11) DEFAULT NULL COMMENT '생성자 ID',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `preset_name` (`preset_name`),
        INDEX `idx_layout_presets_pattern` (`pattern_code`),
        INDEX `idx_layout_presets_usage` (`usage_count` DESC),
        KEY `created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='레이아웃 프리셋 템플릿'";
    
    execute_sql($conn, $sql_layout_presets, "layout_presets 테이블 생성");
    
    // 4. 외래키 제약조건 추가 (테이블이 존재하는 경우에만)
    $sql_fk1 = "ALTER TABLE `layout_columns` 
                 ADD CONSTRAINT `layout_columns_ibfk_1` FOREIGN KEY (`row_id`) REFERENCES `layout_rows` (`id`) ON DELETE CASCADE";
    execute_sql($conn, $sql_fk1, "layout_columns -> layout_rows 외래키 설정");
    
    $sql_fk2 = "ALTER TABLE `layout_columns` 
                 ADD CONSTRAINT `layout_columns_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `display_sections` (`id`) ON DELETE SET NULL";
    execute_sql($conn, $sql_fk2, "layout_columns -> display_sections 외래키 설정");
    
    // 5. 기본 데이터 삽입 (중복 확인 후)
    $check_rows = $conn->query("SELECT COUNT(*) as cnt FROM layout_rows");
    $row_count = $check_rows->fetch_assoc()['cnt'];
    
    if ($row_count == 0) {
        $sql_initial_rows = "INSERT INTO `layout_rows` (`row_name`, `row_order`, `row_description`, `margin_bottom`) VALUES
            ('메인 배너 영역', 10, '상단 메인 배너 또는 프로모션 영역', 30),
            ('추천 상품 영역', 20, '추천 상품 및 인기 상품 진열 영역', 20),
            ('신상품 영역', 30, '신상품 및 최신 상품 진열 영역', 20),
            ('카테고리 영역', 40, '카테고리별 상품 진열 영역', 20)";
        execute_sql($conn, $sql_initial_rows, "기본 레이아웃 행 데이터 삽입");
        
        $sql_initial_columns = "INSERT INTO `layout_columns` (`row_id`, `column_order`, `column_width`, `column_name`) VALUES
            (1, 1, 12, '메인 배너'),
            (2, 1, 12, '추천 상품'),
            (3, 1, 12, '신상품'),
            (4, 1, 12, '카테고리')";
        execute_sql($conn, $sql_initial_columns, "기본 컬럼 구성 데이터 삽입");
    } else {
        log_message("기본 데이터가 이미 존재합니다. 건너뜀.", 'info');
    }
    
    // 6. 기본 프리셋 삽입 (중복 확인 후)
    $check_presets = $conn->query("SELECT COUNT(*) as cnt FROM layout_presets WHERE is_system_preset = 1");
    $preset_count = $check_presets->fetch_assoc()['cnt'];
    
    if ($preset_count == 0) {
        $sql_presets = "INSERT INTO `layout_presets` (`preset_name`, `preset_description`, `pattern_code`, `layout_config`, `is_system_preset`) VALUES
            ('단일 컬럼', '전체 너비 단일 컬럼 레이아웃', '1', '{\"rows\": [{\"columns\": [{\"width\": 12}]}]}', 1),
            ('2분할 레이아웃', '좌우 균등 분할 레이아웃', '2-2', '{\"rows\": [{\"columns\": [{\"width\": 6}, {\"width\": 6}]}]}', 1),
            ('3분할 레이아웃', '3등분 균등 분할 레이아웃', '1-1-1', '{\"rows\": [{\"columns\": [{\"width\": 4}, {\"width\": 4}, {\"width\": 4}]}]}', 1),
            ('메인-서브 레이아웃', '메인 영역과 서브 영역', '2-1', '{\"rows\": [{\"columns\": [{\"width\": 8}, {\"width\": 4}]}]}', 1),
            ('서브-메인 레이아웃', '서브 영역과 메인 영역', '1-2', '{\"rows\": [{\"columns\": [{\"width\": 4}, {\"width\": 8}]}]}', 1),
            ('배너-2분할-배너', '상하 배너 + 중간 2분할', '1-2-1', '{\"rows\": [{\"columns\": [{\"width\": 12}]}, {\"columns\": [{\"width\": 6}, {\"width\": 6}]}, {\"columns\": [{\"width\": 12}]}]}', 1)";
        execute_sql($conn, $sql_presets, "시스템 기본 프리셋 삽입");
    } else {
        log_message("시스템 프리셋이 이미 존재합니다. 건너뜀.", 'info');
    }
    
    log_message("동적 레이아웃 시스템 설치 완료! 성공: {$success_count}, 실패: {$error_count}", 'info');
}

// 설치 진행 상황 표시
if (!empty($installation_log)): ?>
                <div class="mb-4">
                    <h5><i class="fas fa-list me-2"></i>설치 진행 상황</h5>
                    <div class="border rounded p-3" style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($installation_log as $log): ?>
                            <div class="log-entry log-<?php echo $log['type']; ?>">
                                <small class="text-muted">[<?php echo $log['time']; ?>]</small>
                                <?php echo htmlspecialchars($log['message']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="alert alert-<?php echo $error_count > 0 ? 'warning' : 'success'; ?> text-center">
                    <h5>
                        <?php if ($error_count > 0): ?>
                            <i class="fas fa-exclamation-triangle me-2"></i>설치 완료 (일부 오류 있음)
                        <?php else: ?>
                            <i class="fas fa-check-circle me-2"></i>설치 성공!
                        <?php endif; ?>
                    </h5>
                    <p class="mb-3">성공: <strong><?php echo $success_count; ?>개</strong> | 실패: <strong><?php echo $error_count; ?>개</strong></p>
                    
                    <?php if ($error_count == 0): ?>
                        <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                            <a href="layout_builder.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-cogs me-2"></i>레이아웃 빌더 시작하기
                            </a>
                            <a href="display_sections.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>섹션 관리로 돌아가기
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

<?php else: ?>
                <!-- 설치 시작 화면 -->
                <div class="text-center mb-4">
                    <div class="mb-4">
                        <i class="fas fa-th-large fa-4x text-primary mb-3"></i>
                        <h4>동적 레이아웃 시스템</h4>
                        <p class="text-muted">메인 페이지를 1-4개 컬럼으로 자유롭게 구성할 수 있습니다.</p>
                    </div>
                    
                    <div class="row text-start">
                        <div class="col-md-6">
                            <h6><i class="fas fa-check-circle text-success me-2"></i>주요 기능</h6>
                            <ul class="list-unstyled">
                                <li><i class="fas fa-columns me-2"></i>1-4개 컬럼 레이아웃 구성</li>
                                <li><i class="fas fa-drag-left-right me-2"></i>드래그 앤 드롭 편집</li>
                                <li><i class="fas fa-mobile-alt me-2"></i>반응형 디자인 지원</li>
                                <li><i class="fas fa-palette me-2"></i>다양한 프리셋 템플릿</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-info-circle text-info me-2"></i>설치 내용</h6>
                            <ul class="list-unstyled">
                                <li><i class="fas fa-database me-2"></i>layout_rows 테이블</li>
                                <li><i class="fas fa-database me-2"></i>layout_columns 테이블</li>
                                <li><i class="fas fa-database me-2"></i>layout_presets 테이블</li>
                                <li><i class="fas fa-cogs me-2"></i>기본 데이터 및 프리셋</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <form method="POST" class="text-center">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>설치 안전성:</strong> 기존 데이터에 영향을 주지 않으며, 언제든 제거할 수 있습니다.
                    </div>
                    
                    <button type="submit" name="install" class="btn btn-primary btn-lg px-5">
                        <i class="fas fa-download me-2"></i>설치 시작하기
                    </button>
                </form>
<?php endif; ?>

            </div>
        </div>
    </div>
</body>
</html>

<?php
$conn->close();
?>