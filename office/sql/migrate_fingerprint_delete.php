<?php
/**
 * office_fingerprint_enroll_requests 테이블에 request_type / finger_slot 컬럼 추가
 * - 웹에서 지문 삭제 시, ESP32 센서에서도 해당 슬롯의 템플릿을 삭제하기 위한 요청 큐 확장
 */

require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();

echo "<h2>office_fingerprint_enroll_requests 컬럼 추가 (request_type / finger_slot)</h2><pre>";

try {
    $res = $conn->query("SHOW TABLES LIKE 'office_fingerprint_enroll_requests'");
    if (!$res || $res->num_rows === 0) {
        throw new Exception("office_fingerprint_enroll_requests 테이블이 없습니다. migrate_enroll_requests.php 를 먼저 실행하세요.");
    }

    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM office_fingerprint_enroll_requests");
    while ($row = $res->fetch_assoc()) {
        $cols[$row['Field']] = true;
    }

    if (!isset($cols['request_type'])) {
        if ($conn->query(
            "ALTER TABLE office_fingerprint_enroll_requests
             ADD COLUMN request_type ENUM('enroll','delete') NOT NULL DEFAULT 'enroll' AFTER employee_id"
        )) {
            echo "✅ request_type 컬럼 추가 완료\n";
        } else {
            throw new Exception("request_type 컬럼 추가 실패: " . $conn->error);
        }
    } else {
        echo "✓ request_type 컬럼이 이미 존재합니다.\n";
    }

    if (!isset($cols['finger_slot'])) {
        if ($conn->query(
            "ALTER TABLE office_fingerprint_enroll_requests
             ADD COLUMN finger_slot TINYINT UNSIGNED NULL COMMENT 'delete 요청 시 삭제할 센서 슬롯 번호' AFTER request_type"
        )) {
            echo "✅ finger_slot 컬럼 추가 완료\n";
        } else {
            throw new Exception("finger_slot 컬럼 추가 실패: " . $conn->error);
        }
    } else {
        echo "✓ finger_slot 컬럼이 이미 존재합니다.\n";
    }

} catch (Exception $e) {
    echo "\n❌ 오류: " . $e->getMessage() . "\n";
}

$conn->close();
echo "</pre>";
echo "<br><a href='../../office/index.php'>Office 메인으로 이동</a>";
?>
