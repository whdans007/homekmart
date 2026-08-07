<?php
/**
 * office_employee_records 테이블 생성 마이그레이션
 * - 직원 인사기록(경고장 등) 저장용, 향후 지각/결근 점수제 확장 가능
 * Design Ref: docs/02-design/features/employee-hr-records.design.md §3.3
 */

require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();

echo "<h2>office_employee_records 테이블 생성</h2><pre>";

try {
    $res = $conn->query("SHOW TABLES LIKE 'office_employee_records'");
    if ($res && $res->num_rows > 0) {
        echo "✓ 테이블이 이미 존재합니다. 마이그레이션 불필요.\n";
    } else {
        $sql = "CREATE TABLE office_employee_records (
          id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          employee_id       INT UNSIGNED NOT NULL,
          store_id          INT UNSIGNED NOT NULL COMMENT '기록 시점 소속 점포',
          event_type        ENUM('warning','late','absence','other') NOT NULL DEFAULT 'warning',
          category          VARCHAR(100) NULL COMMENT '사유 분류',
          content           VARCHAR(500) NULL COMMENT '상세 내용',
          points            INT NOT NULL DEFAULT 0 COMMENT '감점 (향후 인사평가용, 음수 권장)',
          attachment_files  JSON NULL COMMENT '첨부 이미지 파일명 배열',
          created_by        INT UNSIGNED NULL,
          created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_employee_created (employee_id, created_at),
          INDEX idx_store_type (store_id, event_type),
          FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql)) {
            echo "✅ office_employee_records 테이블 생성 완료\n";
        } else {
            throw new Exception("CREATE TABLE 실패: " . $conn->error);
        }
    }
} catch (Exception $e) {
    echo "\n❌ 오류: " . $e->getMessage() . "\n";
}

$conn->close();
echo "</pre>";
echo "<br><a href='../schedule/employees.php'>직원 관리로 이동</a>";
?>
