<?php
// 긴급 DB 상태 확인 스크립트
require_once '../config/db_config.php';

$conn = get_db_connection();
if (!$conn) {
    die("데이터베이스 연결 실패");
}

echo "<h2>데이터베이스 상태 확인</h2>";

// users 테이블 확인
echo "<h3>Users 테이블</h3>";
$result = $conn->query("SELECT id, username, role FROM users ORDER BY id LIMIT 10");
if ($result && $result->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Username</th><th>Role</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['username']}</td><td>{$row['role']}</td></tr>";
    }
    echo "</table>";
    echo "<p>총 {$result->num_rows}개 행 (처음 10개만 표시)</p>";
} else {
    echo "<p style='color:red'>데이터가 없습니다!</p>";
}

// 전체 테이블 행 수 확인
echo "<h3>전체 테이블 데이터 수</h3>";
$tables_result = $conn->query("SHOW TABLES");
if ($tables_result) {
    echo "<table border='1'>";
    echo "<tr><th>테이블명</th><th>행 수</th></tr>";
    while ($table_row = $tables_result->fetch_array()) {
        $table = $table_row[0];
        $count_result = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
        $count = $count_result->fetch_assoc()['cnt'];
        echo "<tr><td>$table</td><td>$count</td></tr>";
    }
    echo "</table>";
}

$conn->close();
?>
