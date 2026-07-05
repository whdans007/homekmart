<?php
/**
 * office_fingerprints 직원당 최대 2개(지문1/지문2) 등록 허용 마이그레이션
 * - 기존 UNIQUE KEY uniq_employee (store_id, employee_id) 제거
 * - slot 번호 자체의 유일성(uniq_slot)은 그대로 유지
 */

require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();

echo "<h2>office_fingerprints 직원당 최대 2개 등록 마이그레이션</h2><pre>";

try {
    echo "[1단계] 현재 인덱스 확인...\n";
    $res = $conn->query("SHOW INDEX FROM office_fingerprints WHERE Key_name='uniq_employee'");
    $exists = $res && $res->num_rows > 0;

    if (!$exists) {
        echo "✓ uniq_employee 인덱스가 이미 없습니다. 마이그레이션 불필요.\n";
    } else {
        echo "uniq_employee 인덱스 발견. 제거를 진행합니다.\n";

        echo "\n[2단계] UNIQUE KEY uniq_employee 제거...\n";
        $sql = "ALTER TABLE office_fingerprints DROP INDEX uniq_employee";
        if ($conn->query($sql)) {
            echo "✓ uniq_employee 제거 완료\n";
        } else {
            throw new Exception("ALTER TABLE 실패: " . $conn->error);
        }

        echo "\n[3단계] 결과 확인...\n";
        $res2 = $conn->query("SHOW INDEX FROM office_fingerprints");
        while ($row = $res2->fetch_assoc()) {
            echo "- {$row['Key_name']}: {$row['Column_name']}\n";
        }

        echo "\n✅ 마이그레이션 완료! 직원당 최대 2개의 지문 슬롯을 등록할 수 있습니다.\n";
    }

} catch (Exception $e) {
    echo "\n❌ 오류: " . $e->getMessage() . "\n";
}

$conn->close();
echo "</pre>";
echo "<br><a href='../../office/index.php'>Office 메인으로 이동</a>";
?>
