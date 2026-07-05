<?php
/**
 * 시간대 수정 전(UTC+9 설정 때) 찍힌 잘못된 출퇴근 테스트 기록 삭제
 * office_attendance_logs id=9, 10 (employee_id=5, event_time이 created_at보다 1시간 빠른 잘못된 기록)
 * 확인 후 반드시 삭제할 것
 */

require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();

echo "<h2>삭제 대상 확인</h2><pre>";
$r = $conn->query("SELECT * FROM office_attendance_logs WHERE id IN (9, 10)");
while ($row = $r->fetch_assoc()) print_r($row);
echo "</pre>";

$conn->query("DELETE FROM office_attendance_logs WHERE id IN (9, 10)");
echo "<p>✅ id=9, 10 삭제 완료 (영향받은 행: " . $conn->affected_rows . ")</p>";

$conn->close();
echo "<br><a href='../../office/index.php'>Office 메인으로 이동</a>";
?>
