<?php
// layout_presets 테이블 구조 확인
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h1>layout_presets 테이블 확인</h1>";
    
    // 테이블 구조 확인
    echo "<h2>1. 테이블 구조</h2>";
    $result = $conn->query("DESCRIBE layout_presets");
    
    if ($result) {
        echo "<table border='1'>";
        echo "<tr><th>컬럼명</th><th>타입</th><th>NULL</th><th>키</th><th>기본값</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "<td>{$row['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>테이블 구조 조회 실패: " . $conn->error . "</p>";
    }
    
    // 테이블 데이터 확인
    echo "<h2>2. 기존 데이터</h2>";
    $data_result = $conn->query("SELECT * FROM layout_presets");
    
    if ($data_result) {
        if ($data_result->num_rows > 0) {
            echo "<table border='1'>";
            $first = true;
            while ($row = $data_result->fetch_assoc()) {
                if ($first) {
                    echo "<tr>";
                    foreach (array_keys($row) as $col) {
                        echo "<th>$col</th>";
                    }
                    echo "</tr>";
                    $first = false;
                }
                echo "<tr>";
                foreach ($row as $val) {
                    echo "<td>" . htmlspecialchars(substr($val, 0, 50)) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>데이터가 없습니다.</p>";
        }
    } else {
        echo "<p style='color: red;'>데이터 조회 실패: " . $conn->error . "</p>";
    }
    
    // INSERT 테스트
    echo "<h2>3. INSERT 테스트</h2>";
    
    // 먼저 기존 데이터 삭제
    $conn->query("DELETE FROM layout_presets WHERE preset_name = '테스트 프리셋'");
    
    $preset_data = json_encode(['test' => 'data']);
    $stmt = $conn->prepare("INSERT INTO `layout_presets` (`preset_name`, `preset_description`, `pattern_code`, `layout_config`) VALUES (?, ?, ?, ?)");
    
    if ($stmt) {
        $name = '테스트 프리셋';
        $desc = '테스트 설명';
        $pattern = 'test-pattern';
        $stmt->bind_param("ssss", $name, $desc, $pattern, $preset_data);
        
        if ($stmt->execute()) {
            echo "<p style='color: green;'>✅ INSERT 성공!</p>";
        } else {
            echo "<p style='color: red;'>❌ INSERT 실패: " . $stmt->error . "</p>";
        }
        $stmt->close();
    } else {
        echo "<p style='color: red;'>❌ Prepare 실패: " . $conn->error . "</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>