<?php
require_once 'config/db_config.php';

$conn = get_db_connection();
if (!$conn) {
    die('데이터베이스 연결 실패: ' . mysqli_connect_error());
}

echo "=== 현재 display_sections 테이블 구조 ===" . PHP_EOL;
$result = $conn->query('DESCRIBE display_sections');
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Default'] . PHP_EOL;
}

// items_per_slide 컬럼이 이미 존재하는지 확인
$check = $conn->query("SHOW COLUMNS FROM display_sections LIKE 'items_per_slide'");
if ($check->num_rows == 0) {
    echo PHP_EOL . "items_per_slide 컬럼이 없습니다. 추가합니다..." . PHP_EOL;
    
    // 컬럼 추가
    $add_column = "ALTER TABLE display_sections ADD COLUMN items_per_slide INT(2) DEFAULT 4 NOT NULL COMMENT '슬라이드당 표시할 아이템 수 (1-10)'";
    if ($conn->query($add_column)) {
        echo "items_per_slide 컬럼 추가 성공!" . PHP_EOL;
        
        // 기존 데이터 업데이트
        $update = "UPDATE display_sections SET items_per_slide = 4 WHERE items_per_slide IS NULL OR items_per_slide = 0";
        if ($conn->query($update)) {
            echo "기존 데이터 업데이트 완료!" . PHP_EOL;
        }
    } else {
        echo "컬럼 추가 실패: " . $conn->error . PHP_EOL;
    }
} else {
    echo PHP_EOL . "items_per_slide 컬럼이 이미 존재합니다." . PHP_EOL;
}

echo PHP_EOL . "=== 업데이트된 테이블 구조 ===" . PHP_EOL;
$result = $conn->query('DESCRIBE display_sections');
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Default'] . PHP_EOL;
}

$conn->close();
echo PHP_EOL . "데이터베이스 작업 완료!" . PHP_EOL;
?>