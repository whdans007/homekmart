<?php
/**
 * office_fingerprint_enroll_requests 테이블 생성 마이그레이션
 * - 웹에서 지문 등록을 트리거하고 ESP32가 polling 하기 위한 큐 테이블
 */

require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();

echo "<h2>office_fingerprint_enroll_requests 테이블 생성</h2><pre>";

try {
    $res = $conn->query("SHOW TABLES LIKE 'office_fingerprint_enroll_requests'");
    if ($res && $res->num_rows > 0) {
        echo "✓ 테이블이 이미 존재합니다. 마이그레이션 불필요.\n";
    } else {
        $sql = "CREATE TABLE office_fingerprint_enroll_requests (
          id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
          store_id     INT UNSIGNED  NOT NULL,
          employee_id  INT UNSIGNED  NOT NULL,
          status       ENUM('pending','enrolling','success','failed') NOT NULL DEFAULT 'pending',
          result_slot  TINYINT UNSIGNED NULL COMMENT '등록 성공 시 할당된 센서 슬롯 번호',
          error_message VARCHAR(100) NULL,
          requested_by INT UNSIGNED  NULL,
          created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
          updated_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_store_status (store_id, status),
          FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql)) {
            echo "✅ office_fingerprint_enroll_requests 테이블 생성 완료\n";
        } else {
            throw new Exception("CREATE TABLE 실패: " . $conn->error);
        }
    }
} catch (Exception $e) {
    echo "\n❌ 오류: " . $e->getMessage() . "\n";
}

$conn->close();
echo "</pre>";
echo "<br><a href='../../office/index.php'>Office 메인으로 이동</a>";
?>
