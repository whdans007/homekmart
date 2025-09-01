<?php
// 프리셋 직접 추가
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h2>프리셋 추가 실행</h2>";
    
    // 새로운 프리셋들을 배열로 정의
    $presets = [
        [
            'name' => '단일 컬럼',
            'desc' => '전체 너비 단일 레이아웃',
            'pattern' => '1',
            'config' => '{"rows": [{"row_name": "메인 배너", "row_description": "전체 너비 배너 영역", "columns": [{"column_width": 12, "column_name": "전체 컬럼"}]}]}'
        ],
        [
            'name' => '2분할 레이아웃',
            'desc' => '좌우 2등분 분할',
            'pattern' => '1-1',
            'config' => '{"rows": [{"row_name": "2분할 영역", "row_description": "좌우 균등 분할 영역", "columns": [{"column_width": 6, "column_name": "왼쪽 컬럼"}, {"column_width": 6, "column_name": "오른쪽 컬럼"}]}]}'
        ],
        [
            'name' => '3분할 레이아웃',
            'desc' => '3등분 균등 분할',
            'pattern' => '1-1-1',
            'config' => '{"rows": [{"row_name": "3분할 영역", "row_description": "3등분 균등 분할 영역", "columns": [{"column_width": 4, "column_name": "첫 번째 컬럼"}, {"column_width": 4, "column_name": "두 번째 컬럼"}, {"column_width": 4, "column_name": "세 번째 컬럼"}]}]}'
        ],
        [
            'name' => '4분할 레이아웃',
            'desc' => '4등분 균등 분할',
            'pattern' => '1-1-1-1',
            'config' => '{"rows": [{"row_name": "4분할 영역", "row_description": "4등분 균등 분할 영역", "columns": [{"column_width": 3, "column_name": "첫 번째"}, {"column_width": 3, "column_name": "두 번째"}, {"column_width": 3, "column_name": "세 번째"}, {"column_width": 3, "column_name": "네 번째"}]}]}'
        ],
        [
            'name' => '5분할 레이아웃',
            'desc' => '5등분 분할 (반응형)',
            'pattern' => '1-1-1-1-1',
            'config' => '{"rows": [{"row_name": "5분할 영역", "row_description": "5등분 균등 분할 영역", "columns": [{"column_width": 2, "column_name": "첫번째"}, {"column_width": 3, "column_name": "두번째"}, {"column_width": 2, "column_name": "세번째"}, {"column_width": 3, "column_name": "네번째"}, {"column_width": 2, "column_name": "다섯번째"}]}]}'
        ],
        [
            'name' => '메인-서브 레이아웃',
            'desc' => '메인 콘텐츠와 사이드바',
            'pattern' => '1-2-1',
            'config' => '{"rows": [{"row_name": "메인-서브 영역", "row_description": "메인 콘텐츠와 사이드바", "columns": [{"column_width": 8, "column_name": "메인 콘텐츠"}, {"column_width": 4, "column_name": "사이드바"}]}]}'
        ],
        [
            'name' => '1-3-1 레이아웃',
            'desc' => '사이드바 + 3분할 + 사이드바',
            'pattern' => '1-3-1',
            'config' => '{"rows": [{"row_name": "복합 영역", "row_description": "좌우 사이드바와 중앙 3분할", "columns": [{"column_width": 2, "column_name": "왼쪽 사이드바"}, {"column_width": 3, "column_name": "첫 번째"}, {"column_width": 2, "column_name": "두 번째"}, {"column_width": 3, "column_name": "세 번째"}, {"column_width": 2, "column_name": "오른쪽 사이드바"}]}]}'
        ]
    ];
    
    $success = 0;
    $errors = 0;
    
    foreach ($presets as $preset) {
        $stmt = $conn->prepare("INSERT INTO layout_presets (preset_name, preset_description, pattern_code, layout_config, is_system_preset) VALUES (?, ?, ?, ?, 1)");
        
        if ($stmt->execute([$preset['name'], $preset['desc'], $preset['pattern'], $preset['config']])) {
            echo "<p style='color: green;'>✅ 추가됨: {$preset['name']} ({$preset['pattern']})</p>";
            $success++;
        } else {
            echo "<p style='color: red;'>❌ 실패: {$preset['name']} - " . $conn->error . "</p>";
            $errors++;
        }
    }
    
    echo "<hr>";
    echo "<p><strong>결과:</strong> 성공 {$success}개, 실패 {$errors}개</p>";
    
    // 최종 개수 확인
    $count_result = $conn->query("SELECT COUNT(*) as total FROM layout_presets");
    $total = $count_result->fetch_assoc()['total'];
    echo "<p><strong>총 프리셋 개수:</strong> {$total}개</p>";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . $e->getMessage() . "</p>";
}
?>

<p><a href="test_preset_functionality.php">← 프리셋 테스트 페이지로 이동</a></p>