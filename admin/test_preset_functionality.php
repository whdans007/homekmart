<?php
// 프리셋 기능 테스트 페이지
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

ensure_logged_in();
require_permission('admin_access');

$conn = get_db_connection();
$message = '';
$message_type = '';

// 프리셋 데이터 추가 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'run_preset_sql':
                // SQL 파일 실행
                $sql_file = __DIR__ . '/../sql/add_missing_presets.sql';
                if (file_exists($sql_file)) {
                    $sql_content = file_get_contents($sql_file);
                    
                    // SQL을 세미콜론으로 분리하여 실행
                    $statements = array_filter(array_map('trim', explode(';', $sql_content)));
                    $executed = 0;
                    
                    foreach ($statements as $statement) {
                        if (!empty($statement) && !preg_match('/^(--|\#|\/\*)/', $statement)) {
                            if ($conn->query($statement)) {
                                $executed++;
                            }
                        }
                    }
                    
                    $message = "프리셋 SQL 실행 완료: {$executed}개 구문 실행됨";
                    $message_type = 'success';
                } else {
                    $message = "SQL 파일을 찾을 수 없습니다.";
                    $message_type = 'error';
                }
                break;
                
            case 'test_preset':
                $preset_id = (int)($_POST['preset_id'] ?? 0);
                
                if ($preset_id > 0) {
                    try {
                        // 프리셋 존재 여부 확인
                        $check_stmt = $conn->prepare("SELECT id FROM layout_presets WHERE id = ?");
                        $check_stmt->bind_param("i", $preset_id);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows === 0) {
                            $message = "존재하지 않는 프리셋입니다.";
                            $message_type = 'error';
                        } else {
                            // 프리셋 적용 로직을 직접 구현
                            $conn->autocommit(false);
                            
                            try {
                                // 기존 레이아웃 데이터 삭제
                                $conn->query("DELETE FROM layout_columns");
                                $conn->query("DELETE FROM layout_rows");
                                $conn->query("ALTER TABLE layout_rows AUTO_INCREMENT = 1");
                                $conn->query("ALTER TABLE layout_columns AUTO_INCREMENT = 1");
                                
                                // 프리셋 데이터 조회
                                $preset_stmt = $conn->prepare("SELECT * FROM layout_presets WHERE id = ?");
                                $preset_stmt->bind_param("i", $preset_id);
                                $preset_stmt->execute();
                                $preset_result = $preset_stmt->get_result();
                                $preset = $preset_result->fetch_assoc();
                                
                                if (!$preset) {
                                    throw new Exception("프리셋을 찾을 수 없습니다.");
                                }
                                
                                // JSON 설정 파싱
                                $layout_config = json_decode($preset['layout_config'], true);
                                if (!$layout_config || !isset($layout_config['rows'])) {
                                    throw new Exception("프리셋 설정이 올바르지 않습니다.");
                                }
                                
                                // 새 레이아웃 생성
                                foreach ($layout_config['rows'] as $row_index => $row_data) {
                                    $row_order = $row_index + 1;
                                    $row_stmt = $conn->prepare("INSERT INTO layout_rows (row_name, row_description, row_order, is_active) VALUES (?, ?, ?, 1)");
                                    $row_stmt->bind_param("ssi", $row_data['row_name'], $row_data['row_description'], $row_order);
                                    $row_stmt->execute();
                                    $row_id = $conn->insert_id;
                                    
                                    // 컬럼들 생성
                                    foreach ($row_data['columns'] as $col_index => $col_data) {
                                        $col_order = $col_index + 1;
                                        $col_stmt = $conn->prepare("INSERT INTO layout_columns (row_id, column_name, column_width, column_order, is_active) VALUES (?, ?, ?, ?, 1)");
                                        $col_stmt->bind_param("isii", $row_id, $col_data['column_name'], $col_data['column_width'], $col_order);
                                        $col_stmt->execute();
                                    }
                                }
                                
                                // 프리셋 사용 횟수 증가
                                $usage_stmt = $conn->prepare("UPDATE layout_presets SET usage_count = usage_count + 1 WHERE id = ?");
                                $usage_stmt->bind_param("i", $preset_id);
                                $usage_stmt->execute();
                                
                                $conn->commit();
                                $conn->autocommit(true);
                                
                                $message = "프리셋 #{$preset_id} 적용 성공!";
                                $message_type = 'success';
                                
                            } catch (Exception $e) {
                                $conn->rollback();
                                $conn->autocommit(true);
                                throw $e;
                            }
                        }
                    } catch (Exception $e) {
                        $message = "프리셋 적용 실패: " . $e->getMessage();
                        $message_type = 'error';
                    }
                } else {
                    $message = "잘못된 프리셋 ID입니다.";
                    $message_type = 'error';
                }
                break;
        }
    } catch (Exception $e) {
        $message = "오류: " . $e->getMessage();
        $message_type = 'error';
    }
}

