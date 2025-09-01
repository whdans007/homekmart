<?php
session_start();
require_once '../config/db_config.php';

// 간단한 인증 확인
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$conn = get_db_connection();
if (!$conn) {
    die('데이터베이스 연결 실패: ' . mysqli_connect_error());
}

echo "<h2>Display Sections 테이블 구조 확인</h2>";

// 현재 테이블 구조 확인
echo "<h3>현재 테이블 구조</h3>";
$result = $conn->query('DESCRIBE display_sections');
echo "<table border='1'>";
echo "<tr><th>필드명</th><th>타입</th><th>기본값</th><th>NULL 허용</th><th>키</th><th>추가</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// items_per_slide 컬럼 확인
$check = $conn->query("SHOW COLUMNS FROM display_sections LIKE 'items_per_slide'");
echo "<h3>items_per_slide 컬럼 존재 여부</h3>";
if ($check->num_rows == 0) {
    echo "<p style='color: red;'>items_per_slide 컬럼이 존재하지 않습니다.</p>";
    
    echo "<h3>컬럼 추가 시도</h3>";
    $add_column = "ALTER TABLE display_sections ADD COLUMN items_per_slide INT(2) DEFAULT 4 NOT NULL COMMENT '슬라이드당 표시할 아이템 수 (1-10)'";
    
    if ($conn->query($add_column)) {
        echo "<p style='color: green;'>items_per_slide 컬럼 추가 성공!</p>";
        
        // 기존 데이터 업데이트
        $update = "UPDATE display_sections SET items_per_slide = 4 WHERE items_per_slide IS NULL OR items_per_slide = 0";
        if ($conn->query($update)) {
            echo "<p style='color: green;'>기존 데이터 업데이트 완료!</p>";
        }
        
        // 다시 테이블 구조 확인
        echo "<h3>업데이트된 테이블 구조</h3>";
        $result = $conn->query('DESCRIBE display_sections');
        echo "<table border='1'>";
        echo "<tr><th>필드명</th><th>타입</th><th>기본값</th><th>NULL 허용</th><th>키</th><th>추가</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        echo "<p style='color: red;'>컬럼 추가 실패: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: green;'>items_per_slide 컬럼이 이미 존재합니다.</p>";
    
    // 컬럼 정보 표시
    $column_info = $check->fetch_assoc();
    echo "<pre>" . print_r($column_info, true) . "</pre>";
}

// 현재 섹션 데이터 확인
echo "<h3>현재 섹션 데이터</h3>";
$sections = $conn->query("SELECT id, name, layout_type, items_per_slide FROM display_sections ORDER BY display_order");
echo "<table border='1'>";
echo "<tr><th>ID</th><th>섹션명</th><th>레이아웃 타입</th><th>슬라이드당 아이템 수</th></tr>";
while ($row = $sections->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['layout_type']) . "</td>";
    echo "<td>" . (isset($row['items_per_slide']) ? htmlspecialchars($row['items_per_slide']) : 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";

$conn->close();
?>

<br><br>
<a href="display_sections.php">섹션 관리로 돌아가기</a>