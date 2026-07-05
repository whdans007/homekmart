<?php
/**
 * supervisor_shift_time ENUM 확장 마이그레이션
 * 신규 시간 옵션 추가: 8AM~5PM, 8AM~8PM, 8PM~8AM
 */

require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();

echo "<h2>supervisor_shift_time ENUM 확장 마이그레이션</h2><pre>";

try {
    // 현재 컬럼 타입 확인
    echo "[1단계] 현재 컬럼 타입 확인...\n";
    $res = $conn->query("SHOW COLUMNS FROM office_schedule_items LIKE 'supervisor_shift_time'");
    $col = $res ? $res->fetch_assoc() : null;

    if (!$col) {
        throw new Exception("supervisor_shift_time 컬럼을 찾을 수 없습니다.");
    }

    echo "현재 타입: {$col['Type']}\n";

    $new_values = ["'8AM-8PM'","'8PM-8AM'","'11PM~8AM'","'3PM~12AM'","'8AM~5PM'","'8AM~8PM'","'8PM~8AM'"];
    $already_has = array_filter($new_values, fn($v) => str_contains($col['Type'], trim($v, "'")));

    $needs_migration = count($already_has) < count($new_values);

    if (!$needs_migration) {
        echo "✓ 이미 모든 값이 ENUM에 포함되어 있습니다. 마이그레이션 불필요.\n";
    } else {
        echo "\n[2단계] ENUM 확장 실행...\n";
        $sql = "ALTER TABLE office_schedule_items
                MODIFY COLUMN supervisor_shift_time
                  ENUM('8AM-8PM','8PM-8AM','11PM~8AM','3PM~12AM','8AM~5PM','8AM~8PM','8PM~8AM') NULL
                  COMMENT '교대시간 (전 직원 공통)'";

        if ($conn->query($sql)) {
            echo "✓ ENUM 확장 완료\n";
        } else {
            throw new Exception("ALTER TABLE 실패: " . $conn->error);
        }

        // 결과 확인
        echo "\n[3단계] 결과 확인...\n";
        $res2 = $conn->query("SHOW COLUMNS FROM office_schedule_items LIKE 'supervisor_shift_time'");
        $col2 = $res2 ? $res2->fetch_assoc() : null;
        echo "변경 후 타입: " . ($col2['Type'] ?? 'N/A') . "\n";

        echo "\n✅ 마이그레이션 완료!\n";
    }

} catch (Exception $e) {
    echo "\n❌ 오류: " . $e->getMessage() . "\n";
}

$conn->close();
echo "</pre>";
echo "<br><a href='../index.php'>Office 메인으로 이동</a>";
?>