// 현재 프리셋 목록 조회
$presets_query = "SELECT * FROM layout_presets ORDER BY is_system_preset DESC, id ASC";
$presets_result = $conn->query($presets_query);

// 현재 레이아웃 상태 조회
$layout_query = "SELECT COUNT(*) as row_count FROM layout_rows WHERE is_active = 1";
$layout_result = $conn->query($layout_query);
$current_layout_count = $layout_result->fetch_assoc()['row_count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>프리셋 기능 테스트</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .preset-card {
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .preset-card:hover {
            border-color: #DE121C;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(222, 18, 28, 0.2);
        }
        
        .pattern-display {
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            font-weight: bold;
            color: #DE121C;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1><i class="fas fa-magic text-danger me-2"></i>프리셋 기능 테스트</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : $message_type; ?> alert-dismissible fade show">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- 현재 상태 -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>현재 레이아웃 상태</h5>
            </div>
            <div class="card-body">
                <p><strong>활성 행 개수:</strong> <?php echo $current_layout_count; ?>개</p>
                <a href="layout_builder.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-external-link-alt me-1"></i>레이아웃 빌더로 이동
                </a>
                <a href="../shop/index_hmart.php" target="_blank" class="btn btn-success btn-sm">
                    <i class="fas fa-eye me-1"></i>메인 페이지 미리보기
                </a>
            </div>
        </div>
        
        <!-- SQL 실행 -->
        <div class="card mb-4">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="fas fa-database me-2"></i>프리셋 데이터 설치</h5>
            </div>
            <div class="card-body">
                <p>누락된 프리셋들을 데이터베이스에 추가합니다.</p>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="run_preset_sql">
                    <button type="submit" class="btn btn-warning" onclick="return confirm('프리셋 데이터를 추가하시겠습니까?')">
                        <i class="fas fa-play me-1"></i>프리셋 SQL 실행
                    </button>
                </form>
            </div>
        </div>
        
        <!-- 프리셋 목록 및 테스트 -->
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>프리셋 목록 및 테스트</h5>
            </div>
            <div class="card-body">
                <?php if ($presets_result && $presets_result->num_rows > 0): ?>
                    <div class="row">
                        <?php while ($preset = $presets_result->fetch_assoc()): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="card preset-card h-100">
                                    <div class="card-body text-center">
                                        <div class="pattern-display mb-3">
                                            <?php echo htmlspecialchars($preset['pattern_code']); ?>
                                        </div>
                                        
                                        <h6 class="card-title"><?php echo htmlspecialchars($preset['preset_name']); ?></h6>
                                        <p class="card-text small text-muted"><?php echo htmlspecialchars($preset['preset_description']); ?></p>
                                        
                                        <div class="mb-2">
                                            <?php if ($preset['is_system_preset']): ?>
                                                <span class="badge bg-primary">시스템</span>
                                            <?php endif; ?>
                                            <span class="badge bg-secondary"><?php echo $preset['usage_count']; ?>회 사용</span>
                                        </div>
                                        
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="test_preset">
                                            <input type="hidden" name="preset_id" value="<?php echo $preset['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" 
                                                    onclick="return confirm('이 프리셋을 적용하면 현재 레이아웃이 교체됩니다. 계속하시겠습니까?')">
                                                <i class="fas fa-play me-1"></i>테스트
                                            </button>
                                        </form>
                                        
                                        <button class="btn btn-info btn-sm" onclick="showPresetData(<?php echo $preset['id']; ?>)">
                                            <i class="fas fa-code me-1"></i>JSON 보기
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-inbox fa-3x mb-3"></i>
                        <p>프리셋이 없습니다. 먼저 프리셋 SQL을 실행하세요.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mt-4">
            <a href="layout_builder.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-2"></i>레이아웃 빌더로 돌아가기
            </a>
        </div>
    </div>

    <!-- JSON 데이터 모달 -->
    <div class="modal fade" id="presetDataModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">프리셋 JSON 데이터</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <pre id="presetJsonContent" class="bg-light p-3" style="max-height: 400px; overflow-y: auto;"></pre>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const presetData = <?php 
            $presets_result->data_seek(0);
            $presets = [];
            while ($preset = $presets_result->fetch_assoc()) {
                $presets[$preset['id']] = $preset;
            }
            echo json_encode($presets);
        ?>;
        
        function showPresetData(presetId) {
            const preset = presetData[presetId];
            if (preset) {
                document.getElementById('presetJsonContent').textContent = JSON.stringify(JSON.parse(preset.layout_config), null, 2);
                new bootstrap.Modal(document.getElementById('presetDataModal')).show();
            }
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>