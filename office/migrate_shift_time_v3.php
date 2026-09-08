<?php
/**
 * Design Ref: homekmart-store-config §3.3 — supervisor_shift_time ENUM → VARCHAR(20) 완화
 * 점포별 근무시간 설정이 도입되면 ENUM에 없는 문자열이 저장될 수 있어 타입을 완화한다.
 * 값 자체는 정정하지 않는다 (Plan §10 Q2 확인: 기존 7개 값 모두 정상 데이터).
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();

echo "<h2>supervisor_shift_time VARCHAR 완화 마이그레이션 (v3)</h2><pre>";

try {
    echo "[1/4] 현재 컬럼 타입 확인...\n";
    $res = $conn->query("SHOW COLUMNS FROM office_schedule_items LIKE 'supervisor_shift_time'");
    $col = $res ? $res->fetch_assoc() : null;

    if (!$col) {
        throw new Exception("supervisor_shift_time 컬럼을 찾을 수 없습니다.");
    }

    echo "현재 타입: {$col['Type']}\n\n";

    if (stripos($col['Type'], 'varchar') === 0) {
        echo "✓ 이미 VARCHAR로 완화되어 있습니다. 마이그레이션 불필요.\n";
        $conn->close();
        echo "</pre>";
        exit;
    }

    echo "[2/4] 변환 전 값 분포 기록...\n";
    $before = [];
    $before_total = 0;
    $r = $conn->query("SELECT supervisor_shift_time, COUNT(*) AS cnt FROM office_schedule_items GROUP BY supervisor_shift_time");
    while ($row = $r->fetch_assoc()) {
        $key = $row['supervisor_shift_time'] === null ? '(NULL)' : $row['supervisor_shift_time'];
        $before[$key] = (int)$row['cnt'];
        $before_total += (int)$row['cnt'];
        echo "  {$key}: {$row['cnt']}\n";
    }
    echo "  합계: {$before_total}\n\n";

    $conn->autocommit(false);

    echo "[3/4] ALTER TABLE 실행 (ENUM → VARCHAR(20))...\n";
    $sql = "ALTER TABLE office_schedule_items
            MODIFY COLUMN supervisor_shift_time VARCHAR(20) NULL
              COMMENT '감독자 교대시간 표시 문자열. 점포별 store_shift_settings 기반으로 생성됨(v3부터 ENUM 해제)'";

    if (!$conn->query($sql)) {
        throw new Exception("ALTER TABLE 실패: " . $conn->error);
    }
    echo "✓ ALTER TABLE 완료\n\n";

    echo "[4/4] 변환 후 값 분포 대조...\n";
    $after = [];
    $after_total = 0;
    $r2 = $conn->query("SELECT supervisor_shift_time, COUNT(*) AS cnt FROM office_schedule_items GROUP BY supervisor_shift_time");
    while ($row = $r2->fetch_assoc()) {
        $key = $row['supervisor_shift_time'] === null ? '(NULL)' : $row['supervisor_shift_time'];
        $after[$key] = (int)$row['cnt'];
        $after_total += (int)$row['cnt'];
        echo "  {$key}: {$row['cnt']}\n";
    }
    echo "  합계: {$after_total}\n\n";

    // ENUM→VARCHAR 전환 후 GROUP BY 정렬 순서가 달라질 수 있으므로(ENUM은 정의 순서, VARCHAR는 다른 순서)
    // 키 정렬 후 내용만 비교한다 (순서 무관 비교).
    ksort($before);
    ksort($after);
    if ($before !== $after || $before_total !== $after_total) {
        throw new Exception("변환 전/후 값 분포가 일치하지 않습니다. 롤백합니다.");
    }

    $conn->commit();
    $conn->autocommit(true);

    echo "✓ 값 분포 완전 일치 확인\n\n";
    echo "변경 후 컬럼 타입: ";
    $res3 = $conn->query("SHOW COLUMNS FROM office_schedule_items LIKE 'supervisor_shift_time'");
    $col3 = $res3 ? $res3->fetch_assoc() : null;
    echo ($col3['Type'] ?? 'N/A') . "\n\n";

    echo "✅ 마이그레이션 완료!\n";

} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(true);
    echo "\n❌ 오류: " . $e->getMessage() . "\n";
    echo "모든 변경사항이 롤백되었습니다.\n";
}

$conn->close();
echo "</pre>";
echo '<br><a href="index.php">Office 메인으로 이동</a>';
?>
