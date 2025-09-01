<?php
// 간단한 프리셋 데이터 확인
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h2>프리셋 데이터 현황 - " . date('Y-m-d H:i:s') . "</h2>";
    
    // 프리셋 개수 확인
    $count_result = $conn->query("SELECT COUNT(*) as total FROM layout_presets");
    if ($count_result) {
        $count = $count_result->fetch_assoc()['total'];
        echo "<p><strong>총 프리셋 개수:</strong> {$count}개</p>";
    }
    
    // 모든 프리셋 목록
    $result = $conn->query("SELECT id, preset_name, pattern_code, is_system_preset FROM layout_presets ORDER BY id");
    if ($result && $result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>프리셋명</th><th>패턴</th><th>시스템</th></tr>";
        
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['preset_name']}</td>";
            echo "<td>{$row['pattern_code']}</td>";
            echo "<td>" . ($row['is_system_preset'] ? '예' : '아니오') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>프리셋 데이터가 없습니다!</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . $e->getMessage() . "</p>";
}
?>