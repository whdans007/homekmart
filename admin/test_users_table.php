<?php
/**
 * Users 테이블 상태 확인 스크립트
 * 복원 전후 users 테이블의 데이터를 확인하기 위한 도구
 */

require_once '../config/db_config.php';

try {
    $conn = get_db_connection();
    if (!$conn) {
        die("데이터베이스 연결 실패\n");
    }

    $conn->set_charset("utf8mb4");

    echo "=================================================\n";
    echo "Users 테이블 상태 확인\n";
    echo "=================================================\n\n";

    // 테이블 구조 확인
    echo "1. 테이블 구조:\n";
    $structure = $conn->query("SHOW CREATE TABLE users");
    if ($structure) {
        $row = $structure->fetch_array();
        echo $row[1] . "\n\n";
    }

    // AUTO_INCREMENT 값 확인
    echo "2. AUTO_INCREMENT 상태:\n";
    $status = $conn->query("SHOW TABLE STATUS LIKE 'users'");
    if ($status) {
        $row = $status->fetch_assoc();
        echo "  - Auto_increment: " . ($row['Auto_increment'] ?? 'NULL') . "\n";
        echo "  - Rows: " . ($row['Rows'] ?? 0) . "\n\n";
    }

    // 전체 사용자 목록
    echo "3. 전체 사용자 목록:\n";
    $users = $conn->query("SELECT id, username, full_name, email, role, store_id, created_at FROM users ORDER BY id");
    if ($users && $users->num_rows > 0) {
        echo sprintf("%-5s %-15s %-20s %-30s %-15s %-10s %-20s\n",
            "ID", "Username", "Full Name", "Email", "Role", "Store ID", "Created At");
        echo str_repeat("-", 120) . "\n";

        while ($user = $users->fetch_assoc()) {
            echo sprintf("%-5s %-15s %-20s %-30s %-15s %-10s %-20s\n",
                $user['id'],
                $user['username'],
                substr($user['full_name'], 0, 20),
                substr($user['email'], 0, 30),
                $user['role'],
                $user['store_id'] ?? 'NULL',
                $user['created_at']
            );
        }
        echo "\n총 " . $users->num_rows . "명의 사용자\n";
    } else {
        echo "  사용자가 없습니다.\n";
    }

    // ID=1 슈퍼유저 확인
    echo "\n4. ID=1 슈퍼유저 확인:\n";
    $super_user = $conn->query("SELECT * FROM users WHERE id = 1");
    if ($super_user && $super_user->num_rows > 0) {
        $user = $super_user->fetch_assoc();
        echo "  ✅ ID=1 슈퍼유저 존재\n";
        echo "    - Username: " . $user['username'] . "\n";
        echo "    - Full Name: " . $user['full_name'] . "\n";
        echo "    - Email: " . $user['email'] . "\n";
        echo "    - Role: " . $user['role'] . "\n";
        echo "    - Store ID: " . ($user['store_id'] ?? 'NULL') . "\n";
        echo "    - Created At: " . $user['created_at'] . "\n";
    } else {
        echo "  ❌ ID=1 슈퍼유저가 없습니다!\n";
    }

    echo "\n=================================================\n";

    $conn->close();

} catch (Exception $e) {
    echo "오류: " . $e->getMessage() . "\n";
}
?>
