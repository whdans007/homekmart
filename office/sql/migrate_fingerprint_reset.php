<?php
/**
 * office_fingerprint_enroll_requests.request_type ENUM에 'delete_all' 값 추가
 * - ESP32 센서에 남아있는 모든 지문 템플릿을 한 번에 초기화(emptyDatabase)하기 위한 요청 타입
 */

require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();

echo "<h2>office_fingerprint_enroll_requests.request_type 에 'delete_all' 추가</h2><pre>";

try {
    $res = $conn->query("SHOW COLUMNS FROM office_fingerprint_enroll_requests LIKE 'request_type'");
    $col = $res ? $res->fetch_assoc() : null;
    if (!$col) {
        throw new Exception("request_type 컬럼이 없습니다. migrate_fingerprint_delete.php 를 먼저 실행하세요.");
    }

    if (strpos($col['Type'], 'delete_all') !== false) {
        echo "✓ 'delete_all' 값이 이미 존재합니다.\n";
    } else {
        if ($conn->query(
            "ALTER TABLE office_fingerprint_enroll_requests
             MODIFY COLUMN request_type ENUM('enroll','delete','delete_all') NOT NULL DEFAULT 'enroll'"
        )) {
            echo "✅ 'delete_all' 값 추가 완료\n";
        } else {
            throw new Exception("ALTER 실패: " . $conn->error);
        }
    }

    // delete_all 요청은 특정 직원과 무관하므로 employee_id를 NULL 허용으로 변경
    $res2 = $conn->query("SHOW COLUMNS FROM office_fingerprint_enroll_requests LIKE 'employee_id'");
    $col2 = $res2 ? $res2->fetch_assoc() : null;
    if ($col2 && strtoupper($col2['Null']) === 'NO') {
        if ($conn->query(
            "ALTER TABLE office_fingerprint_enroll_requests MODIFY COLUMN employee_id INT UNSIGNED NULL"
        )) {
            echo "✅ employee_id NULL 허용으로 변경 완료\n";
        } else {
            throw new Exception("employee_id NULL 허용 변경 실패: " . $conn->error);
        }
    } else {
        echo "✓ employee_id는 이미 NULL을 허용합니다.\n";
    }
} catch (Exception $e) {
    echo "\n❌ 오류: " . $e->getMessage() . "\n";
}

$conn->close();
echo "</pre>";
echo "<br><a href='../../office/index.php'>Office 메인으로 이동</a>";
?>
