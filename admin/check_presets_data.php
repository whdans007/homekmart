<?php
// 프리셋 데이터 확인
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

ensure_logged_in();
require_permission('admin_access');

try {
    $conn = get_db_connection();
    
    echo "<h1>프리셋 데이터베이스 확인</h1>";
    
    // 1. layout_presets 테이블 존재 확인
    echo "<h2>1. layout_presets 테이블 구조</h2>";
    $table_structure = $conn->query("DESCRIBE layout_presets");
    
    if ($table_structure) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>컬럼명</th><th>타입</th><th>NULL</th><th>키</th><th>기본값</th><th>Extra</th></tr>";
        while ($row = $table_structure->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
            echo "<td>{$row['Extra']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>❌ layout_presets 테이블이 존재하지 않습니다!</p>";
        echo "<p>SQL 오류: " . $conn->error . "</p>";
    }
    
    // 2. 프리셋 데이터 개수 확인
    echo "<h2>2. 프리셋 데이터 개수</h2>";
    $count_result = $conn->query("SELECT COUNT(*) as total_count FROM layout_presets");
    $total_count = $count_result->fetch_assoc()['total_count'] ?? 0;
    echo "<p><strong>총 프리셋 개수:</strong> {$total_count}개</p>";
    
    // 시스템 프리셋과 사용자 프리셋 분리 확인
    $system_count_result = $conn->query("SELECT COUNT(*) as system_count FROM layout_presets WHERE is_system_preset = 1");
    $system_count = $system_count_result->fetch_assoc()['system_count'] ?? 0;
    echo "<p><strong>시스템 프리셋:</strong> {$system_count}개</p>";
    
    $user_count_result = $conn->query("SELECT COUNT(*) as user_count FROM layout_presets WHERE is_system_preset = 0");
    $user_count = $user_count_result->fetch_assoc()['user_count'] ?? 0;
    echo "<p><strong>사용자 프리셋:</strong> {$user_count}개</p>";
    
    // 3. 모든 프리셋 목록 상세 확인
    echo "<h2>3. 모든 프리셋 목록</h2>";
    $all_presets = $conn->query("SELECT * FROM layout_presets ORDER BY is_system_preset DESC, id ASC");
    
    if ($all_presets && $all_presets->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>이름</th><th>패턴</th><th>시스템</th><th>사용횟수</th><th>JSON 길이</th><th>생성일</th></tr>";
        while ($preset = $all_presets->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$preset['id']}</td>";
            echo "<td>" . htmlspecialchars($preset['preset_name']) . "</td>";
            echo "<td>{$preset['pattern_code']}</td>";
            echo "<td>" . ($preset['is_system_preset'] ? '✅' : '❌') . "</td>";
            echo "<td>{$preset['usage_count']}</td>";
            echo "<td>" . strlen($preset['layout_config']) . "자</td>";
            echo "<td>{$preset['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>❌ 프리셋 데이터가 없습니다!</p>";
        echo "<p>SQL 오류: " . $conn->error . "</p>";
    }
    
    // 4. JSON 파싱 테스트
    echo "<h2>4. JSON 파싱 테스트</h2>";
    $all_presets->data_seek(0); // 결과 포인터 리셋
    $json_errors = [];
    
    while ($preset = $all_presets->fetch_assoc()) {
        $json_data = json_decode($preset['layout_config'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_errors[] = [
                'id' => $preset['id'],
                'name' => $preset['preset_name'],
                'error' => json_last_error_msg()
            ];
        }
    }
    
    if (empty($json_errors)) {
        echo "<p style='color: green;'>✅ 모든 프리셋의 JSON 형식이 올바릅니다.</p>";
    } else {
        echo "<p style='color: red;'>❌ JSON 파싱 오류가 있는 프리셋들:</p>";
        echo "<ul>";
        foreach ($json_errors as $error) {
            echo "<li>ID {$error['id']} ({$error['name']}): {$error['error']}</li>";
        }
        echo "</ul>";
    }
    
    // 5. SQL 파일 존재 확인
    echo "<h2>5. SQL 파일 확인</h2>";
    $sql_file_path = __DIR__ . '/../sql/add_missing_presets.sql';
    if (file_exists($sql_file_path)) {
        $file_size = filesize($sql_file_path);
        echo "<p style='color: green;'>✅ SQL 파일이 존재합니다: " . basename($sql_file_path) . " ({$file_size} bytes)</p>";
        
        // 파일 내용의 첫 몇 줄 미리보기
        $lines = file($sql_file_path, FILE_IGNORE_NEW_LINES);
        $preview_lines = array_slice($lines, 0, 10);
        echo "<h3>파일 미리보기 (첫 10줄):</h3>";
        echo "<pre style='background: #f8f9fa; padding: 10px; border: 1px solid #ddd;'>";
        foreach ($preview_lines as $line_num => $line) {
            echo ($line_num + 1) . ": " . htmlspecialchars($line) . "\n";
        }
        echo "</pre>";
    } else {
        echo "<p style='color: red;'>❌ SQL 파일이 없습니다: {$sql_file_path}</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류 발생: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { margin: 10px 0; }
    th, td { padding: 8px; text-align: left; }
    th { background-color: #f0f0f0; }
    h1 { color: #DE121C; }
    h2 { color: #333; border-bottom: 2px solid #DE121C; padding-bottom: 5px; }
    h3 { color: #666; }
</style>

<p><a href="test_preset_functionality.php">← 프리셋 테스트 페이지로 돌아가기</a></p>